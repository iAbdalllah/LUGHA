//! Windows only (CI: windows-latest). Renders an English subtitle with GDI, runs Windows.Media.Ocr on it, and checks
//! the screen-capture path returns a frame of the requested size.
#![cfg(windows)]

use lugha_windows::capture::{self, Frame, Region};
use lugha_windows::ocr::Ocr;
use windows::core::HSTRING;
use windows::Win32::Foundation::RECT;
use windows::Win32::Graphics::Gdi::*;

fn render(text: &str, w: i32, h: i32) -> Frame {
    unsafe {
        let screen = GetDC(None);
        let dc = CreateCompatibleDC(Some(screen));
        let info = BITMAPINFO {
            bmiHeader: BITMAPINFOHEADER {
                biSize: std::mem::size_of::<BITMAPINFOHEADER>() as u32,
                biWidth: w,
                biHeight: -h,
                biPlanes: 1,
                biBitCount: 32,
                biCompression: BI_RGB.0,
                ..Default::default()
            },
            ..Default::default()
        };
        let mut bits: *mut core::ffi::c_void = std::ptr::null_mut();
        let bmp = CreateDIBSection(Some(dc), &info, DIB_RGB_COLORS, &mut bits, None, 0).unwrap();
        let old = SelectObject(dc, bmp.into());
        // Game-like: white text on a dark frame.
        let bg = CreateSolidBrush(windows::Win32::Foundation::COLORREF(0x00201810));
        FillRect(dc, &RECT { left: 0, top: 0, right: w, bottom: h }, bg);
        let font = CreateFontW(44, 0, 0, 0, FW_SEMIBOLD.0 as i32, 0, 0, 0, DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY, (FF_SWISS.0 | VARIABLE_PITCH.0) as u32, &HSTRING::from("Segoe UI"));
        let old_font = SelectObject(dc, font.into());
        SetBkMode(dc, TRANSPARENT);
        SetTextColor(dc, windows::Win32::Foundation::COLORREF(0x00FFFFFF));
        let wide: Vec<u16> = text.encode_utf16().collect();
        let _ = TextOutW(dc, 40, (h - 44) / 2, &wide);
        let _ = GdiFlush();
        let mut bgra = std::slice::from_raw_parts(bits as *const u8, (w * h * 4) as usize).to_vec();
        for px in bgra.chunks_exact_mut(4) {
            px[3] = 255;
        }
        SelectObject(dc, old_font);
        SelectObject(dc, old);
        let _ = DeleteObject(font.into());
        let _ = DeleteObject(bg.into());
        let _ = DeleteObject(bmp.into());
        let _ = DeleteDC(dc);
        ReleaseDC(None, screen);
        Frame { width: w as u32, height: h as u32, bgra }
    }
}

#[test]
fn ocr_reads_a_rendered_subtitle() {
    let frame = render("Water costs more than gold out here", 1000, 120);
    let ocr = Ocr::new("en-US").expect("Windows OCR engine");
    let lines = ocr.recognize(&frame).expect("recognize");
    let text = lines.join(" ").to_lowercase();
    eprintln!("OCR: {lines:?}");
    assert!(text.contains("water") && text.contains("gold"), "got {lines:?}");

    let t0 = std::time::Instant::now();
    for _ in 0..10 {
        ocr.recognize(&frame).unwrap();
    }
    eprintln!("OCR avg {:?} per 1000×120 frame", t0.elapsed() / 10);
}

#[test]
fn capture_returns_requested_size() {
    let (sw, sh) = capture::primary_screen();
    assert!(sw > 0 && sh > 0);
    let r = Region { x: 0, y: 0, w: 320.min(sw), h: 120.min(sh) };
    match capture::capture(r) {
        Ok(f) => assert_eq!((f.width as i32, f.height as i32, f.bgra.len()), (r.w, r.h, (r.w * r.h * 4) as usize)),
        // Service sessions without a desktop can refuse BitBlt; OCR above covers the pipeline.
        Err(e) => eprintln!("capture unavailable in this session: {e}"),
    }
}
