<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // AMIAL-FIX: اعتمادات واتساب من config (تنجو من config:cache و FPM clear_env).
    'whatsapp' => [
        'access_token'    => env('META_WA_ACCESS_TOKEN', ''),
        'app_secret'      => env('META_WA_APP_SECRET', ''),
        'phone_number_id' => env('META_WA_PHONE_NUMBER_ID', ''),
        'verify_token'    => env('META_WA_VERIFY_TOKEN', ''),
        'graph_version'   => env('META_WA_GRAPH_VERSION', 'v19.0'),
    ],

];
