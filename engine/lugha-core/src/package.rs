//! Reading, verifying and installing packages (spec §1, §3).
//!
//! Installed layout (one directory per package): `manifest.json`, `manifest.sig` (exactly as signed by
//! LUGHA), `content.nonce` + `content.enc` (the zstd'd content DB, AES-256-GCM with the version's content
//! key) and `license.json`. Opening re-checks the signature, the license and the logical content hash.

use std::collections::BTreeMap;
use std::io::{Cursor, Read};
use std::path::Path;

use base64::engine::general_purpose::STANDARD as B64;
use base64::Engine;
use rusqlite::Connection;

use crate::crypto::{self, DeviceKeys};
use crate::manifest::{Kind, Manifest};
use crate::trust::TrustStore;
use crate::{content, delta, license::License, normalize, Error, Result, FORMAT};

pub struct Container {
    pub manifest_bytes: Vec<u8>,
    pub manifest: Manifest,
    pub signature: Vec<u8>,
    pub files: BTreeMap<String, Vec<u8>>,
}

impl Container {
    pub fn read(bytes: &[u8]) -> Result<Self> {
        let mut zip = zip::ZipArchive::new(Cursor::new(bytes)).map_err(|e| Error::Container(e.to_string()))?;
        let mut files = BTreeMap::new();
        for i in 0..zip.len() {
            let mut f = zip.by_index(i).map_err(|e| Error::Container(e.to_string()))?;
            let name = f.name().to_string();
            if name.contains("..") || name.starts_with('/') {
                return Err(Error::Container(format!("illegal path {name}")));
            }
            let mut buf = Vec::with_capacity(f.size() as usize);
            f.read_to_end(&mut buf)?;
            files.insert(name, buf);
        }
        let manifest_bytes = files.remove("manifest.json").ok_or_else(|| Error::Container("missing manifest.json".into()))?;
        let sig_b64 = files.remove("manifest.sig").ok_or_else(|| Error::Container("missing manifest.sig".into()))?;
        let signature = B64.decode(String::from_utf8_lossy(&sig_b64).trim()).map_err(|_| Error::Container("manifest.sig is not base64".into()))?;
        let manifest: Manifest = serde_json::from_slice(&manifest_bytes).map_err(|e| Error::Manifest(e.to_string()))?;
        Ok(Self { manifest_bytes, manifest, signature, files })
    }

    /// Signature, format and file hashes. Unlisted files are rejected.
    pub fn verify(&self, trust: &TrustStore) -> Result<()> {
        trust.verify(&self.manifest.key_id, &self.manifest_bytes, &self.signature)?;
        if self.manifest.format != FORMAT {
            return Err(Error::UnsupportedFormat(self.manifest.format.clone()));
        }
        for name in self.files.keys() {
            if !self.manifest.files.contains_key(name) {
                return Err(Error::Container(format!("unlisted file {name}")));
            }
        }
        for (name, entry) in &self.manifest.files {
            let data = self.files.get(name).ok_or_else(|| Error::Container(format!("missing {name}")))?;
            if data.len() as u64 != entry.size || hex::encode(sha256_bytes(data)) != entry.sha256 {
                return Err(Error::HashMismatch(name.clone()));
            }
        }
        Ok(())
    }

    pub fn nonce(&self) -> Result<[u8; 12]> {
        let n = B64.decode(&self.manifest.encryption.nonce).map_err(|_| Error::Manifest("bad nonce".into()))?;
        n.try_into().map_err(|_| Error::Manifest("nonce must be 12 bytes".into()))
    }

    pub fn payload(&self) -> Result<&[u8]> {
        self.files.get(self.manifest.payload_name()).map(|v| v.as_slice()).ok_or_else(|| Error::Container("missing payload".into()))
    }

    pub fn decrypt_payload(&self, key: &[u8; 32]) -> Result<Vec<u8>> {
        let compressed = crypto::aes_decrypt(key, &self.nonce()?, self.payload()?)?;
        Ok(zstd::decode_all(Cursor::new(compressed))?)
    }
}

fn sha256_bytes(data: &[u8]) -> [u8; 32] {
    use sha2::{Digest, Sha256};
    Sha256::digest(data).into()
}

pub struct Package {
    pub manifest: Manifest,
    pub profile: Option<serde_json::Value>,
    pub(crate) conn: Connection,
}

impl Package {
    pub fn connection(&self) -> &Connection {
        &self.conn
    }

    fn from_plain_db(manifest: Manifest, db: &[u8], profile: Option<serde_json::Value>) -> Result<Self> {
        let conn = content::load(db, true)?;
        if content::logical_hash(&conn)? != manifest.content_sha256 {
            return Err(Error::HashMismatch("content".into()));
        }
        Ok(Self { manifest, profile, conn })
    }

    /// Open a full `.lpk` straight from bytes (verifies signature, files, license and content).
    pub fn from_lpk(bytes: &[u8], license: &License, trust: &TrustStore, device: &DeviceKeys, now: i64) -> Result<Self> {
        let c = Container::read(bytes)?;
        c.verify(trust)?;
        if c.manifest.kind != Kind::Full {
            return Err(Error::Container("a delta needs an installed base; use install()".into()));
        }
        let (_, key) = license.open(trust, device, &c.manifest.package_id, &c.manifest.version, now)?;
        let profile = c.files.get("profile.json").and_then(|p| serde_json::from_slice(p).ok());
        Self::from_plain_db(c.manifest.clone(), &c.decrypt_payload(&key)?, profile)
    }

    /// Open a full `.lpk` with its content key directly (build tooling only — clients always use a license).
    #[cfg(feature = "issuer")]
    pub fn from_lpk_with_key(bytes: &[u8], content_key: &[u8; 32], trust: &TrustStore) -> Result<Self> {
        let c = Container::read(bytes)?;
        c.verify(trust)?;
        if c.manifest.kind != Kind::Full {
            return Err(Error::Container("not a full package".into()));
        }
        let profile = c.files.get("profile.json").and_then(|p| serde_json::from_slice(p).ok());
        Self::from_plain_db(c.manifest.clone(), &c.decrypt_payload(content_key)?, profile)
    }

    /// Open an installed package directory.
    pub fn open_dir(dir: &Path, trust: &TrustStore, device: &DeviceKeys, now: i64) -> Result<Self> {
        let manifest_bytes = std::fs::read(dir.join("manifest.json"))?;
        let signature = B64.decode(std::fs::read_to_string(dir.join("manifest.sig"))?.trim()).map_err(|_| Error::Container("bad manifest.sig".into()))?;
        let manifest: Manifest = serde_json::from_slice(&manifest_bytes).map_err(|e| Error::Manifest(e.to_string()))?;
        trust.verify(&manifest.key_id, &manifest_bytes, &signature)?;
        let license = License::parse(&std::fs::read_to_string(dir.join("license.json"))?)?;
        let (_, key) = license.open(trust, device, &manifest.package_id, &manifest.version, now)?;
        let nonce: [u8; 12] = std::fs::read(dir.join("content.nonce"))?.try_into().map_err(|_| Error::Container("bad content.nonce".into()))?;
        let db = zstd::decode_all(Cursor::new(crypto::aes_decrypt(&key, &nonce, &std::fs::read(dir.join("content.enc"))?)?))?;
        let profile = std::fs::read(dir.join("profile.json")).ok().and_then(|p| serde_json::from_slice(&p).ok());
        Self::from_plain_db(manifest, &db, profile)
    }

    /// Install a full package or apply a delta to the package already installed in `dir`.
    /// Everything is verified before anything is written; the directory is replaced atomically.
    pub fn install(dir: &Path, lpk: &[u8], license_json: &str, trust: &TrustStore, device: &DeviceKeys, now: i64) -> Result<Manifest> {
        let c = Container::read(lpk)?;
        c.verify(trust)?;
        let license = License::parse(license_json)?;
        let (_, key) = license.open(trust, device, &c.manifest.package_id, &c.manifest.version, now)?;

        let db = match c.manifest.kind {
            Kind::Full => c.decrypt_payload(&key)?,
            Kind::Delta => {
                let base = Self::open_dir(dir, trust, device, now)?;
                if Some(&base.manifest.version) != c.manifest.base_version.as_ref() || base.manifest.package_id != c.manifest.package_id {
                    return Err(Error::Delta(format!("delta needs base {:?}, installed {}", c.manifest.base_version, base.manifest.version)));
                }
                let ops = c.decrypt_payload(&key)?;
                delta::apply(&content::to_bytes(&base.conn)?, &ops)?
            }
        };
        // Verify the result before touching the disk.
        let check = content::load(&db, true)?;
        if content::logical_hash(&check)? != c.manifest.content_sha256 {
            return Err(Error::HashMismatch("content".into()));
        }

        let tmp = dir.with_extension("installing");
        let _ = std::fs::remove_dir_all(&tmp);
        std::fs::create_dir_all(&tmp)?;
        let nonce: [u8; 12] = crypto::random_bytes();
        let compressed = zstd::encode_all(Cursor::new(&db), 9)?;
        std::fs::write(tmp.join("manifest.json"), &c.manifest_bytes)?;
        std::fs::write(tmp.join("manifest.sig"), B64.encode(&c.signature))?;
        std::fs::write(tmp.join("content.nonce"), nonce)?;
        std::fs::write(tmp.join("content.enc"), crypto::aes_encrypt(&key, &nonce, &compressed))?;
        std::fs::write(tmp.join("license.json"), license_json)?;
        if let Some(profile) = c.files.get("profile.json") {
            std::fs::write(tmp.join("profile.json"), profile)?;
        } else if let Ok(old) = std::fs::read(dir.join("profile.json")) {
            std::fs::write(tmp.join("profile.json"), old)?;
        }
        let old = dir.with_extension("old");
        let _ = std::fs::remove_dir_all(&old);
        if dir.exists() {
            std::fs::rename(dir, &old)?;
        }
        std::fs::rename(&tmp, dir)?;
        let _ = std::fs::remove_dir_all(&old);
        Ok(c.manifest)
    }

    /// Replace only the license (offline-window refresh) without re-downloading the package.
    pub fn refresh_license(dir: &Path, license_json: &str, trust: &TrustStore, device: &DeviceKeys, now: i64) -> Result<()> {
        let manifest: Manifest = serde_json::from_slice(&std::fs::read(dir.join("manifest.json"))?)?;
        License::parse(license_json)?.open(trust, device, &manifest.package_id, &manifest.version, now)?;
        std::fs::write(dir.join("license.json"), license_json)?;
        Ok(())
    }

    pub fn entry_count(&self) -> Result<i64> {
        Ok(self.conn.query_row("SELECT count(*) FROM entries", [], |r| r.get(0))?)
    }

    #[doc(hidden)]
    pub fn debug_lookup_exact(&self, text: &str) -> Result<Option<String>> {
        let h = normalize::key_hash(text).to_vec();
        Ok(self.conn.query_row("SELECT target FROM entries WHERE key_hash = ?1", [h], |r| r.get(0)).ok())
    }
}
