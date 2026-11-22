//! Windows.Media.Ocr — the OCR engine built into Windows 10/11. Fully offline; uses installed language packs
//! (English ships with every Windows install).

use windows::core::HSTRING;
use windows::Globalization::Language;
use windows::Graphics::Imaging::{BitmapAlphaMode, BitmapPixelFormat, SoftwareBitmap};
use windows::Media::Ocr::OcrEngine;
use windows::Security::Cryptography::CryptographicBuffer;

use crate::capture::Frame;

pub struct Ocr {
    engine: OcrEngine,
}

impl Ocr {
    /// `lang` is the game's source language as a BCP-47 tag ("en-US"); falls back to the user's profile languages.
    pub fn new(lang: &str) -> windows::core::Result<Self> {
        unsafe {
            // WinRT needs an apartment on this thread; "already initialized" is fine.
            let _ = windows::Win32::System::WinRT::RoInitialize(windows::Win32::System::WinRT::RO_INIT_MULTITHREADED);
        }
        let engine = Language::CreateLanguage(&HSTRING::from(lang))
            .and_then(|l| OcrEngine::TryCreateFromLanguage(&l))
            .or_else(|_| OcrEngine::TryCreateFromUserProfileLanguages())?;
        Ok(Self { engine })
    }

    pub fn max_dimension() -> u32 {
        OcrEngine::MaxImageDimension().unwrap_or(2600)
    }

    /// One line of text per OCR line, top to bottom.
    pub fn recognize(&self, f: &Frame) -> windows::core::Result<Vec<String>> {
        let buffer = CryptographicBuffer::CreateFromByteArray(&f.bgra)?;
        let bitmap = SoftwareBitmap::CreateCopyWithAlphaFromBuffer(&buffer, BitmapPixelFormat::Bgra8, f.width as i32, f.height as i32, BitmapAlphaMode::Premultiplied)?;
        let result = self.engine.RecognizeAsync(&bitmap)?.join()?;
        let mut out = Vec::new();
        for line in result.Lines()? {
            let t = line.Text()?.to_string();
            if !t.trim().is_empty() {
                out.push(t);
            }
        }
        Ok(out)
    }
}
