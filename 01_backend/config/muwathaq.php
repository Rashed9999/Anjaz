<?php

return [
    /*
     * سرّ مشترك خاص باتصال الخادم بالخادم فقط. إذا غاب، تبقى بوابة القرار
     * مغلقة؛ لا يوجد وضع افتراضي أو مفتاح تطوير يمكن أن يفتح حسابات.
     */
    'decision_secret' => env('MUWATHAQ_DECISION_SECRET'),
    'allowed_clock_skew_seconds' => (int) env('MUWATHAQ_ALLOWED_CLOCK_SKEW_SECONDS', 300),
];
