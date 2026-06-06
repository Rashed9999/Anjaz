<?php

namespace App\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * AMIAL-MONITORING-001 (v1.0-B)
 *
 * StructuredLogger — يحول كل log entry إلى JSON موحد.
 *
 * **استخدام:**
 *   في `config/logging.php` ضمن channels:
 *
 *   'structured' => [
 *       'driver' => 'custom',
 *       'via' => \App\Logging\StructuredLogger::class,
 *       'path' => storage_path('logs/structured.log'),
 *       'level' => 'info',
 *   ],
 *
 * ثم: `LOG_CHANNEL=structured` في .env (production)
 *
 * **النتيجة:** كل سطر log = JSON كامل، قابل للقراءة بـ tools مثل
 * Loki, ELK, CloudWatch Logs Insights, Datadog.
 */
class StructuredLogger
{
    /**
     * يُستدعى من Laravel logging factory.
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('amial');
        $handler = new StreamHandler(
            $config['path'] ?? storage_path('logs/structured.log'),
            $config['level'] ?? Logger::INFO,
        );

        // Formatter يحوّل كل entry إلى JSON
        $handler->setFormatter(new \Monolog\Formatter\JsonFormatter(
            \Monolog\Formatter\JsonFormatter::BATCH_MODE_NEWLINES,
        ));

        // Processor يُضيف context عام لكل log
        $logger->pushProcessor(function ($record) {
            $record['extra']['app'] = 'amial_pay';
            $record['extra']['env'] = app()->environment();
            $record['extra']['app_version'] = config('app.version', '1.0.0');
            $record['extra']['hostname'] = gethostname();

            // أضف request context إن وجد
            if (app()->runningInConsole()) {
                $record['extra']['source'] = 'cli';
            } else {
                try {
                    $request = request();
                    if ($request) {
                        $record['extra']['request_id'] = $request->headers->get('X-Request-Id')
                            ?? \Illuminate\Support\Str::ulid()->toString();
                        $record['extra']['route'] = $request->path();
                        $record['extra']['method'] = $request->method();
                        $record['extra']['user_id'] = auth()->id();
                        $record['extra']['ip'] = $request->ip();
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            return $record;
        });

        $logger->pushHandler($handler);
        return $logger;
    }
}
