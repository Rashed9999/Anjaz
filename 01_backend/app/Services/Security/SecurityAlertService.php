<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-SENTINEL-001 — قناة التنبيهات الأمنية.
 *
 * يرسل تنبيهاً عند حدث حرج عبر مسارين (كلاهما اختياري، fail-safe):
 *   1. Sentry — إن كان sentry/sentry-laravel مثبّتاً (نتحقق بـ function_exists
 *      حتى لا نُلزم بوجود الحزمة).
 *   2. Webhook عام (Slack/Telegram/Discord/أي endpoint) عبر HTTP — بلا أي تبعية.
 *
 * أي فشل في الإرسال لا يكسر التطبيق ولا يعرقل الطلب.
 */
class SecurityAlertService
{
    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $message, array $context = []): void
    {
        // 1) Sentry (إن وُجد)
        try {
            if (config('security_sentinel.alerts.sentry', true) && function_exists('Sentry\\captureMessage')) {
                \Sentry\configureScope(function ($scope) use ($context): void {
                    $scope->setContext('sentinel', $context);
                    $scope->setLevel(\Sentry\Severity::warning());
                });
                \Sentry\captureMessage($message);
            }
        } catch (\Throwable $e) {
            Log::warning('sentinel.alert.sentry_failed', ['error' => $e->getMessage()]);
        }

        // 2) Webhook عام (Slack/Telegram/...)
        try {
            $url = config('security_sentinel.alerts.webhook_url');
            if (is_string($url) && $url !== '') {
                Http::timeout(4)->post($url, [
                    'text' => '🛡️ ' . $message,
                    'message' => $message,
                    'context' => $context,
                    'app' => 'amial_pay',
                    'env' => app()->environment(),
                    'at' => now()->toIso8601String(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('sentinel.alert.webhook_failed', ['error' => $e->getMessage()]);
        }
    }
}
