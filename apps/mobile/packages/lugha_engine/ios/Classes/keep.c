// Keeps the Rust symbols linked into the app so dart:ffi can resolve them via DynamicLibrary.process().
#include "lugha.h"

__attribute__((used)) static void *lugha_keep_symbols[] = {
    (void *)lugha_device_generate, (void *)lugha_engine_new, (void *)lugha_engine_free, (void *)lugha_install,
    (void *)lugha_refresh_license, (void *)lugha_load, (void *)lugha_lookup, (void *)lugha_reset_context,
    (void *)lugha_string_free, (void *)lugha_engine_version, (void *)lugha_link_keys_generate,
    (void *)lugha_link_pair_start, (void *)lugha_link_pair_finish, (void *)lugha_link_pairing_free,
    (void *)lugha_link_session_start, (void *)lugha_link_session_complete, (void *)lugha_link_encrypt,
    (void *)lugha_link_decrypt, (void *)lugha_link_session_free,
    // Remote Play, called from the lugha_remoteplay pod.
    (void *)lugha_rpx_discover, (void *)lugha_rpx_register, (void *)lugha_rpx_wakeup, (void *)lugha_rpx_start,
    (void *)lugha_rpx_request_keyframe, (void *)lugha_rpx_set_pin, (void *)lugha_rpx_stop,
};
