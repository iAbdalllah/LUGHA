# LUGHA for Windows (Tauri 2)

Captures the game's English subtitle area, recognizes it with Windows' built-in OCR, matches it against installed packages with the Rust engine, and shows the reviewed Arabic line in a click-through overlay — and on a paired LUGHA TV.

```
GDI region capture ─► Windows.Media.Ocr ─► stable-frame pipeline ─► lugha-core Matcher ─► overlay window
   (overlay excluded with WDA_EXCLUDEFROMCAPTURE)                                     └─► LUGHA TV (lugha-link)
```

| Path | What |
|---|---|
| `src-tauri/src/api.rs` | REST API v1 client (login, device code, devices, library, downloads, heartbeat, licenses, report a line) |
| `src-tauri/src/sync.rs`, `store.rs` | install / delta / fallback / revoke; packages in `%APPDATA%\LUGHA\packages` |
| `src-tauri/src/pipeline.rs` | OCR lines → subtitle (2 stable frames, hide after 1.2 s of silence) |
| `src-tauri/src/tv.rs` | LUGHA Link sender (SPAKE2 pairing, Noise IK session) over TCP |
| `src-tauri/src/capture.rs`, `ocr.rs`, `secrets.rs` | Windows only: GDI capture, Windows.Media.Ocr, Credential Manager |
| `src-tauri/src/app.rs` | Tauri commands, capture loop, overlay |
| `ui/` | Arabic UI (`index.html`) and overlay (`overlay.html`); opened in a plain browser it runs on a mock for layout work |

Secrets (API token, device key, TV link key) live in Windows Credential Manager under `LUGHA/*`; settings and the cached trust list in `%APPDATA%\LUGHA`.

## Build & test

```bash
cd src-tauri
cargo test --no-default-features                                            # core on any OS: pipeline, store, TV pairing/session/latency
node --test ui/test/overlay.test.cjs                                         # overlay display rules (block, read-hold, history)
LUGHA_API=http://127.0.0.1:8040 cargo test --no-default-features --test live_api   # against a running backend
cargo test --no-default-features                                            # on Windows this also runs the OCR + capture tests
cargo install tauri-cli --version "^2" && cargo tauri build                  # Windows: NSIS + MSI installers
```

`.github/workflows/windows.yml` runs the core tests on Linux and the full suite plus the installer build on `windows-latest`.

**Verified (on macOS):** core tests (5) and the live API test (login → register a `windows` device → install → Arabic match → device removed); the Windows modules and the Windows OCR test type-check against `windows` 0.62 for `x86_64-pc-windows-msvc`; the Tauri shell compiles. **Not yet verified:** running on Windows — the first CI run on `windows-latest` (or a Windows PC) is the real test of capture, OCR and the overlay.

**Limits in v1:** GDI capture works for windowed and borderless-fullscreen games; exclusive-fullscreen games need Windows.Graphics.Capture (planned). The TV is added by IP:port + code (shown on the TV); mDNS discovery on Windows is a follow-up.
