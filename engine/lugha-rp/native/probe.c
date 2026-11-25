/* Developer tool: lists the consoles on this network for a few seconds (`lugha_rp_probe`). */
#include "lugha_rp.h"
#include <stdio.h>
#ifdef _WIN32
#include <windows.h>
#define sleep_s(n) Sleep((n) * 1000)
#else
#include <unistd.h>
#define sleep_s(n) sleep(n)
#endif

static void on_consoles(const LughaRpConsole *c, size_t n, void *user)
{
    (void)user;
    for (size_t i = 0; i < n; i++)
        printf("%s %s %s ready=%d id=%s app=%s version=%s\n", c[i].ps5 ? "PS5" : "PS4", c[i].host, c[i].name,
               c[i].ready, c[i].id, c[i].app, c[i].version);
    fflush(stdout);
}

static void on_log(int level, const char *msg, void *user)
{
    (void)user;
    fprintf(stderr, "[%d] %s\n", level, msg);
}

int main(void)
{
    lugha_rp_set_log(on_log, NULL);
    if (lugha_rp_init() != 0) return 1;
    void *d = lugha_rp_discovery_start(on_consoles, NULL);
    if (!d) return 2;
    sleep_s(5);
    lugha_rp_discovery_stop(d);
    return 0;
}
