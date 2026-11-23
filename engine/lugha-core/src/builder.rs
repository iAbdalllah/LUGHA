//! Package building, validation and signing (spec §8). Compiled only with the `issuer` feature.

use std::collections::{BTreeMap, BTreeSet, HashMap};
use std::io::{Cursor, Write};

use base64::engine::general_purpose::STANDARD as B64;
use base64::Engine;
use ed25519_dalek::{Signer, SigningKey};
use rusqlite::Connection;
use serde::{Deserialize, Serialize};

use crate::content::{self, EntryInput, GlossaryTerm};
use crate::delta::Op;
use crate::manifest::{Encryption, FileEntry, GameVersions, Kind, Manifest};
use crate::normalize::{key_hash, normalize};
use crate::{crypto, Error, Result, FORMAT};

/// Package metadata supplied by the backend for a build.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BuildMeta {
    pub game_id: String,
    pub package_id: String,
    pub version: String,
    #[serde(default = "ar")]
    pub language: String,
    #[serde(default = "ar_sa")]
    pub locale: String,
    #[serde(default = "rtl")]
    pub direction: String,
    pub localization_style: String,
    pub package_type: String,
    pub categories: Vec<String>,
    #[serde(default)]
    pub game_versions: GameVersions,
    #[serde(default)]
    pub platforms: Vec<String>,
    #[serde(default = "v100")]
    pub min_app_version: String,
    #[serde(default)]
    pub coverage_bp: BTreeMap<String, u32>,
    #[serde(default)]
    pub minimized: bool,
    #[serde(default)]
    pub release_notes: BTreeMap<String, String>,
    #[serde(default)]
    pub glossary: Vec<GlossaryTerm>,
    /// Minimum coverage (basis points) per category; below it the build fails.
    #[serde(default)]
    pub min_coverage_bp: BTreeMap<String, u32>,
}

fn ar() -> String {
    "ar".into()
}
fn ar_sa() -> String {
    "ar-SA".into()
}
fn rtl() -> String {
    "rtl".into()
}
fn v100() -> String {
    "1.0.0".into()
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Issue {
    pub entry_id: Option<i64>,
    pub code: String,
    pub message: String,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct Report {
    pub entry_count: usize,
    pub errors: Vec<Issue>,
    pub warnings: Vec<Issue>,
}

impl Report {
    pub fn ok(&self) -> bool {
        self.errors.is_empty()
    }
}

/// Signing key file: `{"key_id": "...", "secret": base64(32), "public": base64(32)}`.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct KeyFile {
    pub key_id: String,
    pub secret: String,
    pub public: String,
}

impl KeyFile {
    pub fn generate(key_id: &str) -> Self {
        let sk = SigningKey::from_bytes(&crypto::random_bytes::<32>());
        Self { key_id: key_id.into(), secret: B64.encode(sk.to_bytes()), public: B64.encode(sk.verifying_key().to_bytes()) }
    }

    pub fn signing_key(&self) -> Result<SigningKey> {
        let b: [u8; 32] = B64.decode(&self.secret).ok().and_then(|v| v.try_into().ok()).ok_or_else(|| Error::Build("bad signing key".into()))?;
        Ok(SigningKey::from_bytes(&b))
    }
}

fn bidi_balanced(s: &str) -> bool {
    let (mut embed, mut isolate) = (0i32, 0i32);
    for c in s.chars() {
        match c {
            '\u{202A}' | '\u{202B}' | '\u{202D}' | '\u{202E}' => embed += 1,
            '\u{202C}' => embed -= 1,
            '\u{2066}' | '\u{2067}' | '\u{2068}' => isolate += 1,
            '\u{2069}' => isolate -= 1,
            _ => {}
        }
        if embed < 0 || isolate < 0 {
            return false;
        }
    }
    embed == 0 && isolate == 0
}

pub fn validate(meta: &BuildMeta, entries: &[EntryInput]) -> Report {
    let mut r = Report { entry_count: entries.len(), ..Default::default() };
    let err = |r: &mut Report, id: Option<i64>, code: &str, msg: String| r.errors.push(Issue { entry_id: id, code: code.into(), message: msg });
    let warn = |r: &mut Report, id: Option<i64>, code: &str, msg: String| r.warnings.push(Issue { entry_id: id, code: code.into(), message: msg });

    if entries.is_empty() {
        err(&mut r, None, "empty_package", "the package has no lines".into());
    }
    let allowed: BTreeSet<&str> = meta.categories.iter().map(String::as_str).collect();
    let mut ids = BTreeSet::new();
    type SeenKey = ([u8; 32], String, Option<String>);
    let mut seen: HashMap<SeenKey, (i64, String)> = HashMap::new();

    for e in entries {
        if !ids.insert(e.id) {
            err(&mut r, Some(e.id), "duplicate_id", format!("entry id {} appears twice", e.id));
        }
        if normalize(&e.source).is_empty() {
            err(&mut r, Some(e.id), "empty_source", "source text is empty after normalization".into());
        }
        if e.target.trim().is_empty() {
            err(&mut r, Some(e.id), "empty_target", "translation is empty".into());
        }
        if !bidi_balanced(&e.target) {
            err(&mut r, Some(e.id), "unbalanced_bidi", "translation has unbalanced bidi control characters".into());
        }
        if !allowed.contains(e.category.as_str()) {
            err(&mut r, Some(e.id), "category_not_in_package", format!("category {} is not part of this package", e.category));
        }
        let k = (key_hash(&e.source), e.category.clone(), e.scene.clone());
        if let Some((other, target)) = seen.get(&k) {
            if *target != e.target {
                err(&mut r, Some(e.id), "conflicting_duplicate", format!("same source as entry {other} in the same category and scene, but a different translation"));
            }
        } else {
            seen.insert(k, (e.id, e.target.clone()));
        }
        let src = e.source.to_lowercase();
        for g in &meta.glossary {
            if !src.contains(&g.term.to_lowercase()) {
                continue;
            }
            if g.dnt && !e.target.contains(&g.term) {
                warn(&mut r, Some(e.id), "glossary_dnt", format!("«{}» should stay untranslated", g.term));
            } else if let Some(t) = g.translation.as_deref().filter(|_| !g.dnt) {
                if !e.target.contains(t) {
                    warn(&mut r, Some(e.id), "glossary_term", format!("«{}» should be translated as «{}»", g.term, t));
                }
            }
        }
    }
    for (cat, min) in &meta.min_coverage_bp {
        let have = meta.coverage_bp.get(cat).copied().unwrap_or(0);
        if have < *min {
            err(&mut r, None, "coverage_below_minimum", format!("{cat} coverage {:.2}% is below {:.2}%", have as f64 / 100.0, *min as f64 / 100.0));
        }
    }
    r
}

pub fn build_db(meta: &BuildMeta, entries: &[EntryInput]) -> Result<Connection> {
    let mut conn = Connection::open_in_memory()?;
    fill_db(&mut conn, meta, entries)?;
    conn.execute_batch("VACUUM")?;
    Ok(conn)
}

fn fill_db(conn: &mut Connection, meta: &BuildMeta, entries: &[EntryInput]) -> Result<()> {
    content::create(conn)?;
    let tx = conn.transaction()?;
    for (k, v) in meta_rows(meta) {
        content::set_meta(&tx, &k, &v)?;
    }
    for e in entries {
        content::insert_entry(&tx, e, meta.minimized)?;
    }
    for g in &meta.glossary {
        content::upsert_glossary(&tx, g)?;
    }
    content::finalize(&tx)?;
    tx.commit()?;
    Ok(())
}

fn meta_rows(meta: &BuildMeta) -> Vec<(String, String)> {
    vec![
        ("format".into(), FORMAT.into()),
        ("game_id".into(), meta.game_id.clone()),
        ("package_id".into(), meta.package_id.clone()),
        ("version".into(), meta.version.clone()),
        ("locale".into(), meta.locale.clone()),
        ("minimized".into(), if meta.minimized { "1".into() } else { "0".into() }),
    ]
}

pub struct Sealed {
    pub lpk: Vec<u8>,
    pub manifest: Manifest,
}

/// zstd level: the strongest for normal packages; a faster one for very large content (a whole game's script),
/// where level 19 takes many minutes for a few percent.
fn zstd_level(len: u64) -> i32 {
    if len > 64 * 1024 * 1024 { 12 } else { 19 }
}

#[allow(clippy::too_many_arguments)]
fn seal(meta: &BuildMeta, kind: Kind, base_version: Option<String>, payload: &[u8], content_sha256: String, entry_count: u32, key: &KeyFile, content_key: &[u8; 32], profile: Option<&[u8]>, now: i64) -> Result<Sealed> {
    let compressed = zstd::encode_all(Cursor::new(payload), zstd_level(payload.len() as u64))?;
    seal_compressed(meta, kind, base_version, compressed, content_sha256, entry_count, key, content_key, profile, now)
}

#[allow(clippy::too_many_arguments)]
fn seal_compressed(meta: &BuildMeta, kind: Kind, base_version: Option<String>, compressed: Vec<u8>, content_sha256: String, entry_count: u32, key: &KeyFile, content_key: &[u8; 32], profile: Option<&[u8]>, now: i64) -> Result<Sealed> {
    let nonce: [u8; 12] = crypto::random_bytes();
    let enc = crypto::aes_encrypt_vec(content_key, &nonce, compressed);
    let payload_name = if kind == Kind::Full { "content.db.zst.enc" } else { "delta.ops.zst.enc" };

    let mut files_data: BTreeMap<String, Vec<u8>> = BTreeMap::new();
    files_data.insert(payload_name.into(), enc);
    if let Some(p) = profile {
        files_data.insert("profile.json".into(), p.to_vec());
    }
    let files = files_data
        .iter()
        .map(|(n, d)| {
            use sha2::{Digest, Sha256};
            (n.clone(), FileEntry { sha256: hex::encode(Sha256::digest(d)), size: d.len() as u64 })
        })
        .collect();

    let manifest = Manifest {
        format: FORMAT.into(),
        game_id: meta.game_id.clone(),
        package_id: meta.package_id.clone(),
        version: meta.version.clone(),
        kind,
        base_version,
        language: meta.language.clone(),
        locale: meta.locale.clone(),
        direction: meta.direction.clone(),
        localization_style: meta.localization_style.clone(),
        package_type: meta.package_type.clone(),
        categories: meta.categories.clone(),
        game_versions: meta.game_versions.clone(),
        platforms: meta.platforms.clone(),
        min_app_version: meta.min_app_version.clone(),
        entry_count,
        coverage_bp: meta.coverage_bp.clone(),
        minimized: meta.minimized,
        files,
        content_sha256,
        encryption: Encryption { alg: "AES-256-GCM".into(), nonce: B64.encode(nonce) },
        release_notes: meta.release_notes.clone(),
        built_at: now,
        key_id: key.key_id.clone(),
    };
    let manifest_bytes = serde_json::to_vec_pretty(&manifest)?;
    let sig = key.signing_key()?.sign(&manifest_bytes);

    let mut buf = Cursor::new(Vec::new());
    {
        let mut zip = zip::ZipWriter::new(&mut buf);
        let opts = zip::write::SimpleFileOptions::default().compression_method(zip::CompressionMethod::Stored);
        let mut add = |name: &str, data: &[u8]| -> Result<()> {
            zip.start_file(name, opts).map_err(|e| Error::Build(e.to_string()))?;
            zip.write_all(data)?;
            Ok(())
        };
        add("manifest.json", &manifest_bytes)?;
        add("manifest.sig", B64.encode(sig.to_bytes()).as_bytes())?;
        for (n, d) in &files_data {
            add(n, d)?;
        }
        zip.finish().map_err(|e| Error::Build(e.to_string()))?;
    }
    Ok(Sealed { lpk: buf.into_inner(), manifest })
}

/// Build a full package. Fails with the report if validation finds errors.
pub fn build_full(meta: &BuildMeta, entries: &[EntryInput], key: &KeyFile, content_key: &[u8; 32], profile: Option<&[u8]>, now: i64) -> Result<(Sealed, Report)> {
    let report = validate(meta, entries);
    if !report.ok() {
        return Err(Error::Build(serde_json::to_string(&report)?));
    }
    let conn = build_db(meta, entries)?;
    let hash = content::logical_hash(&conn)?;
    let db = content::to_bytes(&conn)?;
    Ok((seal(meta, Kind::Full, None, &db, hash, entries.len() as u32, key, content_key, profile, now)?, report))
}

/// [build_full] for large packages: the content DB is built in a temporary file in `work_dir` (a small page cache
/// instead of the whole DB in memory, several times over) and compressed straight from that file. Same output.
#[allow(clippy::too_many_arguments)]
pub fn build_full_on_disk(meta: &BuildMeta, entries: &[EntryInput], key: &KeyFile, content_key: &[u8; 32], profile: Option<&[u8]>, now: i64, work_dir: &std::path::Path) -> Result<(Sealed, Report)> {
    let report = validate(meta, entries);
    if !report.ok() {
        return Err(Error::Build(serde_json::to_string(&report)?));
    }
    let tag: [u8; 6] = crypto::random_bytes();
    let tmp = work_dir.join(format!(".content-{}.db", hex::encode(tag)));
    let packed = work_dir.join(format!(".content-{}.packed.db", hex::encode(tag)));
    let cleanup = || {
        for p in [&tmp, &packed] {
            for suffix in ["", "-journal", "-wal", "-shm"] {
                let _ = std::fs::remove_file(format!("{}{suffix}", p.display()));
            }
        }
    };
    let result = (|| -> Result<(Vec<u8>, String)> {
        let mut conn = Connection::open(&tmp)?;
        // A scratch file: no crash safety needed, and at most ~64 MB of page cache.
        conn.execute_batch("PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF; PRAGMA cache_size = -65536; PRAGMA temp_store = FILE;")?;
        fill_db(&mut conn, meta, entries)?;
        let hash = content::logical_hash(&conn)?;
        // Compact copy (what VACUUM gives the in-memory build), written file to file.
        conn.execute("VACUUM INTO ?1", [packed.to_string_lossy()])?;
        drop(conn);
        let len = std::fs::metadata(&packed)?.len();
        let mut compressed = Vec::new();
        zstd::stream::copy_encode(std::fs::File::open(&packed)?, &mut compressed, zstd_level(len))?;
        Ok((compressed, hash))
    })();
    cleanup();
    let (compressed, hash) = result?;
    Ok((seal_compressed(meta, Kind::Full, None, compressed, hash, entries.len() as u32, key, content_key, profile, now)?, report))
}

/// Ops that turn `old` (a plain content DB) into the package described by `meta` + `entries`.
pub fn diff_ops(old: &Connection, meta: &BuildMeta, entries: &[EntryInput]) -> Result<Vec<Op>> {
    let new_db = build_db(meta, entries)?;
    let row = |c: &Connection, id: i64| -> Option<String> {
        c.query_row(
            "SELECT e.source, e.target, cat.slug, e.speaker, e.scene, e.seq,
                    (SELECT group_concat(h, ',') FROM (SELECT hex(key_hash) AS h FROM entry_aliases WHERE entry_id = e.id ORDER BY 1))
             FROM entries e JOIN categories cat ON cat.id = e.category_id WHERE e.id = ?1",
            [id],
            |r| {
                Ok(format!(
                    "{:?}|{}|{}|{:?}|{:?}|{:?}|{:?}",
                    r.get::<_, Option<String>>(0)?, r.get::<_, String>(1)?, r.get::<_, String>(2)?,
                    r.get::<_, Option<String>>(3)?, r.get::<_, Option<String>>(4)?, r.get::<_, Option<i64>>(5)?, r.get::<_, Option<String>>(6)?
                ))
            },
        )
        .ok()
    };
    let mut ops = Vec::new();
    let new_ids: BTreeSet<i64> = entries.iter().map(|e| e.id).collect();
    for e in entries {
        if row(old, e.id) != row(&new_db, e.id) {
            ops.push(Op::UpsertEntry { entry: e.clone() });
        }
    }
    let mut stmt = old.prepare("SELECT id FROM entries ORDER BY id")?;
    for id in stmt.query_map([], |r| r.get::<_, i64>(0))? {
        let id = id?;
        if !new_ids.contains(&id) {
            ops.push(Op::DeleteEntry { id });
        }
    }
    let glossary = |c: &Connection| -> Result<BTreeMap<String, (Option<String>, bool)>> {
        let mut s = c.prepare("SELECT term, translation, dnt FROM glossary")?;
        let rows = s.query_map([], |r| Ok((r.get::<_, String>(0)?, (r.get::<_, Option<String>>(1)?, r.get::<_, i64>(2)? != 0))))?;
        Ok(rows.collect::<std::result::Result<_, _>>()?)
    };
    let (og, ng) = (glossary(old)?, glossary(&new_db)?);
    for (term, (translation, dnt)) in &ng {
        if og.get(term) != Some(&(translation.clone(), *dnt)) {
            ops.push(Op::UpsertGlossary { term: GlossaryTerm { term: term.clone(), translation: translation.clone(), dnt: *dnt } });
        }
    }
    for term in og.keys().filter(|t| !ng.contains_key(*t)) {
        ops.push(Op::DeleteGlossary { term: term.clone() });
    }
    for (k, v) in meta_rows(meta) {
        ops.push(Op::SetMeta { k, v });
    }
    Ok(ops)
}

/// Build a delta from the previous release's plain DB. The resulting content hash is checked against a
/// fresh full build, so a delta can never produce different content than the full package.
pub fn build_delta(old_db: &[u8], base_version: &str, meta: &BuildMeta, entries: &[EntryInput], key: &KeyFile, content_key: &[u8; 32], now: i64) -> Result<Sealed> {
    let report = validate(meta, entries);
    if !report.ok() {
        return Err(Error::Build(serde_json::to_string(&report)?));
    }
    let old = content::load(old_db, true)?;
    let ops = diff_ops(&old, meta, entries)?;
    let ops_json = serde_json::to_vec(&ops)?;
    let applied = crate::delta::apply(old_db, &ops_json)?;
    let hash = content::logical_hash(&content::load(&applied, true)?)?;
    let expected = content::logical_hash(&build_db(meta, entries)?)?;
    if hash != expected {
        return Err(Error::Build("delta does not reproduce the full build".into()));
    }
    seal(meta, Kind::Delta, Some(base_version.into()), &ops_json, hash, entries.len() as u32, key, content_key, None, now)
}
