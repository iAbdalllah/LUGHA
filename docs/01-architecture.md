# 01 · System Architecture

## 1. Context

```
                         ┌───────────────────────────────────────────┐
                         │                LUGHA Cloud                 │
 Browser ── HTTPS ──────▶│  Store (Laravel + Blade/Livewire, i18n)    │
                         │  Admin (Filament)                          │
 Desktop / Mobile ─ API ▶│  REST API v1 (Sanctum tokens)              │
                         │  Realtime (Laravel Reverb, WebSocket)      │
                         │  Workers (Horizon): package builder, mail, │
                         │    webhooks, analytics rollups             │
                         │  PostgreSQL · Redis · S3 + CDN             │
                         └──────────────┬────────────────────────────┘
                                        │ signed URLs (CDN)
                ┌───────────────────────┼──────────────────────────────┐
                ▼                       ▼                              ▼
       LUGHA Desktop (macOS/Win)   LUGHA Mobile (Flutter)        LUGHA TV (Android TV)
       capture → OCR → lugha-core  library, downloads,           renders subtitles
       → overlay                   devices, remote control       received over LAN
                │                                                      ▲
                └──────────── LAN WebSocket (05-device-protocol) ──────┘
```

Payment providers (Moyasar, HyperPay) talk to the cloud only, via redirect + server-verified webhooks.

## 2. Application boundaries

| App | Owns | Never does |
|-----|------|-----------|
| **Store (web)** | Discovery, cart, checkout, account, library, downloads page, support | Run translation; store card data (provider-hosted fields only) |
| **Admin (Filament)** | Games, packages, pricing, translation editing/review, builds, orders, refunds, users, analytics | Expose public endpoints; bypass the order state machine |
| **API v1** | Auth, catalog read, entitlements, licenses, download authorization, device registry, update checks | Return package content directly (CDN does) |
| **Package builder (worker + `lugha-pkg` CLI)** | Turn approved translations into signed, encrypted `.lpk` files and deltas | Run on request threads |
| **lugha-core (Rust)** | Package read/verify/decrypt, normalization, matching, cache, delta apply, provider chain | Network I/O (callers fetch; core verifies) |
| **Desktop** | Screen capture, OCR, region config, overlay, local package store, TV sender | Upload screenshots or OCR text to the cloud |
| **Mobile** | Account, library, downloads, device management, TV remote/sender, appearance | Capture other apps' screens on iOS; on Android only via user-granted MediaProjection (Phase 4+, optional) |
| **TV** | Pair, receive, render, history | Capture HDMI input |

Rule: clients depend on the **API contract and the package spec**, never on each other's internals.

## 3. Backend (Laravel 12 · Filament 4 · PHP 8.4)

Modular monolith — one deployable, hard module boundaries (each module has its own `Models/`, `Actions/`, `Http/`, `Filament/`, events; cross-module calls go through Actions or events only):

| Module | Responsibility |
|--------|----------------|
| `Identity` | Users, roles (spatie/laravel-permission), email verification, password reset, Sanctum tokens, 2FA for staff |
| `Catalog` | Games, platforms, game versions, media, publishers |
| `Localization` | Content categories, translations, revisions, glossary, review workflow, import/export |
| `Packaging` | Packages, package composition, versions, builds, files, signing keys |
| `Commerce` | Prices, cart, orders, coupons, gift codes, upgrades, VAT, invoices, refunds |
| `Payments` | `PaymentGateway` interface, Moyasar + HyperPay adapters, webhooks, reconciliation |
| `Entitlements` | Ownership grants/revocations, update windows, licenses |
| `Devices` | Device registry, limits, sessions, key registration |
| `Delivery` | Download authorization, signed CDN URLs, download log |
| `Support` | Tickets, messages, reviews |
| `Analytics` | Event intake (minimal), daily rollups, dashboard queries |

Infrastructure: PostgreSQL 17 (primary + PITR backups), Redis (cache, queues, rate limits, Reverb scaling), S3-compatible storage (private bucket for packages, public bucket for media), CDN with signed URLs, Sentry for errors, Horizon for queues, scheduled jobs via `schedule:run`.

i18n: `lang/ar`, `lang/en`; locale from URL prefix (`/ar/…`, `/en/…`), default `ar`. All DB text that users see is stored as JSONB translatable columns (`{"ar": "...", "en": "..."}`) via spatie/laravel-translatable.

### Payments

```php
interface PaymentGateway {
    public function createPayment(Order $order, PaymentIntentData $data): PaymentSession; // returns redirect/hosted-form info
    public function verify(Payment $payment): PaymentStatus;                              // server-to-server status check
    public function handleWebhook(Request $request): WebhookResult;                       // signature verified inside
    public function refund(Payment $payment, Money $amount, string $reason): RefundResult;
}
```

- Credentials from env/secret store only; selected per environment in `config/payments.php`.
- An order becomes `paid` **only** after `verify()` or a signature-checked webhook confirms the amount and currency. The browser redirect never marks anything paid. Idempotency key per payment attempt.
- A `FakeGateway` exists for tests and is refused at boot when `APP_ENV=production`.
- Saudi VAT 15%: prices are stored and displayed VAT-inclusive; order lines store net, VAT and gross. ZATCA (Fatoora) e-invoice generation is a Commerce responsibility (Phase 1 simplified invoice; integration with ZATCA API before public launch).

## 4. The shared engine: `lugha-core` (Rust)

```
lugha-core/
  package/    open .lpk, verify manifest signature, decrypt with license key, SQLite read
  normalize/  NFKC, case-fold, punctuation/whitespace collapse, OCR confusion map
  match/      exact → hash → normalized → fuzzy (trigram + Damerau-Levenshtein) + context scoring
  cache/      LRU of recent OCR strings → results, frame de-duplication
  update/     apply signed deltas transactionally, rollback on failure
  provider/   TranslationProvider trait and chain
  ffi/        UniFFI (Swift, Kotlin) + flutter_rust_bridge + Tauri commands
lugha-pkg/    CLI: build, validate, sign, diff (used by the backend worker and CI)
```

### Translation Provider interface (the Phase 6 seam)

```rust
pub trait TranslationProvider: Send + Sync {
    fn id(&self) -> &'static str;                 // "package", later "local-llm", "cloud-x"
    fn kind(&self) -> ProviderKind;               // Package | Machine (future)
    fn lookup(&self, q: &Query) -> Option<Hit>;   // Query: text, game_id, region tag, recent context
}

pub struct ProviderChain { providers: Vec<Box<dyn TranslationProvider>> }
// v1: ProviderChain::new(vec![Box::new(PackageProvider::new(store))])
```

`Hit` carries `provider_id`, `confidence`, `entry_id` (when from a package) and a `machine_generated: bool` that clients must render with a visible marker. v1 compiles only `PackageProvider`; the chain, the flag and the settings UI slot exist so a future provider is an added crate, not an engine change.

## 5. Desktop

| | macOS (Phase 2) | Windows (Phase 4) |
|---|---|---|
| Shell | Swift 6 / SwiftUI, menu-bar app + settings window | Tauri 2 (Rust + web UI) |
| Capture | ScreenCaptureKit, only the chosen window/region, ~6–10 fps | Windows.Graphics.Capture |
| OCR | Apple Vision `VNRecognizeTextRequest` (on-device) | Windows.Media.Ocr; Tesseract fallback |
| Overlay | Borderless `NSPanel`, click-through, `.screenSaver` level, Core Text RTL | Layered transparent window, DirectWrite |
| Engine | lugha-core via UniFFI | lugha-core natively |
| Storage | `~/Library/Application Support/LUGHA/packages` | `%APPDATA%\LUGHA\packages` |
| Secrets | Keychain (device private key) | DPAPI / Credential Manager |

Pipeline per frame: capture region → skip if perceptual hash unchanged → OCR → join lines → cache check → `ProviderChain.lookup` → debounce (show when stable 2 frames, hide after N ms without text) → overlay + optional TV send. Target end-to-end ≤ 150 ms on Apple Silicon; idle CPU < 3%.

Game profiles (from the package manifest): default capture regions per game & resolution class, subtitle line-join rules, speaker-name pattern. Users can redraw regions; profiles are local.

OCR is text recognition on the user's own machine, not translation; it is permitted under the v1 "no AI translation" rule. Captured frames never leave the device.

## 6. Mobile (Phase 4) — Flutter

Account, library, download/install packages (for the device's own use: TV-sender mode), device management, TV pairing and remote, appearance settings, history. Platform channels: Kotlin (MediaProjection — optional, explicit consent, Android only), Swift (Keychain, local network permission prompt `NSLocalNetworkUsageDescription`). iOS cannot read other apps' screens: the iOS app is a companion/controller; any future ReplayKit broadcast feature needs separate App Review evaluation.

## 7. TV (Phase 3) — Kotlin + Jetpack Compose for TV

Pure renderer. NSD/mDNS advertise, pairing screen with 6-digit code + QR, WebSocket server on LAN, renders RTL with bundled Arabic font (e.g. IBM Plex Sans Arabic / Noto Naskh Arabic), D-pad-first settings (position, size, background opacity), history drawer, connection indicator. No HDMI capture.

## 8. Security model

| Asset | Control |
|---|---|
| Accounts | Argon2id passwords, email verification before purchase, rate-limited login/reset, staff 2FA required |
| API | Sanctum personal tokens per device (scoped abilities), rotated on re-auth; Redis rate limits per user+IP |
| Package integrity | Manifest signed with Ed25519 (key id in manifest; public keys pinned in apps, rotation list served signed) |
| Package confidentiality | Content encrypted AES-256-GCM with a per-version content key; license wraps the key to the device's X25519 public key |
| Ownership | License = signed JSON binding user, device, package, version range, expiry; verified offline by lugha-core |
| Downloads | One-time download token (10 min) → CDN signed URL (5 min); logged |
| Device limits | Configurable per account tier (default 3 active desktops + 2 TVs); removal revokes licenses on next sync |
| Payments | Hosted fields / redirect only; no card data touches LUGHA; webhook signatures verified |
| Privacy | No screenshots or OCR text uploaded; telemetry is opt-in, aggregated (package version, crash, match-rate buckets) |
| Admin | Role-based (Super Admin, Catalog Manager, Translator, Reviewer, Support, Finance); audit log on every write |

## 9. Environments & delivery

`local` (Docker: pgsql, redis, minio, mailpit) → `staging` → `production`. CI: Pest + PHPStan (level 8) + Pint for backend; `cargo test` + `cargo clippy` for core; XCTest for macOS; Compose UI tests for TV. Migrations run in CI against PostgreSQL. Signing keys live in a KMS/HSM; the builder signs via KMS, never with a key on disk.
