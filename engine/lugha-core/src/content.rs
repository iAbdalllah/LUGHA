//! The `content.db` SQLite schema (spec §4) and in-memory loading.

use std::ptr::NonNull;

use rusqlite::serialize::OwnedData;
use rusqlite::{Connection, DatabaseName};

use crate::{Error, Result};

pub const SCHEMA_VERSION: u32 = 1;

pub const SCHEMA: &str = r#"
CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT NOT NULL);
CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT UNIQUE NOT NULL);
CREATE TABLE entries (
  id          INTEGER PRIMARY KEY,
  key_hash    BLOB NOT NULL,
  fold_hash   BLOB NOT NULL,
  norm_len    INTEGER NOT NULL,
  source      TEXT,
  target      TEXT NOT NULL,
  category_id INTEGER NOT NULL REFERENCES categories(id),
  speaker     TEXT,
  scene       TEXT,
  seq         INTEGER
);
CREATE INDEX entries_hash ON entries(key_hash);
CREATE INDEX entries_fold ON entries(fold_hash);
CREATE TABLE entry_aliases (entry_id INTEGER NOT NULL, key_hash BLOB NOT NULL, fold_hash BLOB NOT NULL);
CREATE INDEX aliases_hash ON entry_aliases(key_hash);
CREATE INDEX aliases_fold ON entry_aliases(fold_hash);
CREATE INDEX aliases_entry ON entry_aliases(entry_id);
CREATE TABLE grams (gram INTEGER NOT NULL, entry_id INTEGER NOT NULL, PRIMARY KEY (gram, entry_id)) WITHOUT ROWID;
CREATE INDEX grams_entry ON grams(entry_id);
CREATE TABLE gram_df (gram INTEGER PRIMARY KEY, df INTEGER NOT NULL);
CREATE TABLE glossary (term TEXT PRIMARY KEY, translation TEXT, dnt INTEGER NOT NULL DEFAULT 0);
"#;

/// Load a decrypted, decompressed SQLite image into a read-only in-memory connection.
pub fn load(bytes: &[u8], read_only: bool) -> Result<Connection> {
    let mut conn = Connection::open_in_memory()?;
    // SAFETY: we allocate with sqlite3_malloc64 as `deserialize` requires, copy exactly `len` bytes into it,
    // and hand ownership to SQLite (it frees the buffer when the connection closes).
    unsafe {
        let ptr = rusqlite::ffi::sqlite3_malloc64(bytes.len().max(1) as u64) as *mut u8;
        let nn = NonNull::new(ptr).ok_or_else(|| Error::Container("out of memory".into()))?;
        std::ptr::copy_nonoverlapping(bytes.as_ptr(), ptr, bytes.len());
        conn.deserialize(DatabaseName::Main, OwnedData::from_raw_nonnull(nn, bytes.len()), read_only)?;
    }
    let version: u32 = conn
        .query_row("SELECT v FROM meta WHERE k = 'schema_version'", [], |r| r.get::<_, String>(0))
        .map_err(|_| Error::Container("content.db has no schema_version".into()))?
        .parse()
        .unwrap_or(0);
    if version != SCHEMA_VERSION {
        return Err(Error::UnsupportedFormat(format!("content schema {version}")));
    }
    Ok(conn)
}

pub fn to_bytes(conn: &Connection) -> Result<Vec<u8>> {
    Ok(conn.serialize(DatabaseName::Main)?.to_vec())
}

/// One translation line as it enters a package (from the backend export or a delta op).
#[derive(Debug, Clone, serde::Serialize, serde::Deserialize, PartialEq)]
pub struct EntryInput {
    pub id: i64,
    pub source: String,
    pub target: String,
    pub category: String,
    #[serde(default)]
    pub speaker: Option<String>,
    #[serde(default)]
    pub scene: Option<String>,
    #[serde(default)]
    pub seq: Option<i64>,
    #[serde(default)]
    pub aliases: Vec<String>,
}

#[derive(Debug, Clone, serde::Serialize, serde::Deserialize, PartialEq)]
pub struct GlossaryTerm {
    pub term: String,
    #[serde(default)]
    pub translation: Option<String>,
    #[serde(default)]
    pub dnt: bool,
}

fn category_id(conn: &Connection, slug: &str) -> Result<i64> {
    conn.execute("INSERT OR IGNORE INTO categories (slug) VALUES (?1)", [slug])?;
    Ok(conn.query_row("SELECT id FROM categories WHERE slug = ?1", [slug], |r| r.get(0))?)
}

/// Insert or replace an entry with its derived hashes, aliases and trigram index.
pub fn upsert_entry(conn: &Connection, e: &EntryInput, minimized: bool) -> Result<()> {
    delete_entry(conn, e.id)?;
    insert_entry(conn, e, minimized)
}

/// Insert a new entry (the id must not exist yet). Fresh builds use this directly.
pub fn insert_entry(conn: &Connection, e: &EntryInput, minimized: bool) -> Result<()> {
    use crate::normalize::{normalize, ocr_fold, sha256, trigrams};
    let norm = normalize(&e.source);
    let cat = category_id(conn, &e.category)?;
    conn.prepare_cached(
        "INSERT INTO entries (id, key_hash, fold_hash, norm_len, source, target, category_id, speaker, scene, seq) VALUES (?1,?2,?3,?4,?5,?6,?7,?8,?9,?10)",
    )?
    .execute(rusqlite::params![
        e.id, sha256(&norm).to_vec(), sha256(&ocr_fold(&norm)).to_vec(), norm.chars().count() as i64,
        if minimized { None } else { Some(norm.clone()) }, e.target, cat, e.speaker, e.scene, e.seq
    ])?;
    let mut alias_seen = std::collections::BTreeSet::new();
    for alias in &e.aliases {
        let a = normalize(alias);
        if a.is_empty() || a == norm || !alias_seen.insert(a.clone()) {
            continue;
        }
        conn.execute(
            "INSERT INTO entry_aliases (entry_id, key_hash, fold_hash) VALUES (?1, ?2, ?3)",
            rusqlite::params![e.id, sha256(&a).to_vec(), sha256(&ocr_fold(&a)).to_vec()],
        )?;
    }
    let mut stmt = conn.prepare_cached("INSERT OR IGNORE INTO grams (gram, entry_id) VALUES (?1, ?2)")?;
    for g in trigrams(&norm) {
        stmt.execute(rusqlite::params![g as i64, e.id])?;
    }
    Ok(())
}

pub fn delete_entry(conn: &Connection, id: i64) -> Result<()> {
    conn.execute("DELETE FROM entries WHERE id = ?1", [id])?;
    conn.execute("DELETE FROM entry_aliases WHERE entry_id = ?1", [id])?;
    conn.execute("DELETE FROM grams WHERE entry_id = ?1", [id])?;
    Ok(())
}

pub fn upsert_glossary(conn: &Connection, g: &GlossaryTerm) -> Result<()> {
    conn.execute(
        "INSERT INTO glossary (term, translation, dnt) VALUES (?1, ?2, ?3) ON CONFLICT(term) DO UPDATE SET translation = excluded.translation, dnt = excluded.dnt",
        rusqlite::params![g.term, g.translation, g.dnt as i64],
    )?;
    Ok(())
}

pub fn set_meta(conn: &Connection, k: &str, v: &str) -> Result<()> {
    conn.execute("INSERT INTO meta (k, v) VALUES (?1, ?2) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [k, v])?;
    Ok(())
}

/// Recompute derived indexes after a build or a delta (gram document frequencies for the fuzzy matcher).
pub fn finalize(conn: &Connection) -> Result<()> {
    conn.execute_batch("DELETE FROM gram_df; INSERT INTO gram_df (gram, df) SELECT gram, count(*) FROM grams GROUP BY gram;")?;
    Ok(())
}

pub fn create(conn: &Connection) -> Result<()> {
    conn.execute_batch(SCHEMA)?;
    set_meta(conn, "schema_version", &SCHEMA_VERSION.to_string())
}

/// Hash of the logical content (rows in a fixed order), independent of SQLite's page layout — so a
/// package built fresh and one produced by applying a delta hash the same.
pub fn logical_hash(conn: &Connection) -> Result<String> {
    use sha2::{Digest, Sha256};
    let mut h = Sha256::new();
    let mut feed = |parts: &[String]| {
        for p in parts {
            h.update((p.len() as u64).to_le_bytes());
            h.update(p.as_bytes());
        }
    };
    let s = |v: Option<String>| v.unwrap_or_else(|| "\u{0}".into());

    let mut stmt = conn.prepare("SELECT k, v FROM meta ORDER BY k")?;
    for row in stmt.query_map([], |r| Ok([r.get::<_, String>(0)?, r.get::<_, String>(1)?]))? {
        feed(&row?);
    }
    let mut stmt = conn.prepare(
        "SELECT e.id, e.source, e.target, c.slug, e.speaker, e.scene, e.seq FROM entries e JOIN categories c ON c.id = e.category_id ORDER BY e.id",
    )?;
    let mut alias = conn.prepare("SELECT hex(key_hash) FROM entry_aliases WHERE entry_id = ?1 ORDER BY 1")?;
    // Streamed row by row: a whole game is hundreds of thousands of lines.
    let mut rows = stmt.query([])?;
    while let Some(r) = rows.next()? {
        let row = [
            r.get::<_, i64>(0)?.to_string(), s(r.get(1)?), r.get(2)?, r.get(3)?, s(r.get(4)?), s(r.get(5)?),
            r.get::<_, Option<i64>>(6)?.map(|v| v.to_string()).unwrap_or_else(|| "\u{0}".into()),
        ];
        feed(&row);
        let aliases: Vec<String> = alias.query_map([&row[0]], |r| r.get(0))?.collect::<std::result::Result<_, _>>()?;
        feed(&[format!("aliases:{}", aliases.join(","))]);
    }
    let mut stmt = conn.prepare("SELECT term, translation, dnt FROM glossary ORDER BY term")?;
    for row in stmt.query_map([], |r| Ok([r.get::<_, String>(0)?, s(r.get(1)?), r.get::<_, i64>(2)?.to_string()]))? {
        feed(&row?);
    }
    Ok(hex::encode(h.finalize()))
}
