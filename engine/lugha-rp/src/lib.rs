//! LUGHA Remote Play (AGPL-3.0, built on chiaki-ng): find a PlayStation on the local network, register this device
//! with it once, then open a session whose picture LUGHA reads. The player keeps playing on the TV with the
//! controller; the session only watches.
//!
//! ```no_run
//! let found = lugha_rp::Discovery::start(|consoles| println!("{consoles:?}")).unwrap();
//! ```

use std::ffi::{c_char, c_int, c_void, CStr, CString};
use std::sync::mpsc;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use base64::Engine;

pub mod psn;

mod ffi {
    use std::ffi::{c_char, c_int, c_void};

    #[repr(C)]
    #[derive(Clone, Copy)]
    pub struct Console {
        pub host: [c_char; 64],
        pub name: [c_char; 64],
        pub id: [c_char; 32],
        pub app: [c_char; 128],
        pub version: [c_char; 32],
        pub ready: c_int,
        pub ps5: c_int,
    }

    #[repr(C)]
    pub struct Registered {
        pub ok: c_int,
        pub error: [c_char; 128],
        pub regist_key: [u8; 16],
        pub morning: [u8; 16],
        pub nickname: [c_char; 33],
        pub mac: [c_char; 13],
    }

    pub type LogCb = extern "C" fn(c_int, *const c_char, *mut c_void);
    pub type ConsolesCb = extern "C" fn(*const Console, usize, *mut c_void);
    pub type RegistCb = extern "C" fn(*const Registered, *mut c_void);
    pub type VideoCb = extern "C" fn(*const u8, usize, *mut c_void);
    pub type StateCb = extern "C" fn(c_int, *const c_char, *mut c_void);

    extern "C" {
        pub fn lugha_rp_init() -> c_int;
        pub fn lugha_rp_set_log(cb: Option<LogCb>, user: *mut c_void);
        pub fn lugha_rp_discovery_start(cb: ConsolesCb, user: *mut c_void) -> *mut c_void;
        pub fn lugha_rp_discovery_stop(d: *mut c_void);
        pub fn lugha_rp_wakeup(host: *const c_char, regist_key: *const u8, ps5: c_int) -> c_int;
        pub fn lugha_rp_register(host: *const c_char, account_id: *const u8, pin: u32, ps5: c_int, cb: RegistCb, user: *mut c_void) -> *mut c_void;
        pub fn lugha_rp_register_free(r: *mut c_void);
        pub fn lugha_rp_session_start(
            host: *const c_char,
            regist_key: *const u8,
            morning: *const u8,
            account_id: *const u8,
            ps5: c_int,
            height: c_int,
            fps: c_int,
            video: VideoCb,
            state: StateCb,
            user: *mut c_void,
        ) -> *mut c_void;
        pub fn lugha_rp_session_request_keyframe(s: *mut c_void);
        pub fn lugha_rp_session_set_pin(s: *mut c_void, pin: *const c_char);
        pub fn lugha_rp_session_stop(s: *mut c_void);
    }
}

#[derive(Debug, thiserror::Error)]
pub enum Error {
    #[error("Remote Play could not start")]
    Init,
    #[error("{0}")]
    Refused(String),
    #[error("the console did not answer in time")]
    Timeout,
    #[error("the PSN account id is not valid")]
    AccountId,
    #[error("video decoder: {0}")]
    Decoder(String),
}

fn text(c: &[c_char]) -> String {
    let bytes: Vec<u8> = c.iter().take_while(|&&b| b != 0).map(|&b| b as u8).collect();
    String::from_utf8_lossy(&bytes).into_owned()
}

fn init() -> Result<(), Error> {
    if unsafe { ffi::lugha_rp_init() } == 0 { Ok(()) } else { Err(Error::Init) }
}

/// Sends chiaki's log lines (level 1 debug … 16 error) to `f`, e.g. the app's diagnostics log.
pub fn set_log(f: impl Fn(u32, &str) + Send + Sync + 'static) {
    type Sink = Box<dyn Fn(u32, &str) + Send + Sync>;
    static SINK: Mutex<Option<Sink>> = Mutex::new(None);
    extern "C" fn cb(level: c_int, msg: *const c_char, _: *mut c_void) {
        if msg.is_null() { return }
        let m = unsafe { CStr::from_ptr(msg) }.to_string_lossy();
        if let Some(f) = SINK.lock().unwrap().as_ref() { f(level as u32, &m) }
    }
    *SINK.lock().unwrap() = Some(Box::new(f));
    unsafe { ffi::lugha_rp_set_log(Some(cb), std::ptr::null_mut()) }
}

/// The PSN account id as Remote Play wants it: 8 bytes, little-endian. Accepts the number PSN's API returns
/// (decimal) or chiaki's base64 form.
pub fn account_id(s: &str) -> Result<[u8; 8], Error> {
    let s = s.trim();
    if let Ok(n) = s.parse::<u64>() {
        return Ok(n.to_le_bytes());
    }
    let b = base64::engine::general_purpose::STANDARD.decode(s).map_err(|_| Error::AccountId)?;
    b.try_into().map_err(|_| Error::AccountId)
}

/// A PlayStation on the local network.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Console {
    pub host: String,
    pub name: String,
    pub id: String,
    pub running_app: String,
    pub version: String,
    /// false = rest mode: [`wakeup`] it first.
    pub ready: bool,
    pub ps5: bool,
}

type ConsolesFn = Box<dyn Fn(Vec<Console>) + Send + Sync>;

/// Looks for consoles every second while alive; stops when dropped.
pub struct Discovery {
    handle: *mut c_void,
    _cb: Box<ConsolesFn>,
}

unsafe impl Send for Discovery {}

impl Discovery {
    pub fn start(on_change: impl Fn(Vec<Console>) + Send + Sync + 'static) -> Result<Self, Error> {
        init()?;
        extern "C" fn cb(list: *const ffi::Console, n: usize, user: *mut c_void) {
            let f = unsafe { &*(user as *const ConsolesFn) };
            let list = if n == 0 || list.is_null() { &[][..] } else { unsafe { std::slice::from_raw_parts(list, n) } };
            f(list
                .iter()
                .map(|c| Console {
                    host: text(&c.host),
                    name: text(&c.name),
                    id: text(&c.id),
                    running_app: text(&c.app),
                    version: text(&c.version),
                    ready: c.ready != 0,
                    ps5: c.ps5 != 0,
                })
                .collect());
        }
        let boxed: Box<ConsolesFn> = Box::new(Box::new(on_change));
        let user = &*boxed as *const ConsolesFn as *mut c_void;
        let handle = unsafe { ffi::lugha_rp_discovery_start(cb, user) };
        if handle.is_null() { return Err(Error::Init) }
        Ok(Self { handle, _cb: boxed })
    }
}

impl Drop for Discovery {
    fn drop(&mut self) {
        unsafe { ffi::lugha_rp_discovery_stop(self.handle) }
    }
}

/// What registration gives: keep it (it is the device's key to that console) and pass it to every session.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Registration {
    pub regist_key: [u8; 16],
    pub morning: [u8; 16],
    pub nickname: String,
    pub mac: String,
}

impl Registration {
    /// One line for storage: `regist_key-hex:morning-hex`.
    pub fn to_string_compact(&self) -> String {
        let hex = |b: &[u8]| b.iter().map(|x| format!("{x:02x}")).collect::<String>();
        format!("{}:{}", hex(&self.regist_key), hex(&self.morning))
    }

    pub fn from_string_compact(s: &str, nickname: &str, mac: &str) -> Option<Self> {
        let (a, b) = s.split_once(':')?;
        let unhex = |h: &str| -> Option<[u8; 16]> {
            if h.len() != 32 { return None }
            let mut out = [0u8; 16];
            for (i, o) in out.iter_mut().enumerate() { *o = u8::from_str_radix(&h[i * 2..i * 2 + 2], 16).ok()? }
            Some(out)
        };
        Some(Self { regist_key: unhex(a)?, morning: unhex(b)?, nickname: nickname.into(), mac: mac.into() })
    }
}

/// Registers this device with the console (blocks until it answers, at most 30 s). `pin`: the 8 digits under
/// Settings › System › Remote Play › Link Device.
pub fn register(host: &str, account_id: [u8; 8], pin: u32, ps5: bool) -> Result<Registration, Error> {
    init()?;
    type Tx = mpsc::Sender<Result<Registration, Error>>;
    extern "C" fn cb(r: *const ffi::Registered, user: *mut c_void) {
        let tx = unsafe { &*(user as *const Tx) };
        let r = unsafe { &*r };
        let _ = tx.send(if r.ok != 0 {
            Ok(Registration { regist_key: r.regist_key, morning: r.morning, nickname: text(&r.nickname), mac: text(&r.mac) })
        } else {
            Err(Error::Refused(text(&r.error)))
        });
    }
    let (tx, rx) = mpsc::channel();
    let tx: Box<Tx> = Box::new(tx);
    let host = CString::new(host).map_err(|_| Error::Init)?;
    let handle = unsafe {
        ffi::lugha_rp_register(host.as_ptr(), account_id.as_ptr(), pin, ps5 as c_int, cb, &*tx as *const Tx as *mut c_void)
    };
    if handle.is_null() { return Err(Error::Init) }
    let out = rx.recv_timeout(Duration::from_secs(30)).unwrap_or(Err(Error::Timeout));
    unsafe { ffi::lugha_rp_register_free(handle) };
    drop(tx);
    out
}

/// Wakes a registered console from rest mode (it takes ~20 s to be ready).
pub fn wakeup(host: &str, reg: &Registration, ps5: bool) -> Result<(), Error> {
    init()?;
    let host = CString::new(host).map_err(|_| Error::Init)?;
    if unsafe { ffi::lugha_rp_wakeup(host.as_ptr(), reg.regist_key.as_ptr(), ps5 as c_int) } == 0 { Ok(()) } else { Err(Error::Init) }
}

/// A decoded picture, RGBA, top row first.
#[derive(Clone)]
pub struct Picture {
    pub width: u32,
    pub height: u32,
    pub rgba: Vec<u8>,
}

impl std::fmt::Debug for Picture {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "Picture({}x{})", self.width, self.height)
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum State {
    Connected,
    /// The console asks for its login passcode: [`Session::set_pin`].
    PinRequested,
    Ended(String),
}

/// Session settings.
#[derive(Debug, Clone)]
pub struct Options {
    pub host: String,
    pub ps5: bool,
    pub account_id: [u8; 8],
    pub registration: Registration,
    /// 360, 540, 720 or 1080. 720 is plenty to read subtitles.
    pub height: u32,
    /// How many pictures per second to hand over (every frame is still decoded, as H.264 needs).
    pub pictures_per_second: f32,
}

struct Shared {
    decoder: Mutex<Decoder>,
    on_picture: Box<dyn Fn(Picture) + Send + Sync>,
    on_state: Box<dyn Fn(State) + Send + Sync>,
    every: Duration,
    last: Mutex<Option<Instant>>,
    keyframe_wanted: Mutex<bool>,
}

struct Decoder {
    inner: Option<openh264::decoder::Decoder>,
    errors: u32,
}

/// A Remote Play session that reads the console's picture. Ends when dropped.
pub struct Session {
    handle: *mut c_void,
    shared: Arc<Shared>,
}

unsafe impl Send for Session {}
unsafe impl Sync for Session {}

impl Session {
    pub fn start(
        opts: &Options,
        on_picture: impl Fn(Picture) + Send + Sync + 'static,
        on_state: impl Fn(State) + Send + Sync + 'static,
    ) -> Result<Self, Error> {
        init()?;
        let dec = openh264::decoder::Decoder::new().map_err(|e| Error::Decoder(e.to_string()))?;
        let shared = Arc::new(Shared {
            decoder: Mutex::new(Decoder { inner: Some(dec), errors: 0 }),
            on_picture: Box::new(on_picture),
            on_state: Box::new(on_state),
            every: Duration::from_secs_f32(1.0 / opts.pictures_per_second.clamp(0.5, 30.0)),
            last: Mutex::new(None),
            keyframe_wanted: Mutex::new(false),
        });
        extern "C" fn video(buf: *const u8, n: usize, user: *mut c_void) {
            let s = unsafe { &*(user as *const Shared) };
            let data = unsafe { std::slice::from_raw_parts(buf, n) };
            s.frame(data);
        }
        extern "C" fn state(st: c_int, reason: *const c_char, user: *mut c_void) {
            let s = unsafe { &*(user as *const Shared) };
            let reason = if reason.is_null() { String::new() } else { unsafe { CStr::from_ptr(reason) }.to_string_lossy().into_owned() };
            (s.on_state)(match st {
                1 => State::Connected,
                3 => State::PinRequested,
                _ => State::Ended(reason),
            });
        }
        let host = CString::new(opts.host.as_str()).map_err(|_| Error::Init)?;
        let user = Arc::as_ptr(&shared) as *mut c_void;
        let handle = unsafe {
            ffi::lugha_rp_session_start(
                host.as_ptr(),
                opts.registration.regist_key.as_ptr(),
                opts.registration.morning.as_ptr(),
                opts.account_id.as_ptr(),
                opts.ps5 as c_int,
                opts.height as c_int,
                30,
                video,
                state,
                user,
            )
        };
        if handle.is_null() { return Err(Error::Init) }
        Ok(Self { handle, shared })
    }

    pub fn request_keyframe(&self) {
        unsafe { ffi::lugha_rp_session_request_keyframe(self.handle) }
    }

    pub fn set_pin(&self, pin: &str) {
        if let Ok(p) = CString::new(pin) { unsafe { ffi::lugha_rp_session_set_pin(self.handle, p.as_ptr()) } }
    }

    /// Hands over the next decoded picture right away (e.g. for the area editor), whatever the rate.
    pub fn picture_now(&self) {
        *self.shared.last.lock().unwrap() = None;
    }
}

impl Shared {
    fn frame(&self, data: &[u8]) {
        let due = self.last.lock().unwrap().is_none_or(|t| t.elapsed() >= self.every);
        let mut guard = self.decoder.lock().unwrap();
        let d = &mut *guard;
        let Some(dec) = d.inner.as_mut() else { return };
        // Decode every frame (H.264 needs them all); turn one into RGBA only when a picture is due.
        let outcome = match dec.decode(data) {
            Ok(Some(yuv)) => Ok(due.then(|| {
                use openh264::formats::YUVSource;
                let (w, h) = yuv.dimensions();
                let mut rgba = vec![0u8; w * h * 4];
                yuv.write_rgba8(&mut rgba);
                Picture { width: w as u32, height: h as u32, rgba }
            })),
            Ok(None) => Ok(None),
            Err(_) => Err(()),
        };
        match outcome {
            Ok(pic) => {
                d.errors = 0;
                drop(guard);
                if let Some(p) = pic {
                    *self.last.lock().unwrap() = Some(Instant::now());
                    (self.on_picture)(p);
                }
            }
            Err(()) => {
                d.errors += 1;
                // A lost packet breaks the picture until the next key frame: ask for one (not on every error).
                if d.errors == 1 || d.errors.is_multiple_of(30) { *self.keyframe_wanted.lock().unwrap() = true }
            }
        }
    }
}

impl Drop for Session {
    fn drop(&mut self) {
        unsafe { ffi::lugha_rp_session_stop(self.handle) }
        self.shared.decoder.lock().unwrap().inner = None;
    }
}

/// Call now and then (e.g. with each picture) so a broken picture asks the console for a fresh key frame.
pub fn keep_picture_healthy(s: &Session) {
    let mut w = s.shared.keyframe_wanted.lock().unwrap();
    if *w {
        *w = false;
        s.request_keyframe();
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn account_id_from_number_or_base64() {
        let n = 1234567890123456789u64;
        assert_eq!(account_id(&n.to_string()).unwrap(), n.to_le_bytes());
        let b64 = base64::engine::general_purpose::STANDARD.encode(n.to_le_bytes());
        assert_eq!(account_id(&b64).unwrap(), n.to_le_bytes());
        assert!(account_id("nope!").is_err());
    }

    #[test]
    fn registration_round_trips_through_storage() {
        let r = Registration { regist_key: *b"0123abcd\0\0\0\0\0\0\0\0", morning: [7; 16], nickname: "PS5".into(), mac: "aabbcc".into() };
        let s = r.to_string_compact();
        assert_eq!(Registration::from_string_compact(&s, "PS5", "aabbcc").unwrap(), r);
        assert!(Registration::from_string_compact("zz:yy", "", "").is_none());
    }

    #[test]
    fn discovery_starts_and_stops() {
        let d = Discovery::start(|_| {}).expect("discovery");
        std::thread::sleep(Duration::from_millis(300));
        drop(d);
    }
}
