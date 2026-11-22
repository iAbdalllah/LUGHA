# 04 · Localization Package Specification (`.lpk` v1)

> **Implemented** in `engine/lugha-core` (reader, matcher, deltas) and `engine/lugha-pkg` (builder). Decisions made while implementing, which override older text below:
> - `content_sha256` is a **logical** hash of the rows in a fixed order (meta, entries, aliases, glossary), not of the SQLite file — a delta-patched database and a fresh build have different page layouts but the same content.
> - Manifests carry **no floats**: coverage is `coverage_bp` (basis points, 9700 = 97.00%). The manifest is signed as raw bytes, so no JSON canonicalization is needed.
> - Licenses are bound to one **package version** (each version has its own content key) and carry their payload as base64 of the exact JSON bytes that were signed (see 03-api). Timestamps are unix seconds.
> - The content DB adds `fold_hash` (OCR-folded hash) to entries and aliases, a `grams_entry` index and a `gram_df` table (trigram document frequency); the fuzzy matcher probes only the query's rarest trigrams and ignores trigrams present in >5% of lines.
> - Installed layout (per package directory): `manifest.json` + `manifest.sig` (as signed by LUGHA), `content.nonce` + `content.enc` (zstd content, AES-256-GCM with the version key), `license.json`, optional `profile.json`. Opening re-verifies signature, license and content hash.
> - Measured on 50,000 synthetic lines (Apple Silicon, release): exact lookup ~10 µs, OCR-noisy lookup p50 ~2.9 ms / p95 ~5.6 ms, 100% correct; package ~14.6 MB; build ~18 s.


## 1. Container

```
gta6-sa-full-1.4.0.lpk            (ZIP, stored — no ZIP compression)
├── manifest.json                 plaintext, signed metadata
├── manifest.sig                  Ed25519 signature over canonical manifest.json bytes
├── content.db.zst.enc            SQLite DB → Zstandard (level 19) → AES-256-GCM
└── profile.json                  optional: capture-region presets, line-join rules (plaintext, covered by manifest hash)
```

Delta files (`…-1.3.0_to_1.4.0.lpkd`) have the same layout with `delta.ops.zst.enc` instead of `content.db.zst.enc`.

## 2. Manifest

```json
{
  "format": "lpk/1",
  "game_id": "gta6",
  "package_id": "gta6-sa-full",
  "version": "1.4.0",
  "kind": "full",
  "base_version": null,
  "language": "ar",
  "locale": "ar-SA",
  "direction": "rtl",
  "localization_style": "saudi_white_dialect",
  "package_type": "full_localization",
  "categories": ["dialogues", "cutscenes", "missions", "menus", "notifications", "ui", "items", "tutorials"],
  "game_versions": {"min": "1.00", "max": "1.06"},
  "platforms": ["pc", "ps5", "xbox_series"],
  "min_app_version": "1.2.0",
  "entry_count": 48213,
  "coverage": {"dialogues": 0.97, "menus": 1.0},
  "files": {
    "content.db.zst.enc": {"sha256": "…", "size": 9123456},
    "profile.json": {"sha256": "…", "size": 2210}
  },
  "content_sha256": "…",                 // sha256 of decrypted, decompressed content.db
  "encryption": {"alg": "AES-256-GCM", "key_ref": "ck-…", "nonce": "base64"},
  "release_notes": {"ar": "…", "en": "…"},
  "built_at": "2026-11-02T09:12:00Z",
  "key_id": "pk-2026-01"
}
```

`package_type` is the admin-chosen label, informational only — clients use `categories`.

## 3. Verification order (lugha-core, offline)

1. `manifest.sig` valid for `key_id` (keys pinned in app + signed rotation list).
2. Every file's sha256 matches `manifest.files`.
3. `min_app_version` ≤ app version; `format` supported.
4. License signature valid, `device_fp` = this device, `package_id` matches, version allowed, not expired.
5. Unwrap content key with the device's X25519 private key → decrypt → decompress → `content_sha256` matches.
6. Open SQLite read-only (decrypted DB lives in the app's protected container; on macOS/Windows it is re-encrypted at rest with a device key, or held as a memory-mapped temp file for the session).

Any failure → package marked *invalid*, not loaded, user prompted to re-download.

## 4. `content.db` schema (SQLite, read-only)

```sql
CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT NOT NULL);           -- copies of manifest fields, schema_version
CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT UNIQUE NOT NULL);
CREATE TABLE entries (
  id          INTEGER PRIMARY KEY,          -- = server translations.id (stable across versions)
  key_hash    BLOB NOT NULL,                -- sha256(normalize(source)) 32 bytes
  norm_len    INTEGER NOT NULL,             -- normalized length, prunes fuzzy candidates
  source      TEXT,                         -- normalized source; NULL in "minimized" mode (§6)
  target      TEXT NOT NULL,                -- Arabic, NFC, bidi-safe
  category_id INTEGER NOT NULL REFERENCES categories(id),
  speaker     TEXT,
  scene       TEXT,
  seq         INTEGER
);
CREATE INDEX entries_hash ON entries(key_hash);
CREATE TABLE entry_aliases (entry_id INTEGER NOT NULL, key_hash BLOB NOT NULL); -- alternate source variants (line breaks, platform button names)
CREATE INDEX aliases_hash ON entry_aliases(key_hash);
CREATE TABLE grams (gram INTEGER NOT NULL, entry_id INTEGER NOT NULL) WITHOUT ROWID; -- 3-gram hash → entry, PK(gram, entry_id)
CREATE TABLE glossary (term TEXT PRIMARY KEY, translation TEXT NOT NULL, dnt INTEGER NOT NULL DEFAULT 0);
```

`grams` uses 32-bit hashes of normalized character trigrams (a hand-rolled inverted index instead of FTS5 so it works identically in minimized mode and stays small). Typical size: ~50k entries ≈ 8–12 MB compressed.

## 5. Matching pipeline

Input: OCR text for one capture region (+ recent context: last matched entry, region tag).

| Step | Operation | Cost |
|---|---|---|
| 0 | **Frame dedupe** — OCR string equal to previous (after normalize) → reuse last result | O(1) |
| 1 | **Cache** — LRU (1,024) keyed by normalized string | O(1) |
| 2 | **Normalize** — NFKC, case-fold, strip speaker prefix pattern from profile, unify quotes/dashes/ellipsis, collapse whitespace, remove trailing punctuation | O(n) |
| 3 | **Exact hash** — sha256 → `entries_hash` / `aliases_hash` | 1 index seek |
| 4 | **OCR-folded hash** — apply confusion map (`0↔o`, `1/l/I→l`, `rn→m`, `vv→w`, `5↔s`, curly/straight quotes) to both sides at build time; second hash lookup | 1 seek |
| 5 | **Fuzzy** — trigram hashes → candidate entries with ≥ 40% shared grams and `norm_len` within ±25% → top 20 by Dice → Damerau-Levenshtein similarity on `source` | ~1–3 ms on 50k entries |
| 6 | **Context score** — `+0.05` if candidate's `scene` = last match's scene and `seq` = last.seq + 1..3; speaker match bonus | O(k) |
| 7 | **Decide** — accept if score ≥ 0.86 (exact = 1.0); 0.75–0.86 → show only if context bonus applied; else no subtitle | |
| 8 | **Multi-line split** — if no hit for the whole block, try each OCR line and the two-line joins, then compose | ≤ 3 extra lookups |

Output `Hit { entry_id, target, speaker, category, score, provider_id: "package", machine_generated: false }`.

Memory budget: index pages via SQLite mmap (64 MB cap), LRU ≈ 1 MB. No full in-memory load required.

Duplicate detection (build time): identical `key_hash` within a package with different `target` fails validation unless they differ by `category` or `scene` and are flagged as intentional.

## 6. Source-text minimization (IP option)

Packages contain the original English lines, which are the rights holder's text. Two modes, chosen per game in admin:

- **standard** — `entries.source` present; best fuzzy accuracy. Requires the rights agreement to cover redistribution of source strings in encrypted form.
- **minimized** — `source` NULL. Fuzzy step 5 ranks by trigram-hash Dice coefficient only (no Levenshtein), threshold raised to 0.80 Dice. Accuracy is lower on heavy OCR noise but no source text ships.

## 7. Delta updates

`delta.ops` is a sequence of `upsert_entry`, `delete_entry`, `upsert_alias`, `upsert_glossary`, `set_meta` ops, built by `lugha-pkg diff old.db new.db`. Client applies in one SQLite transaction on a copy, verifies resulting `content_sha256` equals the target manifest, then swaps atomically. Failure → keep old version, fetch full file. The server offers a delta only from the immediately previous two releases; otherwise full.

## 8. Build & validation (`lugha-pkg`)

`lugha-pkg build --game gta6 --package gta6-sa-full --version 1.4.0 --input export.jsonl --out dist/`

Validator rejects on: empty target for published entry; Arabic target containing unbalanced bidi controls; target exceeding `max_len` hint for UI categories; duplicate conflicts (§5); glossary violations (DNT term translated, required term missing) — warnings vs errors configurable; coverage below the package's declared minimum; manifest/schema mismatch. Output: build report JSON stored in `package_versions.build_log`, shown in Filament.

## 9. Versioning

SemVer: **major** = incompatible game-version line or re-segmentation (new entry ids); **minor** = new content/categories; **patch** = corrections. `entries.id` is stable for the life of a major so translation reports and deltas work.
