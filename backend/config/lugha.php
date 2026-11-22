<?php

return [
    'locales' => ['ar', 'en'],
    'default_locale' => 'ar',
    'currency' => 'SAR',
    // Staff must enrol an authenticator app before using the admin. Only turn off on a developer machine.
    'admin_mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', true),
    // Saudi VAT in basis points (1500 = 15%). Store prices are VAT-inclusive.
    'vat_rate_bps' => (int) env('LUGHA_VAT_RATE_BPS', 1500),
    'seller' => [
        'name' => env('LUGHA_SELLER_NAME', 'LUGHA'),
        'vat_number' => env('LUGHA_SELLER_VAT_NUMBER'),     // 15 digits, starts and ends with 3
        'cr_number' => env('LUGHA_SELLER_CR_NUMBER'),
        'address' => env('LUGHA_SELLER_ADDRESS'),
    ],
];
