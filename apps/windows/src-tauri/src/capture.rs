//! Screen-region capture (GDI BitBlt from the desktop DC).
//!
//! v1 covers windowed and borderless-fullscreen games (the common case on modern PC titles). The LUGHA overlay is
//! excluded from capture with `WDA_EXCLUDEFROMCAPTURE` (Windows 10 2004+), so it never feeds back into OCR.
//! Exclusive-fullscreen games need Windows.Graphics.Capture — the planned upgrade path.

use windows::Win32::Foundation::HWND;
use windows::Win32::Graphics::Gdi::{
    BitBlt, CreateCompatibleBitmap, CreateCompatibleDC, DeleteDC, DeleteObject, GetDC, GetDIBits, ReleaseDC, SelectObject, BITMAPINFO,
    BITMAPINFOHEADER, BI_RGB, CAPTUREBLT, DIB_RGB_COLORS, SRCCOPY,
};
use windows::Win32::UI::WindowsAndMessaging::{GetSystemMetrics, SetWindowDisplayAffinity, SM_CXSCREEN, SM_CYSCREEN, WDA_EXCLUDEFROMCAPTURE};

#[derive(Debug, Clone, Copy, PartialEq, serde::Serialize, serde::Deserialize)]
pub struct Region {
    pub x: i32,
    pub y: i32,
    pub w: i32,
    pub h: i32,
}

impl Region {
    /// Default: the bottom quarter of the primary screen, inset a little — where most games draw subtitles.
    pub fn default_subtitles() -> Self {
        let (sw, sh) = primary_screen();
        Region { x: sw / 10, y: sh * 3 / 4, w: sw * 8 / 10, h: sh / 4 - sh / 40 }
    }
}

pub struct Frame {
    pub width: u32,
    pub height: u32,
    /// Top-down BGRA8, `width * height * 4` bytes.
    pub bgra: Vec<u8>,
}

pub fn primary_screen() -> (i32, i32) {
    unsafe { (GetSystemMetrics(SM_CXSCREEN), GetSystemMetrics(SM_CYSCREEN)) }
}

pub fn capture(r: Region) -> windows::core::Result<Frame> {
    let (w, h) = (r.w.max(1), r.h.max(1));
    unsafe {
        let screen = GetDC(None);
        let mem = CreateCompatibleDC(Some(screen));
        let bmp = CreateCompatibleBitmap(screen, w, h);
        let old = SelectObject(mem, bmp.into());
        let blit = BitBlt(mem, 0, 0, w, h, Some(screen), r.x, r.y, SRCCOPY | CAPTUREBLT);
        let mut info = BITMAPINFO {
            bmiHeader: BITMAPINFOHEADER {
                biSize: std::mem::size_of::<BITMAPINFOHEADER>() as u32,
                biWidth: w,
                biHeight: -h, // negative = top-down rows
                biPlanes: 1,
                biBitCount: 32,
                biCompression: BI_RGB.0,
                ..Default::default()
            },
            ..Default::default()
        };
        let mut bgra = vec![0u8; (w * h * 4) as usize];
        let rows = GetDIBits(mem, bmp, 0, h as u32, Some(bgra.as_mut_ptr().cast()), &mut info, DIB_RGB_COLORS);
        SelectObject(mem, old);
        let _ = DeleteObject(bmp.into());
        let _ = DeleteDC(mem);
        ReleaseDC(None, screen);
        blit?;
        if rows == 0 {
            return Err(windows::core::Error::from_thread());
        }
        // GDI leaves alpha at 0; OCR wants opaque pixels.
        for px in bgra.chunks_exact_mut(4) {
            px[3] = 255;
        }
        Ok(Frame { width: w as u32, height: h as u32, bgra })
    }
}

/// Keep a window (the overlay) out of every screen capture, including ours.
pub fn exclude_from_capture(hwnd: *mut core::ffi::c_void) -> windows::core::Result<()> {
    unsafe { SetWindowDisplayAffinity(HWND(hwnd), WDA_EXCLUDEFROMCAPTURE) }
}

// ---------- window mode (PS Remote Play and the like) ----------

use windows::core::BOOL;
use windows::Win32::Foundation::{LPARAM, RECT};
use windows::Win32::Storage::Xps::{PrintWindow, PRINT_WINDOW_FLAGS};
use windows::Win32::UI::WindowsAndMessaging::{EnumWindows, GetClientRect, GetWindowTextW, IsIconic, IsWindowVisible, PW_RENDERFULLCONTENT};

/// Visible, non-minimised top-level windows with a title, as (handle, title).
pub fn list_windows() -> Vec<(isize, String)> {
    unsafe extern "system" fn each(hwnd: HWND, out: LPARAM) -> BOOL {
        let out = &mut *(out.0 as *mut Vec<(isize, String)>);
        if IsWindowVisible(hwnd).as_bool() && !IsIconic(hwnd).as_bool() {
            let mut buf = [0u16; 256];
            let n = GetWindowTextW(hwnd, &mut buf);
            if n > 0 {
                out.push((hwnd.0 as isize, String::from_utf16_lossy(&buf[..n as usize])));
            }
        }
        BOOL(1)
    }
    let mut out: Vec<(isize, String)> = Vec::new();
    unsafe {
        let _ = EnumWindows(Some(each), LPARAM(&mut out as *mut _ as isize));
    }
    out
}

/// First window whose title contains `needle` (case-insensitive).
pub fn find_window(needle: &str) -> Option<HWND> {
    let n = needle.to_lowercase();
    list_windows().into_iter().find(|(_, t)| t.to_lowercase().contains(&n)).map(|(h, _)| HWND(h as *mut _))
}

/// Captures `area` (fractions of the client area, origin top-left) of a window — also while other windows cover it —
/// with PrintWindow(PW_RENDERFULLCONTENT), which asks the window to render itself (works for GPU-drawn video).
pub fn capture_window(hwnd: HWND, area: (f32, f32, f32, f32)) -> windows::core::Result<Frame> {
    unsafe {
        let mut rc = RECT::default();
        GetClientRect(hwnd, &mut rc)?;
        let (cw, ch) = ((rc.right - rc.left).max(1), (rc.bottom - rc.top).max(1));
        let screen = GetDC(None);
        let mem = CreateCompatibleDC(Some(screen));
        let bmp = CreateCompatibleBitmap(screen, cw, ch);
        let old = SelectObject(mem, bmp.into());
        // PW_CLIENTONLY (1) | PW_RENDERFULLCONTENT (2)
        let ok = PrintWindow(hwnd, mem, PRINT_WINDOW_FLAGS(1 | PW_RENDERFULLCONTENT)).as_bool();
        let mut info = BITMAPINFO {
            bmiHeader: BITMAPINFOHEADER {
                biSize: std::mem::size_of::<BITMAPINFOHEADER>() as u32,
                biWidth: cw,
                biHeight: -ch,
                biPlanes: 1,
                biBitCount: 32,
                biCompression: BI_RGB.0,
                ..Default::default()
            },
            ..Default::default()
        };
        let mut full = vec![0u8; (cw * ch * 4) as usize];
        let rows = GetDIBits(mem, bmp, 0, ch as u32, Some(full.as_mut_ptr().cast()), &mut info, DIB_RGB_COLORS);
        SelectObject(mem, old);
        let _ = DeleteObject(bmp.into());
        let _ = DeleteDC(mem);
        ReleaseDC(None, screen);
        if !ok || rows == 0 {
            return Err(windows::core::Error::from_thread());
        }
        let (ax, ay, aw, ah) = area;
        let x0 = ((ax.clamp(0.0, 1.0) * cw as f32) as i32).min(cw - 1);
        let y0 = ((ay.clamp(0.0, 1.0) * ch as f32) as i32).min(ch - 1);
        let w = ((aw * cw as f32) as i32).clamp(1, cw - x0);
        let h = ((ah * ch as f32) as i32).clamp(1, ch - y0);
        let mut bgra = Vec::with_capacity((w * h * 4) as usize);
        for row in y0..y0 + h {
            let start = ((row * cw + x0) * 4) as usize;
            bgra.extend_from_slice(&full[start..start + (w * 4) as usize]);
        }
        for px in bgra.chunks_exact_mut(4) {
            px[3] = 255;
        }
        Ok(Frame { width: w as u32, height: h as u32, bgra })
    }
}
