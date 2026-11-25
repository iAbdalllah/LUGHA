/* LUGHA Remote Play: the flat C API in lugha_rp.h over chiaki-ng. AGPL-3.0, like chiaki-ng. */
#include "lugha_rp.h"

#include <chiaki/common.h>
#include <chiaki/discovery.h>
#include <chiaki/discoveryservice.h>
#include <chiaki/log.h>
#include <chiaki/regist.h>
#include <chiaki/session.h>

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#ifdef _WIN32
#include <winsock2.h>
#include <ws2tcpip.h>
#include <iphlpapi.h>
#else
#include <arpa/inet.h>
#include <ifaddrs.h>
#include <net/if.h>
#include <netinet/in.h>
#endif

/* ---- Logging ----------------------------------------------------------------------------------------------------- */

static lugha_rp_log_cb g_log_cb;
static void *g_log_user;
static ChiakiLog g_log;

static void on_log(ChiakiLogLevel level, const char *msg, void *user)
{
    (void)user;
    if (g_log_cb) g_log_cb((int)level, msg, g_log_user);
}

void lugha_rp_set_log(lugha_rp_log_cb cb, void *user)
{
    g_log_cb = cb;
    g_log_user = user;
}

int lugha_rp_init(void)
{
    static int done;
    if (done) return 0;
#ifdef _WIN32
    WSADATA wsa;
    if (WSAStartup(MAKEWORD(2, 2), &wsa) != 0) return -1;
#endif
    chiaki_log_init(&g_log, CHIAKI_LOG_ALL & ~CHIAKI_LOG_VERBOSE, on_log, NULL);
    if (chiaki_lib_init() != CHIAKI_ERR_SUCCESS) return -1;
    done = 1;
    return 0;
}

static void copy_str(char *dst, size_t size, const char *src)
{
    if (!size) return;
    snprintf(dst, size, "%s", src ? src : "");
}

/* ---- Discovery --------------------------------------------------------------------------------------------------- */

typedef struct {
    ChiakiDiscoveryService service;
    lugha_rp_consoles_cb cb;
    void *user;
} Discovery;

static void on_hosts(ChiakiDiscoveryHost *hosts, size_t count, void *user)
{
    Discovery *d = user;
    LughaRpConsole list[16];
    size_t n = count < 16 ? count : 16;
    for (size_t i = 0; i < n; i++) {
        ChiakiDiscoveryHost *h = &hosts[i];
        LughaRpConsole *c = &list[i];
        memset(c, 0, sizeof(*c));
        copy_str(c->host, sizeof(c->host), h->host_addr);
        copy_str(c->name, sizeof(c->name), h->host_name);
        copy_str(c->id, sizeof(c->id), h->host_id);
        copy_str(c->app, sizeof(c->app), h->running_app_name);
        copy_str(c->version, sizeof(c->version), h->system_version);
        c->ready = h->state == CHIAKI_DISCOVERY_HOST_STATE_READY;
        c->ps5 = chiaki_discovery_host_is_ps5(h);
    }
    d->cb(list, n, d->user);
}

/* The broadcast address of every network this device is on (255.255.255.255 alone can leave by a VPN instead). */
static size_t local_broadcasts(uint32_t *out, size_t max)
{
    size_t n = 0;
#ifdef _WIN32
    ULONG len = 16 * 1024;
    IP_ADAPTER_INFO *info = malloc(len);
    if (!info) return 0;
    if (GetAdaptersInfo(info, &len) == ERROR_BUFFER_OVERFLOW) {
        free(info);
        info = malloc(len);
        if (!info) return 0;
    }
    if (GetAdaptersInfo(info, &len) == NO_ERROR) {
        for (IP_ADAPTER_INFO *a = info; a && n < max; a = a->Next) {
            if (a->Type != IF_TYPE_IEEE80211 && a->Type != MIB_IF_TYPE_ETHERNET) continue;
            for (IP_ADDR_STRING *ip = &a->IpAddressList; ip && n < max; ip = ip->Next) {
                uint32_t addr = 0, mask = 0;
                if (inet_pton(AF_INET, ip->IpAddress.String, &addr) != 1 || addr == 0) continue;
                if (inet_pton(AF_INET, ip->IpMask.String, &mask) != 1) continue;
                out[n++] = addr | ~mask;
            }
        }
    }
    free(info);
#else
    struct ifaddrs *list = NULL;
    if (getifaddrs(&list) != 0) return 0;
    for (struct ifaddrs *i = list; i && n < max; i = i->ifa_next) {
        if (!i->ifa_addr || i->ifa_addr->sa_family != AF_INET || !i->ifa_broadaddr) continue;
        if ((i->ifa_flags & (IFF_UP | IFF_RUNNING | IFF_LOOPBACK | IFF_BROADCAST)) != (IFF_UP | IFF_RUNNING | IFF_BROADCAST))
            continue;
        uint32_t b = ((struct sockaddr_in *)i->ifa_broadaddr)->sin_addr.s_addr;
        int seen = 0;
        for (size_t k = 0; k < n; k++) seen |= out[k] == b;
        if (!seen) out[n++] = b;
    }
    freeifaddrs(list);
#endif
    return n;
}

void *lugha_rp_discovery_start(lugha_rp_consoles_cb cb, void *user)
{
    if (lugha_rp_init() != 0 || !cb) return NULL;
    Discovery *d = calloc(1, sizeof(*d));
    if (!d) return NULL;
    d->cb = cb;
    d->user = user;

    ChiakiDiscoveryServiceOptions options;
    memset(&options, 0, sizeof(options));
    options.ping_ms = 1000;
    options.ping_initial_ms = 100;
    options.hosts_max = 16;
    options.host_drop_pings = 3;
    options.cb = on_hosts;
    options.cb_user = d;

    struct sockaddr_in in_addr;
    memset(&in_addr, 0, sizeof(in_addr));
    in_addr.sin_family = AF_INET;
    in_addr.sin_addr.s_addr = 0xffffffff; /* 255.255.255.255 */
    struct sockaddr_storage addr;
    memset(&addr, 0, sizeof(addr));
    memcpy(&addr, &in_addr, sizeof(in_addr));
    options.send_addr = &addr;
    options.send_addr_size = sizeof(in_addr);

    uint32_t broadcasts[8];
    struct sockaddr_storage extra[8];
    size_t nb = local_broadcasts(broadcasts, 8);
    for (size_t i = 0; i < nb; i++) {
        struct sockaddr_in b;
        memset(&b, 0, sizeof(b));
        b.sin_family = AF_INET;
        b.sin_addr.s_addr = broadcasts[i];
        memset(&extra[i], 0, sizeof(extra[i]));
        memcpy(&extra[i], &b, sizeof(b));
    }
    options.broadcast_addrs = nb ? extra : NULL;
    options.broadcast_num = nb;

    ChiakiErrorCode err = chiaki_discovery_service_init(&d->service, &options, &g_log);
    if (err != CHIAKI_ERR_SUCCESS) {
        CHIAKI_LOGE(&g_log, "LUGHA: discovery failed to start: %s", chiaki_error_string(err));
        free(d);
        return NULL;
    }
    return d;
}

void lugha_rp_discovery_stop(void *discovery)
{
    Discovery *d = discovery;
    if (!d) return;
    chiaki_discovery_service_fini(&d->service);
    free(d);
}

int lugha_rp_wakeup(const char *host, const uint8_t regist_key[16], int ps5)
{
    if (lugha_rp_init() != 0 || !host || !regist_key) return -1;
    /* The credential is the regist key's leading hex digits read as a number (as chiaki's GUI does). */
    char key[17];
    memcpy(key, regist_key, 16);
    key[16] = 0;
    size_t len = strlen(key);
    if (len == 0 || len > 8) return -1;
    char *end = NULL;
    unsigned long long credential = strtoull(key, &end, 16);
    if (!end || *end) return -1;
    return chiaki_discovery_wakeup(&g_log, NULL, host, credential, ps5 != 0) == CHIAKI_ERR_SUCCESS ? 0 : -1;
}

/* ---- Registration ------------------------------------------------------------------------------------------------ */

typedef struct {
    ChiakiRegist regist;
    char host[64];
    lugha_rp_regist_cb cb;
    void *user;
} Regist;

static void on_regist(ChiakiRegistEvent *event, void *user)
{
    Regist *r = user;
    LughaRpRegistered out;
    memset(&out, 0, sizeof(out));
    switch (event->type) {
    case CHIAKI_REGIST_EVENT_TYPE_FINISHED_SUCCESS: {
        ChiakiRegisteredHost *h = event->registered_host;
        out.ok = 1;
        memcpy(out.regist_key, h->rp_regist_key, sizeof(out.regist_key));
        memcpy(out.morning, h->rp_key, sizeof(out.morning));
        copy_str(out.nickname, sizeof(out.nickname), h->server_nickname);
        snprintf(out.mac, sizeof(out.mac), "%02x%02x%02x%02x%02x%02x", h->server_mac[0], h->server_mac[1],
                 h->server_mac[2], h->server_mac[3], h->server_mac[4], h->server_mac[5]);
        break;
    }
    case CHIAKI_REGIST_EVENT_TYPE_FINISHED_CANCELED:
        copy_str(out.error, sizeof(out.error), "canceled");
        break;
    default:
        copy_str(out.error, sizeof(out.error), "the console refused: check the PIN and the PSN account");
        break;
    }
    r->cb(&out, r->user);
}

void *lugha_rp_register(const char *host, const uint8_t account_id[8], uint32_t pin, int ps5, lugha_rp_regist_cb cb,
                        void *user)
{
    if (lugha_rp_init() != 0 || !host || !account_id || !cb) return NULL;
    Regist *r = calloc(1, sizeof(*r));
    if (!r) return NULL;
    copy_str(r->host, sizeof(r->host), host);
    r->cb = cb;
    r->user = user;

    ChiakiRegistInfo info;
    memset(&info, 0, sizeof(info));
    info.target = ps5 ? CHIAKI_TARGET_PS5_1 : CHIAKI_TARGET_PS4_10;
    info.host = r->host;
    info.broadcast = false;
    info.psn_online_id = NULL; /* only for PS4 below 7.0 */
    memcpy(info.psn_account_id, account_id, sizeof(info.psn_account_id));
    info.pin = pin;
    if (chiaki_regist_start(&r->regist, &g_log, &info, on_regist, r) != CHIAKI_ERR_SUCCESS) {
        free(r);
        return NULL;
    }
    return r;
}

void lugha_rp_register_free(void *regist)
{
    Regist *r = regist;
    if (!r) return;
    chiaki_regist_stop(&r->regist);
    chiaki_regist_fini(&r->regist);
    free(r);
}

/* ---- Session ----------------------------------------------------------------------------------------------------- */

typedef struct {
    ChiakiSession session;
    char host[64];
    lugha_rp_video_cb video;
    lugha_rp_state_cb state;
    void *user;
} Session;

static bool on_video(uint8_t *buf, size_t buf_size, int32_t frames_lost, bool frame_recovered, void *user)
{
    (void)frames_lost;
    (void)frame_recovered;
    Session *s = user;
    if (s->video) s->video(buf, buf_size, s->user);
    return true;
}

static void on_event(ChiakiEvent *event, void *user)
{
    Session *s = user;
    if (!s->state) return;
    switch (event->type) {
    case CHIAKI_EVENT_CONNECTED:
        s->state(LUGHA_RP_CONNECTED, "", s->user);
        break;
    case CHIAKI_EVENT_LOGIN_PIN_REQUEST:
        s->state(LUGHA_RP_PIN, event->login_pin_request.pin_incorrect ? "incorrect" : "", s->user);
        break;
    case CHIAKI_EVENT_QUIT: {
        char reason[256];
        snprintf(reason, sizeof(reason), "%s%s%s", chiaki_quit_reason_string(event->quit.reason),
                 event->quit.reason_str ? ": " : "", event->quit.reason_str ? event->quit.reason_str : "");
        s->state(LUGHA_RP_QUIT, reason, s->user);
        break;
    }
    default:
        break;
    }
}

void *lugha_rp_session_start(const char *host, const uint8_t regist_key[16], const uint8_t morning[16],
                             const uint8_t account_id[8], int ps5, int height, int fps, lugha_rp_video_cb video,
                             lugha_rp_state_cb state, void *user)
{
    if (lugha_rp_init() != 0 || !host || !regist_key || !morning) return NULL;
    Session *s = calloc(1, sizeof(*s));
    if (!s) return NULL;
    copy_str(s->host, sizeof(s->host), host);
    s->video = video;
    s->state = state;
    s->user = user;

    ChiakiConnectInfo info;
    memset(&info, 0, sizeof(info));
    info.ps5 = ps5 != 0;
    info.host = s->host;
    memcpy(info.regist_key, regist_key, sizeof(info.regist_key));
    memcpy(info.morning, morning, sizeof(info.morning));
    ChiakiVideoResolutionPreset res = height >= 1080 ? CHIAKI_VIDEO_RESOLUTION_PRESET_1080p
                                      : height >= 720 ? CHIAKI_VIDEO_RESOLUTION_PRESET_720p
                                      : height >= 540 ? CHIAKI_VIDEO_RESOLUTION_PRESET_540p
                                                      : CHIAKI_VIDEO_RESOLUTION_PRESET_360p;
    chiaki_connect_video_profile_preset(&info.video_profile, res,
                                        fps >= 60 ? CHIAKI_VIDEO_FPS_PRESET_60 : CHIAKI_VIDEO_FPS_PRESET_30);
    /* H.264: every OS decodes it in hardware without extra packs (HEVC needs a paid extension on some Windows). */
    info.video_profile.codec = CHIAKI_CODEC_H264;
    info.video_profile_auto_downgrade = true;
    info.enable_keyboard = false;
    info.enable_dualsense = false;
    /* LUGHA only reads the picture; the player hears the game from the console as usual. */
    info.audio_video_disabled = CHIAKI_AUDIO_DISABLED;
    info.auto_regist = false;
    info.holepunch_session = NULL;
    info.rudp_sock = NULL;
    if (account_id) memcpy(info.psn_account_id, account_id, sizeof(info.psn_account_id));
    info.packet_loss_max = 0.05;
    info.enable_idr_on_fec_failure = true;

    if (chiaki_session_init(&s->session, &info, &g_log) != CHIAKI_ERR_SUCCESS) {
        free(s);
        return NULL;
    }
    chiaki_session_set_event_cb(&s->session, on_event, s);
    chiaki_session_set_video_sample_cb(&s->session, on_video, s);
    if (chiaki_session_start(&s->session) != CHIAKI_ERR_SUCCESS) {
        chiaki_session_fini(&s->session);
        free(s);
        return NULL;
    }
    return s;
}

void lugha_rp_session_request_keyframe(void *session)
{
    Session *s = session;
    if (s) chiaki_session_request_idr(&s->session);
}

void lugha_rp_session_set_pin(void *session, const char *pin)
{
    Session *s = session;
    if (s && pin) chiaki_session_set_login_pin(&s->session, (const uint8_t *)pin, strlen(pin));
}

void lugha_rp_session_stop(void *session)
{
    Session *s = session;
    if (!s) return;
    s->video = NULL;
    chiaki_session_stop(&s->session);
    chiaki_session_join(&s->session);
    s->state = NULL;
    chiaki_session_fini(&s->session);
    free(s);
}
