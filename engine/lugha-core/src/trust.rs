//! Pinned Ed25519 public keys (key_id → key). Apps ship the production keys; rotation lists are signed by a pinned key.

use std::collections::HashMap;

use base64::engine::general_purpose::STANDARD as B64;
use base64::Engine;
use ed25519_dalek::{Signature, Verifier, VerifyingKey};

use crate::{Error, Result};

#[derive(Default, Clone)]
pub struct TrustStore {
    keys: HashMap<String, VerifyingKey>,
}

impl TrustStore {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn add(&mut self, key_id: &str, public_key: [u8; 32]) -> Result<()> {
        let key = VerifyingKey::from_bytes(&public_key).map_err(|e| Error::Signature(e.to_string()))?;
        self.keys.insert(key_id.to_string(), key);
        Ok(())
    }

    pub fn add_base64(&mut self, key_id: &str, public_key_b64: &str) -> Result<()> {
        let bytes = B64.decode(public_key_b64).map_err(|e| Error::Signature(e.to_string()))?;
        self.add(key_id, bytes.try_into().map_err(|_| Error::Signature("public key must be 32 bytes".into()))?)
    }

    pub fn verify(&self, key_id: &str, message: &[u8], signature: &[u8]) -> Result<()> {
        let key = self.keys.get(key_id).ok_or_else(|| Error::Signature(format!("unknown key id {key_id}")))?;
        let sig = Signature::from_slice(signature).map_err(|e| Error::Signature(e.to_string()))?;
        key.verify(message, &sig).map_err(|_| Error::Signature("signature does not verify".into()))
    }
}
