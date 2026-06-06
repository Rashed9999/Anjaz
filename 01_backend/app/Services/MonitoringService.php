<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * AMIAL-MONITORING-001 (v1.0-B)
 *
 * MonitoringService — wrapper موحد لتسجيل metrics و events.
 *
 * **التصميم:**
 *   - يستخدم Sentry لو مُثبَّت (`sentry/sentry-laravel`)
 *   - يستخدم Log facade دائماً (fallback)
 *   - kfeatures: capture, breadcrumb, metric, alert
 *
 * **التركيب:**
 *   composer require sentry/sentry-laravel
 *   php artisan sentry:publish --dsn=https://YOUR_DSN@sentry.io/PROJECT
 *
 * **استخدام:**
 *   $monitoring->captureException($e, ['order_id' => $order->id]);
 *   $monitoring->captureMessage('Bill pay slow', 'warning', ['latency_ms' => 5000]);
 *   $monitoring->metric('bill_pay.success', 1, ['provider' => 'stub']);
 *   $monitoring->alert('CRITICAL', 'Queue depth exceeded 10k', ['depth' => 12345]);
 */
class MonitoringService
{
    /**
     * يلتقط exception ويرسله لـ Sentry + Log.
     */
    public function captureException(\Throwable $e, array $context = []): void
    {
        Log::error($e->getMessage(), array_merge($context, [
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace_summary' => substr($e->getTraceAsString(), 0, 1000),
        ]));

        if ($this->isSentryAvailable()) {
            try {
                \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($context) {
                    foreach ($context as $key => $value) {
                        $scope->setExtra($key, $value);
                    }
                });
                \Sentry\captureException($e);
            } catch (\Throwable $sentryError) {
                Log::warning('Sentry capture failed', ['err' => $sentryError->getMessage()]);
            }
        }
    }

    /**
     * يلتقط رسالة (لا exception).
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): void
    {
        $logLevel = match ($level) {
            'critical', 'fatal' => 'critical',
            'error' => 'error',
            'warning' => 'warning',
            'info' => 'info',
            default => 'debug',
        };
        Log::$logLevel($message, $context);

        if ($this->isSentryAvailable()) {
            try {
                \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($context, $level) {
                    foreach ($context as $key => $value) {
                        $scope->setExtra($key, $value);
                    }
                    $scope->setLevel($this->sentryLevel($level));
                });
                \Sentry\captureMessage($message);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * يسجل metric (counter, gauge).
     *
     * مع Sentry Performance أو StatsD، هذا يصبح metric حقيقي.
     */
    public function metric(string $name, float $value = 1, array $tags = []): void
    {
        Log::info("metric.{$name}", ['value' => $value, 'tags' => $tags]);
        // future: send to StatsD/Sentry metrics
    }

    /**
     * تنبيه عاجل (مع log critical).
     *
     * في production: يجب أن يرسل إشعار Slack/Email/SMS.
     * في v1.0 نسجل critical log + Sentry. مستقبلاً نضيف Slack webhook.
     */
    public function alert(string $severity, string $message, array $context = []): void
    {
        Log::critical("ALERT[{$severity}]: {$message}", $context);

        if ($this->isSentryAvailable()) {
            $this->captureMessage("ALERT: {$message}", 'critical', $context);
        }

        // Slack webhook (لو configured)
        $webhook = config('services.alerts.slack_webhook');
        if ($webhook) {
            try {
                \Http::timeout(3)->post($webhook, [
                    'text' => "🚨 *{$severity}*: {$message}",
                    'attachments' => [[
                        'color' => 'danger',
                        'fields' => array_map(
                            fn($k, $v) => ['title' => $k, 'value' => (string)$v, 'short' => true],
                            array_keys($context),
                            array_values($context),
                        ),
                    ]],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Slack alert failed', ['err' => $e->getMessage()]);
            }
        }
    }

    private function isSentryAvailable(): bool
    {
        return function_exists('\Sentry\captureException')
            && !empty(config('sentry.dsn'));
    }

    private function sentryLevel(string $level)
    {
        if (!class_exists(\Sentry\Severity::class)) return null;
        return match ($level) {
            'critical', 'fatal' => \Sentry\Severity::fatal(),
            'error' => \Sentry\Severity::error(),
            'warning' => \Sentry\Severity::warning(),
            'info' => \Sentry\Severity::info(),
            default => \Sentry\Severity::debug(),
        };
    }
}
