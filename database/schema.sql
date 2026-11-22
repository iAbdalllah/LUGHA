-- LUGHA · PostgreSQL 17 reference schema (source of truth for Laravel migrations)
-- Conventions: bigint identity PKs; public-facing ids are ULIDs (char(26)) in `public_id`;
-- user-visible text is JSONB {"ar": "...", "en": "..."}; money is integer minor units (halalas) + ISO currency;
-- timestamps are timestamptz (UTC). Soft deletes only where history matters.

CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ───────────────────────────── Identity ─────────────────────────────
CREATE TABLE users (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id        char(26) NOT NULL UNIQUE,
  name             varchar(120) NOT NULL,
  email            citext NOT NULL UNIQUE,
  email_verified_at timestamptz,
  password         varchar(255) NOT NULL,              -- argon2id
  locale           varchar(10) NOT NULL DEFAULT 'ar-SA',
  country_code     char(2),
  status           varchar(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','closed')),
  device_limit_override smallint CHECK (device_limit_override BETWEEN 0 AND 50),
  two_factor_secret text,
  marketing_opt_in boolean NOT NULL DEFAULT false,
  last_login_at    timestamptz,
  remember_token   varchar(100),
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),
  deleted_at       timestamptz
);
-- roles / permissions / model_has_roles: spatie/laravel-permission standard tables (not repeated here)

-- ───────────────────────────── Catalog ─────────────────────────────
CREATE TABLE platforms (                                  -- PC, PS5, Xbox Series, Switch 2 …
  id         smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  slug       varchar(40) NOT NULL UNIQUE,
  name       jsonb NOT NULL,
  sort_order smallint NOT NULL DEFAULT 0
);

CREATE TABLE games (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id       char(26) NOT NULL UNIQUE,
  slug            varchar(80) NOT NULL UNIQUE,             -- also the package `game_id`
  title           jsonb NOT NULL,
  description     jsonb NOT NULL DEFAULT '{}',
  developer       varchar(160),
  publisher       varchar(160),
  release_date    date,
  cover_path      varchar(255),
  hero_path       varchar(255),
  rights_status   varchar(16) NOT NULL DEFAULT 'pending'
                  CHECK (rights_status IN ('pending','licensed','permitted','rejected')),
  rights_notes    text,
  status          varchar(16) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','coming_soon','published','archived')),
  is_featured     boolean NOT NULL DEFAULT false,
  published_at    timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  deleted_at      timestamptz
);
CREATE INDEX games_status_featured_idx ON games (status, is_featured);
CREATE INDEX games_title_trgm_idx ON games USING gin ((title->>'en') gin_trgm_ops);

CREATE TABLE game_platforms (
  game_id     bigint NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  platform_id smallint NOT NULL REFERENCES platforms(id),
  PRIMARY KEY (game_id, platform_id)
);

CREATE TABLE game_versions (                               -- game builds a package can target
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  game_id     bigint NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  version     varchar(40) NOT NULL,                        -- "1.02", "2025.11 patch"
  released_at date,
  notes       text,
  UNIQUE (game_id, version)
);

CREATE TABLE game_media (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  game_id    bigint NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  kind       varchar(16) NOT NULL CHECK (kind IN ('screenshot','subtitle_preview','video')),
  path       varchar(255) NOT NULL,
  caption    jsonb NOT NULL DEFAULT '{}',
  sort_order smallint NOT NULL DEFAULT 0
);

-- ─────────────────────── Localization content ───────────────────────
CREATE TABLE translation_categories (                     -- dialogues, cutscenes, missions, menus, ui, items, tutorials …
  id         smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  slug       varchar(40) NOT NULL UNIQUE,
  name       jsonb NOT NULL,
  parent_id  smallint REFERENCES translation_categories(id),
  sort_order smallint NOT NULL DEFAULT 0
);

CREATE TABLE localization_styles (                        -- msa, saudi_white_dialect, gulf …
  id     smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  slug   varchar(40) NOT NULL UNIQUE,
  name   jsonb NOT NULL
);

CREATE TABLE translations (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  game_id          bigint NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  category_id      smallint NOT NULL REFERENCES translation_categories(id),
  style_id         smallint NOT NULL REFERENCES localization_styles(id),
  locale           varchar(10) NOT NULL DEFAULT 'ar-SA',
  string_key       varchar(160),                            -- stable id if known (e.g. "M03_CUT_014")
  source_text      text NOT NULL,
  source_hash      char(64) NOT NULL,                       -- sha256(normalize(source_text))
  target_text      text NOT NULL DEFAULT '',
  speaker          varchar(120),
  context          text,
  sequence_no      integer,                                  -- order within a scene, for context scoring
  scene_ref        varchar(120),
  min_game_version_id bigint REFERENCES game_versions(id),
  max_game_version_id bigint REFERENCES game_versions(id),
  status           varchar(16) NOT NULL DEFAULT 'draft'
                   CHECK (status IN ('draft','in_review','approved','published','rejected')),
  translator_id    bigint REFERENCES users(id),
  reviewer_id      bigint REFERENCES users(id),
  reviewed_at      timestamptz,
  revision         integer NOT NULL DEFAULT 1,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),
  UNIQUE (game_id, locale, style_id, source_hash, category_id)
);
CREATE INDEX translations_game_status_idx ON translations (game_id, status, category_id);
CREATE INDEX translations_source_trgm_idx ON translations USING gin (source_text gin_trgm_ops);
CREATE INDEX translations_reviewer_queue_idx ON translations (status, reviewer_id) WHERE status = 'in_review';

CREATE TABLE translation_revisions (                      -- append-only history
  id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  translation_id bigint NOT NULL REFERENCES translations(id) ON DELETE CASCADE,
  revision       integer NOT NULL,
  target_text    text NOT NULL,
  status         varchar(16) NOT NULL,
  changed_by     bigint REFERENCES users(id),
  comment        text,
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (translation_id, revision)
);

CREATE TABLE translation_glossaries (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  game_id     bigint REFERENCES games(id) ON DELETE CASCADE,  -- NULL = global glossary
  locale      varchar(10) NOT NULL DEFAULT 'ar-SA',
  term        varchar(200) NOT NULL,
  translation varchar(200) NOT NULL,
  do_not_translate boolean NOT NULL DEFAULT false,
  notes       text,
  UNIQUE (game_id, locale, term)
);

CREATE TABLE import_jobs (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  game_id     bigint NOT NULL REFERENCES games(id),
  format      varchar(8) NOT NULL CHECK (format IN ('csv','json','xlsx')),
  direction   varchar(8) NOT NULL CHECK (direction IN ('import','export')),
  file_path   varchar(255) NOT NULL,
  status      varchar(16) NOT NULL DEFAULT 'queued',
  stats       jsonb NOT NULL DEFAULT '{}',                -- created/updated/skipped/errors
  created_by  bigint NOT NULL REFERENCES users(id),
  created_at  timestamptz NOT NULL DEFAULT now(),
  finished_at timestamptz
);

-- ───────────────────────────── Packages ─────────────────────────────
CREATE TABLE localization_packages (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id       char(26) NOT NULL UNIQUE,
  game_id         bigint NOT NULL REFERENCES games(id),
  slug            varchar(100) NOT NULL UNIQUE,            -- package_id, e.g. "gta6-sa-full"
  name            jsonb NOT NULL,
  summary         jsonb NOT NULL DEFAULT '{}',
  tier_rank       smallint NOT NULL DEFAULT 1,             -- ordering + upgrade direction (higher = more)
  locale          varchar(10) NOT NULL DEFAULT 'ar-SA',
  style_id        smallint NOT NULL REFERENCES localization_styles(id),
  update_policy   varchar(16) NOT NULL DEFAULT 'version_line'
                  CHECK (update_policy IN ('version_line','duration','lifetime')),
  update_months   smallint,                                 -- when update_policy = 'duration'
  status          varchar(16) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','unpublished','archived')),
  is_bundle       boolean NOT NULL DEFAULT false,
  sort_order      smallint NOT NULL DEFAULT 0,
  published_at    timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX packages_game_status_idx ON localization_packages (game_id, status, sort_order);

CREATE TABLE package_categories (                         -- which content a package contains
  package_id  bigint NOT NULL REFERENCES localization_packages(id) ON DELETE CASCADE,
  category_id smallint NOT NULL REFERENCES translation_categories(id),
  PRIMARY KEY (package_id, category_id)
);

CREATE TABLE package_inclusions (                         -- bundle → included packages
  bundle_id   bigint NOT NULL REFERENCES localization_packages(id) ON DELETE CASCADE,
  included_id bigint NOT NULL REFERENCES localization_packages(id),
  PRIMARY KEY (bundle_id, included_id),
  CHECK (bundle_id <> included_id)
);

CREATE TABLE package_platforms (
  package_id  bigint NOT NULL REFERENCES localization_packages(id) ON DELETE CASCADE,
  platform_id smallint NOT NULL REFERENCES platforms(id),
  PRIMARY KEY (package_id, platform_id)
);

CREATE TABLE package_prices (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id       bigint NOT NULL REFERENCES localization_packages(id) ON DELETE CASCADE,
  currency         char(3) NOT NULL DEFAULT 'SAR',
  base_amount      integer NOT NULL CHECK (base_amount >= 0),   -- VAT-inclusive, minor units
  sale_amount      integer CHECK (sale_amount >= 0),
  sale_starts_at   timestamptz,
  sale_ends_at     timestamptz,
  UNIQUE (package_id, currency),
  CHECK (sale_amount IS NULL OR sale_amount <= base_amount)
);

CREATE TABLE package_upgrades (                           -- owning `from` lets you buy `to` for `amount`
  from_package_id bigint NOT NULL REFERENCES localization_packages(id) ON DELETE CASCADE,
  to_package_id   bigint NOT NULL REFERENCES localization_packages(id) ON DELETE CASCADE,
  currency        char(3) NOT NULL DEFAULT 'SAR',
  amount          integer NOT NULL CHECK (amount >= 0),
  PRIMARY KEY (from_package_id, to_package_id, currency),
  CHECK (from_package_id <> to_package_id)
);

CREATE TABLE package_versions (
  id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id         bigint NOT NULL REFERENCES localization_packages(id) ON DELETE CASCADE,
  version            varchar(32) NOT NULL,                  -- semver
  major              integer NOT NULL,                      -- for 'version_line' update policy
  game_version_min_id bigint REFERENCES game_versions(id),
  game_version_max_id bigint REFERENCES game_versions(id),
  min_app_version    varchar(32) NOT NULL DEFAULT '1.0.0',
  release_notes      jsonb NOT NULL DEFAULT '{}',
  entry_count        integer,
  coverage           jsonb NOT NULL DEFAULT '{}',          -- {"dialogues": 0.97, "menus": 1.0}
  build_status       varchar(16) NOT NULL DEFAULT 'pending'
                     CHECK (build_status IN ('pending','building','validated','failed','released','revoked')),
  build_log          text,
  manifest           jsonb,                                 -- signed manifest as shipped
  signing_key_id     varchar(32),
  released_at        timestamptz,
  created_by         bigint REFERENCES users(id),
  created_at         timestamptz NOT NULL DEFAULT now(),
  UNIQUE (package_id, version)
);
CREATE INDEX package_versions_released_idx ON package_versions (package_id, released_at DESC) WHERE build_status = 'released';

CREATE TABLE package_files (
  id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_version_id bigint NOT NULL REFERENCES package_versions(id) ON DELETE CASCADE,
  kind               varchar(8) NOT NULL CHECK (kind IN ('full','delta')),
  base_version_id    bigint REFERENCES package_versions(id),   -- for delta
  storage_key        varchar(255) NOT NULL,
  size_bytes         bigint NOT NULL,
  sha256             char(64) NOT NULL,
  content_key_ref    varchar(128) NOT NULL,                     -- KMS-wrapped content key id
  created_at         timestamptz NOT NULL DEFAULT now(),
  CHECK ((kind = 'delta') = (base_version_id IS NOT NULL)),
  UNIQUE (package_version_id, kind, base_version_id)
);

-- ───────────────────────────── Commerce ─────────────────────────────
CREATE TABLE coupons (
  id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code           citext NOT NULL UNIQUE,
  kind           varchar(8) NOT NULL CHECK (kind IN ('percent','fixed')),
  value          integer NOT NULL CHECK (value > 0),          -- percent * 100 or minor units
  currency       char(3),
  min_order_amount integer,
  max_redemptions integer,
  per_user_limit smallint NOT NULL DEFAULT 1,
  redeemed_count integer NOT NULL DEFAULT 0,
  scope          jsonb NOT NULL DEFAULT '{}',                 -- {"games":[..], "packages":[..]}
  starts_at      timestamptz,
  ends_at        timestamptz,
  is_active      boolean NOT NULL DEFAULT true,
  created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE gift_codes (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code_hash     char(64) NOT NULL UNIQUE,                     -- sha256 of the code; plaintext shown once
  package_id    bigint NOT NULL REFERENCES localization_packages(id),
  purchased_order_id bigint,                                   -- FK added after orders
  redeemed_by   bigint REFERENCES users(id),
  redeemed_at   timestamptz,
  expires_at    timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE carts (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id    bigint REFERENCES users(id) ON DELETE CASCADE,
  session_id varchar(100),
  coupon_id  bigint REFERENCES coupons(id),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (user_id IS NOT NULL OR session_id IS NOT NULL)
);
CREATE TABLE cart_items (
  cart_id    bigint NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
  package_id bigint NOT NULL REFERENCES localization_packages(id),
  as_gift    boolean NOT NULL DEFAULT false,
  PRIMARY KEY (cart_id, package_id, as_gift)
);

CREATE TABLE orders (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id       char(26) NOT NULL UNIQUE,
  number          varchar(20) NOT NULL UNIQUE,                -- "LG-2026-000123"
  user_id         bigint NOT NULL REFERENCES users(id),
  status          varchar(20) NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending','awaiting_payment','paid','failed','cancelled',
                                    'partially_refunded','refunded')),
  currency        char(3) NOT NULL DEFAULT 'SAR',
  subtotal_amount integer NOT NULL,                            -- gross before discount
  discount_amount integer NOT NULL DEFAULT 0,
  vat_amount      integer NOT NULL,
  total_amount    integer NOT NULL,
  coupon_id       bigint REFERENCES coupons(id),
  billing         jsonb NOT NULL DEFAULT '{}',                 -- name, country, optional VAT number
  invoice_number  varchar(40) UNIQUE,
  paid_at         timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  CHECK (total_amount = subtotal_amount - discount_amount)
);
CREATE INDEX orders_user_idx ON orders (user_id, created_at DESC);
CREATE INDEX orders_status_idx ON orders (status, created_at DESC);
ALTER TABLE gift_codes ADD CONSTRAINT gift_codes_order_fk FOREIGN KEY (purchased_order_id) REFERENCES orders(id);

CREATE TABLE order_items (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  order_id        bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  package_id      bigint NOT NULL REFERENCES localization_packages(id),
  kind            varchar(10) NOT NULL DEFAULT 'purchase' CHECK (kind IN ('purchase','upgrade','gift')),
  upgrade_from_package_id bigint REFERENCES localization_packages(id),
  unit_amount     integer NOT NULL,                            -- VAT-inclusive, after line discount
  discount_amount integer NOT NULL DEFAULT 0,
  vat_amount      integer NOT NULL,
  snapshot        jsonb NOT NULL,                              -- package name, categories, update terms at purchase
  refunded_amount integer NOT NULL DEFAULT 0
);
CREATE INDEX order_items_package_idx ON order_items (package_id);

CREATE TABLE payments (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  order_id         bigint NOT NULL REFERENCES orders(id),
  gateway          varchar(20) NOT NULL CHECK (gateway IN ('moyasar','hyperpay','fake')),
  gateway_ref      varchar(120),
  idempotency_key  char(26) NOT NULL UNIQUE,
  method           varchar(20),                               -- mada, visa, mastercard, applepay, stcpay
  status           varchar(16) NOT NULL DEFAULT 'initiated'
                   CHECK (status IN ('initiated','pending','paid','failed','refunded','partially_refunded','voided')),
  amount           integer NOT NULL,
  currency         char(3) NOT NULL,
  raw_last_status  jsonb,
  verified_at      timestamptz,
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now(),
  UNIQUE (gateway, gateway_ref)
);

CREATE TABLE refunds (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  payment_id   bigint NOT NULL REFERENCES payments(id),
  order_item_id bigint REFERENCES order_items(id),
  amount       integer NOT NULL CHECK (amount > 0),
  reason       text NOT NULL,
  status       varchar(16) NOT NULL DEFAULT 'requested' CHECK (status IN ('requested','approved','processed','rejected')),
  gateway_ref  varchar(120),
  requested_by bigint REFERENCES users(id),
  approved_by  bigint REFERENCES users(id),
  created_at   timestamptz NOT NULL DEFAULT now(),
  processed_at timestamptz
);

CREATE TABLE payment_webhook_events (                       -- idempotent intake
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  gateway     varchar(20) NOT NULL,
  event_id    varchar(160) NOT NULL,
  payload     jsonb NOT NULL,
  processed_at timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (gateway, event_id)
);

-- ─────────────────────────── Entitlements ───────────────────────────
CREATE TABLE entitlements (                                -- THE ownership table
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id          bigint NOT NULL REFERENCES users(id),
  package_id       bigint NOT NULL REFERENCES localization_packages(id),
  source           varchar(12) NOT NULL CHECK (source IN ('purchase','upgrade','bundle','gift','admin','promo')),
  order_item_id    bigint REFERENCES order_items(id),
  parent_entitlement_id bigint REFERENCES entitlements(id),  -- bundle child → bundle grant
  update_major     integer,                                   -- 'version_line': updates within this major
  updates_until    timestamptz,                               -- 'duration'
  status           varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','revoked')),
  revoked_reason   varchar(40),
  granted_at       timestamptz NOT NULL DEFAULT now(),
  revoked_at       timestamptz
);
CREATE UNIQUE INDEX entitlements_one_active_idx ON entitlements (user_id, package_id) WHERE status = 'active';
CREATE INDEX entitlements_user_idx ON entitlements (user_id, status);

-- ───────────────────────────── Devices ─────────────────────────────
CREATE TABLE devices (
  id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id      char(26) NOT NULL UNIQUE,
  user_id        bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind           varchar(10) NOT NULL CHECK (kind IN ('macos','windows','android','ios','android_tv')),
  name           varchar(120) NOT NULL,
  model          varchar(120),
  os_version     varchar(40),
  app_version    varchar(32),
  public_key     bytea NOT NULL,                             -- X25519, 32 bytes; licenses are wrapped to it
  key_fingerprint char(64) NOT NULL UNIQUE,
  status         varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','removed','blocked')),
  last_seen_at   timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now(),
  removed_at     timestamptz
);
CREATE INDEX devices_user_active_idx ON devices (user_id, kind) WHERE status = 'active';

CREATE TABLE device_sessions (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  device_id       bigint NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
  token_id        bigint NOT NULL,                            -- personal_access_tokens.id (Sanctum)
  ip              inet,
  user_agent      varchar(255),
  created_at      timestamptz NOT NULL DEFAULT now(),
  last_used_at    timestamptz,
  revoked_at      timestamptz
);

CREATE TABLE licenses (                                    -- issued per (entitlement, device, package_version)
  id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  entitlement_id     bigint NOT NULL REFERENCES entitlements(id),
  device_id          bigint NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
  package_version_id bigint NOT NULL REFERENCES package_versions(id),
  expires_at         timestamptz NOT NULL,                    -- offline grace window, refreshed on sync
  issued_at          timestamptz NOT NULL DEFAULT now(),
  revoked_at         timestamptz,
  UNIQUE (entitlement_id, device_id, package_version_id)
);

CREATE TABLE downloads (
  id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id            bigint NOT NULL REFERENCES users(id),
  device_id          bigint REFERENCES devices(id),
  package_file_id    bigint NOT NULL REFERENCES package_files(id),
  token_hash         char(64) NOT NULL UNIQUE,
  token_expires_at   timestamptz NOT NULL,
  ip                 inet,
  status             varchar(12) NOT NULL DEFAULT 'authorized' CHECK (status IN ('authorized','started','completed','expired')),
  created_at         timestamptz NOT NULL DEFAULT now(),
  completed_at       timestamptz
);
CREATE INDEX downloads_user_idx ON downloads (user_id, created_at DESC);

CREATE TABLE installations (                               -- what the device reports as installed
  device_id          bigint NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
  package_id         bigint NOT NULL REFERENCES localization_packages(id),
  package_version_id bigint NOT NULL REFERENCES package_versions(id),
  installed_at       timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (device_id, package_id)
);

-- ──────────────────────── Community & support ────────────────────────
CREATE TABLE reviews (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id     bigint NOT NULL REFERENCES users(id),
  package_id  bigint NOT NULL REFERENCES localization_packages(id),
  rating      smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
  body        text,
  status      varchar(12) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','published','hidden')),
  created_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (user_id, package_id)                               -- app layer requires an active entitlement
);

CREATE TABLE support_tickets (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  public_id   char(26) NOT NULL UNIQUE,
  user_id     bigint NOT NULL REFERENCES users(id),
  order_id    bigint REFERENCES orders(id),
  package_id  bigint REFERENCES localization_packages(id),
  subject     varchar(200) NOT NULL,
  category    varchar(20) NOT NULL CHECK (category IN ('billing','download','translation_error','device','other')),
  status      varchar(12) NOT NULL DEFAULT 'open' CHECK (status IN ('open','pending_user','resolved','closed')),
  priority    varchar(8) NOT NULL DEFAULT 'normal',
  assignee_id bigint REFERENCES users(id),
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE support_messages (
  id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  ticket_id  bigint NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
  author_id  bigint NOT NULL REFERENCES users(id),
  body       text NOT NULL,
  is_internal boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE translation_reports (                         -- "this line is wrong" from the desktop app
  id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id        bigint NOT NULL REFERENCES users(id),
  package_version_id bigint NOT NULL REFERENCES package_versions(id),
  entry_id       bigint NOT NULL,                            -- package entry id → translations.id
  note           text,
  status         varchar(12) NOT NULL DEFAULT 'open',
  created_at     timestamptz NOT NULL DEFAULT now()
);

-- ───────────────────────────── Operations ─────────────────────────────
CREATE TABLE signing_keys (
  key_id      varchar(32) PRIMARY KEY,
  algorithm   varchar(16) NOT NULL DEFAULT 'ed25519',
  public_key  bytea NOT NULL,
  kms_ref     varchar(255) NOT NULL,
  status      varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','retiring','revoked')),
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE audit_logs (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  actor_id    bigint REFERENCES users(id),
  action      varchar(60) NOT NULL,
  subject_type varchar(60) NOT NULL,
  subject_id  bigint NOT NULL,
  changes     jsonb,
  ip          inet,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_subject_idx ON audit_logs (subject_type, subject_id, created_at DESC);

CREATE TABLE analytics_daily (                             -- rollups; raw events kept 90 days elsewhere
  day          date NOT NULL,
  metric       varchar(40) NOT NULL,                       -- revenue, orders, downloads, active_devices, update_adoption
  dimension    varchar(40) NOT NULL DEFAULT '',             -- 'game:12', 'package:40', ''
  value        numeric(18,2) NOT NULL,
  PRIMARY KEY (day, metric, dimension)
);
