# 02 · Database Design

Full DDL: [../database/schema.sql](../database/schema.sql) (PostgreSQL 17). Laravel migrations are generated from it one module at a time in Phase 1.

## Entity map

```
games ─┬─< game_platforms >── platforms
       ├─< game_versions
       ├─< game_media
       ├─< translations >── translation_categories, localization_styles
       │      └─< translation_revisions
       ├─< translation_glossaries
       └─< localization_packages ─┬─< package_categories >── translation_categories
                                  ├─< package_inclusions (bundle → packages)
                                  ├─< package_platforms
                                  ├─< package_prices
                                  ├─< package_upgrades (from → to, price)
                                  └─< package_versions ──< package_files (full | delta)

users ─┬─< orders ──< order_items ── localization_packages
       │     └─< payments ──< refunds
       ├─< entitlements ── localization_packages      ◀── the ownership truth
       ├─< devices ─┬─< device_sessions
       │            ├─< licenses (entitlement × device × version)
       │            └─< installations
       ├─< downloads ── package_files
       ├─< reviews, support_tickets ──< support_messages
       └─< translation_reports
```

## Key rules

**Package content is a query, not a list.** A package version's entries = `translations` where `game_id` matches, `status = 'published'`, `category_id ∈ package_categories` (plus the categories of every `package_inclusions` child), `style_id`/`locale` match, and the game-version range overlaps the version's range. The builder snapshots this into the `.lpk`; the DB never stores a per-package copy of rows.

**Bundles.** A bundle is a package with `is_bundle = true` and `package_inclusions` rows. Buying it creates one entitlement for the bundle plus child entitlements (`parent_entitlement_id`) so clients and refunds reason about each package separately.

**Upgrades.** `package_upgrades(from, to, amount)` is explicit and admin-priced. Checkout offers the upgrade price only if the user holds an active entitlement for `from`. Completing it grants `to` with `source = 'upgrade'`; the `from` entitlement stays (no downgrade surprises).

**Updates included.** `update_policy` on the package, copied to the entitlement at grant time:
- `version_line` → `entitlements.update_major` = current major; any release with that major is downloadable.
- `duration` → `updates_until = granted_at + update_months`; releases with `released_at <= updates_until`.
- `lifetime` → all releases.
Changing a package's policy later never changes existing entitlements (terms at purchase are also frozen in `order_items.snapshot`).

**Order state machine.** `pending → awaiting_payment → paid | failed | cancelled`; `paid → partially_refunded → refunded`. Only the `Payments` module transitions to `paid`, inside a transaction that also creates entitlements. Refund `processed` revokes the matching entitlements (and bundle children) and their licenses.

**Money.** Integer halalas, VAT-inclusive at the line level; `orders.total_amount = subtotal − discount` is enforced by a CHECK.

**Device limit.** Effective limit = `users.device_limit_override` ?? config default per kind. Enforced in the registration Action with `SELECT … FOR UPDATE` on the user's active devices.

**Publishing guard.** A package version can move to `released` only if `games.rights_status IN ('licensed','permitted')` and the package is `published` (enforced in the Action and re-checked in a DB trigger added in Phase 1).

**Privacy.** No gameplay content is stored server-side. `translation_reports` hold an entry id and optional user note only.

## Indexing strategy

| Query | Index |
|---|---|
| Store listing by status/featured | `games_status_featured_idx` |
| Search by title | trigram GIN on `title->>'en'` (+ Arabic in Phase 1 with an `ar` expression index) |
| Game page packages | `packages_game_status_idx` |
| Translator/reviewer queues | `translations_game_status_idx`, partial `translations_reviewer_queue_idx` |
| Source-text fuzzy search in editor | trigram GIN on `translations.source_text` |
| Library | `entitlements_user_idx`, partial unique `entitlements_one_active_idx` |
| Latest release per package | partial `package_versions_released_idx` |
| Order history | `orders_user_idx` |

## Seed data (configuration, not catalog)

`platforms`, `translation_categories` (dialogues, cutscenes, side_dialogues, missions, mission_descriptions, story_notifications, menus, settings, notifications, ui, system_messages, items, tutorials, codex), `localization_styles` (msa, saudi_white_dialect, gulf), roles/permissions. Games and packages are **never** seeded in production — they're created in Filament.
