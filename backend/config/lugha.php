<?php

return [
    'locales' => ['ar', 'en'],
    'default_locale' => 'ar',
    'currency' => 'SAR',
    // Active devices per account: desktop/mobile apps together, TVs separately.
    'device_limits' => ['app' => (int) env('LUGHA_DEVICE_LIMIT_APPS', 3), 'tv' => (int) env('LUGHA_DEVICE_LIMIT_TVS', 2)],
    // Offline license window, in days; refreshed whenever the app syncs.
    'license_days' => (int) env('LUGHA_LICENSE_DAYS', 60),
    // Package signing (docs/04 §3). Production: keep the key in a KMS/HSM and sign there.
    'signing_key_path' => env('LUGHA_SIGNING_KEY_PATH', storage_path('app/private/keys/signing-key.json')),
    'pkg_binary' => env('LUGHA_PKG_BINARY', base_path('../engine/target/release/lugha-pkg')),
    // Staff must enrol an authenticator app before using the admin. Only turn off on a developer machine.
    'admin_mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', true),

    // Largest admin upload (translation import files), in KB. Must stay under PHP's upload_max_filesize.
    'max_upload_kb' => (int) env('LUGHA_MAX_UPLOAD_KB', 204800),

    // Saudi VAT in basis points (1500 = 15%). Store prices are VAT-inclusive.
    'vat_rate_bps' => (int) env('LUGHA_VAT_RATE_BPS', 1500),
    'seller' => [
        'name' => env('LUGHA_SELLER_NAME', 'LUGHA'),
        'vat_number' => env('LUGHA_SELLER_VAT_NUMBER'),     // 15 digits, starts and ends with 3
        'cr_number' => env('LUGHA_SELLER_CR_NUMBER'),
        'address' => env('LUGHA_SELLER_ADDRESS'),
    ],
];
