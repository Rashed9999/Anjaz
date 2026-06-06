<?php

namespace App\Services\Security;

use App\Models\AccountSecurityEvent;
use App\Models\SentinelBlockedIp;
use App\Models\SentinelEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-SENTINEL-001 — دماغ الحارس المخفي.
 *
 * يفحص الطلب، يحسب "نقاط خطورة" بناءً على التوقيعات، يقرر الإجراء
 * (monitor / challenge / block)، ويسجّل الحدث في عدة وجهات:
 *   - جدول sentinel_events (دائماً، لو store_db مفعّل)
 *   - account_security_events (لو المستخدم مُصادَقاً — يظهر في شاشة "أمان الحساب")
 *   - قناة السجل (structured / Sentry لاحقاً)
 *
 * مبدأ التصميم: **fail-safe** — أي خطأ داخلي لا يجب أن يكسر الطلب.
 */
class SecuritySentinelService
{
    public const ACTION_MONITOR = 'monitor';
    public const ACTION_CHALLENGE = 'challenge';
    public const ACTION_BLOCK = 'block';

    private const CACHE_PREFIX = 'sentinel:blocked:';

    public function __construct(private readonly SecurityAlertService $alerts)
    {
    }

    /**
     * يحلّل الطلب ويُرجع تقريراً.
     *
     * @return array{score:int, severity:string, action:string, signatures:array<int,string>}
     */
    public function analyze(Request $request): array
    {
        $matched = [];
        $score = 0;

        // 1) فحص User-Agent ضد بصمات الفاحصات
        $ua = strtolower((string) $request->userAgent());
        foreach (ThreatSignatures::scannerUserAgents() as $needle) {
            if ($ua !== '' && str_contains($ua, $needle)) {
                $matched[] = 'SCANNER_UA:' . $needle;
                $score += 30;
                break; // توقيع واحد يكفي لهذه الفئة
            }
        }
        if ($ua === '') {
            $matched[] = 'EMPTY_UA';
            $score += 15;
        }

        // 2) فحص المسار ضد مسارات الطُّعم
        $path = strtolower($request->path());
        foreach (ThreatSignatures::baitPaths() as $bait) {
            if (str_contains($path, strtolower($bait))) {
                $matched[] = 'BAIT_PATH:' . $bait;
                $score += 60; // طلب طُعم = مؤشر قوي جداً
                break;
            }
        }

        // 3) فحص المدخلات (query + body + raw path) ضد أنماط الحقن
        $haystack = $this->collectInputString($request);
        if ($haystack !== '') {
            foreach (ThreatSignatures::inputPatterns() as $sig) {
                if (@preg_match($sig['pattern'], $haystack) === 1) {
                    $matched[] = $sig['code'];
                    $score += $sig['weight'];
                }
            }
        }

        $score = min($score, 100);

        return [
            'score' => $score,
            'severity' => $this->severityFor($score),
            'action' => $this->actionFor($score),
            'signatures' => array_values(array_unique($matched)),
        ];
    }

    /**
     * يسجّل الحدث (fail-safe — لا يرمي استثناءات للأعلى).
     *
     * @param array{score:int, severity:string, action:string, signatures:array<int,string>} $report
     */
    public function record(Request $request, array $report): void
    {
        try {
            if (config('security_sentinel.store_db', true)) {
                SentinelEvent::create([
                    'user_id' => optional($request->user())->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                    'method' => $request->method(),
                    'path' => mb_substr($request->path(), 0, 500),
                    'threat_score' => $report['score'],
                    'severity' => $report['severity'],
                    'signatures' => $report['signatures'],
                    'action' => $report['action'],
                    'request_id' => $request->headers->get('X-Request-Id'),
                ]);
            }

            // جسر مع شاشة "أمان الحساب" إن كان المستخدم معروفاً
            $user = $request->user();
            if ($user && $report['severity'] !== 'info') {
                AccountSecurityEvent::create([
                    'user_id' => $user->id,
                    'event_type' => 'SENTINEL_ALERT',
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                    'note' => 'نشاط مشبوه على الحساب (Sentinel)',
                    'metadata' => [
                        'score' => $report['score'],
                        'signatures' => $report['signatures'],
                        'path' => $request->path(),
                    ],
                    'severity' => $report['severity'] === 'critical' ? 'critical' : 'warning',
                ]);
            }

            Log::channel(config('security_sentinel.log_channel', 'stack'))->warning('sentinel.alert', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'method' => $request->method(),
                'score' => $report['score'],
                'action' => $report['action'],
                'signatures' => $report['signatures'],
                'user_id' => optional($request->user())->id,
            ]);

            // عند حدث حرج: تنبيه فوري + تقييم الحظر التلقائي
            if ($report['severity'] === 'critical') {
                $this->alerts->critical('نشاط حرج رصده الحارس', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'score' => $report['score'],
                    'signatures' => $report['signatures'],
                ]);

                $this->maybeAutoBlock($request);
            }
        } catch (\Throwable $e) {
            // الحارس لا يكسر التطبيق أبداً
            Log::error('sentinel.record_failed', ['error' => $e->getMessage()]);
        }
    }

    /** هل هذا العنوان محظور حالياً؟ (cache سريع ثم DB). */
    public function isBlocked(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $cached = Cache::get(self::CACHE_PREFIX . $ip);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $block = SentinelBlockedIp::active()->where('ip_address', $ip)->first();
        $active = $block !== null;

        // خزّن النتيجة في الكاش لدقائق قليلة لتخفيف ضغط DB
        Cache::put(self::CACHE_PREFIX . $ip, $active, now()->addMinutes(5));

        return $active;
    }

    /** حظر عنوان IP (يدوي أو تلقائي). $minutes=null → حظر دائم. */
    public function blockIp(string $ip, string $reason, ?int $minutes = null, string $by = 'auto'): void
    {
        $until = $minutes !== null ? now()->addMinutes($minutes) : null;

        $block = SentinelBlockedIp::updateOrCreate(
            ['ip_address' => $ip],
            ['reason' => $reason, 'blocked_until' => $until, 'created_by' => $by],
        );
        $block->increment('hits');

        Cache::put(self::CACHE_PREFIX . $ip, true, $until ?? now()->addDay());
    }

    /** رفع الحظر عن عنوان IP. */
    public function unblockIp(string $ip): void
    {
        SentinelBlockedIp::where('ip_address', $ip)->delete();
        Cache::forget(self::CACHE_PREFIX . $ip);
    }

    /** يحظر IP تلقائياً إذا تجاوز عدد أحداثه الحرجة العتبة خلال النافذة. */
    private function maybeAutoBlock(Request $request): void
    {
        if (! config('security_sentinel.auto_block.enabled', true)) {
            return;
        }

        $ip = $request->ip();
        if ($ip === null || $this->isBlocked($ip)) {
            return;
        }

        $window = (int) config('security_sentinel.auto_block.window_minutes', 60);
        $threshold = (int) config('security_sentinel.auto_block.threshold', 5);

        $criticalCount = SentinelEvent::where('ip_address', $ip)
            ->where('severity', 'critical')
            ->where('created_at', '>=', now()->subMinutes($window))
            ->count();

        if ($criticalCount >= $threshold) {
            $duration = (int) config('security_sentinel.auto_block.duration_minutes', 1440);
            $this->blockIp($ip, "auto: {$criticalCount} critical events / {$window}m", $duration, 'auto');

            $this->alerts->critical('🚫 حظر تلقائي لعنوان IP', [
                'ip' => $ip,
                'critical_events' => $criticalCount,
                'window_minutes' => $window,
                'duration_minutes' => $duration,
            ]);
        }
    }

    public function isWhitelisted(Request $request): bool
    {
        $ip = $request->ip();
        $whitelist = (array) config('security_sentinel.whitelist_ips', []);
        if ($ip !== null && in_array($ip, $whitelist, true)) {
            return true;
        }

        $path = $request->path();
        foreach ((array) config('security_sentinel.ignore_paths', []) as $ignore) {
            if (fnmatch($ignore, $path)) {
                return true;
            }
        }

        return false;
    }

    private function collectInputString(Request $request): string
    {
        $parts = [
            $request->getQueryString() ?? '',
            $request->getRequestUri(),
        ];

        try {
            // المدخلات النصية فقط (نتجاهل الملفات)
            $inputs = $request->except(array_keys($request->allFiles()));
            $parts[] = $this->flatten($inputs);
        } catch (\Throwable) {
            // تجاهل — لا نعرقل الطلب
        }

        return implode("\n", array_filter($parts));
    }

    /** يحوّل مصفوفة متداخلة إلى نص قابل للفحص. */
    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            $out = '';
            foreach ($value as $k => $v) {
                $out .= ' ' . $k . ' ' . $this->flatten($v);
            }

            return $out;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function severityFor(int $score): string
    {
        return match (true) {
            $score >= (int) config('security_sentinel.block_threshold', 80) => 'critical',
            $score >= (int) config('security_sentinel.warning_threshold', 40) => 'warning',
            $score > 0 => 'notice',
            default => 'info',
        };
    }

    private function actionFor(int $score): string
    {
        if ($score >= (int) config('security_sentinel.block_threshold', 80)) {
            return self::ACTION_BLOCK;
        }
        if ($score >= (int) config('security_sentinel.warning_threshold', 40)) {
            return self::ACTION_CHALLENGE;
        }

        return self::ACTION_MONITOR;
    }
}
