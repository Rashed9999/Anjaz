<?php

return [
    // بوابة المراجعة على النطاق الفرعي. يترك فارغاً قبل نشر موثّق؛ تبقى
    // الطلبات محفوظة في صندوق الإرسال ولا يُنشأ مسار تسجيل بديل للمستخدم.
    'base_url' => env('MUWATHAQ_BASE_URL'),
    // سر نقل موحّد اختياري. يشتق منه النظام مفتاحين منفصلين لكل اتجاه.
    // عند ضبط bridge_secret/decision_secret صراحةً تكون لهما الأولوية لتدوير آمن.
    'transport_secret' => env('MUWATHAQ_TRANSPORT_SECRET'),
    'bridge_secret' => env('MUWATHAQ_BRIDGE_SECRET'),
    'timeout_seconds' => (int) env('MUWATHAQ_TIMEOUT_SECONDS', 10),

    /*
     * سرّ مشترك خاص باتصال الخادم بالخادم فقط. إذا غاب، تبقى بوابة القرار
     * مغلقة؛ لا يوجد وضع افتراضي أو مفتاح تطوير يمكن أن يفتح حسابات.
     */
    'decision_secret' => env('MUWATHAQ_DECISION_SECRET'),
    'allowed_clock_skew_seconds' => (int) env('MUWATHAQ_ALLOWED_CLOCK_SKEW_SECONDS', 300),
];
