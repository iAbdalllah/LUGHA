use std::collections::BTreeMap;

use serde::{Deserialize, Serialize};

/// `manifest.json` (spec §2). Signed as raw bytes by `manifest.sig`; no floats anywhere, so every
/// language reads and writes it identically (coverage is in basis points: 9700 = 97.00%).
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Manifest {
    pub format: String,
    pub game_id: String,
    pub package_id: String,
    pub version: String,
    pub kind: Kind,
    pub base_version: Option<String>,
    pub language: String,
    pub locale: String,
    pub direction: String,
    pub localization_style: String,
    pub package_type: String,
    pub categories: Vec<String>,
    pub game_versions: GameVersions,
    pub platforms: Vec<String>,
    pub min_app_version: String,
    pub entry_count: u32,
    pub coverage_bp: BTreeMap<String, u32>,
    pub minimized: bool,
    pub files: BTreeMap<String, FileEntry>,
    pub content_sha256: String,
    pub encryption: Encryption,
    pub release_notes: BTreeMap<String, String>,
    pub built_at: i64,
    pub key_id: String,
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum Kind {
    Full,
    Delta,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq)]
pub struct GameVersions {
    pub min: Option<String>,
    pub max: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct FileEntry {
    pub sha256: String,
    pub size: u64,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Encryption {
    pub alg: String,
    /// base64, 12 bytes
    pub nonce: String,
}

impl Manifest {
    /// Name of the encrypted payload inside the container.
    pub fn payload_name(&self) -> &'static str {
        match self.kind {
            Kind::Full => "content.db.zst.enc",
            Kind::Delta => "delta.ops.zst.enc",
        }
    }
}

/// Compare dotted numeric versions ("1.10.0" > "1.9.2"). Non-numeric parts compare as 0.
pub fn version_cmp(a: &str, b: &str) -> std::cmp::Ordering {
    let parse = |s: &str| s.split('.').map(|p| p.parse::<u64>().unwrap_or(0)).collect::<Vec<_>>();
    let (mut x, mut y) = (parse(a), parse(b));
    let n = x.len().max(y.len());
    x.resize(n, 0);
    y.resize(n, 0);
    x.cmp(&y)
}
