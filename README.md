# LUGHA · لغة

The Arabic game localization ecosystem: a store that sells reviewed Arabic localization packages for games, and a family of apps (macOS, Windows, Android/iOS companion, Android TV) that show those translations as subtitles while you play.

Design documents are in `docs/`. **Phase 1 is built** in [`backend/`](backend/README.md): Laravel 13 Store (ar/en), Filament admin, Moyasar checkout, ZATCA invoices and REST API v1. `docker-compose.yml` runs PostgreSQL, Redis and Mailpit; `.github/workflows/backend.yml` runs the tests on SQLite and PostgreSQL.

| # | Document | Covers |
|---|----------|--------|
| 1 | [docs/01-architecture.md](docs/01-architecture.md) | System architecture, application boundaries, tech choices, the shared Rust engine, Translation Provider interface, security model |
| 2 | [docs/02-database.md](docs/02-database.md) + [database/schema.sql](database/schema.sql) | Normalized PostgreSQL schema: catalog, packages, translations, commerce, entitlements, devices |
| 3 | [docs/03-api.md](docs/03-api.md) | REST API v1 contracts, auth, errors, webhooks, realtime channels |
| 4 | [docs/04-package-spec.md](docs/04-package-spec.md) | `.lpk` package format, manifest, signing, encryption, matching pipeline, delta updates |
| 5 | [docs/05-device-protocol.md](docs/05-device-protocol.md) | Desktop/mobile → TV pairing and subtitle streaming over LAN |
| 6 | [docs/06-roadmap.md](docs/06-roadmap.md) | Phased roadmap with exit criteria, risks, open decisions |
| 7 | [UI design canvas](https://claude.ai/artifact/TKx3718JF4fuf2dBtTyfsZ) (sources in [design/project](design/project)) | High-fidelity screens: Store home, Game & packages, Checkout, Library & devices (EN/LTR), Admin translation editor, macOS overlay, Android TV |

## Five decisions that shape everything

1. **Ownership is an entitlement, not an order.** Orders record money; `entitlements` record what a user may download. Refunds, gifts, upgrades and bundles all become entitlement changes, so every client checks one table.
2. **Packages are data, not types.** "Dialogue / Story / Full / Ultimate" are rows. A package = a set of content categories (+ optional included packages) per game. Admins build any combination.
3. **One engine, every platform.** The package reader and matcher is one Rust crate (`lugha-core`) bound into Swift (UniFFI), Tauri (native), Kotlin (UniFFI) and Flutter (flutter_rust_bridge). Matching behaves identically everywhere.
4. **Gameplay is offline.** Once a package and its license are on the device, OCR → lookup → overlay runs with no network. The cloud only sells, licenses and delivers.
5. **No AI translation in v1 — but a seam for it.** All text comes from reviewed packages. A `TranslationProvider` chain exists from day one with exactly one provider (`PackageProvider`); Phase 6 adds more without touching the engine.

## The risk to resolve before launch

Selling Arabic translations of a commercial game's script is a derivative work of that script. **Each game needs a license or written permission from its rights holder** (the brief's §11 says this; it's the gating business item, not a technical one). The architecture helps — LUGHA never touches game files, and the package format can avoid shipping the original English text in plaintext (see 04 §6) — but it does not replace the license. The `games.rights_status` column blocks publishing a package for a game whose rights aren't cleared.
