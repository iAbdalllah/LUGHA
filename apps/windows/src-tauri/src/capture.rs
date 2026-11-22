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
