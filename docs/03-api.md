# 03 · API Contracts (REST v1)

Base: `https://api.lugha.app/v1` · JSON only · `Accept-Language: ar|en` picks the language of translatable fields (both are returned when `?all_locales=1`).

## Conventions

- **Auth**: `Authorization: Bearer <sanctum token>`. Web store uses session cookies + CSRF on the same routes (Sanctum SPA mode). Device tokens carry abilities: `catalog:read`, `library:read`, `download`, `devices:manage`.
- **IDs**: public ULIDs (`"id": "01JD…"`), slugs where readable (`games/{slug}`).
- **Money**: `{"amount": 4900, "currency": "SAR", "formatted": "49.00 ر.س"}` (minor units, VAT-inclusive).
- **Pagination**: cursor — `?cursor=…&limit=20` → `{"data": [...], "next_cursor": "…" | null}`.
- **Errors** (RFC 9457 problem+json):
  ```json
  {"type": "https://lugha.app/errors/device-limit", "title": "Device limit reached",
   "status": 409, "code": "device_limit_reached", "detail": "…", "errors": {"field": ["…"]}}
  ```
- **Rate limits**: auth 10/min/IP; download authorize 30/h/user; everything else 120/min/token. `429` with `Retry-After`.
- **Idempotency**: `Idempotency-Key` header required on `POST /orders` and `POST /orders/{id}/pay`.

## Auth & account

| Method | Path | Body → Response |
|---|---|---|
| POST | `/auth/register` | `{name,email,password,locale}` → `201 {user}` + verification mail |
| POST | `/auth/login` | `{email,password,device?: DeviceRegistration}` → `{token, user, device?}` |
| POST | `/auth/logout` | → `204` (revokes current token) |
| POST | `/auth/email/resend` | → `202` |
| POST | `/auth/password/forgot` | `{email}` → `202` (always) |
| POST | `/auth/password/reset` | `{token,email,password}` → `204` |
| GET  | `/me` | → `{user}` |
| PATCH| `/me` | `{name?,locale?,marketing_opt_in?}` → `{user}` |

Desktop/TV sign-in uses the **device code flow**: `POST /auth/device-code {device}` → `{user_code:"7K4-Q9P", verification_url, device_code, interval:5}`; the app polls `POST /auth/device-token {device_code}` until the user approves at `lugha.app/activate`. No passwords typed on TVs.

## Catalog (public)

| Method | Path | Notes |
|---|---|---|
| GET | `/games?platform=pc&q=&sort=featured|new|updated&cursor=` | list cards |
| GET | `/games/{slug}` | full game: metadata, platforms, versions, media, packages (summary) |
| GET | `/games/{slug}/packages` | packages with categories, coverage, price, upgrade offers for the caller |
| GET | `/packages/{slug}` | package detail: categories, inclusions, versions/changelog, compatibility, reviews summary |
| GET | `/home` | curated sections: featured, recently_added, popular_packages, recently_updated, platforms |
| GET | `/categories`, `/platforms`, `/styles` | config lists |

`Package` shape:
```json
{
  "id": "01JD…", "slug": "gta6-sa-full", "game": {"slug": "gta6", "title": "…"},
  "name": "التعريب الكامل", "summary": "…", "tier_rank": 3, "is_bundle": false,
  "locale": "ar-SA", "style": {"slug": "saudi_white_dialect", "name": "لهجة سعودية بيضاء"},
  "categories": [{"slug": "dialogues", "name": "الحوارات", "coverage": 0.97}],
  "includes": [],
  "platforms": ["pc", "ps5"],
  "price": {"base": {...money}, "sale": {...money} | null, "sale_ends_at": null},
  "upgrade_offer": {"from": "gta6-sa-dialogue", "price": {...money}} | null,
  "update_terms": {"policy": "version_line", "major": 1, "label": "كل تحديثات الإصدار 1.x"},
  "latest_version": {"version": "1.4.0", "released_at": "2026-11-02", "game_versions": "1.00–1.06"},
  "owned": false
}
```

## Cart & checkout

| Method | Path | Notes |
|---|---|---|
| GET | `/cart` | lines, coupon, totals (net/VAT/gross), warnings (already owned, upgrade applied) |
| POST | `/cart/items` | `{package, as_gift?}` |
| DELETE | `/cart/items/{package}` | |
| POST | `/cart/coupon` | `{code}` → `422 coupon_invalid|coupon_expired|coupon_not_applicable` |
| POST | `/orders` | creates order from cart (prices re-computed server-side) → `{order}` status `pending` |
| POST | `/orders/{id}/pay` | `{gateway:"moyasar", method:"mada|creditcard|applepay|stcpay", return_url}` → `{payment_id, action: {type:"redirect"|"hosted_form", url, params}}` |
| GET | `/orders/{id}` | status polling after return; becomes `paid` only once verified |
| GET | `/orders` | history |
| GET | `/orders/{id}/invoice` | PDF (ZATCA simplified tax invoice with QR) |
| POST | `/gift-codes/redeem` | `{code}` → `{entitlement}` |
| POST | `/orders/{id}/refund-requests` | `{order_item_id, reason}` → `202` (handled by Finance in admin) |

Webhooks (server ← gateway): `POST /webhooks/moyasar`, `POST /webhooks/hyperpay`. Signature checked, stored in `payment_webhook_events` (unique event id → idempotent), then `VerifyPayment` job confirms via the gateway API before transitioning.

## Library, devices, delivery

| Method | Path | Notes |
|---|---|---|
| GET | `/library` | active entitlements with package, latest downloadable version, installed state per device |
| GET | `/devices` | |
| POST | `/devices` | `{kind,name,model,os_version,app_version,public_key(base64)}` → `201 {device}` or `409 device_limit_reached` (body lists active devices so the client can offer removal) |
| DELETE | `/devices/{id}` | revoke device, its tokens, its licenses |
| POST | `/devices/{id}/heartbeat` | `{app_version, installations:[{package, version}]}` → `{licenses_to_refresh:[…], revoked:[…]}` |
| GET | `/updates?device={id}` | for each installed package: newest entitled version + best file (delta from installed if available) |
| POST | `/downloads` | `{package, version?, device, from_version?}` → `{download_id, url (CDN signed, 5 min), sha256, size, kind, license}` |
| POST | `/downloads/{id}/complete` | `{sha256}` → `204` |
| POST | `/licenses/refresh` | `{device, packages:[…]}` → `{licenses:[…]}` (extends offline window) |
| POST | `/translation-reports` | `{package, version?, entry_id, reason, kind?: report\|suggestion, suggested_text?, note?, source?}` → `202 {id, status}` — owners only; `suggestion` needs an approved contributor; one open item per person per line |
| GET | `/me/feedback` | the caller's reports and suggestions with their status |
| GET | `/packages/{slug}/reviews` | public: `{summary: {average, count, distribution, qa_score, qa}, data, next_cursor}` |
| POST / DELETE | `/packages/{slug}/reviews` | owners: `{rating 1–5, title?, body?}` creates or edits the caller's review |

`license` (verified offline by lugha-core). **As implemented**, the payload travels as base64 of the exact signed JSON bytes, is bound to one package *version*, and uses unix timestamps:
```json
{"v": 1, "payload": "base64({\"v\":1,\"license_id\",\"user\",\"device_fp\",\"package_id\",\"version\",\"content_key_wrapped\",\"issued_at\",\"expires_at\"})",
 "key_id": "pk-2026-11", "sig": "base64(ed25519(payload bytes))"}
```
Implemented device/delivery endpoints: `GET /trust`, `POST /auth/device-code`, `POST /auth/device-token`, `GET|POST /devices`, `DELETE /devices/{id}`, `POST /devices/{id}/heartbeat`, `POST /devices/{id}/licenses`, `POST /downloads`, `GET /downloads/{id}/file` (signed URL), `POST /downloads/{id}/complete`.

Original sketch:
```json
{
  "payload": {
    "v": 1, "license_id": "01JD…", "user": "01JC…", "device_fp": "sha256…",
    "package_id": "gta6-sa-full", "versions": {"major": 1} ,
    "content_key_wrapped": "base64(X25519-ECDH + HKDF + AES-GCM of content key)",
    "issued_at": "2026-11-22T10:00:00Z", "expires_at": "2027-01-21T10:00:00Z"
  },
  "key_id": "lk-2026-01", "sig": "base64(ed25519 over canonical JSON of payload)"
}
```
Offline window default 60 days; renewed silently on any online launch.

## Realtime (Laravel Reverb, Pusher protocol)

Private channels, authorized via `/broadcasting/auth`:

| Channel | Events |
|---|---|
| `private-user.{id}` | `entitlement.granted`, `entitlement.revoked`, `order.paid`, `package.update_available`, `device.removed` |
| `private-device.{id}` | `license.revoked`, `config.changed` |

Used so a desktop app shows the package the moment checkout completes in the browser. The desktop↔TV link does **not** go through the cloud — see 05.

## Admin

Filament talks to the domain Actions directly (no public admin API). An internal `/internal/v1/builds/{id}/callback` endpoint (mTLS/HMAC) lets the builder report progress if it runs as a separate service later.
