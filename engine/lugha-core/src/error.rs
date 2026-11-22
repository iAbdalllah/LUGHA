use thiserror::Error;

#[derive(Debug, Error)]
pub enum Error {
    #[error("io: {0}")]
    Io(#[from] std::io::Error),
    #[error("package container is invalid: {0}")]
    Container(String),
    #[error("manifest is invalid: {0}")]
    Manifest(String),
    #[error("signature check failed: {0}")]
    Signature(String),
    #[error("file hash mismatch for {0}")]
    HashMismatch(String),
    #[error("license rejected: {0}")]
    License(String),
    #[error("decryption failed")]
    Decrypt,
    #[error("unsupported format {0}")]
    UnsupportedFormat(String),
    #[error("database: {0}")]
    Db(#[from] rusqlite::Error),
    #[error("json: {0}")]
    Json(#[from] serde_json::Error),
    #[error("delta does not apply: {0}")]
    Delta(String),
    #[error("build failed: {0}")]
    Build(String),
}

pub type Result<T> = std::result::Result<T, Error>;
