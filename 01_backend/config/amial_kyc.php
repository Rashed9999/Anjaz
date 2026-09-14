<?php

/**
 * AMIAL-KYC-BIOMETRIC-001 — إعداد مزود إثبات الحياة ومطابقة الوجه.
 *
 * `none` يعني غير مربوط، لا يعني فشل المستخدم ولا نجاحه. لا توجد قيم
 * تجريبية أو درجات وهمية في الإنتاج؛ عند إضافة مزود حقيقي يضاف Driver
 * صريح ويُضبط اسمه هنا عبر البيئة قبل تفعيل خيار التحقق الآلي للمستخدم.
 */
return [
    'biometric' => [
        'provider' => env('AMIAL_KYC_BIOMETRIC_PROVIDER', 'none'),
        'enabled' => filter_var(
            env('AMIAL_KYC_BIOMETRIC_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],
];
