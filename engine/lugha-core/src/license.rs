//! Device licenses (docs/03-api.md). The payload is carried as base64 of its exact JSON bytes and the
//! signature covers those bytes, so no JSON canonicalization is needed across PHP and Rust.

use base64::engine::general_purpose::STANDARD as B64;
use base64::Engine;
use serde::{Deserialize, Serialize};

use crate::crypto::{self, DeviceKeys};
use crate::trust::TrustStore;
use crate::{Error, Result};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct License {
    pub v: u32,
    pub payload: String,
    pub key_id: String,
    pub sig: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct LicensePayload {
    pub v: u32,
    pub license_id: String,
    pub user: String,
    pub device_fp: String,
    pub package_id: String,
    pub version: String,
    /// base64(eph_public || nonce || aes-gcm(content key))
    pub content_key_wrapped: String,
    pub issued_at: i64,
    /// Offline window end (unix seconds). Refreshed whenever the app is online.
    pub expires_at: i64,
}

impl License {
    pub fn parse(json: &str) -> Result<Self> {
        Ok(serde_json::from_str(json)?)
    }

    /// Verify signature, binding and expiry; return the payload and the unwrapped content key.
    pub fn open(&self, trust: &TrustStore, device: &DeviceKeys, package_id: &str, version: &str, now: i64) -> Result<(LicensePayload, [u8; 32])> {
        if self.v != 1 {
            return Err(Error::License(format!("unsupported license version {}", self.v)));
        }
        let bytes = B64.decode(&self.payload).map_err(|_| Error::License("payload is not base64".into()))?;
        let sig = B64.decode(&self.sig).map_err(|_| Error::License("signature is not base64".into()))?;
        trust.verify(&self.key_id, &bytes, &sig).map_err(|e| Error::License(e.to_string()))?;

        let p: LicensePayload = serde_json::from_slice(&bytes)?;
        if p.device_fp != device.fingerprint() {
            return Err(Error::License("issued for a different device".into()));
        }
        if p.package_id != package_id || p.version != version {
            return Err(Error::License(format!("issued for {} {}, not {package_id} {version}", p.package_id, p.version)));
        }
        if now >= p.expires_at {
            return Err(Error::License("expired — connect to the internet to refresh".into()));
        }
        let wrapped = B64.decode(&p.content_key_wrapped).map_err(|_| Error::License("wrapped key is not base64".into()))?;
        let key = crypto::unwrap_key(&wrapped, device)?;
        Ok((p, key))
    }

    #[cfg(feature = "issuer")]
    pub fn issue(signing: &ed25519_dalek::SigningKey, key_id: &str, payload: &LicensePayload) -> Self {
        use ed25519_dalek::Signer;
        let bytes = serde_json::to_vec(payload).expect("serialize license");
        Self { v: 1, payload: B64.encode(&bytes), key_id: key_id.to_string(), sig: B64.encode(signing.sign(&bytes).to_bytes()) }
    }
}
