<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AMIAL-TRANSACTION-EMAIL-001
    |--------------------------------------------------------------------------
    |
    | Transaction receipts use a sender separate from security/OTP mail.
    | The same verified Resend domain/API key may be reused, but customers see
    | a stable receipts identity instead of the OTP sender.
    |
    */
    'enabled' => (bool) env('AMIAL_TRANSACTION_EMAIL_ENABLED', true),

    'from_address' => env(
        'AMIAL_TRANSACTION_EMAIL_FROM_ADDRESS',
        'receipts@amialpay.com'
    ),

    'from_name' => env(
        'AMIAL_TRANSACTION_EMAIL_FROM_NAME',
        'أميال باي | إيصالات المعاملات'
    ),

    'website_url' => env(
        'AMIAL_WEBSITE_URL',
        env('APP_URL', 'https://amialpay.com')
    ),

    'resend' => [
        'api_key' => env('RESEND_API_KEY'),
        'api_url' => env('RESEND_API_URL', 'https://api.resend.com/emails'),
    ],
];
