<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-MAINT-001 — يحجب مسارات ميزة موقوفة من لوحة «الصيانة الأولية».
 *
 * الاستخدام:  ->middleware('feature:safe_payment')
 *
 * عند إيقاف الميزة: يرد 503 برسالة صيانة. الأدمن يستثنى (ليختبر ويُعيد التشغيل).
 * آمن بالتصميم: أي عطل في القراءة → مرور طبيعي (fail-open، لا يُقفل خطأً).
 */
class RequireFeatureEnabled
{
    public function __construct(
        private readonly FeatureFlagService $features,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        try {
            // الأدمن يمرّ دائماً (ليتمكّن من الاختبار وإعادة التشغيل)
            $user = $request->user('api') ?? $request->user();
            $isAdmin = $user && ((int) ($user->type ?? -1) === 0
                || in_array($user->role ?? null, ['admin', 'super_admin'], true));

            if (!$isAdmin && !$this->features->isEnabled($featureKey)) {
                return new JsonResponse([
                    'success' => false,
                    'code' => 'FEATURE_UNDER_MAINTENANCE',
                    'message' => 'هذه الخدمة تحت الصيانة مؤقتاً. نعتذر عن الإزعاج، حاول لاحقاً.',
                    'errors' => (object) [],
                    'meta' => ['feature' => $featureKey],
                ], 503);
            }
        } catch (\Throwable $e) {
            // fail-open: لا نمنع الخدمة بسبب عطل تقني في الفحص
        }

        return $next($request);
    }
}
