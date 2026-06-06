<?php

namespace App\Http\Middleware;

use App\Services\Security\SecuritySentinelService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-SENTINEL-001 — الحارس المخفي (Application-level IDS).
 *
 * middleware صغير "يجلس بين كل طلب والكود": يفحص الطلب بصمت، يحسب نقاط خطورة،
 * يسجّل المشبوه، ويحظر فقط عند تجاوز العتبة **و** تفعيل وضع الحظر.
 *
 * الوضع الافتراضي = monitor (لا يحظر شيئاً) لضمان عدم تعطيل أي طلب شرعي قبل
 * ضبط العتبات على بيانات حقيقية. فعّل الحظر عبر config/.env عند الجاهزية.
 *
 * مبدأ ذهبي: **fail-open** — أي خطأ داخلي في الحارس يمرّر الطلب ولا يكسره.
 */
class SecuritySentinel
{
    public function __construct(private readonly SecuritySentinelService $sentinel)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security_sentinel.enabled', true)) {
            return $next($request);
        }

        try {
            if ($this->sentinel->isWhitelisted($request)) {
                return $next($request);
            }

            // عنوان محظور (تلقائي/يدوي)؟ → رفض فوري حتى في وضع monitor
            if ($this->sentinel->isBlocked($request->ip())) {
                return $this->blockResponse($request);
            }

            $report = $this->sentinel->analyze($request);

            if ($report['score'] > 0) {
                $this->sentinel->record($request, $report);

                $blockMode = config('security_sentinel.mode', 'monitor') === 'block';
                if ($blockMode && $report['action'] === SecuritySentinelService::ACTION_BLOCK) {
                    return $this->blockResponse($request);
                }
            }
        } catch (\Throwable) {
            // fail-open: لا نكسر الطلب بسبب خطأ في الحارس
        }

        return $next($request);
    }

    private function blockResponse(Request $request): Response
    {
        // رسالة عامّة لا تكشف سبب الحظر (تجنّب مساعدة المهاجم)
        $payload = [
            'response_code' => 'request_blocked',
            'message' => 'تم رفض الطلب لأسباب أمنية.',
        ];

        return response()->json($payload, 403);
    }
}
