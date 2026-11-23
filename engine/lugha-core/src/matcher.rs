//! OCR text → reviewed translation (spec §5). Everything is local; no network.

use std::num::NonZeroUsize;

use lru::LruCache;
use rusqlite::OptionalExtension;
use serde::{Deserialize, Serialize};

use crate::normalize::{normalize, ocr_fold, sha256, trigrams};
use crate::package::Package;

#[derive(Debug, Clone, Default)]
pub struct Query {
    pub text: String,
    /// Capture region tag from the game profile ("subtitle", "objective"…), used for context only.
    pub region: Option<String>,
}

impl Query {
    pub fn new(text: impl Into<String>) -> Self {
        Self { text: text.into(), region: None }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Method {
    Exact,
    Alias,
    OcrFolded,
    Fuzzy,
    Lines,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Hit {
    pub entry_id: i64,
    pub package_id: String,
    pub target: String,
    pub speaker: Option<String>,
    pub category: String,
    pub score: f32,
    pub method: Method,
    pub provider_id: String,
    /// Always false for package hits; future machine providers must set it and clients must mark the line.
    pub machine_generated: bool,
}

#[derive(Debug, Clone, Copy)]
pub struct Thresholds {
    pub accept: f32,
    pub with_context: f32,
    pub context_bonus: f32,
    pub min_shared_grams: f32,
    pub len_tolerance: f32,
    /// How many of the query's rarest trigrams are used to find candidates.
    pub probe_grams: usize,
    /// Trigrams found in more than this share of lines carry no signal and are not probed.
    pub max_df_ratio: f32,
    /// A query this long (normalized chars) may match the start or end of a longer line…
    pub partial_min_chars: usize,
    /// …when it is this close to that part of it.
    pub partial_accept: f32,
}

impl Default for Thresholds {
    fn default() -> Self {
        Self { accept: 0.86, with_context: 0.75, context_bonus: 0.05, min_shared_grams: 0.30, len_tolerance: 0.25, probe_grams: 16, max_df_ratio: 0.05, partial_min_chars: 24, partial_accept: 0.92 }
    }
}

struct Last {
    package: usize,
    scene: Option<String>,
    seq: Option<i64>,
}

pub struct Matcher {
    packages: Vec<Package>,
    entry_counts: Vec<i64>,
    cache: LruCache<String, Option<Hit>>,
    last_norm: Option<String>,
    last_hit: Option<Hit>,
    last: Option<Last>,
    pub thresholds: Thresholds,
}

struct Candidate {
    id: i64,
    source: Option<String>,
    target: String,
    category: String,
    speaker: Option<String>,
    scene: Option<String>,
    seq: Option<i64>,
}

impl Matcher {
    pub fn new(packages: Vec<Package>) -> Self {
        let entry_counts = packages.iter().map(|p| p.entry_count().unwrap_or(0)).collect();
        Self {
            packages,
            entry_counts,
            cache: LruCache::new(NonZeroUsize::new(1024).unwrap()),
            last_norm: None,
            last_hit: None,
            last: None,
            thresholds: Thresholds::default(),
        }
    }

    pub fn packages(&self) -> &[Package] {
        &self.packages
    }

    /// Forget scene context (e.g. the user switched games or reset the session).
    pub fn reset_context(&mut self) {
        self.last = None;
        self.last_norm = None;
        self.last_hit = None;
    }

    pub fn lookup(&mut self, q: &Query) -> Option<Hit> {
        let norm = normalize(&q.text);
        if norm.is_empty() {
            return None;
        }
        // Step 0: the same subtitle on consecutive frames.
        if self.last_norm.as_deref() == Some(norm.as_str()) {
            return self.last_hit.clone();
        }
        // Step 1: cache. Only context-free hits are cached — never misses, because a line rejected now may
        // be accepted later once scene context makes it plausible (repeated frames are handled by step 0).
        let hit = match self.cache.get(&norm).cloned() {
            Some(h) => h,
            None => {
                let (h, cacheable) = self.resolve(&q.text, &norm);
                if cacheable && h.is_some() {
                    self.cache.put(norm.clone(), h.clone());
                }
                h
            }
        };
        self.last_norm = Some(norm);
        self.last_hit = hit.clone();
        if let Some(h) = &hit {
            if let Some((pi, scene, seq)) = self.locate(h) {
                self.last = Some(Last { package: pi, scene, seq });
            }
        }
        hit
    }

    fn locate(&self, h: &Hit) -> Option<(usize, Option<String>, Option<i64>)> {
        let pi = self.packages.iter().position(|p| p.manifest.package_id == h.package_id)?;
        self.packages[pi]
            .conn
            .query_row("SELECT scene, seq FROM entries WHERE id = ?1", [h.entry_id], |r| Ok((pi, r.get(0)?, r.get(1)?)))
            .ok()
    }

    /// Returns (hit, cacheable).
    fn resolve(&self, raw: &str, norm: &str) -> (Option<Hit>, bool) {
        let key = sha256(norm).to_vec();
        let fold = sha256(&ocr_fold(norm)).to_vec();

        for (pi, p) in self.packages.iter().enumerate() {
            if let Some(c) = self.by_hash(p, "key_hash", &key) {
                return (Some(self.hit(pi, c.0, 1.0, c.1)), true);
            }
        }
        for (pi, p) in self.packages.iter().enumerate() {
            if let Some(c) = self.by_hash(p, "fold_hash", &fold) {
                return (Some(self.hit(pi, c.0, 0.97, Method::OcrFolded)), true);
            }
        }
        if let Some((pi, c, score, used_context)) = self.fuzzy(norm) {
            return (Some(self.hit(pi, c, score, Method::Fuzzy)), !used_context);
        }
        // Step 8: multi-line blocks — try each OCR line.
        let lines: Vec<&str> = raw.lines().map(str::trim).filter(|l| !l.is_empty()).collect();
        if lines.len() > 1 {
            let mut parts = Vec::new();
            let mut first: Option<Hit> = None;
            for line in lines {
                let n = normalize(line);
                let (h, _) = self.resolve(line, &n);
                match h {
                    Some(h) => {
                        parts.push(h.target.clone());
                        first.get_or_insert(h);
                    }
                    None => return (None, true),
                }
            }
            if let Some(mut h) = first {
                h.target = parts.join("\n");
                h.method = Method::Lines;
                return (Some(h), true);
            }
        }
        (None, true)
    }

    fn by_hash(&self, p: &Package, column: &str, hash: &[u8]) -> Option<(Candidate, Method)> {
        let sql_entry = format!("SELECT id FROM entries WHERE {column} = ?1 LIMIT 1");
        if let Ok(Some(id)) = p.conn.query_row(&sql_entry, [hash], |r| r.get::<_, i64>(0)).optional() {
            return self.candidate(p, id).map(|c| (c, Method::Exact));
        }
        let sql_alias = format!("SELECT entry_id FROM entry_aliases WHERE {column} = ?1 LIMIT 1");
        if let Ok(Some(id)) = p.conn.query_row(&sql_alias, [hash], |r| r.get::<_, i64>(0)).optional() {
            return self.candidate(p, id).map(|c| (c, Method::Alias));
        }
        None
    }

    fn candidate(&self, p: &Package, id: i64) -> Option<Candidate> {
        p.conn
            .query_row(
                "SELECT e.id, e.source, e.target, c.slug, e.speaker, e.scene, e.seq FROM entries e JOIN categories c ON c.id = e.category_id WHERE e.id = ?1",
                [id],
                |r| Ok(Candidate { id: r.get(0)?, source: r.get(1)?, target: r.get(2)?, category: r.get(3)?, speaker: r.get(4)?, scene: r.get(5)?, seq: r.get(6)? }),
            )
            .ok()
    }

    fn fuzzy(&self, norm: &str) -> Option<(usize, Candidate, f32, bool)> {
        let t = self.thresholds;
        let q_grams = trigrams(norm);
        if q_grams.len() < 3 {
            return None;
        }
        let q_len = norm.chars().count() as f32;
        let mut best: Option<(usize, Candidate, f32, bool)> = None;
        for (pi, p) in self.packages.iter().enumerate() {
            let minimized = p.manifest.minimized;
            // Candidate generation from the query's rarest trigrams only (common ones like " th" are in
            // almost every line and would make the aggregation scan most of the index).
            let Ok(mut df_stmt) = p.conn.prepare_cached("SELECT df FROM gram_df WHERE gram = ?1") else { continue };
            let mut present: Vec<(i64, i64)> = q_grams
                .iter()
                .filter_map(|g| df_stmt.query_row([*g as i64], |r| r.get::<_, i64>(0)).ok().map(|df| (*g as i64, df)))
                .filter(|(_, df)| (*df as f32) <= (self.entry_counts[pi] as f32 * t.max_df_ratio).max(50.0))
                .collect();
            present.sort_by_key(|(_, df)| *df);
            present.truncate(t.probe_grams);
            if present.len() < 3 {
                continue;
            }
            let min_shared = ((present.len() as f32) * t.min_shared_grams).ceil().max(2.0) as i64;
            let placeholders = vec!["?"; present.len()].join(",");
            let sql = format!(
                "SELECT g.entry_id, count(*) AS c, e.norm_len FROM grams g JOIN entries e ON e.id = g.entry_id
                 WHERE g.gram IN ({placeholders}) GROUP BY g.entry_id HAVING c >= ? ORDER BY c DESC LIMIT 30"
            );
            let Ok(mut stmt) = p.conn.prepare_cached(&sql) else { continue };
            let mut params: Vec<i64> = present.iter().map(|(g, _)| *g).collect();
            params.push(min_shared);
            let rows: Vec<(i64, i64, i64)> = match stmt.query_map(rusqlite::params_from_iter(params.iter()), |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?))) {
                Ok(it) => it.filter_map(|r| r.ok()).collect(),
                Err(_) => continue,
            };
            for (id, _probe_hits, norm_len) in rows {
                // A long line the game shows in parts: the query is the start or the end of it.
                let partial = norm_len as f32 > q_len * (1.0 + t.len_tolerance);
                if partial && (minimized || q_len < t.partial_min_chars as f32 || norm_len as f32 > q_len * 4.0) {
                    continue;
                }
                if !partial && (norm_len as f32 - q_len).abs() > q_len * t.len_tolerance {
                    continue;
                }
                let Some(c) = self.candidate(p, id) else { continue };
                let mut score = match (&c.source, minimized) {
                    (Some(src), false) if partial => {
                        let s = partial_score(norm, src);
                        if s < t.partial_accept {
                            continue;
                        }
                        s - 0.02
                    }
                    (Some(src), false) => strsim::normalized_damerau_levenshtein(norm, src)
                        .max(strsim::normalized_damerau_levenshtein(&ocr_fold(norm), &ocr_fold(src))) as f32,
                    _ => {
                        // Minimized packages have no source text: Dice coefficient over all trigrams.
                        let e_grams: Vec<i64> = p.conn.prepare_cached("SELECT gram FROM grams WHERE entry_id = ?1")
                            .and_then(|mut s| s.query_map([id], |r| r.get(0))?.collect())
                            .unwrap_or_default();
                        let shared = q_grams.iter().filter(|g| e_grams.binary_search(&(**g as i64)).is_ok()).count();
                        (2.0 * shared as f32) / (q_grams.len() as f32 + e_grams.len() as f32)
                    }
                };
                let accept = if minimized { t.accept - 0.06 } else { t.accept };
                let with_context = if minimized { t.with_context - 0.05 } else { t.with_context };
                let mut used_context = false;
                if let Some(last) = &self.last {
                    if last.package == pi && last.scene.is_some() && c.scene == last.scene {
                        if let (Some(ls), Some(cs)) = (last.seq, c.seq) {
                            if (1..=3).contains(&(cs - ls)) {
                                score += t.context_bonus;
                                used_context = true;
                            }
                        }
                    }
                }
                let ok = score >= accept || (used_context && score >= with_context);
                if ok && best.as_ref().map(|b| score > b.2).unwrap_or(true) {
                    best = Some((pi, c, score.min(1.0), used_context));
                }
            }
        }
        best
    }

    fn hit(&self, pi: usize, c: Candidate, score: f32, method: Method) -> Hit {
        Hit {
            entry_id: c.id,
            package_id: self.packages[pi].manifest.package_id.clone(),
            target: c.target,
            speaker: c.speaker,
            category: c.category,
            score,
            method,
            provider_id: "package".into(),
            machine_generated: false,
        }
    }
}

/// How well `q` matches the start or the end of the longer `src` (cut at the nearest word boundary).
fn partial_score(q: &str, src: &str) -> f32 {
    let n = q.chars().count();
    let chars: Vec<char> = src.chars().collect();
    if n == 0 || chars.len() <= n {
        return 0.0;
    }
    let cut = |s: &[char]| -> String { s.iter().collect() };
    let mut best = 0.0f64;
    // Allow the cut to fall a few characters either side so a part ending mid-word still lines up.
    for d in 0..=4usize {
        for len in [n.saturating_sub(d), (n + d).min(chars.len())] {
            if len == 0 {
                continue;
            }
            let head = cut(&chars[..len]);
            let tail = cut(&chars[chars.len() - len..]);
            for part in [head, tail] {
                let s = strsim::normalized_damerau_levenshtein(q, &part)
                    .max(strsim::normalized_damerau_levenshtein(&ocr_fold(q), &ocr_fold(&part)));
                best = best.max(s);
            }
        }
    }
    best as f32
}
