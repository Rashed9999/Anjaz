<?php

return [
    /*
     * AMIAL-EMAIL-OTP-001
     *
     * Pilot mode uses email for OTP while the existing SMS/WhatsApp stack stays
     * intact and can be re-enabled without changing controllers or database data.
     */
    'registration_channel' => env('REGISTRATION_OTP_CHANNEL', 'email'),
    'password_reset_channel' => env('PASSWORD_RESET_CHANNEL', 'email'),
    'pin_recovery_channel' => env('PIN_RECOVERY_CHANNEL', 'email'),

    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
    'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'verification_ttl_minutes' => (int) env('OTP_VERIFICATION_TTL_MINUTES', 10),

    'resend' => [
        'api_key' => env('RESEND_API_KEY'),
        'api_url' => env('RESEND_API_URL', 'https://api.resend.com/emails'),
        'from_address' => env('MAIL_FROM_ADDRESS', 'verify@amialpay.com'),
        'from_name' => env('MAIL_FROM_NAME', 'Amial Pay'),
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    ],
];
