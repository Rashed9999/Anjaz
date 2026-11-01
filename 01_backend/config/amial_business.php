<?php

/**
 * Amial Pay is the only authority for wallet balances and payment settlement.
 * No default HMAC secrets. Unconfigured endpoints must remain unavailable.
 */
return [
    'inbound_secret' => env('AMIAL_BUSINESS_INBOUND_SECRET'),
    'mobile_pos_catalog_enabled' => (bool) env('AMIAL_BUSINESS_MOBILE_POS_CATALOG_ENABLED', false),
    'mobile_pos_base_url' => env('AMIAL_BUSINESS_MOBILE_POS_BASE_URL'),
    'mobile_pos_secret' => env('AMIAL_BUSINESS_MOBILE_POS_SECRET'),
    'webhook_secret' => env('AMIAL_BUSINESS_WEBHOOK_SECRET'),
    'webhook_url' => env('AMIAL_BUSINESS_WEBHOOK_URL'),
    'webhook_timeout_seconds' => (int) env('AMIAL_BUSINESS_WEBHOOK_TIMEOUT_SECONDS', 10),
    'auto_link_on_approval' => (bool) env('AMIAL_BUSINESS_AUTO_LINK', true),
    'allowed_clock_skew_seconds' => (int) env('AMIAL_BUSINESS_ALLOWED_CLOCK_SKEW_SECONDS', 300),
    'wallet_currency' => env('AMIAL_BUSINESS_WALLET_CURRENCY', 'YER'),
    'intent_ttl_seconds' => ['default' => 300, 'min' => 60, 'max' => 900],
    'webhook_backoff_seconds' => [60, 300, 900, 3600, 21600, 43200],
];
