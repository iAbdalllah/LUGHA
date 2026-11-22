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

// ── LUGHA Link (Mac → TV). Binary values are base64 inside the JSON results. ──
typedef struct LughaLinkPairing LughaLinkPairing;
typedef struct LughaLinkSession LughaLinkSession;

// {"secret_hex","public","fingerprint"} — the Mac's link identity; keep the secret in the Keychain.
char *lugha_link_keys_generate(void);
// Pairing with the 6-digit code shown on the TV: start → send PAIR1; on PAIR2 → finish → send PAIR3, store "peer".
char *lugha_link_pair_start(const char *code, const char *secret_hex, LughaLinkPairing **out_pairing);
char *lugha_link_pair_finish(LughaLinkPairing *pairing, const char *pair2_b64); // frees the pairing
void lugha_link_pairing_free(LughaLinkPairing *pairing);
// Session (Noise IK) with a paired TV: start → send HS1; on HS2 → complete.
char *lugha_link_session_start(const char *secret_hex, const char *peer_b64, LughaLinkSession **out_session);
char *lugha_link_session_complete(LughaLinkSession *session, const char *hs2_b64);
char *lugha_link_encrypt(LughaLinkSession *session, const char *plaintext_json); // {"msg"}
char *lugha_link_decrypt(LughaLinkSession *session, const char *msg_b64);        // {"text"}
void lugha_link_session_free(LughaLinkSession *session);
const char *lugha_engine_version(void);

#ifdef __cplusplus
}
#endif

#endif
