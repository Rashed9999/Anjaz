<?php

return [
    // بوابة المراجعة على النطاق الفرعي. يترك فارغاً قبل نشر موثّق؛ تبقى
    // الطلبات محفوظة في صندوق الإرسال ولا يُنشأ مسار تسجيل بديل للمستخدم.
    'base_url' => env('MUWATHAQ_BASE_URL'),
    'bridge_secret' => env('MUWATHAQ_BRIDGE_SECRET'),
    'timeout_seconds' => (int) env('MUWATHAQ_TIMEOUT_SECONDS', 10),

    /*
     * سرّ مشترك خاص باتصال الخادم بالخادم فقط. إذا غاب، تبقى بوابة القرار
     * مغلقة؛ لا يوجد وضع افتراضي أو مفتاح تطوير يمكن أن يفتح حسابات.
     */
    'decision_secret' => env('MUWATHAQ_DECISION_SECRET'),
    'allowed_clock_skew_seconds' => (int) env('MUWATHAQ_ALLOWED_CLOCK_SKEW_SECONDS', 300),
];
