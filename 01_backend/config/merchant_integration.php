<?php

return [
    // This adapter is disabled in every environment until staging proves parity.
    'enabled' => (bool) env('AMIAL_MERCHANT_BRIDGE_ENABLED', false),
    'base_url' => env('AMIAL_MERCHANT_BASE_URL', ''),
    'secret' => env('AMIAL_MERCHANT_BRIDGE_SECRET', ''),
    'issuer' => 'amial-pay',
    'audience' => 'amial-merchant',
    'timeout_seconds' => 5,
    'connect_timeout_seconds' => 2,
];
