/* LUGHA Remote Play: a small flat C API over chiaki-ng (AGPL-3.0) for LAN play with a PS5 or PS4.
 *
 * The apps (Windows in Rust, Mac and iPhone in Swift) only see plain types here: find the console, register this
 * device with it once (PSN account id + the 8-digit PIN the console shows), then open a session whose H.264 video
 * arrives as complete frames for the app to decode. Every callback runs on a chiaki thread: copy what you need and
 * return quickly.
 */
#ifndef LUGHA_RP_H
#define LUGHA_RP_H

#include <stddef.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

/* Call once before anything else; 0 = ok. */
int lugha_rp_init(void);

/* Optional: every chiaki log line (level 1 debug .. 16 error). */
typedef void (*lugha_rp_log_cb)(int level, const char *msg, void *user);
void lugha_rp_set_log(lugha_rp_log_cb cb, void *user);

/* ---- Finding consoles ---------------------------------------------------------------------------------------- */

typedef struct {
    char host[64];      /* IP address */
    char name[64];      /* console name */
    char id[32];        /* host id (MAC, hex) */
    char app[128];      /* running game, if any */
    char version[32];   /* system version */
    int ready;          /* 1 = on, 0 = rest mode (wake it first) */
    int ps5;            /* 1 = PS5, 0 = PS4 */
} LughaRpConsole;

typedef void (*lugha_rp_consoles_cb)(const LughaRpConsole *consoles, size_t count, void *user);

/* Looks for consoles on the local network every second until stopped; cb gets the full list on each change.
 * Returns a handle, or NULL on error. */
void *lugha_rp_discovery_start(lugha_rp_consoles_cb cb, void *user);
void lugha_rp_discovery_stop(void *discovery);

/* Wakes a registered console from rest mode. regist_key: the 16 bytes from registration. 0 = sent. */
int lugha_rp_wakeup(const char *host, const uint8_t regist_key[16], int ps5);

/* ---- Registering this device (once per console) ----------------------------------------------------------------- */

typedef struct {
    int ok;                 /* 1 = registered */
    char error[128];        /* why not, when ok == 0 */
    uint8_t regist_key[16]; /* keep these three: every session needs them */
    uint8_t morning[16];
    char nickname[33];      /* console name */
    char mac[13];           /* console MAC, hex */
} LughaRpRegistered;

typedef void (*lugha_rp_regist_cb)(const LughaRpRegistered *result, void *user);

/* account_id: the 8 bytes of the PSN account id (little-endian, as PSN's API returns it base64). pin: the 8 digits the
 * console shows under Settings > System > Remote Play > Link Device. Returns a handle (free with
 * lugha_rp_register_free after cb ran, or to cancel), or NULL. */
void *lugha_rp_register(const char *host, const uint8_t account_id[8], uint32_t pin, int ps5, lugha_rp_regist_cb cb,
                        void *user);
void lugha_rp_register_free(void *regist);

/* ---- The session ------------------------------------------------------------------------------------------------ */

enum {
    LUGHA_RP_CONNECTED = 1, /* video will follow */
    LUGHA_RP_QUIT = 2,      /* ended; reason says why */
    LUGHA_RP_PIN = 3        /* the console wants its login passcode: lugha_rp_session_set_pin */
};

/* One complete H.264 access unit (Annex B, SPS/PPS before key frames). */
typedef void (*lugha_rp_video_cb)(const uint8_t *frame, size_t size, void *user);
typedef void (*lugha_rp_state_cb)(int state, const char *reason, void *user);

/* height: 360, 540, 720 or 1080; fps: 30 or 60. Returns a handle, or NULL. */
void *lugha_rp_session_start(const char *host, const uint8_t regist_key[16], const uint8_t morning[16],
                             const uint8_t account_id[8], int ps5, int height, int fps, lugha_rp_video_cb video,
                             lugha_rp_state_cb state, void *user);
/* Asks for a key frame (after a decoder error, or to get a picture right away). */
void lugha_rp_session_request_keyframe(void *session);
void lugha_rp_session_set_pin(void *session, const char *pin);
/* Ends the session and frees it; no callback runs after this returns. */
void lugha_rp_session_stop(void *session);

#ifdef __cplusplus
}
#endif

#endif
