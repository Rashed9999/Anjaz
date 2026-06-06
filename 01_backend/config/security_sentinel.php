<?php

/**
 * AMIAL-SENTINEL-001 — إعدادات الحارس المخفي.
 *
 * استخدام: config('security_sentinel.mode')
 */

return [
    // تفعيل/تعطيل الحارس بالكامل
    'enabled' => env('SENTINEL_ENABLED', true),

    // 'monitor' = يرصد ويسجّل فقط (آمن، افتراضي)
    // 'block'   = يحظر الطلبات التي تتجاوز block_threshold
    'mode' => env('SENTINEL_MODE', 'monitor'),

    // عتبة الحظر (0-100): score >= هذا → حظر (في وضع block)
    'block_threshold' => (int) env('SENTINEL_BLOCK_THRESHOLD', 80),

    // عتبة التحذير: score >= هذا → severity=warning + challenge
    'warning_threshold' => (int) env('SENTINEL_WARNING_THRESHOLD', 40),

    // تخزين الأحداث في جدول sentinel_events
    'store_db' => env('SENTINEL_STORE_DB', true),

    // قناة السجل (يفضّل 'structured' في الإنتاج)
    'log_channel' => env('SENTINEL_LOG_CHANNEL', 'stack'),

    // عناوين IP موثوقة (لا تُفحَص) — مثلاً بوابات الدفع/المراقبة
    'whitelist_ips' => array_filter(explode(',', (string) env('SENTINEL_WHITELIST_IPS', ''))),

    // مسارات تُتجاهَل (تدعم wildcards عبر fnmatch)
    'ignore_paths' => [
        'health/*',
        'api/v1/amial/ping',
    ],

    // ============================================================
    // الحظر التلقائي لـ IP المتكرر
    // ============================================================
    'auto_block' => [
        'enabled' => env('SENTINEL_AUTO_BLOCK', true),

        // عدد الأحداث الحرجة من نفس IP خلال النافذة الذي يُفعّل الحظر
        'threshold' => (int) env('SENTINEL_AUTO_BLOCK_THRESHOLD', 5),

        // نافذة العدّ (دقائق)
        'window_minutes' => (int) env('SENTINEL_AUTO_BLOCK_WINDOW', 60),

        // مدّة الحظر (دقائق)
        'duration_minutes' => (int) env('SENTINEL_AUTO_BLOCK_DURATION', 1440), // 24h
    ],

    // ============================================================
    // التنبيهات (Sentry + Webhook)
    // ============================================================
    'alerts' => [
        // إرسال للـ Sentry إن كان sentry/sentry-laravel مثبّتاً
        'sentry' => env('SENTINEL_ALERT_SENTRY', true),

        // Webhook عام (Slack/Telegram/Discord/...) — اتركه فارغاً للتعطيل
        'webhook_url' => env('SENTINEL_ALERT_WEBHOOK', ''),
    ],
];
