<?php

return [
    // LUGHA's provider is Moyasar. 'fake' is only for local development and tests (refused in production).
    // 'hyperpay' is implemented as a dormant alternative; it stays off unless configured here.
    'default' => env('PAYMENT_GATEWAY', 'moyasar'),

    'moyasar' => [
        'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com/v1'),
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
    ],

    'hyperpay' => [
        'base_url' => env('HYPERPAY_BASE_URL', 'https://eu-test.oppwa.com'), // live: https://eu-prod.oppwa.com
        'access_token' => env('HYPERPAY_ACCESS_TOKEN'),
        'test_mode' => (bool) env('HYPERPAY_TEST_MODE', true),
        'webhook_key' => env('HYPERPAY_WEBHOOK_KEY'),
        // One entity per brand group; leave a method empty to hide it.
        'entities' => [
            'mada' => env('HYPERPAY_ENTITY_MADA'),
            'card' => env('HYPERPAY_ENTITY_CARD'),
            'applepay' => env('HYPERPAY_ENTITY_APPLEPAY'),
        ],
    ],
];
