//! LUGHA for Windows. Everything except capture/OCR (Windows APIs) and the Tauri shell is plain Rust, tested on
//! any OS with `cargo test --no-default-features`.

pub mod api;
pub mod frame;
pub mod pipeline;
pub mod store;
pub mod sync;
pub mod tv;

#[cfg(windows)]
pub mod capture;
#[cfg(windows)]
pub mod ocr;
#[cfg(windows)]
pub mod secrets;

#[cfg(feature = "app")]
pub mod app;
#[cfg(feature = "app")]
pub mod ps5;
