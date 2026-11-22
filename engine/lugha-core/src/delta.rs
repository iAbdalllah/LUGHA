//! Incremental updates (spec §7). A delta is a JSON array of ops, zstd-compressed and encrypted with the
//! new version's content key. Applying happens on a copy; the caller checks the logical hash afterwards.

use serde::{Deserialize, Serialize};

use crate::content::{self, EntryInput, GlossaryTerm};
use crate::{Error, Result};

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(tag = "op", rename_all = "snake_case")]
pub enum Op {
    UpsertEntry { entry: EntryInput },
    DeleteEntry { id: i64 },
    UpsertGlossary { term: GlossaryTerm },
    DeleteGlossary { term: String },
    SetMeta { k: String, v: String },
}

pub fn apply(base_db: &[u8], ops_json: &[u8]) -> Result<Vec<u8>> {
    let ops: Vec<Op> = serde_json::from_slice(ops_json).map_err(|e| Error::Delta(e.to_string()))?;
    let mut conn = content::load(base_db, false)?;
    let minimized = conn
        .query_row("SELECT v FROM meta WHERE k = 'minimized'", [], |r| r.get::<_, String>(0))
        .map(|v| v == "1")
        .unwrap_or(false);
    let tx = conn.transaction()?;
    for op in &ops {
        match op {
            Op::UpsertEntry { entry } => content::upsert_entry(&tx, entry, minimized)?,
            Op::DeleteEntry { id } => content::delete_entry(&tx, *id)?,
            Op::UpsertGlossary { term } => content::upsert_glossary(&tx, term)?,
            Op::DeleteGlossary { term } => {
                tx.execute("DELETE FROM glossary WHERE term = ?1", [term])?;
            }
            Op::SetMeta { k, v } => content::set_meta(&tx, k, v)?,
        }
    }
    content::finalize(&tx)?;
    tx.commit()?;
    conn.execute_batch("VACUUM")?;
    content::to_bytes(&conn)
}
