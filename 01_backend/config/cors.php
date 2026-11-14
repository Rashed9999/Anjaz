<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // AMIAL-SEC-CORS-001 — لا `*` في الإنتاج. تطبيقُ الجوال لا يخضع لـCORS أصلاً (طلباتٌ أصليّة)،
    // فالمسموح هنا نطاقاتُ الويب الرسميّة وحدَها: CORS_ALLOWED_ORIGINS (مفصولةٌ بفواصل) وإلا APP_URL.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('APP_URL', '')))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'Idempotency-Key', 'X-Device-Id', 'X-Request-Id', 'Accept-Language'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
