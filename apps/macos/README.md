# LUGHA for Mac

Menu-bar app (SwiftUI, macOS 14+) that reads the game's subtitle area on-device and shows the reviewed Arabic line on top.

**Pipeline:** ScreenCaptureKit (one region, ~8 fps, skips unchanged frames) → Apple Vision OCR (on-device) → `lugha-core` lookup (Rust, offline) → click-through RTL overlay. Frames and text never leave the Mac.

## Build & run

```bash
./scripts/make-app.sh          # builds engine (lugha-ffi) + app → build/LUGHA.app (ad-hoc signed)
open build/LUGHA.app
```

First run: choose **Library & account…**, check the server address (default `http://127.0.0.1:8040`), **Sign in** — the browser opens the activation page with the code filled in; approve it. The app registers this Mac (its key stays in the Keychain), downloads your packages and installs them. Then **Select capture region** over the game's subtitles and **Start**. macOS will ask for Screen Recording permission once.

## Code

| Path | What |
|---|---|
| `Sources/LughaKit/Engine.swift` | Swift wrapper over the Rust engine's C ABI (`engine/lugha-ffi`) |
| `Sources/LughaKit/APIClient.swift` | REST API v1: device-code sign-in, devices, library, downloads, heartbeat, licenses, report a line |
| `Sources/LughaKit/PackageStore.swift` | Installed packages + `SyncService` (installs, delta updates with full fallback, license refresh, refund removal) |
| `Sources/LughaKit/CaptureService.swift` | ScreenCaptureKit region capture; excludes LUGHA's own overlay |
| `Sources/LughaKit/OCRService.swift` | Vision text recognition (no language correction, so names survive) |
| `Sources/LughaKit/SubtitlePipeline.swift` | Stable-frame gating, hide-after-silence, history |
| `Sources/LughaApp/` | Menu-bar UI, overlay panel, region picker, Arabic/English strings |

```bash
swift test                     # engine via Swift, real Vision OCR → match, pipeline timing
LUGHA_E2E_URL=http://127.0.0.1:8040 LUGHA_E2E_EMAIL=player@lugha.test LUGHA_E2E_PASSWORD=password swift test --filter LiveSyncTests
```

## Before shipping

Developer ID signing + notarization (`SIGN_IDENTITY=... ./scripts/make-app.sh`, then `notarytool`), pin the production trust key in the app, and an accuracy trial on a licensed game (roadmap exit criterion: ≥95% of dialogue lines matched, ≤150 ms).
