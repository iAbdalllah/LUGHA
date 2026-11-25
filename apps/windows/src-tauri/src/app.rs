//! Tauri shell: app state, commands for the UI (ui/index.html), the capture → OCR → match loop and the overlay.

use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
#[cfg(windows)]
use std::time::{Duration, Instant};

use lugha_core::crypto::DeviceKeys;
use lugha_core::{Matcher, Query, TrustStore};
use lugha_link::StaticKeys;
use serde::{Deserialize, Serialize};
use tauri::{Emitter, Manager};

use crate::api::{Api, ApiError};
#[cfg(windows)]
use crate::pipeline::Pipeline;
use crate::pipeline::Subtitle;
#[cfg(windows)]
use crate::pipeline::Found;
use crate::store::PackageStore;
use crate::sync;
use crate::tv::TvSender;

const APP_VERSION: &str = env!("CARGO_PKG_VERSION");

// ---------- secrets: Credential Manager on Windows, a private file elsewhere (dev builds only) ----------

mod vault {
    #[cfg(windows)]
    pub use crate::secrets::{delete, get, set};

    #[cfg(not(windows))]
    fn path(key: &str) -> std::path::PathBuf {
        super::data_dir().join(format!(".secret-{key}"))
    }
    #[cfg(not(windows))]
    pub fn get(key: &str) -> Option<String> {
        std::fs::read_to_string(path(key)).ok()
    }
    #[cfg(not(windows))]
    pub fn set(key: &str, value: &str) -> std::io::Result<()> {
        std::fs::write(path(key), value)
    }
    #[cfg(not(windows))]
    pub fn delete(key: &str) {
        let _ = std::fs::remove_file(path(key));
    }
}

fn data_dir() -> PathBuf {
    let d = dirs::data_dir().unwrap_or_else(std::env::temp_dir).join("LUGHA");
    let _ = std::fs::create_dir_all(&d);
    d
}

// ---------- settings (non-secret, JSON next to the packages) ----------

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(default)]
pub struct Settings {
    pub server: String,
    pub ocr_language: String,
    pub region: Option<Region>,
    pub tv_addr: Option<String>,
    pub tv_public: Option<String>,
    pub interval_ms: u64,
    /// Window mode: read from the window whose title contains this (e.g. "PS Remote Play") instead of a screen
    /// region. It keeps working while the window is covered, so the game can be played on the TV.
    pub capture_window: Option<String>,
    /// Subtitle area inside that window, as fractions (x, y, w, h) of its client area.
    pub window_area: (f32, f32, f32, f32),
    /// Also draw subtitles on this PC (often off in window mode: they go to the TV).
    pub show_on_pc: bool,
    #[serde(flatten)]
    pub display: Display,
    /// Reading areas: only text inside the enabled ones is read (empty = the whole capture).
    pub areas: Vec<Area>,
    /// Read the game straight from the linked PS5 (LUGHA's own Remote Play) instead of the screen or a window.
    pub use_ps5: bool,
    pub ps5: Option<crate::ps5::Console>,
}

/// A box to read from, as fractions (x, y, w, h) of the captured picture, origin top-left.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Area {
    pub name: String,
    pub x: f32,
    pub y: f32,
    pub w: f32,
    pub h: f32,
    #[serde(default = "yes")]
    pub enabled: bool,
}

fn yes() -> bool {
    true
}

/// Where and how the Arabic is shown, here and on the TV (same options as the Mac app).
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(default)]
pub struct Display {
    /// "over": the Arabic hides the English; "above": just above it, the English stays visible; "history": nothing
    /// over the game, only the history panel.
    pub display: String,
    /// Colour of the text box and the history panel, #RRGGBB.
    pub plate_color: String,
    /// Side panel with the recent dialogue: "off", "left" or "right".
    pub history: String,
    pub fade_ms: u32,
    /// Keep a replaced line up (dimmed, above the new one) until it could be read.
    pub read_hold: bool,
    /// Show text only once it has been read this long (avoids half-drawn lines).
    pub stable_ms: u32,
    /// Treat the English as gone after it is missing this long.
    pub gone_after_ms: u32,
}

impl Default for Display {
    fn default() -> Self {
        Self { display: "over".into(), plate_color: "#000000".into(), history: "off".into(), fade_ms: 150, read_hold: true, stable_ms: 200, gone_after_ms: 1200 }
    }
}

/// Release builds talk to the live server; debug builds to a local backend (`php artisan serve --port=8040`).
const DEFAULT_SERVER: &str = if cfg!(debug_assertions) { "http://127.0.0.1:8040" } else { "https://lugha.iabdallah.com" };

impl Default for Settings {
    fn default() -> Self {
        Self {
            server: std::env::var("LUGHA_API").unwrap_or_else(|_| DEFAULT_SERVER.into()),
            ocr_language: "en-US".into(),
            region: None,
            tv_addr: None,
            tv_public: None,
            interval_ms: 200,
            capture_window: None,
            window_area: (0.08, 0.70, 0.84, 0.26),
            show_on_pc: true,
            display: Display::default(),
            use_ps5: false,
            ps5: None,
            areas: vec![],
        }
    }
}

impl Settings {
    fn load() -> Self {
        let mut s: Self = std::fs::read(data_dir().join("settings.json")).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default();
        // Installers before 0.1.1 saved the developer's local backend; a release build moves them to the live server.
        if !cfg!(debug_assertions) && s.server.starts_with("http://127.0.0.1:8040") && std::env::var("LUGHA_API").is_err() {
            s.server = DEFAULT_SERVER.into();
        }
        s
    }
    pub(crate) fn save(&self) {
        let _ = std::fs::write(data_dir().join("settings.json"), serde_json::to_vec_pretty(self).unwrap_or_default());
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Serialize, Deserialize)]
pub struct Region {
    pub x: i32,
    pub y: i32,
    pub w: i32,
    pub h: i32,
}

#[cfg(windows)]
impl From<Region> for crate::capture::Region {
    fn from(r: Region) -> Self {
        Self { x: r.x, y: r.y, w: r.w, h: r.h }
    }
}

fn default_region() -> Region {
    #[cfg(windows)]
    {
        let r = crate::capture::Region::default_subtitles();
        Region { x: r.x, y: r.y, w: r.w, h: r.h }
    }
    #[cfg(not(windows))]
    {
        Region { x: 192, y: 810, w: 1536, h: 243 }
    }
}

// ---------- state ----------

pub struct Core {
    pub(crate) settings: Settings,
    api: Api,
    user: Option<String>,
    device: DeviceKeys,
    link: StaticKeys,
    device_id: Option<String>,
    trust: Option<TrustStore>,
    store: PackageStore,
    matcher: Option<Matcher>,
    packages: Vec<(String, String)>,
    tv: Option<TvSender>,
    history: Vec<Subtitle>,
    last_error: Option<String>,
}

type Shared = Arc<Mutex<Core>>;
pub type SharedCore = Shared;

pub(crate) fn with_core<R>(s: &Shared, f: impl FnOnce(&mut Core) -> R) -> R {
    f(&mut s.lock().unwrap_or_else(|p| p.into_inner()))
}

pub(crate) fn vault_get(key: &str) -> Option<String> {
    vault::get(key)
}

pub(crate) fn vault_set(key: &str, value: &str) -> Result<(), String> {
    vault::set(key, value).map_err(|e| e.to_string())
}

pub(crate) fn vault_delete(key: &str) {
    vault::delete(key)
}

#[derive(Default)]
struct Capture {
    running: Arc<AtomicBool>,
}

fn secret_key(name: &str) -> [u8; 32] {
    if let Some(bytes) = vault::get(name).and_then(|h| hex::decode(h.trim()).ok()).filter(|b| b.len() == 32) {
        return bytes.try_into().unwrap();
    }
    let fresh = DeviceKeys::generate().secret;
    let _ = vault::set(name, &hex::encode(fresh));
    fresh
}

impl Core {
    fn new() -> Self {
        let settings = Settings::load();
        let api = Api::new(&settings.server, vault::get("api_token"));
        let store = PackageStore::new(Some(data_dir().join("packages"))).expect("packages folder");
        let trust = std::fs::read_to_string(data_dir().join("trust.json")).ok().and_then(|t| sync::trust_from_json(&t));
        let mut core = Self {
            device: DeviceKeys::from_secret(secret_key("device_secret")),
            link: StaticKeys::from_secret(secret_key("link_secret")),
            device_id: vault::get("device_id"),
            settings,
            api,
            user: None,
            trust,
            store,
            matcher: None,
            packages: vec![],
            tv: None,
            history: vec![],
            last_error: None,
        };
        core.reload(); // works offline with the cached trust list + licenses
        core
    }

    fn reload(&mut self) {
        let Some(trust) = &self.trust else { return };
        let (pkgs, errs) = sync::load(&self.store, trust, &self.device);
        self.packages = pkgs.iter().map(|p| (p.manifest.package_id.clone(), p.manifest.version.clone())).collect();
        self.matcher = Some(Matcher::new(pkgs));
        if !errs.is_empty() {
            self.last_error = Some(errs.join("\n"));
        }
    }

    fn refresh_trust(&mut self) -> Result<(), String> {
        let raw = self.api.trust_json().map_err(msg)?;
        self.trust = Some(sync::trust_from_json(&raw).ok_or("invalid trust document")?);
        let _ = std::fs::write(data_dir().join("trust.json"), raw);
        Ok(())
    }

    /// After a token is obtained: remember it, register this PC once, sync.
    fn finish_sign_in(&mut self, token: String, name: String) -> Result<SyncSummary, String> {
        self.api.token = Some(token.clone());
        let _ = vault::set("api_token", &token);
        self.user = Some(name);
        self.refresh_trust()?;
        if self.device_id.is_none() {
            let host = std::env::var("COMPUTERNAME").unwrap_or_else(|_| "Windows PC".into());
            let id = self.api.register_device(&host, &lugha_link::b64(&self.device.public), APP_VERSION).map_err(msg)?;
            let _ = vault::set("device_id", &id);
            self.device_id = Some(id);
        }
        self.sync_now()
    }

    fn sync_now(&mut self) -> Result<SyncSummary, String> {
        if self.trust.is_none() {
            self.refresh_trust()?;
        }
        let device_id = self.device_id.clone().ok_or("not signed in")?;
        let r = sync::sync(&self.api, &self.store, self.trust.as_ref().unwrap(), &self.device, &device_id, APP_VERSION);
        self.reload();
        Ok(SyncSummary { installed: r.installed, updated: r.updated, removed: r.removed, errors: r.errors })
    }
}

fn msg(e: ApiError) -> String {
    match e {
        ApiError::Http { code, .. } if code == "device_limit_reached" => {
            "وصلت إلى الحد الأقصى للأجهزة. احذف جهازاً من حسابك على الموقع ثم حاول مجدداً.".into()
        }
        ApiError::Http { status: 401, .. } => "انتهت الجلسة — سجّل الدخول مرة أخرى.".into(),
        ApiError::Network(_) => "تعذّر الاتصال بالخادم.".into(),
        e => e.to_string(),
    }
}

// ---------- commands ----------

#[derive(Serialize)]
pub struct Status {
    server: String,
    user: Option<String>,
    signed_in: bool,
    device_id: Option<String>,
    packages: Vec<(String, String)>,
    region: Region,
    capturing: bool,
    tv: Option<String>,
    tv_paired: bool,
    tv_connected: bool,
    ocr_language: String,
    windows: bool,
    capture_window: Option<String>,
    window_area: (f32, f32, f32, f32),
    show_on_pc: bool,
    last_error: Option<String>,
    display: Display,
    areas: Vec<Area>,
    use_ps5: bool,
}

#[derive(Serialize)]
pub struct SyncSummary {
    installed: Vec<String>,
    updated: Vec<String>,
    removed: Vec<String>,
    errors: Vec<String>,
}

#[derive(Serialize, Clone)]
pub struct Line {
    text: String,
    speaker: Option<String>,
    source: String,
    entry_id: i64,
    package_id: String,
    /// Readable text colour and backdrop strength for the Arabic drawn on the picture (display "above").
    tone_fg: Option<String>,
    tone_shade: Option<f32>,
}

impl From<&Subtitle> for Line {
    fn from(s: &Subtitle) -> Self {
        Self { text: s.text.clone(), speaker: s.speaker.clone(), source: s.source.clone(), entry_id: s.entry_id, package_id: s.package_id.clone(), tone_fg: None, tone_shade: None }
    }
}

type Cmd<T> = Result<T, String>;

fn lock<'a>(s: &'a tauri::State<'_, Shared>) -> std::sync::MutexGuard<'a, Core> {
    s.lock().unwrap_or_else(|p| p.into_inner())
}

#[tauri::command(async)]
fn status(core: tauri::State<'_, Shared>, cap: tauri::State<'_, Capture>) -> Cmd<Status> {
    let mut c = lock(&core);
    if c.user.is_none() && c.api.token.is_some() {
        c.user = c.api.me().ok();
    }
    Ok(Status {
        server: c.settings.server.clone(),
        user: c.user.clone(),
        signed_in: c.api.token.is_some(),
        device_id: c.device_id.clone(),
        packages: c.packages.clone(),
        region: c.settings.region.unwrap_or_else(default_region),
        capturing: cap.running.load(Ordering::SeqCst),
        tv: c.settings.tv_addr.clone(),
        tv_paired: c.settings.tv_public.is_some(),
        tv_connected: c.tv.is_some(),
        ocr_language: c.settings.ocr_language.clone(),
        capture_window: c.settings.capture_window.clone(),
        window_area: c.settings.window_area,
        show_on_pc: c.settings.show_on_pc,
        display: c.settings.display.clone(),
        areas: c.settings.areas.clone(),
        use_ps5: c.settings.use_ps5,
        windows: cfg!(windows),
        last_error: c.last_error.take(),
    })
}

#[tauri::command(async)]
fn set_server(core: tauri::State<'_, Shared>, url: String) -> Cmd<()> {
    let mut c = lock(&core);
    c.settings.server = url.trim().trim_end_matches('/').to_string();
    c.settings.save();
    c.api.base = c.settings.server.clone();
    Ok(())
}

#[tauri::command(async)]
fn sign_in(core: tauri::State<'_, Shared>, email: String, password: String) -> Cmd<SyncSummary> {
    let mut c = lock(&core);
    let host = std::env::var("COMPUTERNAME").unwrap_or_else(|_| "Windows PC".into());
    let (token, name) = c.api.login(email.trim(), &password, &host).map_err(msg)?;
    c.finish_sign_in(token, name)
}

#[derive(Serialize)]
pub struct DeviceCode {
    device_code: String,
    user_code: String,
    url: String,
    interval: u64,
}

#[tauri::command(async)]
fn start_device_sign_in(core: tauri::State<'_, Shared>) -> Cmd<DeviceCode> {
    let c = lock(&core);
    let host = std::env::var("COMPUTERNAME").unwrap_or_else(|_| "Windows PC".into());
    let d = c.api.start_device_code(&host).map_err(msg)?;
    Ok(DeviceCode { device_code: d.device_code, user_code: d.user_code, url: d.verification_url_complete, interval: d.interval.max(2) })
}

/// One poll; Ok(None) while the user hasn't approved yet.
#[tauri::command(async)]
fn poll_device_sign_in(core: tauri::State<'_, Shared>, device_code: String) -> Cmd<Option<SyncSummary>> {
    let mut c = lock(&core);
    match c.api.poll_device_code(&device_code).map_err(msg)? {
        None => Ok(None),
        Some((token, name)) => c.finish_sign_in(token, name).map(Some),
    }
}

#[tauri::command(async)]
fn sign_out(core: tauri::State<'_, Shared>, cap: tauri::State<'_, Capture>) -> Cmd<()> {
    cap.running.store(false, Ordering::SeqCst);
    let mut c = lock(&core);
    if let Some(id) = c.device_id.take() {
        // Frees the device slot; ignore failures (offline sign-out still clears local data).
        let _ = ureq::delete(&format!("{}/api/v1/devices/{id}", c.api.base))
            .set("Accept", "application/json")
            .set("Authorization", &format!("Bearer {}", c.api.token.clone().unwrap_or_default()))
            .call();
    }
    for (p, _) in c.store.installed() {
        c.store.remove(&p);
    }
    vault::delete("api_token");
    vault::delete("device_id");
    c.api.token = None;
    c.user = None;
    c.matcher = None;
    c.packages.clear();
    Ok(())
}

#[tauri::command(async)]
fn sync_now(core: tauri::State<'_, Shared>) -> Cmd<SyncSummary> {
    lock(&core).sync_now()
}

#[tauri::command(async)]
fn lookup(core: tauri::State<'_, Shared>, text: String) -> Cmd<Option<Line>> {
    let mut c = lock(&core);
    let Some(m) = c.matcher.as_mut() else { return Ok(None) };
    Ok(m.lookup(&Query::new(text.clone())).map(|h| Line { text: h.target, speaker: h.speaker, source: text, entry_id: h.entry_id, package_id: h.package_id, tone_fg: None, tone_shade: None }))
}

/// Report a wrong line to the reviewers (the server checks the account owns the package and the line is in it).
#[tauri::command(async)]
fn report_line(core: tauri::State<'_, Shared>, package: String, entry_id: i64, reason: String, note: Option<String>) -> Cmd<()> {
    let c = lock(&core);
    let version = c.packages.iter().find(|(p, _)| *p == package).map(|(_, v)| v.clone());
    c.api.report_line(&package, version.as_deref(), entry_id, &reason, note.as_deref().map(str::trim).filter(|n| !n.is_empty()), "windows").map_err(|e| e.to_string())
}

#[tauri::command(async)]
fn history(core: tauri::State<'_, Shared>) -> Cmd<Vec<Line>> {
    Ok(lock(&core).history.iter().map(Line::from).collect())
}

/// Titles of open windows, PS Remote Play first.
#[tauri::command(async)]
fn list_windows() -> Cmd<Vec<String>> {
    #[cfg(windows)]
    {
        let mut t: Vec<String> = crate::capture::list_windows().into_iter().map(|(_, t)| t).filter(|t| !t.starts_with("LUGHA")).collect();
        t.sort_by_key(|x| (!x.to_lowercase().contains("remote play"), x.to_lowercase()));
        t.dedup();
        Ok(t)
    }
    #[cfg(not(windows))]
    Ok(vec![])
}

#[tauri::command(async)]
fn set_capture_window(core: tauri::State<'_, Shared>, title: Option<String>, area: Option<(f32, f32, f32, f32)>, show_on_pc: Option<bool>) -> Cmd<()> {
    let mut c = lock(&core);
    c.settings.capture_window = title.filter(|t| !t.trim().is_empty());
    if let Some(a) = area.filter(|a| a.2 > 0.02 && a.3 > 0.02) {
        c.settings.window_area = a;
    }
    if let Some(s) = show_on_pc {
        c.settings.show_on_pc = s;
    }
    c.settings.save();
    Ok(())
}

/// Reading areas from the UI (fractions of the captured picture).
#[tauri::command(async)]
fn set_areas(core: tauri::State<'_, Shared>, areas: Vec<Area>) -> Cmd<()> {
    let mut c = lock(&core);
    c.settings.areas = areas
        .into_iter()
        .filter(|a| a.w > 0.01 && a.h > 0.01)
        .map(|a| Area { x: a.x.clamp(0.0, 1.0), y: a.y.clamp(0.0, 1.0), w: a.w.min(1.0 - a.x.clamp(0.0, 1.0)), h: a.h.min(1.0 - a.y.clamp(0.0, 1.0)), ..a })
        .collect();
    c.settings.save();
    Ok(())
}

/// The overlay page loaded: send it where the subtitle area is and the display options.
/// Choose the PS5 (LUGHA's own Remote Play) as the source, or go back to the screen/window.
#[tauri::command(async)]
fn set_use_ps5(core: tauri::State<'_, Shared>, ps5: tauri::State<'_, crate::ps5::Ps5>, on: bool) -> Cmd<()> {
    let mut c = lock(&core);
    if on && c.settings.ps5.is_none() {
        return Err("اربط جهاز PS5 أولاً.".into());
    }
    c.settings.use_ps5 = on;
    c.settings.save();
    drop(c);
    if !on {
        crate::ps5::disconnect(&ps5);
    }
    Ok(())
}

#[tauri::command(async)]
fn overlay_ready(app: tauri::AppHandle, core: tauri::State<'_, Shared>) -> Cmd<()> {
    let r = lock(&core).settings.region.unwrap_or_else(default_region);
    place_overlay(&app, r);
    Ok(())
}

/// Display options from the UI: saved, sent to the overlay and the TV.
#[tauri::command(async)]
fn set_display(app: tauri::AppHandle, core: tauri::State<'_, Shared>, display: Display) -> Cmd<()> {
    let mut c = lock(&core);
    let d = Display {
        display: if ["above", "history"].contains(&display.display.as_str()) { display.display } else { "over".into() },
        plate_color: if display.plate_color.len() == 7 && display.plate_color.starts_with('#') && display.plate_color[1..].chars().all(|c| c.is_ascii_hexdigit()) {
            display.plate_color
        } else {
            "#000000".into()
        },
        history: if ["left", "right"].contains(&display.history.as_str()) { display.history } else { "off".into() },
        fade_ms: display.fade_ms.min(1000),
        read_hold: display.read_hold,
        stable_ms: display.stable_ms.min(1000),
        gone_after_ms: display.gone_after_ms.clamp(100, 3000),
    };
    c.settings.display = d.clone();
    c.settings.save();
    let region = c.settings.region.unwrap_or_else(default_region);
    if let Some(tv) = c.tv.as_mut() {
        let _ = tv.set_style(&d.display, &history_side(&d), &d.plate_color, d.fade_ms, d.read_hold);
    }
    drop(c);
    place_overlay(&app, region);
    Ok(())
}

#[tauri::command(async)]
fn set_region(app: tauri::AppHandle, core: tauri::State<'_, Shared>, region: Option<Region>) -> Cmd<Region> {
    let mut c = lock(&core);
    c.settings.region = region.filter(|r| r.w >= 40 && r.h >= 20);
    c.settings.save();
    let r = c.settings.region.unwrap_or_else(default_region);
    drop(c); // place_overlay reads the settings too
    place_overlay(&app, r);
    Ok(r)
}

#[tauri::command(async)]
fn set_ocr_language(core: tauri::State<'_, Shared>, lang: String) -> Cmd<()> {
    let mut c = lock(&core);
    c.settings.ocr_language = lang;
    c.settings.save();
    Ok(())
}

#[tauri::command(async)]
fn tv_pair(core: tauri::State<'_, Shared>, addr: String, code: String) -> Cmd<()> {
    let mut c = lock(&core);
    let addr = normalize_addr(&addr);
    let host = std::env::var("COMPUTERNAME").unwrap_or_else(|_| "Windows PC".into());
    let tv = TvSender::pair(&addr, code.trim(), &c.link, &host).map_err(|e| tv_msg(&e))?;
    c.settings.tv_addr = Some(addr);
    c.settings.tv_public = Some(lugha_link::b64(&tv.tv_public));
    c.settings.save();
    c.tv = Some(tv);
    push_tv_style(&mut c);
    Ok(())
}

#[tauri::command(async)]
fn tv_connect(core: tauri::State<'_, Shared>, addr: Option<String>) -> Cmd<()> {
    let mut c = lock(&core);
    if let Some(a) = addr {
        c.settings.tv_addr = Some(normalize_addr(&a));
        c.settings.save();
    }
    let addr = c.settings.tv_addr.clone().ok_or("لا يوجد تلفاز مقترن")?;
    let public: [u8; 32] = c
        .settings
        .tv_public
        .as_deref()
        .and_then(|k| lugha_link::unb64(k).ok())
        .and_then(|b| b.try_into().ok())
        .ok_or("لا يوجد تلفاز مقترن")?;
    let host = std::env::var("COMPUTERNAME").unwrap_or_else(|_| "Windows PC".into());
    c.tv = Some(TvSender::connect(&addr, &c.link, public, &host).map_err(|e| tv_msg(&e))?);
    push_tv_style(&mut c);
    Ok(())
}

/// The history panel's side: history-only display always shows it (right unless left was chosen).
fn history_side(d: &Display) -> String {
    if d.display == "history" && d.history == "off" { "right".into() } else { d.history.clone() }
}

/// Sends the display options to the connected TV (it keeps them until the next change).
fn push_tv_style(c: &mut Core) {
    let d = c.settings.display.clone();
    if let Some(tv) = c.tv.as_mut() {
        let _ = tv.set_style(&d.display, &history_side(&d), &d.plate_color, d.fade_ms, d.read_hold);
    }
}

#[tauri::command(async)]
fn tv_test(core: tauri::State<'_, Shared>) -> Cmd<u64> {
    let mut c = lock(&core);
    let tv = c.tv.as_mut().ok_or("التلفاز غير متصل")?;
    let rtt = tv.show("مرحباً من لغة على ويندوز", None, true).map_err(|e| tv_msg(&e))?;
    Ok(rtt.map(|d| d.as_millis() as u64).unwrap_or(0))
}

#[tauri::command(async)]
fn tv_forget(core: tauri::State<'_, Shared>) -> Cmd<()> {
    let mut c = lock(&core);
    c.tv = None;
    c.settings.tv_addr = None;
    c.settings.tv_public = None;
    c.settings.save();
    Ok(())
}

fn normalize_addr(a: &str) -> String {
    let a = a.trim();
    if a.contains(':') { a.to_string() } else { format!("{a}:{}", lugha_link::DEFAULT_PORT) }
}

fn tv_msg(e: &crate::tv::TvError) -> String {
    match e {
        crate::tv::TvError::WrongCode => "الرمز غير صحيح — استخدم الرمز الظاهر على التلفاز.".into(),
        crate::tv::TvError::Locked => "الاقتران مقفل مؤقتاً — حاول بعد دقيقة.".into(),
        crate::tv::TvError::Io(_) => "تعذّر الوصول إلى التلفاز. تأكد أنه على نفس الشبكة.".into(),
        e => e.to_string(),
    }
}

// ---------- capture loop ----------

#[tauri::command(async)]
fn start_capture(app: tauri::AppHandle, core: tauri::State<'_, Shared>, cap: tauri::State<'_, Capture>) -> Cmd<()> {
    if !cfg!(windows) {
        return Err("الالتقاط متاح على ويندوز فقط.".into());
    }
    if lock(&core).matcher.as_ref().is_none() {
        return Err("لا توجد حزم مثبتة — زامن مكتبتك أولاً.".into());
    }
    if cap.running.swap(true, Ordering::SeqCst) {
        return Ok(());
    }
    let (region, show_on_pc, window_mode, use_ps5) = {
        let c = lock(&core);
        (c.settings.region.unwrap_or_else(default_region), c.settings.show_on_pc, c.settings.capture_window.is_some(), c.settings.use_ps5)
    };
    if use_ps5 {
        // The game is on the TV: read the PS5's picture through LUGHA's own Remote Play session.
        if let Err(e) = crate::ps5::connect(&app, core.inner(), &app.state::<crate::ps5::Ps5>()) {
            cap.running.store(false, Ordering::SeqCst);
            return Err(e);
        }
    } else if !window_mode || show_on_pc {
        // Window mode with "show on this PC" off: subtitles go only to the TV.
        place_overlay(&app, region);
        if let Some(w) = app.get_webview_window("overlay") {
            let _ = w.show();
        }
    }
    let running = cap.running.clone();
    let shared: Shared = core.inner().clone();
    std::thread::spawn(move || capture_loop(app, shared, running));
    Ok(())
}

#[tauri::command(async)]
fn stop_capture(app: tauri::AppHandle, cap: tauri::State<'_, Capture>) -> Cmd<()> {
    cap.running.store(false, Ordering::SeqCst);
    // Free the console for other Remote Play apps.
    crate::ps5::disconnect(&app.state::<crate::ps5::Ps5>());
    if let Some(w) = app.get_webview_window("overlay") {
        let _ = w.hide();
    }
    let _ = app.emit("subtitle", Option::<Line>::None);
    Ok(())
}

#[cfg(windows)]
fn capture_loop(app: tauri::AppHandle, shared: Shared, running: Arc<AtomicBool>) {
    let lang = shared.lock().map(|c| c.settings.ocr_language.clone()).unwrap_or_else(|_| "en-US".into());
    let ocr = match crate::ocr::Ocr::new(&lang) {
        Ok(o) => o,
        Err(e) => {
            running.store(false, Ordering::SeqCst);
            let _ = app.emit("capture-error", format!("تعذّر تشغيل التعرف على النص: {e}"));
            return;
        }
    };
    let mut pipeline = Pipeline::default();
    while running.load(Ordering::SeqCst) {
        let t0 = Instant::now();
        let (region, interval, window, area, use_ps5) = {
            let c = shared.lock().unwrap_or_else(|p| p.into_inner());
            (c.settings.region.unwrap_or_else(default_region), c.settings.interval_ms, c.settings.capture_window.clone(), c.settings.window_area, c.settings.use_ps5)
        };
        let frame = if use_ps5 {
            app.state::<crate::ps5::Ps5>().frame_bgra().map(|(width, height, bgra)| crate::capture::Frame { width, height, bgra })
        } else { match window {
            // Window mode: the window may be behind others while the game is played on the TV.
            Some(title) => match crate::capture::find_window(&title) {
                Some(hwnd) => crate::capture::capture_window(hwnd, area).ok(),
                None => {
                    let _ = app.emit("capture-error", format!("لم يُعثر على نافذة «{title}». افتح PS Remote Play واتصل بجهاز PS5."));
                    running.store(false, Ordering::SeqCst);
                    break;
                }
            },
            None => crate::capture::capture(region.into()).ok(),
        } };
        let areas: Vec<Area> = shared.lock().map(|c| c.settings.areas.iter().filter(|a| a.enabled).cloned().collect()).unwrap_or_default();
        // Where the Arabic goes in "above" mode is just above the text: sample the top band of the (first) area.
        let mut tone = None;
        let lines = match frame {
            None => vec![],
            Some(f) => {
                let top = areas.first().map(|a| (a.x, a.y, a.w, (a.h * 0.3).max(0.02))).unwrap_or((0.0, 0.0, 1.0, 0.25));
                tone = Some(crate::frame::tone(f.width, f.height, &f.bgra, top));
                if areas.is_empty() {
                    ocr.recognize(&f).unwrap_or_default()
                } else {
                    // Reading areas: OCR each box on its own; text elsewhere (signs, the HUD) is never read.
                    areas
                        .iter()
                        .filter_map(|a| crate::frame::crop(f.width, f.height, &f.bgra, (a.x, a.y, a.w, a.h)))
                        .flat_map(|(width, height, bgra)| ocr.recognize(&crate::capture::Frame { width, height, bgra }).unwrap_or_default())
                        .collect()
                }
            }
        };
        let mut c = shared.lock().unwrap_or_else(|p| p.into_inner());
        let core = &mut *c;
        pipeline.stable_frames = stable_frames(core.settings.display.stable_ms, interval);
        pipeline.hide_after = Duration::from_millis(core.settings.display.gone_after_ms as u64);
        let changed = pipeline.ingest(&lines, Instant::now(), |t| {
            core.matcher.as_mut()?.lookup(&Query::new(t)).map(|h| Found { text: h.target, speaker: h.speaker, entry_id: h.entry_id, package_id: h.package_id })
        });
        if changed {
            let line = pipeline.current.as_ref().map(Line::from).map(|mut l| {
                if let Some((fg, shade)) = &tone { l.tone_fg = Some(fg.clone()); l.tone_shade = Some(*shade); }
                l
            });
            let _ = app.emit("subtitle", line.clone());
            if let Some(sub) = &pipeline.current {
                core.history.insert(0, sub.clone());
                core.history.truncate(100);
            }
            if let Some(tv) = core.tv.as_mut() {
                let sent = match &line {
                    Some(l) => tv.show(&l.text, l.speaker.as_deref(), false).map(|_| ()),
                    None => tv.clear(),
                };
                if sent.is_err() {
                    core.tv = None;
                    let _ = app.emit("tv-lost", ());
                }
            }
        }
        drop(c);
        let spent = t0.elapsed();
        std::thread::sleep(Duration::from_millis(interval).saturating_sub(spent));
    }
}

#[cfg(not(windows))]
fn capture_loop(_app: tauri::AppHandle, _shared: Shared, running: Arc<AtomicBool>) {
    running.store(false, Ordering::SeqCst);
}

/// Frames needed for `stable_ms` at one frame per `interval_ms` (at least 1).
fn stable_frames(stable_ms: u32, interval_ms: u64) -> u32 {
    1 + (stable_ms as u64).div_ceil(interval_ms.max(1)) as u32
}

/// What the overlay page needs: the subtitle area inside the overlay window and the display options.
#[derive(Serialize, Clone)]
struct OverlayConfig {
    region: Region,
    #[serde(flatten)]
    display: Display,
}

/// The overlay covers the whole monitor that holds the subtitle area (click-through, excluded from capture), so the
/// Arabic can sit over or above the English and the history panel can go at the side.
fn place_overlay(app: &tauri::AppHandle, r: Region) {
    let Some(w) = app.get_webview_window("overlay") else { return };
    let (cx, cy) = (r.x + r.w / 2, r.y + r.h / 2);
    let monitor = w.available_monitors().ok().and_then(|ms| {
        ms.into_iter().find(|m| {
            let (p, s) = (m.position(), m.size());
            cx >= p.x && cy >= p.y && cx < p.x + s.width as i32 && cy < p.y + s.height as i32
        })
    }).or_else(|| w.primary_monitor().ok().flatten());
    let (ox, oy, ow, oh) = match &monitor {
        Some(m) => (m.position().x, m.position().y, m.size().width, m.size().height),
        None => (r.x, r.y, r.w.max(1) as u32, r.h.max(1) as u32),
    };
    let _ = w.set_position(tauri::PhysicalPosition::new(ox, oy));
    let _ = w.set_size(tauri::PhysicalSize::new(ow, oh));
    let display = app.state::<Shared>().lock().map(|c| c.settings.display.clone()).unwrap_or_default();
    let _ = app.emit_to("overlay", "overlay-config", OverlayConfig { region: Region { x: r.x - ox, y: r.y - oy, w: r.w, h: r.h }, display });
}

pub fn run() {
    tauri::Builder::default()
        .manage::<Shared>(Arc::new(Mutex::new(Core::new())))
        .manage(Capture::default())
        .manage(crate::ps5::Ps5::default())
        .setup(|app| {
            if let Some(overlay) = app.get_webview_window("overlay") {
                let _ = overlay.set_ignore_cursor_events(true);
                #[cfg(windows)]
                if let Ok(hwnd) = overlay.hwnd() {
                    // The overlay sits on top of the game's subtitles; keep it out of our own capture.
                    let _ = crate::capture::exclude_from_capture(hwnd.0 as *mut core::ffi::c_void);
                }
            }
            // Reconnect to the paired TV in the background; failures just leave it disconnected.
            let shared: Shared = app.state::<Shared>().inner().clone();
            std::thread::spawn(move || {
                let (addr, public, link) = {
                    let c = shared.lock().unwrap_or_else(|p| p.into_inner());
                    (c.settings.tv_addr.clone(), c.settings.tv_public.clone(), c.link.clone())
                };
                let (Some(addr), Some(public)) = (addr, public) else { return };
                let Some(public) = lugha_link::unb64(&public).ok().and_then(|b| <[u8; 32]>::try_from(b).ok()) else { return };
                let host = std::env::var("COMPUTERNAME").unwrap_or_else(|_| "Windows PC".into());
                if let Ok(tv) = TvSender::connect(&addr, &link, public, &host) {
                    shared.lock().unwrap_or_else(|p| p.into_inner()).tv = Some(tv);
                }
            });
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            status,
            set_server,
            sign_in,
            start_device_sign_in,
            poll_device_sign_in,
            sign_out,
            sync_now,
            lookup,
            history,
            report_line,
            list_windows,
            set_capture_window,
            set_display,
            set_areas,
            overlay_ready,
            set_region,
            set_ocr_language,
            tv_pair,
            tv_connect,
            tv_test,
            tv_forget,
            start_capture,
            stop_capture,
            set_use_ps5,
            crate::ps5::ps5_status,
            crate::ps5::psn_sign_in,
            crate::ps5::ps5_find,
            crate::ps5::ps5_link,
            crate::ps5::ps5_connect,
            crate::ps5::ps5_disconnect,
            crate::ps5::ps5_set_pin,
            crate::ps5::ps5_wake,
            crate::ps5::ps5_forget,
            crate::ps5::ps5_picture
        ])
        .run(tauri::generate_context!())
        .expect("LUGHA failed to start");
}
