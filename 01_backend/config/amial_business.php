<?php

/*
 * AMIAL-BUSINESS-GATEWAY-001 — قناة الخادم بالخادم بين أميال باي و«أميال أعمال».
 *
 * أميال أعمال تشغّل المنشأة (البيع والمخزون والدفاتر)، وأميال باي يبقى المصدر
 * الوحيد للمال. هذه القناة لا تحرّك ريالاً: تنشئ طلبات دفع QR للتاجر، وتُبلغ
 * أميال أعمال حين يدفعها العميل من محفظته عبر المسار المالي القائم.
 *
 * لكل اتجاه سرّ مستقل؛ إن غاب سرّ الاتجاه الوارد تبقى البوابة مغلقة (503)،
 * ولا يوجد سرّ افتراضي أو وضع تطوير يفتحها.
 */
return [
    // أميال أعمال → أميال باي: يوقّع به كل طلب وارد إلى /api/v1/business.
    'inbound_secret' => env('AMIAL_BUSINESS_INBOUND_SECRET'),

    // أميال باي → أميال أعمال: يوقّع به كل حدث يُرسل إلى webhook_url.
    'webhook_secret' => env('AMIAL_BUSINESS_WEBHOOK_SECRET'),
    'webhook_url' => env('AMIAL_BUSINESS_WEBHOOK_URL'),
    'webhook_timeout_seconds' => (int) env('AMIAL_BUSINESS_WEBHOOK_TIMEOUT_SECONDS', 10),

    // التاجر الذي يعتمده موثّق يُربط تلقائياً. يُطفأ بمتغير بيئة إن لزم.
    'auto_link_on_approval' => (bool) env('AMIAL_BUSINESS_AUTO_LINK', true),

    'allowed_clock_skew_seconds' => (int) env('AMIAL_BUSINESS_ALLOWED_CLOCK_SKEW_SECONDS', 300),

    // عملة محفظة التاجر. نيّة الدفع لا تُنشأ إلا بها؛ أي تحويل عملة (ريال
    // سعودي مثلاً) يحدث في أميال أعمال قبل طلب الدفع، لا هنا.
    'wallet_currency' => env('AMIAL_BUSINESS_WALLET_CURRENCY', 'YER'),

    // صلاحية رمز الدفع بالثواني. قصيرة عمداً: الرمز يُعرض على شاشة الكاشير.
    'intent_ttl_seconds' => [
        'default' => 300,
        'min' => 60,
        'max' => 900,
    ],

    // جدول إعادة محاولة تسليم الأحداث (ثوانٍ بعد كل فشل). بعد آخرها يصبح
    // الحدث «dead» ويُسجَّل تنبيه تشغيلي؛ لا يُحذف شيء.
    'webhook_backoff_seconds' => [60, 300, 900, 3600, 21600, 43200],
];
