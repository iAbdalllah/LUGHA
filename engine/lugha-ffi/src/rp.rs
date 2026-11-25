//! LUGHA Remote Play for the Swift apps (iPhone, Mac): the same core as Windows (lugha-rp: chiaki-ng + H.264
//! decode), as plain C. JSON in, JSON out; pictures arrive as RGBA through a callback. AGPL-3.0 (chiaki-ng).

use std::ffi::{c_char, c_void};
use std::sync::Arc;
use std::time::Duration;

use base64::Engine;
use serde_json::json;

use crate::{err, out, s};

/// The last chiaki log lines (warnings and errors, plus LUGHA's own notes), for showing why something failed.
static LOG: std::sync::Mutex<Vec<String>> = std::sync::Mutex::new(Vec::new());

fn log_once() {
    static ONCE: std::sync::Once = std::sync::Once::new();
    ONCE.call_once(|| {
        lugha_rp::set_log(|level, m| {
            if level >= 4 {
                let mut l = LOG.lock().unwrap();
                l.push(m.to_string());
                let n = l.len();
                if n > 40 { l.drain(..n - 40); }
            }
        })
    });
}

fn note(m: impl Into<String>) {
    let mut l = LOG.lock().unwrap();
    l.push(m.into());
}

/// Recent warnings and errors from Remote Play, newest last, one per line.
#[no_mangle]
pub extern "C" fn lugha_rpx_log() -> *mut c_char {
    let l = LOG.lock().unwrap();
    std::ffi::CString::new(l.join("\n")).map(std::ffi::CString::into_raw).unwrap_or(std::ptr::null_mut())
}

fn b64(b: &[u8]) -> String {
    base64::engine::general_purpose::STANDARD.encode(b)
}

fn unb64_16(v: &str) -> Option<[u8; 16]> {
    base64::engine::general_purpose::STANDARD.decode(v).ok()?.try_into().ok()
}

/// Consoles on the local network within `timeout_ms`: `[{"host","name","ps5","ready","app","id"}]`.
#[no_mangle]
pub extern "C" fn lugha_rpx_discover(timeout_ms: u32) -> *mut c_char {
    log_once();
    let found: Arc<std::sync::Mutex<Vec<lugha_rp::Console>>> = Arc::default();
    let f = found.clone();
    let d = match lugha_rp::Discovery::start(move |list| *f.lock().unwrap() = list) {
        Ok(d) => d,
        Err(e) => return err(e),
    };
    std::thread::sleep(Duration::from_millis(timeout_ms.clamp(500, 15_000) as u64));
    drop(d);
    let list = found.lock().unwrap().clone();
    out(json!(list
        .iter()
        .map(|c| json!({"host": c.host, "name": c.name, "ps5": c.ps5, "ready": c.ready, "app": c.running_app, "id": c.id}))
        .collect::<Vec<_>>()))
}

/// Registers with the console. `account_id`: PSN account id, base64 of 8 bytes (or the decimal user id).
/// Returns `{"ok":true,"regist_key","rp_key","nickname","mac"}` (keys base64) or `{"ok":false,"error"}`.
#[no_mangle]
pub unsafe extern "C" fn lugha_rpx_register(host: *const c_char, account_id: *const c_char, pin: u32, ps5: bool) -> *mut c_char {
    log_once();
    let (Some(host), Some(acc)) = (s(host), s(account_id)) else { return err("missing argument") };
    let id = match lugha_rp::account_id(acc) {
        Ok(i) => i,
        Err(e) => return err(e),
    };
    match lugha_rp::register(host, id, pin, ps5) {
        Ok(r) => out(json!({"ok": true, "regist_key": b64(&r.regist_key), "rp_key": b64(&r.morning), "nickname": r.nickname, "mac": r.mac})),
        Err(e) => err(e),
    }
}

/// Wakes a registered console. 0 = sent.
#[no_mangle]
pub unsafe extern "C" fn lugha_rpx_wakeup(host: *const c_char, regist_key_b64: *const c_char, ps5: bool) -> i32 {
    let (Some(host), Some(k)) = (s(host), s(regist_key_b64)) else { return -1 };
    let Some(key) = unb64_16(k) else { return -1 };
    let reg = lugha_rp::Registration { regist_key: key, morning: [0; 16], nickname: String::new(), mac: String::new() };
    if lugha_rp::wakeup(host, &reg, ps5).is_ok() { 0 } else { -1 }
}

/// `frame(ctx, data, size)`: one H.264 access unit (Annex B; SPS/PPS before key frames), valid only during the call.
/// Decode it with VideoToolbox.
pub type FrameCb = extern "C" fn(*mut c_void, *const u8, usize);
/// `state(ctx, state, reason)`: 1 connected, 2 ended (reason), 3 login passcode wanted (reason "incorrect" on retry).
pub type StateCb = extern "C" fn(*mut c_void, i32, *const c_char);

pub struct LughaRpSession(lugha_rp::Session);

struct Ctx(*mut c_void);
unsafe impl Send for Ctx {}
unsafe impl Sync for Ctx {}

/// Opens a session; every video frame is handed to `frame`. Returns NULL on error.
#[no_mangle]
pub unsafe extern "C" fn lugha_rpx_start(
    host: *const c_char,
    ps5: bool,
    regist_key_b64: *const c_char,
    rp_key_b64: *const c_char,
    height: u32,
    frame: FrameCb,
    state: StateCb,
    ctx: *mut c_void,
) -> *mut LughaRpSession {
    log_once();
    let (Some(host), Some(k), Some(m)) = (s(host), s(regist_key_b64), s(rp_key_b64)) else {
        note("LUGHA: start without host or keys");
        return std::ptr::null_mut();
    };
    let (Some(key), Some(morning)) = (unb64_16(k), unb64_16(m)) else {
        note(format!("LUGHA: registration keys are not 16 bytes (regist {} chars, rp {} chars)", k.len(), m.len()));
        return std::ptr::null_mut();
    };
    let opts = lugha_rp::Options {
        host: host.to_string(),
        ps5,
        account_id: [0; 8],
        registration: lugha_rp::Registration { regist_key: key, morning, nickname: String::new(), mac: String::new() },
        height: if height == 0 { 720 } else { height },
        pictures_per_second: 0.0,
    };
    let c1 = Arc::new(Ctx(ctx));
    let c2 = c1.clone();
    let session = lugha_rp::Session::start_raw(
        &opts,
        move |data| frame(c1.0, data.as_ptr(), data.len()),
        move |st| {
            let (code, reason) = match st {
                lugha_rp::State::Connected => (1, String::new()),
                lugha_rp::State::PinRequested => (3, String::new()),
                lugha_rp::State::Ended(r) => (2, r),
            };
            let r = std::ffi::CString::new(reason).unwrap_or_default();
            state(c2.0, code, r.as_ptr());
        },
    );
    match session {
        Ok(s) => Box::into_raw(Box::new(LughaRpSession(s))),
        Err(e) => {
            note(format!("LUGHA: Remote Play did not start: {e}"));
            std::ptr::null_mut()
        }
    }
}

/// The decoder could not decode a frame: ask the console for a fresh key frame.
#[no_mangle]
pub unsafe extern "C" fn lugha_rpx_request_keyframe(session: *mut LughaRpSession) {
    if let Some(s) = session.as_ref() {
        s.0.request_keyframe()
    }
}

#[no_mangle]
pub unsafe extern "C" fn lugha_rpx_set_pin(session: *mut LughaRpSession, pin: *const c_char) {
    if let (Some(sess), Some(p)) = (session.as_ref(), s(pin)) {
        sess.0.set_pin(p)
    }
}

/// Ends the session; no callback runs after this returns.
#[no_mangle]
pub unsafe extern "C" fn lugha_rpx_stop(session: *mut LughaRpSession) {
    if !session.is_null() {
        drop(Box::from_raw(session));
    }
}
