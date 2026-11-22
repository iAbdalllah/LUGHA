# LUGHA backend: Store, Admin, API (Phase 1)

Laravel 13 · Filament 5 · PHP 8.4. Architecture and schema rationale: [../docs](../docs).

## Run locally

```bash
export PATH=/usr/local/opt/php@8.4/bin:$PATH   # PHP 8.3+ required
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed && php artisan storage:link
php artisan serve --port=8040
```

- Store: `http://127.0.0.1:8040/ar` (or `/en`). Admin: `/admin`.
- Demo accounts (seeded only outside production): `admin@lugha.test` and `player@lugha.test`, password `password`.
- Demo games are **fictional placeholders**. Production never seeds games or packages; they're created in the admin.
- `PAYMENT_GATEWAY=fake` sends checkout to `/dev/fake-pay/…`, where you pick success or failure. That route doesn't exist in production, and `GatewayManager` refuses the fake gateway there.
- Local dev uses SQLite by default. For PostgreSQL (the production engine), run `docker compose up -d` in `lugha/` and use the `pgsql` settings commented in `.env.example`.
- Staff 2FA: the admin requires an authenticator app (`ADMIN_MFA_REQUIRED=true`, the default). Only a developer machine should set it to `false`.

```bash
php artisan test        # 64 tests: pricing, checkout, webhooks, refunds, gifts, ZATCA, API v1, 2FA, workflow, import/export, pages, access
vendor/bin/pint         # code style
```

## Layout

| Path | What |
|---|---|
| `app/Domain/Commerce` | `CartPricer` (the only place prices are computed), `CreateOrderFromCart`, `FulfillOrder`, `RefundOrderItem`, `RedeemGiftCode` |
| `app/Domain/Payments` | `PaymentGateway` interface, `MoyasarGateway` (the provider), `HyperPayGateway` (dormant alternative), `FakeGateway`, `StartPayment`, `VerifyPayment` |
| `app/Domain/Invoicing` | `ZatcaQr`: ZATCA generation-phase TLV QR for invoices and credit notes |
| `app/Http/Controllers/Api/V1` | REST API v1 (`routes/api.php`, served at `/api/v1`), problem+json errors via `App\Support\ApiProblem` |
| `app/Domain/Entitlements` | Grants and revocations, including bundle children |
| `app/Domain/Localization` | `TextNormalizer` (shared with lugha-core), review workflow, CSV/JSON/XLSX transfer |
| `app/Filament` | Admin: games, packages & pricing, translations, glossary, orders/refunds, coupons, users, support, settings, dashboard |
| `app/Http/Controllers/Store` | Store pages, checkout, payment return, webhooks |
| `resources/views/store` | Blade + Tailwind v4, RTL/LTR via logical properties |
| `lang/ar.json`, `lang/ar/*` | Arabic UI; `lang/en.json` mirrors keys (needed on case-insensitive filesystems) |

## Rules the code enforces

- **Money is only marked paid by a server-to-server check.** The browser return URL and webhooks both call `VerifyPayment`, which asks the gateway and compares amount and currency. Webhooks are deduplicated by event id.
- **Ownership lives in `entitlements`.** Paid orders grant entitlements, and refunds revoke them along with any bundle children and redeemed gifts.
- **Rights gate.** A package can't be saved as published unless its game's rights are `licensed` or `permitted`.
- **Imports never publish.** Edited approved or published lines go back to review. Every wording or status change writes a revision.
- **Terms are frozen at purchase** in `order_items.snapshot`, and update terms are copied onto the entitlement.

## Payments: Moyasar

LUGHA uses **Moyasar**, which is the default gateway everywhere except local and test runs. For staging, set `MOYASAR_SECRET_KEY` (a test key, `sk_test_…`) and `MOYASAR_WEBHOOK_SECRET`, then point the Moyasar webhook at `POST /webhooks/payments/moyasar`. Buyers pay on Moyasar's hosted page (mada, cards, Apple Pay, STC Pay). The invoice, status and refund calls follow Moyasar's v1 API, but **they haven't been run against a live Moyasar sandbox yet**: the tests use faked HTTP responses, so do one sandbox purchase and refund before going live.

`HyperPayGateway` is implemented and tested but not used. It only switches on if `PAYMENT_GATEWAY=hyperpay` and its keys are set.

## ZATCA (Saudi e-invoicing)

- **Generation phase (done):** each paid order shows a simplified tax invoice, or a tax invoice when the buyer gives a VAT number. Invoices are numbered in sequence, carry the seller's VAT and CR numbers, break out VAT, and include the TLV QR code. Every refund issues a numbered credit note with its own QR. This needs `LUGHA_SELLER_NAME`, `LUGHA_SELLER_VAT_NUMBER`, `LUGHA_SELLER_CR_NUMBER` and `LUGHA_SELLER_ADDRESS`.
- **Integration phase (not done):** UBL 2.1 XML, cryptographic stamp, and reporting/clearance through Fatoora. This needs LUGHA's own ZATCA onboarding (CSID via an OTP from the Fatoora portal), and should be done once the company's ZATCA wave applies.

## API v1

The routes match `docs/03-api.md`: auth (Sanctum tokens with abilities `catalog:read`, `library:read`, `commerce`), catalog, cart, orders (with a required `Idempotency-Key` header), pay (returns a redirect URL), refund requests, gift codes and library. Language comes from `Accept-Language`. Device registration, licenses and downloads arrive in Phase 2.

## After Phase 1

- **Phase 2:** device registry, licenses and signed downloads, alongside the package builder. Package versions can be marked released for changelogs, but there are no files yet.
- **Before launch:** a Moyasar sandbox run, ZATCA integration-phase onboarding, final refund and privacy policy text, and real game rights agreements.
