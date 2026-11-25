//! PS5 as the game source, without Sony's PS Remote Play app: LUGHA signs in to PSN (Sony's own page in a window),
//! links this PC with the console once (the 8-digit code from the PS5), then opens its own Remote Play session and
//! reads the picture. The player keeps playing on the TV with the controller. Built on lugha-rp (chiaki-ng,
//! AGPL-3.0).

use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde::{Deserialize, Serialize};
use tauri::{Emitter, Manager};

/// The linked console (not secret; the registration keys live in the vault).
#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct Console {
    pub host: String,
    pub name: String,
    pub ps5: bool,
    pub mac: String,
}

#[derive(Serialize, Clone)]
pub struct Found {
    host: String,
    name: String,
    ps5: bool,
    ready: bool,
    app: String,
}

#[derive(Serialize)]
pub struct Ps5Status {
    psn: bool,
    console: Option<Console>,
    linked: bool,
    /// "off" | "connecting" | "connected" | "pin" | an error message
    state: String,
}

/// The live session and its latest picture.
#[derive(Default)]
pub struct Ps5 {
    session: Mutex<Option<lugha_rp::Session>>,
    latest: Arc<Mutex<Option<lugha_rp::Picture>>>,
    state: Arc<Mutex<String>>,
}

impl Ps5 {
    /// The newest picture as top-down BGRA (what Windows OCR reads), or None before the first one.
    pub fn frame_bgra(&self) -> Option<(u32, u32, Vec<u8>)> {
        let p = self.latest.lock().ok()?.clone()?;
        let mut bgra = p.rgba;
        for px in bgra.chunks_exact_mut(4) {
            px.swap(0, 2);
        }
        if let Some(s) = self.session.lock().unwrap().as_ref() {
            lugha_rp::keep_picture_healthy(s);
        }
        Some((p.width, p.height, bgra))
    }

    pub fn connected(&self) -> bool {
        self.session.lock().map(|s| s.is_some()).unwrap_or(false) && *self.state.lock().unwrap() == "connected"
    }

    fn state(&self) -> String {
        let s = self.state.lock().unwrap().clone();
        if s.is_empty() { "off".into() } else { s }
    }
}

type Shared = crate::app::SharedCore;
type Cmd<T> = Result<T, String>;

const VAULT_ACCOUNT: &str = "psn_account";
const VAULT_REGIST: &str = "ps5_registration";

fn account() -> Option<[u8; 8]> {
    crate::app::vault_get(VAULT_ACCOUNT).and_then(|s| lugha_rp::account_id(&s).ok())
}

fn registration(c: &Console) -> Option<lugha_rp::Registration> {
    crate::app::vault_get(VAULT_REGIST).and_then(|s| lugha_rp::Registration::from_string_compact(&s, &c.name, &c.mac))
}

#[tauri::command(async)]
pub fn ps5_status(core: tauri::State<'_, Shared>, ps5: tauri::State<'_, Ps5>) -> Cmd<Ps5Status> {
    let console = crate::app::with_core(&core, |c| c.settings.ps5.clone());
    let linked = console.as_ref().and_then(registration).is_some();
    Ok(Ps5Status { psn: account().is_some(), console, linked, state: ps5.state() })
}

/// Opens Sony's sign-in page; when Sony redirects back, the account id is fetched and saved. The UI hears
/// "psn-signed-in" (ok) or "psn-error" (message). Async on purpose: a window built from a synchronous command
/// (the main thread) stays blank on Windows (WebView2 deadlock).
#[tauri::command(async)]
pub fn psn_sign_in(app: tauri::AppHandle) -> Cmd<()> {
    if let Some(w) = app.get_webview_window("psn") {
        let _ = w.set_focus();
        return Ok(());
    }
    let url: tauri::Url = lugha_rp::psn::authorize_url().parse().map_err(|_| "bad sign-in address".to_string())?;
    let handle = app.clone();
    tauri::WebviewWindowBuilder::new(&app, "psn", tauri::WebviewUrl::External(url))
        .title("PlayStation Network")
        .inner_size(480.0, 720.0)
        .center()
        .on_navigation(move |u| {
            let Some(code) = lugha_rp::psn::code_from_redirect(u.as_str()) else { return true };
            let app = handle.clone();
            std::thread::spawn(move || {
                if let Some(w) = app.get_webview_window("psn") {
                    let _ = w.close();
                }
                match lugha_rp::psn::account_id_from_code(&code) {
                    Ok(id) => {
                        let _ = crate::app::vault_set(VAULT_ACCOUNT, &u64::from_le_bytes(id).to_string());
                        let _ = app.emit("psn-signed-in", ());
                    }
                    Err(e) => {
                        let _ = app.emit("psn-error", e.to_string());
                    }
                }
            });
            false
        })
        .build()
        .map_err(|e| e.to_string())?;
    Ok(())
}

/// Looks for consoles on this network for ~3 seconds.
#[tauri::command(async)]
pub fn ps5_find() -> Cmd<Vec<Found>> {
    let found: Arc<Mutex<Vec<lugha_rp::Console>>> = Arc::default();
    let f = found.clone();
    let d = lugha_rp::Discovery::start(move |list| *f.lock().unwrap() = list).map_err(|e| e.to_string())?;
    std::thread::sleep(Duration::from_secs(3));
    drop(d);
    let list = found.lock().unwrap().clone();
    Ok(list.into_iter().map(|c| Found { host: c.host, name: c.name, ps5: c.ps5, ready: c.ready, app: c.running_app }).collect())
}

/// Links this PC with the console using the 8-digit code it shows (Settings › System › Remote Play › Link Device).
#[tauri::command(async)]
pub fn ps5_link(core: tauri::State<'_, Shared>, host: String, name: String, ps5: bool, pin: String) -> Cmd<Console> {
    let id = account().ok_or("سجّل الدخول إلى PlayStation Network أولاً.")?;
    let pin: u32 = pin.trim().replace(' ', "").parse().map_err(|_| "الرمز ٨ أرقام.".to_string())?;
    let reg = lugha_rp::register(host.trim(), id, pin, ps5).map_err(|e| match e {
        lugha_rp::Error::Timeout => "لم يرد الجهاز. تأكد أنه يعرض الرمز وأنه على نفس الشبكة.".to_string(),
        e => format!("رفض الجهاز الربط ({e}). تأكد من الرمز ومن حساب PSN نفسه المسجل على الجهاز."),
    })?;
    crate::app::vault_set(VAULT_REGIST, &reg.to_string_compact())?;
    let console = Console { host: host.trim().into(), name: if reg.nickname.is_empty() { name } else { reg.nickname.clone() }, ps5, mac: reg.mac };
    crate::app::with_core(&core, |c| {
        c.settings.ps5 = Some(console.clone());
        c.settings.save();
    });
    Ok(console)
}

/// Starts the session (no-op when already connected). The UI hears "ps5-state" with the new state.
#[tauri::command(async)]
pub fn ps5_connect(app: tauri::AppHandle, core: tauri::State<'_, Shared>, ps5: tauri::State<'_, Ps5>) -> Cmd<()> {
    connect(&app, &core, &ps5)
}

pub fn connect(app: &tauri::AppHandle, core: &Shared, ps5: &Ps5) -> Cmd<()> {
    if ps5.session.lock().unwrap().is_some() {
        return Ok(());
    }
    let console = crate::app::with_core(core, |c| c.settings.ps5.clone()).ok_or("اربط جهاز PS5 أولاً.")?;
    let reg = registration(&console).ok_or("اربط جهاز PS5 أولاً.")?;
    let id = account().ok_or("سجّل الدخول إلى PlayStation Network أولاً.")?;
    let opts = lugha_rp::Options { host: console.host.clone(), ps5: console.ps5, account_id: id, registration: reg, height: 720, pictures_per_second: 6.0 };
    *ps5.state.lock().unwrap() = "connecting".into();
    let _ = app.emit("ps5-state", "connecting");
    let latest = ps5.latest.clone();
    let state = ps5.state.clone();
    let app2 = app.clone();
    let session = lugha_rp::Session::start(
        &opts,
        move |p| *latest.lock().unwrap() = Some(p),
        move |s| {
            let v = match s {
                lugha_rp::State::Connected => "connected".to_string(),
                lugha_rp::State::PinRequested => "pin".to_string(),
                lugha_rp::State::Ended(r) => if r.is_empty() { "off".into() } else { r },
            };
            *state.lock().unwrap() = v.clone();
            let _ = app2.emit("ps5-state", v);
        },
    )
    .map_err(|e| e.to_string())?;
    *ps5.session.lock().unwrap() = Some(session);
    Ok(())
}

#[tauri::command(async)]
pub fn ps5_disconnect(app: tauri::AppHandle, ps5: tauri::State<'_, Ps5>) -> Cmd<()> {
    disconnect(&ps5);
    let _ = app.emit("ps5-state", "off");
    Ok(())
}

pub fn disconnect(ps5: &Ps5) {
    let s = ps5.session.lock().unwrap().take();
    drop(s); // ends the session (joins chiaki's threads) outside the lock
    *ps5.latest.lock().unwrap() = None;
    *ps5.state.lock().unwrap() = "off".into();
}

#[tauri::command(async)]
pub fn ps5_set_pin(ps5: tauri::State<'_, Ps5>, pin: String) -> Cmd<()> {
    if let Some(s) = ps5.session.lock().unwrap().as_ref() {
        s.set_pin(pin.trim());
    }
    Ok(())
}

#[tauri::command(async)]
pub fn ps5_wake(core: tauri::State<'_, Shared>) -> Cmd<()> {
    let console = crate::app::with_core(&core, |c| c.settings.ps5.clone()).ok_or("اربط جهاز PS5 أولاً.")?;
    let reg = registration(&console).ok_or("اربط جهاز PS5 أولاً.")?;
    lugha_rp::wakeup(&console.host, &reg, console.ps5).map_err(|e| e.to_string())
}

#[tauri::command(async)]
pub fn ps5_forget(core: tauri::State<'_, Shared>, ps5: tauri::State<'_, Ps5>) -> Cmd<()> {
    disconnect(&ps5);
    crate::app::vault_delete(VAULT_REGIST);
    crate::app::with_core(&core, |c| {
        c.settings.ps5 = None;
        c.settings.use_ps5 = false;
        c.settings.save();
    });
    Ok(())
}

/// The latest picture as a PNG data URL (960 px wide at most), for drawing reading areas on it.
#[tauri::command(async)]
pub fn ps5_picture(ps5: tauri::State<'_, Ps5>) -> Cmd<Option<String>> {
    let Some(p) = ps5.latest.lock().unwrap().clone() else { return Ok(None) };
    Ok(Some(png_data_url(&p, 960)))
}

pub fn png_data_url(p: &lugha_rp::Picture, max_w: u32) -> String {
    use base64::Engine;
    let scale = (p.width as f32 / max_w as f32).max(1.0);
    let (w, h) = (((p.width as f32) / scale) as u32, ((p.height as f32) / scale) as u32);
    let mut rgb = Vec::with_capacity((w * h * 3) as usize);
    for y in 0..h {
        let sy = ((y as f32 * scale) as u32).min(p.height - 1);
        for x in 0..w {
            let sx = ((x as f32 * scale) as u32).min(p.width - 1);
            let i = ((sy * p.width + sx) * 4) as usize;
            rgb.extend_from_slice(&p.rgba[i..i + 3]);
        }
    }
    let mut out = Vec::new();
    {
        let mut e = png::Encoder::new(&mut out, w, h);
        e.set_color(png::ColorType::Rgb);
        e.set_compression(png::Compression::Fast);
        if let Ok(mut wr) = e.write_header() {
            let _ = wr.write_image_data(&rgb);
        }
    }
    format!("data:image/png;base64,{}", base64::engine::general_purpose::STANDARD.encode(out))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn preview_is_a_small_png() {
        let p = lugha_rp::Picture { width: 1280, height: 720, rgba: vec![200; 1280 * 720 * 4] };
        let url = png_data_url(&p, 640);
        assert!(url.starts_with("data:image/png;base64,"));
        assert!(url.len() < 200_000);
    }
}
