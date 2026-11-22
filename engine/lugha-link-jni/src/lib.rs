//! JNI surface for `app.lugha.tv.link.NativeLink` (receiver side). Handles are boxed Rust objects passed as
//! jlong; Kotlin must call the matching free. Results are JSON strings ({"ok":true,...} / {"ok":false,"error"}).

use jni::objects::{JClass, JString};
use jni::sys::{jlong, jstring};
use jni::JNIEnv;
use lugha_link::{b64, unb64, ReceiverPairing, Session, StaticKeys};
use serde_json::{json, Value};

fn jstr(env: &mut JNIEnv, v: Value) -> jstring {
    env.new_string(v.to_string()).map(|s| s.into_raw()).unwrap_or(std::ptr::null_mut())
}

fn get(env: &mut JNIEnv, s: &JString) -> String {
    env.get_string(s).map(Into::into).unwrap_or_default()
}

fn keys(secret_hex: &str) -> Option<StaticKeys> {
    hex::decode(secret_hex).ok()?.try_into().ok().map(StaticKeys::from_secret)
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_generateKeys(mut env: JNIEnv, _: JClass) -> jstring {
    let k = StaticKeys::generate();
    jstr(&mut env, json!({"ok": true, "secret_hex": hex::encode(k.secret), "public": b64(&k.public), "fingerprint": k.fingerprint()}))
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_newPairingCode(mut env: JNIEnv, _: JClass) -> jstring {
    jstr(&mut env, json!(lugha_link::new_pairing_code()))
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_pairingNew(mut env: JNIEnv, _: JClass, code: JString, secret_hex: JString) -> jlong {
    let (code, secret) = (get(&mut env, &code), get(&mut env, &secret_hex));
    match keys(&secret) {
        Some(k) => Box::into_raw(Box::new(ReceiverPairing::new(&code, k))) as jlong,
        None => 0,
    }
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_pairingRespond(mut env: JNIEnv, _: JClass, handle: jlong, msg_b64: JString) -> jstring {
    let msg = get(&mut env, &msg_b64);
    let Some(p) = (unsafe { (handle as *mut ReceiverPairing).as_mut() }) else { return jstr(&mut env, json!({"ok": false, "error": "no pairing"})) };
    let r = unb64(&msg).and_then(|m| p.respond(&m));
    jstr(&mut env, match r {
        Ok(m) => json!({"ok": true, "msg": b64(&m)}),
        Err(e) => json!({"ok": false, "error": e.to_string()}),
    })
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_pairingFinish(mut env: JNIEnv, _: JClass, handle: jlong, msg_b64: JString) -> jstring {
    let msg = get(&mut env, &msg_b64);
    let Some(p) = (unsafe { (handle as *mut ReceiverPairing).as_mut() }) else { return jstr(&mut env, json!({"ok": false, "error": "no pairing"})) };
    let r = unb64(&msg).and_then(|m| p.finish(&m));
    jstr(&mut env, match r {
        Ok(peer) => json!({"ok": true, "peer": b64(&peer), "fingerprint": lugha_link::fingerprint(&peer)}),
        Err(e) => json!({"ok": false, "error": e.to_string()}),
    })
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_pairingFree(_: JNIEnv, _: JClass, handle: jlong) {
    if handle != 0 {
        drop(unsafe { Box::from_raw(handle as *mut ReceiverPairing) });
    }
}

/// HS1 from a sender; `trusted_json` = JSON array of base64 public keys of paired senders.
/// Returns {"ok","handle","msg" (HS2),"peer"} — handle is a decimal string (jlong).
#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_sessionAccept(mut env: JNIEnv, _: JClass, secret_hex: JString, hs1_b64: JString, trusted_json: JString) -> jstring {
    let (secret, hs1, trusted) = (get(&mut env, &secret_hex), get(&mut env, &hs1_b64), get(&mut env, &trusted_json));
    let trusted: Vec<Vec<u8>> = serde_json::from_str::<Vec<String>>(&trusted).unwrap_or_default().iter().filter_map(|t| unb64(t).ok()).collect();
    let Some(me) = keys(&secret) else { return jstr(&mut env, json!({"ok": false, "error": "bad key"})) };
    let r = unb64(&hs1).and_then(|m| Session::accept(&me, &m, |k| trusted.iter().any(|t| t.as_slice() == k)));
    jstr(&mut env, match r {
        Ok((sess, hs2, peer)) => json!({"ok": true, "handle": (Box::into_raw(Box::new(sess)) as jlong).to_string(), "msg": b64(&hs2), "peer": b64(&peer)}),
        Err(e) => json!({"ok": false, "error": e.to_string()}),
    })
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_decrypt(mut env: JNIEnv, _: JClass, handle: jlong, msg_b64: JString) -> jstring {
    let msg = get(&mut env, &msg_b64);
    let Some(s) = (unsafe { (handle as *mut Session).as_mut() }) else { return jstr(&mut env, json!({"ok": false, "error": "no session"})) };
    let r = unb64(&msg).and_then(|m| s.decrypt(&m));
    jstr(&mut env, match r {
        Ok(p) => json!({"ok": true, "text": String::from_utf8_lossy(&p)}),
        Err(e) => json!({"ok": false, "error": e.to_string()}),
    })
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_encrypt(mut env: JNIEnv, _: JClass, handle: jlong, text: JString) -> jstring {
    let text = get(&mut env, &text);
    let Some(s) = (unsafe { (handle as *mut Session).as_mut() }) else { return jstr(&mut env, json!({"ok": false, "error": "no session"})) };
    jstr(&mut env, match s.encrypt(text.as_bytes()) {
        Ok(c) => json!({"ok": true, "msg": b64(&c)}),
        Err(e) => json!({"ok": false, "error": e.to_string()}),
    })
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_sessionFree(_: JNIEnv, _: JClass, handle: jlong) {
    if handle != 0 {
        drop(unsafe { Box::from_raw(handle as *mut Session) });
    }
}

// ── Sender side (Android phone app in Phase 4; also lets instrumented tests drive a real sender). ──

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_senderPairStart(mut env: JNIEnv, _: JClass, code: JString, secret_hex: JString) -> jstring {
    let (code, secret) = (get(&mut env, &code), get(&mut env, &secret_hex));
    let Some(k) = keys(&secret) else { return jstr(&mut env, json!({"ok": false, "error": "bad key"})) };
    let (p, msg) = lugha_link::SenderPairing::start(&code, k);
    jstr(&mut env, json!({"ok": true, "handle": (Box::into_raw(Box::new(p)) as jlong).to_string(), "msg": b64(&msg)}))
}

/// Frees the sender pairing.
#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_senderPairFinish(mut env: JNIEnv, _: JClass, handle: jlong, msg_b64: JString) -> jstring {
    let msg = get(&mut env, &msg_b64);
    if handle == 0 {
        return jstr(&mut env, json!({"ok": false, "error": "no pairing"}));
    }
    let mut p = unsafe { Box::from_raw(handle as *mut lugha_link::SenderPairing) };
    jstr(&mut env, match unb64(&msg).and_then(|m| p.finish(&m)) {
        Ok((m3, peer)) => json!({"ok": true, "msg": b64(&m3), "peer": b64(&peer)}),
        Err(e) => json!({"ok": false, "error": e.to_string()}),
    })
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_sessionInitiate(mut env: JNIEnv, _: JClass, secret_hex: JString, peer_b64: JString) -> jstring {
    let (secret, peer) = (get(&mut env, &secret_hex), get(&mut env, &peer_b64));
    let (Some(me), Some(peer)) = (keys(&secret), unb64(&peer).ok().and_then(|v| <[u8; 32]>::try_from(v).ok())) else {
        return jstr(&mut env, json!({"ok": false, "error": "bad key"}));
    };
    jstr(&mut env, match Session::initiate(&me, &peer) {
        Ok((s, m)) => json!({"ok": true, "handle": (Box::into_raw(Box::new(s)) as jlong).to_string(), "msg": b64(&m)}),
        Err(e) => json!({"ok": false, "error": e.to_string()}),
    })
}

#[no_mangle]
pub extern "system" fn Java_app_lugha_tv_link_NativeLink_sessionComplete(mut env: JNIEnv, _: JClass, handle: jlong, msg_b64: JString) -> jstring {
    let msg = get(&mut env, &msg_b64);
    let Some(s) = (unsafe { (handle as *mut Session).as_mut() }) else { return jstr(&mut env, json!({"ok": false, "error": "no session"})) };
    jstr(&mut env, match unb64(&msg).and_then(|m| s.complete(&m)) {
        Ok(()) => json!({"ok": true}),
        Err(e) => json!({"ok": false, "error": e.to_string()}),
    })
}
