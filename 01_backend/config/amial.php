<?php

/**
 * AMIAL — إعدادات المنصة الموحدة.
 *
 * استخدام: config('amial.safe_payment.fee_percent')
 */

return [
    // ============================================================
    // AMIAL-SAFE-PAYMENT-001 (v1.1)
    // ============================================================
    'safe_payment' => [
        // رسوم المنصة (نسبة، تخصم عند الإفراج للبائع)
        'fee_percent' => env('AMIAL_SAFE_PAYMENT_FEE_PERCENT', '1.0'),

        // الحد الأقصى للمبلغ (ريال سعودي)
        'max_amount' => env('AMIAL_SAFE_PAYMENT_MAX_AMOUNT', '100000.0000'),

        // مهلة قبول البائع (ساعات)
        'seller_response_hours' => env('AMIAL_SAFE_PAYMENT_SELLER_HOURS', 72),

        // مهلة المشتري لتأكيد الاستلام (أيام) — للتنبيه فقط في v1
        'buyer_confirm_days' => env('AMIAL_SAFE_PAYMENT_BUYER_DAYS', 7),
    ],

    // ============================================================
    // AMIAL-BILL-PAY-001
    // ============================================================
    'bill_pay' => [
        'reconcile_window_hours' => env('AMIAL_BILL_RECONCILE_HOURS', 24),
        'max_orders_per_reconcile' => env('AMIAL_BILL_MAX_RECONCILE', 50),
    ],

    // ============================================================
    // AMIAL-FUND-FAMILY-001
    // ============================================================
    'family_fund' => [
        'max_members' => env('AMIAL_FUND_MAX_MEMBERS', 50),
        'max_funds_per_user' => env('AMIAL_FUND_MAX_PER_USER', 10),
    ],

    // ============================================================
    // AMIAL-RECEIPTS-001
    // ============================================================
    'receipts' => [
        'storage_disk' => env('AMIAL_RECEIPTS_DISK', 'local'),
        'retention_days' => env('AMIAL_RECEIPTS_RETENTION_DAYS', 730), // سنتان
    ],

    // ============================================================
    // AMIAL-PII-ENCRYPTION-001 (v1.3)
    // ============================================================
    'encryption' => [
        // base64-encoded 32 bytes; toggle to fresh ones via:
        // php -r 'echo base64_encode(random_bytes(32)) . PHP_EOL;'
        'pii_key' => env('AMIAL_PII_ENCRYPTION_KEY'),
        'blind_index_key' => env('AMIAL_PII_BLIND_INDEX_KEY'),

        // فحوصات أمان
        'require_keys_in_production' => env('AMIAL_REQUIRE_PII_KEYS', true),
    ],

    // ============================================================
    // AMIAL-DONATIONS-001 (v1.2)
    // ============================================================
    'donations' => [
        // رسوم المنصة (نسبة، تخصم من كل تبرع)
        'fee_percent' => env('AMIAL_DONATIONS_FEE_PERCENT', '1.0'),

        // الحد الأدنى للتبرع
        'min_amount' => env('AMIAL_DONATIONS_MIN_AMOUNT', '1.0000'),

        // الحد الأقصى للتبرع الواحد
        'max_amount' => env('AMIAL_DONATIONS_MAX_AMOUNT', '50000.0000'),
    ],
];
