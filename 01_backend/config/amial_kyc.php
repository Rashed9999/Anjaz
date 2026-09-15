<?php

/**
 * AMIAL-KYC-BIOMETRIC-001 — إعداد مزود إثبات الحياة ومطابقة الوجه.
 *
 * `none` يعني غير مربوط، لا يعني فشل المستخدم ولا نجاحه. لا توجد قيم
 * تجريبية أو درجات وهمية في الإنتاج؛ عند إضافة مزود حقيقي يضاف Driver
 * صريح إلى registry أدناه ويُضبط alias عبر البيئة قبل التفعيل.
 *
 * مهم: ENV لا يحمل اسم class. هذا registry هو القائمة الوحيدة المسموح
 * تشغيلها، حتى لا يتحول متغير بيئة إلى class injection.
 */
return [
    'biometric' => [
        'provider' => env('AMIAL_KYC_BIOMETRIC_PROVIDER', 'none'),
        'enabled' => filter_var(
            env('AMIAL_KYC_BIOMETRIC_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),

        // alias => Driver class. تبقى فارغة حتى توقيع عقد مزود حقيقي.
        'drivers' => [
            // 'provider_alias' => \App\Services\Kyc\Biometric\Drivers\RealProviderDriver::class,
        ],

        // حماية من إنشاء جلسات متكررة عند النقر أو إعادة المحاولة السريعة.
        'restart_cooldown_seconds' => (int) env('AMIAL_KYC_BIOMETRIC_RESTART_COOLDOWN', 120),
        'attempt_ttl_minutes' => (int) env('AMIAL_KYC_BIOMETRIC_ATTEMPT_TTL', 30),
        'event_retention_days' => (int) env('AMIAL_KYC_BIOMETRIC_EVENT_RETENTION_DAYS', 180),
    ],
];
