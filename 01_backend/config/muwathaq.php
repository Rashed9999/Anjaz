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
    // إثبات ملكية هاتف العميل: reviewer (الآن: علامة المراجع في موثّق تُفعّل is_phone_verified) أو otp
    // (جاهز للتفعيل: لا يُفعَّل الهاتف من علامة المراجع، ويُثبته العميل برمز داخل التطبيق حين يُربط مزوّد SMS/WhatsApp).
    // يُقلَّب المفتاحان معاً: MUWATHAQ_PHONE_VERIFICATION هنا وفي موثّق.
    'phone_verification' => in_array(env('MUWATHAQ_PHONE_VERIFICATION', 'reviewer'), ['reviewer', 'otp'], true) ? env('MUWATHAQ_PHONE_VERIFICATION', 'reviewer') : 'reviewer',

    'tier_upgrade_mode' => env('MUWATHAQ_TIER_UPGRADE_MODE', 'off'),
    'tier_upgrade_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MUWATHAQ_TIER_UPGRADE_USER_IDS', ''))))),
    // أرقام حسابات/محافظ (8 أرقام) مسموحة في canary — بديل عن معرّف المستخدم الداخلي.
    'tier_upgrade_accounts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MUWATHAQ_TIER_UPGRADE_ACCOUNTS', ''))))),

    // CAP-02: مراجعة إثبات السكن داخل موثّق. off افتراضياً؛ canary لا يمس إلا المعرّفات المسموحة.
    'residence_review_mode' => env('MUWATHAQ_RESIDENCE_REVIEW_MODE', 'off'),
    // CAP-02b/c: طلبات تغيير البيان وتوثيق منشأة التاجر داخل موثّق. off افتراضياً.
    'profile_change_mode' => env('MUWATHAQ_PROFILE_CHANGE_MODE', 'off'),
    'profile_change_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MUWATHAQ_PROFILE_CHANGE_USER_IDS', ''))))),
    // أرقام حسابات/محافظ (8 أرقام) مسموحة في canary — بديل عن معرّف المستخدم الداخلي.
    'profile_change_accounts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MUWATHAQ_PROFILE_CHANGE_ACCOUNTS', ''))))),
    'merchant_verification_mode' => env('MUWATHAQ_MERCHANT_VERIFICATION_MODE', 'off'),
    'merchant_verification_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MUWATHAQ_MERCHANT_VERIFICATION_USER_IDS', ''))))),
    // أرقام حسابات/محافظ (8 أرقام) مسموحة في canary — بديل عن معرّف المستخدم الداخلي.
    'merchant_verification_accounts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MUWATHAQ_MERCHANT_VERIFICATION_ACCOUNTS', ''))))),
    'residence_review_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MUWATHAQ_RESIDENCE_REVIEW_USER_IDS', ''))))),
    // أرقام حسابات/محافظ (8 أرقام) مسموحة في canary — بديل عن معرّف المستخدم الداخلي.
    'residence_review_accounts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MUWATHAQ_RESIDENCE_REVIEW_ACCOUNTS', ''))))),
];
