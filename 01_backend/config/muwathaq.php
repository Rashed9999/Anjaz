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

    // CAP-01: مراجعة ترقية Tier 2 داخل موثّق. off افتراضياً؛ canary لا يمس إلا المعرّفات المسموحة.
    'tier_upgrade_mode' => env('MUWATHAQ_TIER_UPGRADE_MODE', 'off'),
    'tier_upgrade_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MUWATHAQ_TIER_UPGRADE_USER_IDS', ''))))),

    // CAP-02: مراجعة إثبات السكن داخل موثّق. off افتراضياً؛ canary لا يمس إلا المعرّفات المسموحة.
    'residence_review_mode' => env('MUWATHAQ_RESIDENCE_REVIEW_MODE', 'off'),
    'residence_review_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MUWATHAQ_RESIDENCE_REVIEW_USER_IDS', ''))))),
];
