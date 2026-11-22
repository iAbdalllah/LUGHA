//! C ABI over lugha-core (see include/lugha.h). JSON in, JSON out, so the Swift side stays tiny.
#![allow(clippy::missing_safety_doc)]

use std::ffi::{c_char, CStr, CString};
use std::path::Path;
use std::time::{SystemTime, UNIX_EPOCH};

use lugha_core::crypto::DeviceKeys;
use lugha_core::{Matcher, Package, Query, TrustStore};
use serde_json::{json, Value};

pub struct LughaEngine {
    trust: TrustStore,
    device: DeviceKeys,
    matcher: Matcher,
}

fn now() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs() as i64).unwrap_or(0)
}

fn out(v: Value) -> *mut c_char {
    CString::new(v.to_string()).map(CString::into_raw).unwrap_or(std::ptr::null_mut())
}

fn err(e: impl std::fmt::Display) -> *mut c_char {
    out(json!({"ok": false, "error": e.to_string()}))
}

unsafe fn s<'a>(p: *const c_char) -> Option<&'a str> {
    if p.is_null() {
        None
    } else {
        CStr::from_ptr(p).to_str().ok()
    }
}

fn trust_from_json(raw: &str) -> Option<TrustStore> {
    let v: Value = serde_json::from_str(raw).ok()?;
    let mut t = TrustStore::new();
    for k in v.get("keys")?.as_array()? {
        t.add_base64(k.get("key_id")?.as_str()?, k.get("public")?.as_str()?).ok()?;
    }
    Some(t)
}

#[no_mangle]
pub extern "C" fn lugha_device_generate() -> *mut c_char {
    use base64::Engine;
    let d = DeviceKeys::generate();
    out(json!({"ok": true, "secret_hex": hex::encode(d.secret), "public": base64::engine::general_purpose::STANDARD.encode(d.public), "fingerprint": d.fingerprint()}))
}

#[no_mangle]
pub unsafe extern "C" fn lugha_engine_new(trust_json: *const c_char, device_secret_hex: *const c_char) -> *mut LughaEngine {
    let (Some(trust), Some(secret)) = (s(trust_json).and_then(trust_from_json), s(device_secret_hex)) else { return std::ptr::null_mut() };
    let Some(secret): Option<[u8; 32]> = hex::decode(secret).ok().and_then(|b| b.try_into().ok()) else { return std::ptr::null_mut() };
    Box::into_raw(Box::new(LughaEngine { trust, device: DeviceKeys::from_secret(secret), matcher: Matcher::new(vec![]) }))
}

#[no_mangle]
pub unsafe extern "C" fn lugha_engine_free(engine: *mut LughaEngine) {
    if !engine.is_null() {
        drop(Box::from_raw(engine));
    }
}

#[no_mangle]
pub unsafe extern "C" fn lugha_install(engine: *mut LughaEngine, package_dir: *const c_char, lpk_path: *const c_char, license_json: *const c_char) -> *mut c_char {
    let Some(e) = engine.as_mut() else { return err("no engine") };
    let (Some(dir), Some(lpk), Some(lic)) = (s(package_dir), s(lpk_path), s(license_json)) else { return err("bad arguments") };
    let bytes = match std::fs::read(lpk) {
        Ok(b) => b,
        Err(x) => return err(x),
    };
    match Package::install(Path::new(dir), &bytes, lic, &e.trust, &e.device, now()) {
        Ok(m) => out(json!({"ok": true, "manifest": m})),
        Err(x) => err(x),
    }
}

#[no_mangle]
pub unsafe extern "C" fn lugha_refresh_license(engine: *mut LughaEngine, package_dir: *const c_char, license_json: *const c_char) -> *mut c_char {
    let Some(e) = engine.as_mut() else { return err("no engine") };
    let (Some(dir), Some(lic)) = (s(package_dir), s(license_json)) else { return err("bad arguments") };
    match Package::refresh_license(Path::new(dir), lic, &e.trust, &e.device, now()) {
        Ok(()) => out(json!({"ok": true})),
        Err(x) => err(x),
    }
}

#[no_mangle]
pub unsafe extern "C" fn lugha_load(engine: *mut LughaEngine, packages_root: *const c_char) -> *mut c_char {
    let Some(e) = engine.as_mut() else { return err("no engine") };
    let Some(root) = s(packages_root) else { return err("bad arguments") };
    let mut packages = Vec::new();
    let mut loaded = Vec::new();
    let mut errors = Vec::new();
    if let Ok(dirs) = std::fs::read_dir(root) {
        for d in dirs.flatten() {
            let path = d.path();
            if !path.is_dir() || path.extension().is_some() {
                continue; // skip *.installing / *.old leftovers
            }
            match Package::open_dir(&path, &e.trust, &e.device, now()) {
                Ok(p) => {
                    loaded.push(json!({"package_id": p.manifest.package_id, "version": p.manifest.version, "game_id": p.manifest.game_id,
                        "entries": p.entry_count().unwrap_or(0), "profile": p.profile}));
                    packages.push(p);
                }
                Err(x) => errors.push(json!({"dir": path.file_name().map(|n| n.to_string_lossy().to_string()), "error": x.to_string()})),
            }
        }
    }
    e.matcher = Matcher::new(packages);
    out(json!({"ok": true, "packages": loaded, "errors": errors}))
}

#[no_mangle]
pub unsafe extern "C" fn lugha_lookup(engine: *mut LughaEngine, text: *const c_char) -> *mut c_char {
    let Some(e) = engine.as_mut() else { return err("no engine") };
    let Some(t) = s(text) else { return err("bad arguments") };
    out(json!({"ok": true, "hit": e.matcher.lookup(&Query::new(t))}))
}

#[no_mangle]
pub unsafe extern "C" fn lugha_reset_context(engine: *mut LughaEngine) {
    if let Some(e) = engine.as_mut() {
        e.matcher.reset_context();
    }
}

#[no_mangle]
pub unsafe extern "C" fn lugha_string_free(p: *mut c_char) {
    if !p.is_null() {
        drop(CString::from_raw(p));
    }
}

#[no_mangle]
pub extern "C" fn lugha_engine_version() -> *const c_char {
    concat!(env!("CARGO_PKG_VERSION"), "\0").as_ptr() as *const c_char
}

// ───────────────────────────── LUGHA Link (sender side, Mac) ─────────────────────────────
// Binary values travel as base64 inside the JSON results.

pub struct LughaLinkPairing(lugha_link::SenderPairing);
pub struct LughaLinkSession(lugha_link::Session);

fn link_keys(secret_hex: &str) -> Option<lugha_link::StaticKeys> {
    let s: [u8; 32] = hex::decode(secret_hex).ok()?.try_into().ok()?;
    Some(lugha_link::StaticKeys::from_secret(s))
}

#[no_mangle]
pub extern "C" fn lugha_link_keys_generate() -> *mut c_char {
    let k = lugha_link::StaticKeys::generate();
    out(json!({"ok": true, "secret_hex": hex::encode(k.secret), "public": lugha_link::b64(&k.public), "fingerprint": k.fingerprint()}))
}

/// Start pairing with the code shown on the TV. Writes the handle to `out_pairing`; returns {"ok","msg"} (PAIR1).
#[no_mangle]
pub unsafe extern "C" fn lugha_link_pair_start(code: *const c_char, secret_hex: *const c_char, out_pairing: *mut *mut LughaLinkPairing) -> *mut c_char {
    let (Some(code), Some(keys)) = (s(code), s(secret_hex).and_then(link_keys)) else { return err("bad arguments") };
    let (p, msg) = lugha_link::SenderPairing::start(code, keys);
    *out_pairing = Box::into_raw(Box::new(LughaLinkPairing(p)));
    out(json!({"ok": true, "msg": lugha_link::b64(&msg)}))
}

/// Handle PAIR2 → {"ok","msg" (PAIR3),"peer" (TV public key)}. Frees the pairing.
#[no_mangle]
pub unsafe extern "C" fn lugha_link_pair_finish(pairing: *mut LughaLinkPairing, msg_b64: *const c_char) -> *mut c_char {
    if pairing.is_null() {
        return err("no pairing");
    }
    let mut p = Box::from_raw(pairing);
    let Some(msg) = s(msg_b64).and_then(|m| lugha_link::unb64(m).ok()) else { return err("bad message") };
    match p.0.finish(&msg) {
        Ok((m3, peer)) => out(json!({"ok": true, "msg": lugha_link::b64(&m3), "peer": lugha_link::b64(&peer)})),
        Err(e) => err(e),
    }
}

/// Open a session with a paired TV. Writes the handle; returns {"ok","msg"} (HS1).
#[no_mangle]
pub unsafe extern "C" fn lugha_link_session_start(secret_hex: *const c_char, peer_b64: *const c_char, out_session: *mut *mut LughaLinkSession) -> *mut c_char {
    let Some(keys) = s(secret_hex).and_then(link_keys) else { return err("bad key") };
    let Some(peer): Option<[u8; 32]> = s(peer_b64).and_then(|p| lugha_link::unb64(p).ok()).and_then(|v| v.try_into().ok()) else { return err("bad peer key") };
    match lugha_link::Session::initiate(&keys, &peer) {
        Ok((sess, msg)) => {
            *out_session = Box::into_raw(Box::new(LughaLinkSession(sess)));
            out(json!({"ok": true, "msg": lugha_link::b64(&msg)}))
        }
        Err(e) => err(e),
    }
}

#[no_mangle]
pub unsafe extern "C" fn lugha_link_session_complete(session: *mut LughaLinkSession, msg_b64: *const c_char) -> *mut c_char {
    let Some(sess) = session.as_mut() else { return err("no session") };
    let Some(msg) = s(msg_b64).and_then(|m| lugha_link::unb64(m).ok()) else { return err("bad message") };
    match sess.0.complete(&msg) {
        Ok(()) => out(json!({"ok": true})),
        Err(e) => err(e),
    }
}

/// Encrypt a UTF-8 JSON message → {"ok","msg"} (DATA payload).
#[no_mangle]
pub unsafe extern "C" fn lugha_link_encrypt(session: *mut LughaLinkSession, plaintext: *const c_char) -> *mut c_char {
    let Some(sess) = session.as_mut() else { return err("no session") };
    let Some(p) = s(plaintext) else { return err("bad plaintext") };
    match sess.0.encrypt(p.as_bytes()) {
        Ok(c) => out(json!({"ok": true, "msg": lugha_link::b64(&c)})),
        Err(e) => err(e),
    }
}

/// Decrypt a DATA payload → {"ok","text"}.
#[no_mangle]
pub unsafe extern "C" fn lugha_link_decrypt(session: *mut LughaLinkSession, msg_b64: *const c_char) -> *mut c_char {
    let Some(sess) = session.as_mut() else { return err("no session") };
    let Some(c) = s(msg_b64).and_then(|m| lugha_link::unb64(m).ok()) else { return err("bad message") };
    match sess.0.decrypt(&c) {
        Ok(p) => out(json!({"ok": true, "text": String::from_utf8_lossy(&p)})),
        Err(e) => err(e),
    }
}

#[no_mangle]
pub unsafe extern "C" fn lugha_link_session_free(session: *mut LughaLinkSession) {
    if !session.is_null() {
        drop(Box::from_raw(session));
    }
}

#[no_mangle]
pub unsafe extern "C" fn lugha_link_pairing_free(pairing: *mut LughaLinkPairing) {
    if !pairing.is_null() {
        drop(Box::from_raw(pairing));
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use base64::Engine;
    use lugha_core::builder::{build_full, BuildMeta, KeyFile};
    use lugha_core::content::EntryInput;
    use lugha_core::license::{License, LicensePayload};

    unsafe fn take(p: *mut c_char) -> Value {
        let v = serde_json::from_str(CStr::from_ptr(p).to_str().unwrap()).unwrap();
        lugha_string_free(p);
        v
    }

    #[test]
    fn install_load_lookup_through_the_c_abi() {
        unsafe {
            let dev = take(lugha_device_generate());
            let key = KeyFile::generate("pk-ffi");
            let device = DeviceKeys::from_secret(hex::decode(dev["secret_hex"].as_str().unwrap()).unwrap().try_into().unwrap());
            let ck = lugha_core::crypto::random_bytes::<32>();
            let meta: BuildMeta = serde_json::from_value(json!({"game_id": "g", "package_id": "g-full", "version": "1.0.0",
                "localization_style": "msa", "package_type": "p", "categories": ["menus"]})).unwrap();
            let entries = vec![EntryInput { id: 1, source: "Continue".into(), target: "متابعة".into(), category: "menus".into(), speaker: None, scene: None, seq: None, aliases: vec![] }];
            let (sealed, _) = build_full(&meta, &entries, &key, &ck, None, now()).unwrap();
            let lic = License::issue(&key.signing_key().unwrap(), "pk-ffi", &LicensePayload {
                v: 1, license_id: "l".into(), user: "u".into(), device_fp: device.fingerprint(), package_id: "g-full".into(), version: "1.0.0".into(),
                content_key_wrapped: base64::engine::general_purpose::STANDARD.encode(lugha_core::crypto::wrap_key(&ck, &device.public)),
                issued_at: now(), expires_at: now() + 3600,
            });

            let root = std::env::temp_dir().join(format!("lugha-ffi-{}", std::process::id()));
            let _ = std::fs::remove_dir_all(&root);
            std::fs::create_dir_all(&root).unwrap();
            let lpk = root.join("g.lpk");
            std::fs::write(&lpk, &sealed.lpk).unwrap();

            let trust = CString::new(json!({"keys": [{"key_id": "pk-ffi", "public": key.public}]}).to_string()).unwrap();
            let secret = CString::new(dev["secret_hex"].as_str().unwrap()).unwrap();
            let engine = lugha_engine_new(trust.as_ptr(), secret.as_ptr());
            assert!(!engine.is_null());

            let pkgs = root.join("packages");
            let dir = CString::new(pkgs.join("g-full").to_str().unwrap()).unwrap();
            let lpk_c = CString::new(lpk.to_str().unwrap()).unwrap();
            let lic_c = CString::new(serde_json::to_string(&lic).unwrap()).unwrap();
            assert_eq!(take(lugha_install(engine, dir.as_ptr(), lpk_c.as_ptr(), lic_c.as_ptr()))["ok"], true);

            let root_c = CString::new(pkgs.to_str().unwrap()).unwrap();
            let loaded = take(lugha_load(engine, root_c.as_ptr()));
            assert_eq!(loaded["packages"][0]["package_id"], "g-full");

            let q = CString::new("CONTINUE").unwrap();
            assert_eq!(take(lugha_lookup(engine, q.as_ptr()))["hit"]["target"], "متابعة");
            let bad = take(lugha_install(engine, dir.as_ptr(), q.as_ptr(), lic_c.as_ptr()));
            assert_eq!(bad["ok"], false);
            lugha_engine_free(engine);
        }
    }
}
