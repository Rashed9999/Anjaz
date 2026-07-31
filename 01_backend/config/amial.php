<?php

/**
 * AMIAL — إعدادات المنصة الموحدة.
 *
 * استخدام: config('amial.safe_payment.fee_percent')
 */

return [
    // ============================================================
    // AMIAL-FIX(H2) — شبكة الوكلاء (شحن الرصيد)
    // ============================================================
    'agent' => [
        // الحدّ الأقصى لمبلغ شحن رصيد الوكيل الواحد (ريال) — حاجز أمان
        'max_topup' => env('AMIAL_AGENT_MAX_TOPUP', '100000000'),
    ],

    // تخطّي كابتشا دخول الأدمن — للعرض/الاختبار فقط (الإنتاج: false)
    'disable_admin_captcha' => env('AMIAL_DISABLE_ADMIN_CAPTCHA', false),

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
        // AMIAL-FIX: مفاتيح ثابتة افتراضية (كانت تُولَّد عشوائياً كل نشر فتنكسر
        // فهارس البحث المُعمّاة → فشل الدخول). للإنتاج: اضبطها كمتغيّرات بيئة.
        'pii_key' => env('AMIAL_PII_ENCRYPTION_KEY', 'ynZEB1h/HBqgQPmWKH7AuB/NVqpSpqkT+GiqnF+wQmo='),
        'blind_index_key' => env('AMIAL_PII_BLIND_INDEX_KEY', 'aPq8RXLclEIEz6I26E2UEzaRGT3nQrcZhR5NkcY5Q3k='),

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

    // ============================================================
    // AMIAL-AML-001 — محرّك مكافحة غسل الأموال (AML)
    // ============================================================
    'aml' => [
        // مفتاح التشغيل الرئيسي. عند false لا يُستدعى المحرّك إطلاقاً.
        // التشغيل آمن: القواعد تبدأ في وضع الظل (shadow) فلا توقف أي معاملة
        // حتى يفعّلها الأدمن صراحةً — إطلاق مصرفي متدرّج (observe ← enforce).
        'enabled' => env('AMIAL_AML_ENABLED', true),

        // الأنواع الخاضعة للفحص. البقية تمرّ بلا فحص.
        'screened_types' => [
            'send_money', 'cash_out', 'safe_payment_fund', 'donation', 'pay_merchant',
        ],
    ],

    // ============================================================
    // AMIAL-GOVERNORATES-001 — نطاق التشغيل الجغرافي
    // ============================================================
    //
    // سبب وجود أميال باي في الجنوب والمناطق المحرّرة وحدها ليس تفضيلاً:
    // اليمن فيه بنكان مركزيان وعملتان تحملان اسم «الريال» بسعرين مختلفين.
    // النظام يحسب بوحدة واحدة، فلو عملت عبر الخط صار كل تحويل مراجحةَ عملة
    // على حساب سيولتك — والحساب متوازن في الدفاتر بينما الخسارة حقيقية.
    // يضاف إليه أن الترخيص من البنك المركزي في عدن.
    //
    // هذه القائمة هي «الجدول المتفق عليه». جدول المحافظات نفسه جغرافيا ثابتة
    // في App\Support\YemenGovernorates؛ أمّا أيّها تعمل فيه فقرار تشغيلي
    // يتغيّر مع خريطة السيطرة — ولذلك مكانه هنا، يُعدَّل بمتغيّر بيئة بلا
    // إصدار برمجي جديد.
    //
    // AMIAL_OPERATIONAL_GOVERNORATES=YE-AD,YE-LA,YE-AB (رموز مفصولة بفواصل)
    'operational_governorates' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'AMIAL_OPERATIONAL_GOVERNORATES',
            // الافتراضي = ما كان معمولاً به فعلاً في ZoneAssignmentService
            'YE-AD,YE-LA,YE-AB,YE-DA,YE-SH,YE-HD,YE-MR,YE-SU'
        ))
    ))),

    // AMIAL-ZONE-BOUNDARY-001 — إلزام موقع الوكيل لحظة العملية النقدية.
    //
    //   soft   (افتراضي) يُرفض إن أُرسل موقع خارج النطاق، ويُسجَّل تحذير إن غاب.
    //                    ابدأ به: الوكلاء الذين لم يحدّثوا التطبيق بعد لا
    //                    يتعطّلون، والمخالف الفعلي يُرفض ويُدقَّق.
    //   strict           يُرفض أيضاً إن غاب الموقع. فعّله بعد تحديث كل الوكلاء.
    //   off              تسجيل فقط — للطوارئ.
    'agent_location_mode' => env('AMIAL_AGENT_LOCATION_MODE', 'soft'),

    // ============================================================
    // AMIAL-INSIDER-001 — مراقبة سلوك الموظفين (التهديد الداخلي)
    // ============================================================
    'insider_watch' => [
        // أقصى اطّلاعات على ملفات عملاء لموظف/يوم قبل تنبيه حرج
        'max_profile_views_per_day' => env('AMIAL_IW_MAX_VIEWS', 150),
        // أقصى عمليات بحث لموظف/يوم
        'max_searches_per_day' => env('AMIAL_IW_MAX_SEARCHES', 200),
        // أي وصول خارج الدوام يُنبَّه عليه (0 = أول وصول ليلي يُنبِّه)
        'max_after_hours' => env('AMIAL_IW_MAX_AFTER_HOURS', 0),
        // ساعات الليل: من night_start إلى night_end
        'night_start' => env('AMIAL_IW_NIGHT_START', 22),
        'night_end' => env('AMIAL_IW_NIGHT_END', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | AMIAL-DUAL-APPROVAL-001 — التسويات
    |--------------------------------------------------------------------------
    */
    'settlement' => [
        // فوق هذا المبلغ (شاملاً) تحتاج التسوية موافقة موظّفين اثنين.
        //
        // موافقتان على كل تسوية صغيرة تُعطّل العمل، فيُعتمد بالجملة بلا قراءة
        // ويصير الضابط طقساً. والحدّ يجعل التأنّي حيث يستحقّ.
        //
        // ضبطُه إلى 0 يعني «موافقتان دائماً» لا «واحدة دائماً»: الإعداد
        // الناقص يُشدّد ولا يُرخي.
        'dual_approval_threshold' => env('AMIAL_SETTLEMENT_DUAL_THRESHOLD', '100000'),
    ],
];
