//! OCR lines in, subtitle out — the same state machine as the Mac app (stable-frame gating, hide after silence).

use std::time::{Duration, Instant};

#[derive(Debug, Clone, PartialEq)]
pub struct Subtitle {
    pub text: String,
    pub speaker: Option<String>,
    pub source: String,
    /// The line's id in its package (= the server's translation id) — what a "report this line" sends.
    pub entry_id: i64,
    pub package_id: String,
}

/// What a lookup found for the stable OCR text.
pub struct Found {
    pub text: String,
    pub speaker: Option<String>,
    pub entry_id: i64,
    pub package_id: String,
}

pub struct Pipeline {
    pub stable_frames: u32,
    pub hide_after: Duration,
    pub current: Option<Subtitle>,
    pub history: Vec<Subtitle>,
    candidate: Option<String>,
    count: u32,
    last_text: Option<Instant>,
    shown_source: Option<String>,
}

impl Default for Pipeline {
    fn default() -> Self {
        Self { stable_frames: 2, hide_after: Duration::from_millis(1200), current: None, history: vec![], candidate: None, count: 0, last_text: None, shown_source: None }
    }
}

impl Pipeline {
    /// Feed one frame's OCR lines; `lookup` maps text → the matched line. Returns true when `current` changed.
    pub fn ingest(&mut self, lines: &[String], now: Instant, mut lookup: impl FnMut(&str) -> Option<Found>) -> bool {
        let text = lines.iter().map(|l| l.trim()).filter(|l| !l.is_empty()).collect::<Vec<_>>().join("\n");
        if text.is_empty() {
            self.candidate = None;
            self.count = 0;
            if self.current.is_some() && self.last_text.is_some_and(|t| now.duration_since(t) >= self.hide_after) {
                self.current = None;
                self.shown_source = None;
                return true;
            }
            return false;
        }
        self.last_text = Some(now);
        if Some(&text) == self.shown_source.as_ref() {
            return false;
        }
        if Some(&text) == self.candidate.as_ref() {
            self.count += 1;
        } else {
            self.candidate = Some(text.clone());
            self.count = 1;
        }
        if self.count < self.stable_frames {
            return false;
        }
        self.count = 0;
        self.shown_source = Some(text.clone());
        let found = find(&text.replace('\n', " "), &mut lookup).or_else(|| {
            // A menu or list: each line is its own entry. Show the known ones, in screen order.
            let lines: Vec<&str> = text.lines().filter(|l| !l.trim().is_empty()).take(12).collect();
            if lines.len() < 2 {
                return None;
            }
            // Each line, and the prompts in a line that button icons joined ("DEFUSE X PROVOKE").
            let hits: Vec<Found> = lines.iter().flat_map(|l| {
                let parts = glyph_parts(l.trim());
                if parts.len() > 1 { parts } else { vec![l.trim().to_string()] }
            }).filter_map(|l| find(&l, &mut lookup)).collect();
            let first = hits.first()?;
            let (entry_id, package_id) = (first.entry_id, first.package_id.clone());
            let mut seen = std::collections::HashSet::new();
            let text = hits.iter().map(|h| h.text.clone()).filter(|t| seen.insert(t.clone())).collect::<Vec<_>>().join("  ·  ");
            Some(Found { text, speaker: None, entry_id, package_id })
        });
        match found {
            None => {
                let changed = self.current.is_some();
                self.current = None;
                changed
            }
            Some(f) => {
                let sub = Subtitle { text: f.text, speaker: f.speaker, source: text, entry_id: f.entry_id, package_id: f.package_id };
                if self.current.as_ref() == Some(&sub) {
                    return false;
                }
                self.history.insert(0, sub.clone());
                self.history.truncate(50);
                self.current = Some(sub);
                true
            }
        }
    }
}

/// Typographic punctuation OCR reads from game fonts as plain ASCII, as the package has it.
pub fn plain(text: &str) -> String {
    text.replace('…', "...").replace(['’', '‘', '`'], "'").replace(['“', '”'], "\"").replace(['–', '—'], "-")
}

/// "CO Wilson: Okay..." → (Some("CO Wilson"), "Okay..."): a short capitalised name before a colon.
pub fn split_speaker(text: &str) -> (Option<String>, String) {
    let Some((name, rest)) = text.split_once(':') else { return (None, text.into()) };
    let (name, rest) = (name.trim(), rest.trim());
    let words: Vec<&str> = name.split_whitespace().collect();
    let cap = |w: &str| w.chars().next().is_some_and(|c| c.is_uppercase() || c.is_numeric());
    let ok = !rest.is_empty() && (1..=3).contains(&words.len()) && name.chars().count() <= 24
        && words.last().is_some_and(|w| cap(w)) && words[..words.len() - 1].iter().all(|w| cap(w) || w.chars().count() <= 3)
        && name.chars().all(|c| c.is_alphanumeric() || " .'-#".contains(c));
    if ok { (Some(name.into()), rest.into()) } else { (None, text.into()) }
}

/// A button icon read as text: a lone symbol, or X/O/C/0 alone or in brackets ("[O]", "©", "•").
fn is_glyph(w: &str) -> bool {
    let core = w.trim_matches(|c| "[](){}".contains(c));
    let mut cs = core.chars();
    match (cs.next(), cs.next()) {
        (None, _) => true,
        (Some(c), None) => !c.is_alphanumeric() || ("XOCxo0".contains(c) && w.chars().count() <= 3),
        _ => false,
    }
}

/// Button icons at either end dropped ("[O] Photo Studio" → "Photo Studio").
pub fn trim_glyphs(text: &str) -> String {
    let mut w: Vec<&str> = text.split_whitespace().collect();
    while w.len() > 1 && is_glyph(w[0]) { w.remove(0); }
    while w.len() > 1 && is_glyph(w[w.len() - 1]) { w.pop(); }
    w.join(" ")
}

/// A row split at button icons.
pub fn glyph_parts(text: &str) -> Vec<String> {
    text.split_whitespace().collect::<Vec<_>>().split(|w| is_glyph(w)).filter(|p| !p.is_empty()).map(|p| p.join(" ")).collect()
}

/// "TAKE AMMO PACK (0/3)" → ("TAKE AMMO PACK", Some("(0/3)")).
pub fn split_counter(text: &str) -> (String, Option<String>) {
    let t = text.trim_end();
    if let (Some(open), true) = (t.rfind('('), t.ends_with(')')) {
        let inner = &t[open + 1..t.len() - 1];
        if let Some((a, b)) = inner.split_once('/') {
            if open > 0 && !a.is_empty() && !b.is_empty() && a.len() <= 3 && b.len() <= 3 && a.chars().chain(b.chars()).all(|c| c.is_ascii_digit()) {
                return (t[..open].trim_end().into(), Some(t[open..].into()));
            }
        }
    }
    (t.into(), None)
}

/// One piece of text → its line: without the speaker name, then as read, then — a name in a dim colour that OCR
/// garbled and read without its colon ("Staf Gember Absolutely no rush…") — without the first one or two words.
/// A counter ("(0/3)") stays next to the Arabic.
pub fn find(read: &str, lookup: &mut impl FnMut(&str) -> Option<Found>) -> Option<Found> {
    let (raw, counter) = split_counter(&trim_glyphs(&plain(read)));
    let (speaker, body) = split_speaker(&raw);
    let mut tries: Vec<(String, Option<String>)> = match &speaker { Some(s) => vec![(body, Some(s.clone())), (raw.clone(), None)], None => vec![(raw.clone(), None)] };
    let words: Vec<&str> = raw.split_whitespace().collect();
    if speaker.is_none() && words.len() >= 4 && raw.chars().any(|c| c.is_lowercase()) {
        for n in 1..=2 {
            if words.len() - n >= 3 { tries.push((words[n..].join(" "), None)); }
        }
    }
    for (text, name) in tries {
        if let Some(mut f) = lookup(&text) {
            if f.speaker.is_none() { f.speaker = name; }
            if let Some(c) = &counter { f.text = format!("{} {c}", f.text); }
            return Some(f);
        }
    }
    None
}

#[cfg(test)]
mod match_tests {
    use super::*;

    fn table(q: &str) -> Option<Found> {
        let t = match q {
            "Absolutely no rush you take your time" => "على راحتك، خذ وقتك!",
            "Okay..." => "تمام...",
            "DEFUSE" => "فكك",
            "PROVOKE" => "استفزاز",
            "TAKE AMMO PACK" => "خذ صندوق ذخيرة",
            _ => return None,
        };
        Some(Found { text: t.into(), speaker: None, entry_id: 1, package_id: "p".into() })
    }

    #[test]
    fn garbled_names_punctuation_icons_and_counters() {
        assert_eq!(find("Staf Gember Absolutely no rush you take your time", &mut table).unwrap().text, "على راحتك، خذ وقتك!");
        let w = find("CO Wilson: Okay…", &mut table).unwrap();
        assert_eq!((w.text.as_str(), w.speaker.as_deref()), ("تمام...", Some("CO Wilson")));
        assert_eq!(split_speaker("co Wilson: keep it straight").0.as_deref(), Some("co Wilson"));
        assert_eq!(glyph_parts("DEFUSE X PROVOKE"), vec!["DEFUSE", "PROVOKE"]);
        assert_eq!(trim_glyphs("[O] Photo Studio"), "Photo Studio");
        assert_eq!(find("TAKE AMMO PACK (0/3)", &mut table).unwrap().text, "خذ صندوق ذخيرة (0/3)");
    }

    #[test]
    fn a_row_of_two_prompts_shows_both() {
        let mut p = Pipeline { stable_frames: 1, ..Default::default() };
        p.ingest(&["DEFUSE X PROVOKE".into(), "something unknown here".into()], Instant::now(), table);
        assert_eq!(p.current.as_ref().unwrap().text, "فكك  ·  استفزاز");
    }
}
