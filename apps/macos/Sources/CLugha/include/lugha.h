// LUGHA engine C interface (lugha-ffi). All strings are UTF-8, NUL-terminated.
// Every function returning `char *` returns a JSON document the caller must free with lugha_string_free.
// Results look like {"ok": true, ...} or {"ok": false, "error": "..."}.
// An engine is not thread-safe: call it from one serial queue.
#ifndef LUGHA_H
#define LUGHA_H

#ifdef __cplusplus
extern "C" {
#endif

typedef struct LughaEngine LughaEngine;

// {"secret_hex", "public" (base64), "fingerprint"} — store the secret in the Keychain.
char *lugha_device_generate(void);

// trust_json: {"keys":[{"key_id","public"}]} as served by GET /api/v1/trust (and pinned in the app).
// Returns NULL if the trust document or device secret is invalid.
LughaEngine *lugha_engine_new(const char *trust_json, const char *device_secret_hex);
void lugha_engine_free(LughaEngine *engine);

// Install a downloaded .lpk (full or delta) into package_dir. {"ok", "manifest"}
char *lugha_install(LughaEngine *engine, const char *package_dir, const char *lpk_path, const char *license_json);
// Replace an installed package's license (offline-window refresh). {"ok"}
char *lugha_refresh_license(LughaEngine *engine, const char *package_dir, const char *license_json);
// Open every installed package under root (one directory each) for matching. {"ok", "packages":[...], "errors":[...]}
char *lugha_load(LughaEngine *engine, const char *packages_root);
// Look up OCR text. {"ok", "hit": {...} | null}
char *lugha_lookup(LughaEngine *engine, const char *text);
void lugha_reset_context(LughaEngine *engine);

void lugha_string_free(char *s);
const char *lugha_engine_version(void);

#ifdef __cplusplus
}
#endif

#endif
