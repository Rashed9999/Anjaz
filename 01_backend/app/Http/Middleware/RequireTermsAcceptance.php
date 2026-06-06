<?php

namespace App\Http\Middleware;

use App\Services\LegalTermsService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-LEGAL-001
 *
 * يرفض العمليات المالية من user لم يقبل آخر إصدار من السياسة.
 *
 * الاستخدام:
 *   Route::middleware(['auth:api', 'amial.terms'])->post(...)
 *
 * Response عند الرفض: HTTP 403 + code TERMS_ACCEPTANCE_REQUIRED.
 * التطبيق (Flutter) يكتشف هذا الكود ويفتح شاشة السياسة الإلزامية.
 *
 * مهم: لا يُطبق على endpoints قبول السياسة نفسها (لتجنب deadlock):
 *   POST /api/v1/amial/legal/accept
 *   GET  /api/v1/amial/legal/status
 *
 * Locale يُحدد من Accept-Language أو X-Locale header، الافتراضي 'ar'.
 */
class RequireTermsAcceptance
{
    public function __construct(
        private readonly LegalTermsService $service,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // غير مصادق → نتركها لـ middleware auth (هو من يرفض)
        if (!$user) {
            return $next($request);
        }

        $locale = $this->detectLocale($request);

        if ($this->service->needsAcceptance($user, $locale)) {
            $current = $this->service->currentTerm($locale);

            return new JsonResponse([
                'success' => false,
                'message' => 'You must accept the latest terms before proceeding',
                'code' => 'TERMS_ACCEPTANCE_REQUIRED',
                'errors' => (object)[],
                'meta' => [
                    'current_version' => $current?->version,
                    'current_locale' => $current?->locale,
                    'title' => $current?->title,
                    'changelog' => $current?->changelog,
                    'effective_at' => $current?->effective_at?->toIso8601String(),
                ],
            ], 403);
        }

        return $next($request);
    }

    private function detectLocale(Request $request): string
    {
        $locale = $request->header('X-Locale')
            ?? $request->header('Accept-Language')
            ?? 'ar';

        // ناخذ أول 2 حرف (en-US → en)
        $locale = strtolower(substr($locale, 0, 2));

        return in_array($locale, ['ar', 'en']) ? $locale : 'ar';
    }
}
