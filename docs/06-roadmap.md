# 06 · Product Roadmap

Each phase ships something usable and ends with explicit exit criteria. Phase 6 (AI providers) is out of scope until the platform is commercially live.

## Phase 1 — Commerce foundation (Store, backend, admin)

**Status (2026-11-22): built** in `backend/`. 64 tests pass on both PostgreSQL 17 and SQLite. Before launch: a Moyasar sandbox run, ZATCA integration-phase onboarding, and final refund/privacy copy. Payment provider: Moyasar (HyperPay is implemented but dormant).

- Laravel 12 modular monolith, PostgreSQL, Redis, S3, Horizon, Reverb, Sentry; Docker dev env; CI.
- Identity: register, verify email, login, reset, profile, roles; staff 2FA.
- Catalog + Filament: games, platforms, versions, media, rights status.
- Packaging (admin side): packages, categories, inclusions, prices, upgrades, publish/unpublish.
- Localization editor: translations CRUD, statuses + review queue, revisions, glossary, CSV/JSON/XLSX import/export.
- Store (ar default, en): Home, Store, Game, Package, Cart, Checkout, Library, Purchases, Downloads, Profile, Support.
- Commerce: cart, coupons, gift codes, bundles, upgrades, VAT, orders, invoices, refunds; Moyasar adapter first, HyperPay second.
- Entitlements + library.
- **Exit**: a real (sandbox-gateway) purchase on staging grants an entitlement visible in Library; refund revokes it; all pages work in ar/en; Pest feature tests for order/payment/entitlement flows.

## Phase 2 — Package engine + macOS MVP

**Status (2026-11-22): built** in `engine/` (lugha-core, lugha-pkg, lugha-ffi), `backend/` (devices, device-code sign-in, builder, licenses, downloads, updates) and `apps/macos/` (menu-bar app: sign-in, sync, ScreenCaptureKit capture, Vision OCR, RTL overlay). Verified: engine tests + 50k benchmark, PHP↔Rust license interop, Swift OCR→engine test, live app↔backend sync. Not yet: Developer ID signing/notarization, KMS-backed signing, CDN delivery, real-game accuracy trials (exit criterion ≥95% on a licensed title).

- `lugha-core` (normalize, match, verify, decrypt, delta) with benchmark suite; `lugha-pkg` build/validate/sign/diff.
- Builder job in backend, build reports in Filament, KMS signing, licenses, download authorization, CDN.
- Devices API, device-code sign-in, limits.
- macOS app: sign-in, library, install/update, game profile select, region picker, ScreenCaptureKit + Vision OCR, overlay, appearance, history, report-a-line.
- **Exit**: on a test game with a licensed package, ≥ 95% of on-screen dialogue lines matched with ≤ 150 ms latency, offline, < 3% idle CPU; tampered package rejected.

## Phase 3 — TV

**Status (2026-11-22): built** in `apps/tv/` (Kotlin + Compose for TV receiver: pairing code + QR, encrypted sessions, RTL renderer, D-pad settings, history, optional overlay over other apps, mDNS) and the macOS app's *Send to TV*. Verified on an Android 14 emulator: pairing, wrong-code lockout, unpaired senders refused, Mac → TV p95 ≈ 45–49 ms incl. paint at 1080p, correct Arabic shaping (screenshots). Not yet: a run on real Android TV hardware, Play signing, and a TV system image test (no TV image was installed locally).

- Android TV app (Compose for TV): mDNS, SPAKE2 pairing, Noise session, renderer, D-pad settings, history.
- macOS sender mode.
- **Exit**: pair in < 30 s; p95 latency ≤ 50 ms on LAN; correct Arabic shaping with mixed Latin names.

## Phase 4 — Windows, Android, iOS

**Status (2026-11-22): built, partly verified.** Flutter app in `apps/mobile/` (account, library with delta installs and license refresh through the Rust engine via `dart:ffi`, devices with device-limit handling, TV discovery/pairing/sender/style remote, history) — integration tests pass on an Android 14 emulator and the iPhone 17 Pro simulator; phone → TV p95 ≈ 20 ms incl. paint. Windows app in `apps/windows/` (Tauri 2, GDI capture with the overlay excluded, Windows.Media.Ocr, Credential Manager, TV sender) — core and live-API tests pass on macOS and the Windows code type-checks for MSVC, but it has **not run on Windows yet** (first `windows-latest` CI run pending). Not done: Android MediaProjection capture (optional), Windows.Graphics.Capture for exclusive fullscreen, mDNS discovery on Windows, physical devices, store review.

- Windows (Tauri 2 + WGC + Windows OCR).
- Flutter mobile: account, library, downloads, devices, TV remote/sender, appearance. Android optional MediaProjection capture behind consent.
- **Exit**: feature parity of package manager across platforms; store-review approvals.

## Phase 5 — Growth

Advanced analytics, community translation contributions (contributor role → existing review workflow), public reviews & QA scores, subscriptions if the numbers support it.

## Phase 6 — Optional AI providers (not now)

New `TranslationProvider` crates (local model, cloud) behind user opt-in, always marked `machine_generated`. No engine changes required.

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| No rights agreements with publishers | Cannot legally sell | `rights_status` publish gate; start with indie/AA partners who opt in; minimized source mode |
| OCR quality on stylized fonts | Missed lines | Per-game profiles, aliases, fuzzy + context; report-a-line loop |
| Anti-cheat flags overlays | Users banned | Overlay is a separate OS window (no injection, no game memory reads); document per game; test before listing |
| Console players (no desktop capture) | Smaller market | TV + capture-card path on desktop; phone-camera mode evaluated in Phase 5 |
| Payment/ZATCA compliance | Launch delay | Moyasar first; e-invoicing in Phase 1 scope |

## Open decisions for the owner

1. Store frontend: Blade + Livewire (fastest with Filament, recommended) vs a separate Next.js app on the same API.
2. Launch catalog: which first 3–5 games, with which rights holders.
3. Default device limits and offline license window (proposed 3 desktops + 2 TVs, 60 days).
4. Refund policy terms (e.g. within 14 days if not downloaded).
