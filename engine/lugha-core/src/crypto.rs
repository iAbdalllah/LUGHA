//! Content encryption (AES-256-GCM) and license key wrapping (X25519 + HKDF-SHA256 + AES-256-GCM).
//! The backend implements the same wrap in PHP (sodium + openssl); keep the HKDF info string in sync.

use aes_gcm::aead::{Aead, KeyInit};
use aes_gcm::{Aes256Gcm, Nonce};
use hkdf::Hkdf;
use rand::rngs::OsRng;
use rand::RngCore;
use sha2::Sha256;
use x25519_dalek::{PublicKey, StaticSecret};

use crate::{Error, Result};

pub const WRAP_INFO: &[u8] = b"lugha-license-v1";

pub fn random_bytes<const N: usize>() -> [u8; N] {
    let mut b = [0u8; N];
    OsRng.fill_bytes(&mut b);
    b
}

pub fn aes_encrypt(key: &[u8; 32], nonce: &[u8; 12], plain: &[u8]) -> Vec<u8> {
    Aes256Gcm::new(key.into()).encrypt(Nonce::from_slice(nonce), plain).expect("aes-gcm encrypt")
}

/// Same output as [aes_encrypt] (ciphertext then tag), encrypting the buffer in place.
pub fn aes_encrypt_vec(key: &[u8; 32], nonce: &[u8; 12], mut buf: Vec<u8>) -> Vec<u8> {
    use aes_gcm::aead::AeadInPlace;
    Aes256Gcm::new(key.into()).encrypt_in_place(Nonce::from_slice(nonce), b"", &mut buf).expect("aes-gcm encrypt");
    buf
}

pub fn aes_decrypt(key: &[u8; 32], nonce: &[u8; 12], cipher: &[u8]) -> Result<Vec<u8>> {
    Aes256Gcm::new(key.into()).decrypt(Nonce::from_slice(nonce), cipher).map_err(|_| Error::Decrypt)
}

/// Device key pair. The secret stays on the device (Keychain/DPAPI); the public key is registered with the API.
pub struct DeviceKeys {
    pub secret: [u8; 32],
    pub public: [u8; 32],
}

impl DeviceKeys {
    pub fn generate() -> Self {
        let secret = StaticSecret::random_from_rng(OsRng);
        let public = PublicKey::from(&secret);
        Self { secret: secret.to_bytes(), public: public.to_bytes() }
    }

    pub fn from_secret(secret: [u8; 32]) -> Self {
        let public = PublicKey::from(&StaticSecret::from(secret)).to_bytes();
        Self { secret, public }
    }

    /// sha256 of the public key, hex — the `device_fp` licenses are bound to.
    pub fn fingerprint(&self) -> String {
        fingerprint(&self.public)
    }
}

pub fn sha256_bytes(data: &[u8]) -> [u8; 32] {
    use sha2::Digest;
    Sha256::digest(data).into()
}

pub fn fingerprint(public: &[u8; 32]) -> String {
    use sha2::Digest;
    hex::encode(Sha256::digest(public))
}

fn kek(shared: &[u8; 32], eph_public: &[u8; 32], device_public: &[u8; 32]) -> [u8; 32] {
    let mut salt = Vec::with_capacity(64);
    salt.extend_from_slice(eph_public);
    salt.extend_from_slice(device_public);
    let mut out = [0u8; 32];
    Hkdf::<Sha256>::new(Some(&salt), shared).expand(WRAP_INFO, &mut out).expect("hkdf");
    out
}

/// Wrap a content key to a device: `eph_public(32) || nonce(12) || aes-gcm(ciphertext+tag)`.
pub fn wrap_key(content_key: &[u8; 32], device_public: &[u8; 32]) -> Vec<u8> {
    let eph = StaticSecret::random_from_rng(OsRng);
    let eph_public = PublicKey::from(&eph).to_bytes();
    let shared = eph.diffie_hellman(&PublicKey::from(*device_public)).to_bytes();
    let nonce: [u8; 12] = random_bytes();
    let mut out = eph_public.to_vec();
    out.extend_from_slice(&nonce);
    out.extend(aes_encrypt(&kek(&shared, &eph_public, device_public), &nonce, content_key));
    out
}

pub fn unwrap_key(wrapped: &[u8], device: &DeviceKeys) -> Result<[u8; 32]> {
    if wrapped.len() != 32 + 12 + 32 + 16 {
        return Err(Error::License("wrapped key has the wrong length".into()));
    }
    let eph_public: [u8; 32] = wrapped[..32].try_into().unwrap();
    let nonce: [u8; 12] = wrapped[32..44].try_into().unwrap();
    let shared = StaticSecret::from(device.secret).diffie_hellman(&PublicKey::from(eph_public)).to_bytes();
    let plain = aes_decrypt(&kek(&shared, &eph_public, &device.public), &nonce, &wrapped[44..])?;
    plain.try_into().map_err(|_| Error::License("unwrapped key has the wrong length".into()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn wrap_roundtrip_and_wrong_device_fails() {
        let device = DeviceKeys::generate();
        let other = DeviceKeys::generate();
        let key: [u8; 32] = random_bytes();
        let wrapped = wrap_key(&key, &device.public);
        assert_eq!(unwrap_key(&wrapped, &device).unwrap(), key);
        assert!(unwrap_key(&wrapped, &other).is_err());
    }
}
