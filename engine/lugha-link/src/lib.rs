//! LUGHA Link v1 (docs/05-device-protocol.md) — the cryptographic core, transport-agnostic.
//!
//! Frames on the wire: `u32 big-endian length || u8 type || payload`.
//!
//! **Pairing** (first time, 6-digit code shown on the TV; SPAKE2 so the code can't be brute-forced offline):
//! ```text
//! sender   → PAIR1: spake2 A
//! receiver → PAIR2: spake2 B || seal(k_r2i, receiver static public)
//! sender   → PAIR3: seal(k_i2r, sender static public)
//! receiver → PAIR_OK (stored) | PAIR_FAIL (wrong code / locked)
//! ```
//! A wrong code makes the AEAD open fail on the other side. Both sides then store each other's static key.
//!
//! **Session** (every connection): Noise `IK` — the sender knows the TV's key from pairing; the TV learns the
//! sender's key from message 1 and accepts it only if it is in its paired list. Then encrypted JSON messages.

use base64::engine::general_purpose::STANDARD as B64;
use base64::Engine;
use chacha20poly1305::aead::{Aead, KeyInit, Payload};
use chacha20poly1305::{ChaCha20Poly1305, Nonce};
use hkdf::Hkdf;
use rand::rngs::OsRng;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use spake2::{Ed25519Group, Identity, Password, Spake2};
use thiserror::Error;
use x25519_dalek::{PublicKey, StaticSecret};

pub const PROTOCOL: &str = "lugha-link/1";
pub const NOISE_PATTERN: &str = "Noise_IK_25519_ChaChaPoly_BLAKE2s";
pub const SERVICE_TYPE: &str = "_lugha._tcp";
pub const DEFAULT_PORT: u16 = 47800;
pub const MAX_FRAME: usize = 64 * 1024;

/// Frame types (the byte after the length).
pub mod frame {
    pub const PAIR1: u8 = 0x01;
    pub const PAIR2: u8 = 0x02;
    pub const PAIR3: u8 = 0x03;
    /// Receiver stored the sender; the sender may now send HS1.
    pub const PAIR_OK: u8 = 0x04;
    pub const PAIR_FAIL: u8 = 0x0F;
    pub const HS1: u8 = 0x10;
    pub const HS2: u8 = 0x11;
    pub const DATA: u8 = 0x20;
}

#[derive(Debug, Error)]
pub enum LinkError {
    #[error("wrong pairing code or tampered pairing message")]
    PairingFailed,
    #[error("malformed message")]
    Malformed,
    #[error("sender is not paired with this TV")]
    NotPaired,
    #[error("unexpected message in this state")]
    State,
    #[error("noise: {0}")]
    Noise(String),
}

pub type Result<T> = std::result::Result<T, LinkError>;

/// A device's long-term link identity (X25519). Generated once; the secret stays on the device.
#[derive(Clone)]
pub struct StaticKeys {
    pub secret: [u8; 32],
    pub public: [u8; 32],
}

impl StaticKeys {
    pub fn generate() -> Self {
        let s = StaticSecret::random_from_rng(OsRng);
        Self { public: PublicKey::from(&s).to_bytes(), secret: s.to_bytes() }
    }

    pub fn from_secret(secret: [u8; 32]) -> Self {
        Self { public: PublicKey::from(&StaticSecret::from(secret)).to_bytes(), secret }
    }

    /// Short fingerprint shown in UIs / mDNS TXT (first 16 hex chars of sha256(public)).
    pub fn fingerprint(&self) -> String {
        fingerprint(&self.public)
    }
}

pub fn fingerprint(public: &[u8; 32]) -> String {
    hex::encode(Sha256::digest(public))[..16].to_string()
}

/// Random 6-digit pairing code.
pub fn new_pairing_code() -> String {
    use rand::Rng;
    format!("{:06}", OsRng.gen_range(0..1_000_000))
}

fn derive(k: &[u8], info: &[u8]) -> [u8; 32] {
    let mut out = [0u8; 32];
    Hkdf::<Sha256>::new(Some(PROTOCOL.as_bytes()), k).expand(info, &mut out).expect("hkdf");
    out
}

fn seal(key: &[u8; 32], aad: &[u8], plain: &[u8]) -> Vec<u8> {
    ChaCha20Poly1305::new(key.into()).encrypt(Nonce::from_slice(&[0u8; 12]), Payload { msg: plain, aad }).expect("seal")
}

fn open(key: &[u8; 32], aad: &[u8], cipher: &[u8]) -> Result<Vec<u8>> {
    ChaCha20Poly1305::new(key.into()).decrypt(Nonce::from_slice(&[0u8; 12]), Payload { msg: cipher, aad }).map_err(|_| LinkError::PairingFailed)
}

const ID_SENDER: &[u8] = b"lugha-link sender";
const ID_RECEIVER: &[u8] = b"lugha-link receiver";

/// Sender side of pairing (Mac / phone).
pub struct SenderPairing {
    spake: Option<Spake2<Ed25519Group>>,
    me: StaticKeys,
    msg1: Vec<u8>,
}

impl SenderPairing {
    /// Returns the state and PAIR1.
    pub fn start(code: &str, me: StaticKeys) -> (Self, Vec<u8>) {
        let (spake, msg1) = Spake2::<Ed25519Group>::start_a(&Password::new(code.as_bytes()), &Identity::new(ID_SENDER), &Identity::new(ID_RECEIVER));
        (Self { spake: Some(spake), me, msg1: msg1.clone() }, msg1)
    }

    /// Handle PAIR2 → (PAIR3, receiver static public).
    pub fn finish(&mut self, pair2: &[u8]) -> Result<(Vec<u8>, [u8; 32])> {
        let spake = self.spake.take().ok_or(LinkError::State)?;
        if pair2.len() < 33 {
            return Err(LinkError::Malformed);
        }
        let (b, sealed) = pair2.split_at(33);
        let k = spake.finish(b).map_err(|_| LinkError::PairingFailed)?;
        let mut aad = self.msg1.clone();
        aad.extend_from_slice(b);
        let peer: [u8; 32] = open(&derive(&k, b"r2i"), &aad, sealed)?.try_into().map_err(|_| LinkError::Malformed)?;
        aad.extend_from_slice(sealed);
        Ok((seal(&derive(&k, b"i2r"), &aad, &self.me.public), peer))
    }
}

/// Receiver side of pairing (TV).
pub struct ReceiverPairing {
    code: String,
    me: StaticKeys,
    k: Option<Vec<u8>>,
    transcript: Vec<u8>,
}

impl ReceiverPairing {
    pub fn new(code: &str, me: StaticKeys) -> Self {
        Self { code: code.to_string(), me, k: None, transcript: Vec::new() }
    }

    /// Handle PAIR1 → PAIR2.
    pub fn respond(&mut self, pair1: &[u8]) -> Result<Vec<u8>> {
        if self.k.is_some() {
            return Err(LinkError::State);
        }
        let (spake, b) = Spake2::<Ed25519Group>::start_b(&Password::new(self.code.as_bytes()), &Identity::new(ID_SENDER), &Identity::new(ID_RECEIVER));
        let k = spake.finish(pair1).map_err(|_| LinkError::Malformed)?;
        let mut aad = pair1.to_vec();
        aad.extend_from_slice(&b);
        let sealed = seal(&derive(&k, b"r2i"), &aad, &self.me.public);
        aad.extend_from_slice(&sealed);
        self.transcript = aad;
        self.k = Some(k);
        let mut out = b;
        out.extend(sealed);
        Ok(out)
    }

    /// Handle PAIR3 → sender static public (store it as a trusted sender).
    pub fn finish(&mut self, pair3: &[u8]) -> Result<[u8; 32]> {
        let k = self.k.take().ok_or(LinkError::State)?;
        open(&derive(&k, b"i2r"), &self.transcript, pair3)?.try_into().map_err(|_| LinkError::Malformed)
    }
}

/// An encrypted session (Noise IK). Initiator = sender, responder = receiver.
pub struct Session {
    hs: Option<snow::HandshakeState>,
    ts: Option<snow::TransportState>,
    peer: Option<[u8; 32]>,
}

fn builder<'a>(me: &'a StaticKeys) -> snow::Builder<'a> {
    snow::Builder::new(NOISE_PATTERN.parse().expect("pattern")).prologue(PROTOCOL.as_bytes()).local_private_key(&me.secret)
}

fn noise<E: std::fmt::Display>(e: E) -> LinkError {
    LinkError::Noise(e.to_string())
}

impl Session {
    /// Sender: start a session with a paired TV → (session, HS1).
    pub fn initiate(me: &StaticKeys, receiver_public: &[u8; 32]) -> Result<(Self, Vec<u8>)> {
        let mut hs = builder(me).remote_public_key(receiver_public).build_initiator().map_err(noise)?;
        let mut buf = vec![0u8; 1024];
        let n = hs.write_message(&[], &mut buf).map_err(noise)?;
        buf.truncate(n);
        Ok((Self { hs: Some(hs), ts: None, peer: Some(*receiver_public) }, buf))
    }

    /// Receiver: handle HS1; `is_trusted` decides on the sender's static key → (session, HS2, sender public).
    pub fn accept(me: &StaticKeys, hs1: &[u8], is_trusted: impl Fn(&[u8; 32]) -> bool) -> Result<(Self, Vec<u8>, [u8; 32])> {
        let mut hs = builder(me).build_responder().map_err(noise)?;
        let mut tmp = vec![0u8; 1024];
        hs.read_message(hs1, &mut tmp).map_err(noise)?;
        let peer: [u8; 32] = hs.get_remote_static().ok_or(LinkError::Malformed)?.try_into().map_err(|_| LinkError::Malformed)?;
        if !is_trusted(&peer) {
            return Err(LinkError::NotPaired);
        }
        let mut buf = vec![0u8; 1024];
        let n = hs.write_message(&[], &mut buf).map_err(noise)?;
        buf.truncate(n);
        let ts = hs.into_transport_mode().map_err(noise)?;
        Ok((Self { hs: None, ts: Some(ts), peer: Some(peer) }, buf, peer))
    }

    /// Sender: handle HS2 → session ready.
    pub fn complete(&mut self, hs2: &[u8]) -> Result<()> {
        let mut hs = self.hs.take().ok_or(LinkError::State)?;
        let mut tmp = vec![0u8; 1024];
        hs.read_message(hs2, &mut tmp).map_err(noise)?;
        self.ts = Some(hs.into_transport_mode().map_err(noise)?);
        Ok(())
    }

    pub fn is_ready(&self) -> bool {
        self.ts.is_some()
    }

    pub fn peer(&self) -> Option<[u8; 32]> {
        self.peer
    }

    pub fn encrypt(&mut self, plain: &[u8]) -> Result<Vec<u8>> {
        let ts = self.ts.as_mut().ok_or(LinkError::State)?;
        let mut buf = vec![0u8; plain.len() + 16];
        let n = ts.write_message(plain, &mut buf).map_err(noise)?;
        buf.truncate(n);
        Ok(buf)
    }

    pub fn decrypt(&mut self, cipher: &[u8]) -> Result<Vec<u8>> {
        let ts = self.ts.as_mut().ok_or(LinkError::State)?;
        let mut buf = vec![0u8; cipher.len()];
        let n = ts.read_message(cipher, &mut buf).map_err(noise)?;
        buf.truncate(n);
        Ok(buf)
    }
}

/// Application messages carried inside DATA frames (JSON, `t` = type).
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(tag = "t")]
pub enum Message {
    #[serde(rename = "hello")]
    Hello { app: String, app_version: String, sender_name: String },
    #[serde(rename = "welcome")]
    Welcome { tv_name: String, screen_w: u32, screen_h: u32 },
    #[serde(rename = "sub.show")]
    Show { id: u64, sid: u64, text: String, #[serde(default)] speaker: Option<String>, #[serde(default)] dir: Option<String>, #[serde(default)] ack: bool, #[serde(default)] ts: u64 },
    /// `all`: translation stopped, so the TV also drops lines kept up for reading and its history panel.
    #[serde(rename = "sub.clear")]
    Clear { id: u64, #[serde(default, skip_serializing_if = "std::ops::Not::not")] all: bool },
    #[serde(rename = "style.set")]
    Style {
        font_scale: f32,
        position_y: f32,
        bg_opacity: f32,
        /// "over" (the Arabic hides the English), "above" (just above it) or "history" (only the history panel).
        /// Older TVs ignore the optional keys.
        #[serde(default, skip_serializing_if = "Option::is_none")]
        display: Option<String>,
        /// Side panel with the recent dialogue: "off", "left" or "right".
        #[serde(default, skip_serializing_if = "Option::is_none")]
        history: Option<String>,
        /// Colour of the text boxes and the history panel, #RRGGBB.
        #[serde(default, skip_serializing_if = "Option::is_none")]
        plate_color: Option<String>,
        #[serde(default, skip_serializing_if = "Option::is_none")]
        fade_ms: Option<u32>,
        /// Keep a replaced dialogue line up (dimmed) until it could be read.
        #[serde(default, skip_serializing_if = "Option::is_none")]
        read_hold: Option<bool>,
    },
    #[serde(rename = "ack")]
    Ack { id: u64, painted_ms: u64 },
    #[serde(rename = "ping")]
    Ping { id: u64 },
    #[serde(rename = "pong")]
    Pong { id: u64 },
    #[serde(rename = "bye")]
    Bye { reason: String },
    #[serde(rename = "error")]
    Error { code: String, message: String },
}

impl Message {
    pub fn to_json(&self) -> Vec<u8> {
        serde_json::to_vec(self).expect("serialize")
    }

    pub fn from_json(b: &[u8]) -> Result<Self> {
        serde_json::from_slice(b).map_err(|_| LinkError::Malformed)
    }
}

pub fn b64(b: &[u8]) -> String {
    B64.encode(b)
}

pub fn unb64(s: &str) -> Result<Vec<u8>> {
    B64.decode(s).map_err(|_| LinkError::Malformed)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn pair(code_sender: &str, code_tv: &str) -> Result<([u8; 32], [u8; 32], StaticKeys, StaticKeys)> {
        let (mac, tv) = (StaticKeys::generate(), StaticKeys::generate());
        let (mut s, p1) = SenderPairing::start(code_sender, mac.clone());
        let mut r = ReceiverPairing::new(code_tv, tv.clone());
        let p2 = r.respond(&p1)?;
        let (p3, tv_seen) = s.finish(&p2)?;
        let mac_seen = r.finish(&p3)?;
        Ok((tv_seen, mac_seen, mac, tv))
    }

    #[test]
    fn pairing_exchanges_static_keys() {
        let (tv_seen, mac_seen, mac, tv) = pair("482916", "482916").unwrap();
        assert_eq!(tv_seen, tv.public);
        assert_eq!(mac_seen, mac.public);
    }

    #[test]
    fn wrong_code_fails() {
        assert!(matches!(pair("482916", "482917"), Err(LinkError::PairingFailed)));
    }

    #[test]
    fn session_roundtrip_and_untrusted_sender_rejected() {
        let (tv_pub, mac_pub, mac, tv) = pair("111111", "111111").unwrap();
        let (mut s, hs1) = Session::initiate(&mac, &tv_pub).unwrap();
        let (mut r, hs2, who) = Session::accept(&tv, &hs1, |k| *k == mac_pub).unwrap();
        assert_eq!(who, mac.public);
        s.complete(&hs2).unwrap();

        let msg = Message::Show { id: 1, sid: 7, text: "نطلع مع الفجر.".into(), speaker: Some("ريم".into()), dir: Some("rtl".into()), ack: true, ts: 0 };
        let c = s.encrypt(&msg.to_json()).unwrap();
        assert_eq!(Message::from_json(&r.decrypt(&c).unwrap()).unwrap(), msg);
        let back = r.encrypt(&Message::Ack { id: 1, painted_ms: 3 }.to_json()).unwrap();
        assert!(matches!(Message::from_json(&s.decrypt(&back).unwrap()).unwrap(), Message::Ack { id: 1, .. }));

        // Replayed ciphertext is rejected (nonces advance).
        assert!(r.decrypt(&c).is_err());

        // A stranger who never paired can't open a session.
        let stranger = StaticKeys::generate();
        let (_, hs1) = Session::initiate(&stranger, &tv_pub).unwrap();
        assert!(matches!(Session::accept(&tv, &hs1, |k| *k == mac_pub), Err(LinkError::NotPaired)));
    }

    #[test]
    fn tampered_ciphertext_is_rejected() {
        let (tv_pub, mac_pub, mac, tv) = pair("222222", "222222").unwrap();
        let (mut s, hs1) = Session::initiate(&mac, &tv_pub).unwrap();
        let (mut r, hs2, _) = Session::accept(&tv, &hs1, |k| *k == mac_pub).unwrap();
        s.complete(&hs2).unwrap();
        let mut c = s.encrypt(b"{\"t\":\"ping\",\"id\":1}").unwrap();
        c[0] ^= 1;
        assert!(r.decrypt(&c).is_err());
    }
}
