<?php

namespace App\Http\Middleware;

use App\Services\Access\EntitlementService;
use App\Services\Wholesale\WholesaleAccessPolicyService as WholesalePolicy;
use App\Support\Access\CapabilityRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-ENTITLEMENTS-001 — البوابة العامة للقدرات.
 *
 * 402 = باقة/حد، 403 = دور، وNOT_APPLICABLE لا يتحول إلى ترقية كاذبة.
 *
 * AMIAL-WHOLESALE-ACCESS-001: مسارات الجملة لها RBAC أدق من بعض القدرات
 * العامة القديمة (مثل inventory الذي كان يحمل retail.stock.*). إذا أعطى
 * WholesalePolicy القرار AVAILABLE للفعل نفسه، لا يجوز لحارس عام أقل دقة
 * أن يرفضه بعد ذلك بنمط صلاحية قطاع آخر.
 */
class EnsureCapability
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly WholesalePolicy $wholesale,
    ) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user) {
            return $next($request); // حارس المصادقة يتولاها.
        }

        // الأدمن يمر للفحص والإصلاح.
        if ((int) ($user->type ?? -1) === ADMIN_TYPE) {
            return $next($request);
        }

        // الجملة: إن كان action-level policy قد أجاز الفعل، فهو يجمع الباقة
        // مع wholesale.* permission الدقيقة. لا نعيد رفضه بنمط retail.*.
        $path = trim($request->path(), '/');
        if ($path === 'api/v1/amial/merchant/wholesale'
            || str_starts_with($path, 'api/v1/amial/merchant/wholesale/')) {
            $action = $this->wholesale->actionFor($request->method(), $path);
            if ($action !== null) {
                $state = $this->wholesale->state($user, $action);
                if (($state['state'] ?? null) === WholesalePolicy::AVAILABLE) {
                    return $next($request);
                }
            }
        }

        if (! CapabilityRegistry::exists($code)) {
            report(new \RuntimeException("قدرة غير مسجَّلة في البوابة: {$code}"));

            return $this->deny(
                'CAPABILITY_UNKNOWN',
                'هذه الخدمة غير معرّفة — أبلغ الدعم',
                500,
            );
        }

        $denial = $this->entitlements->gate($user, $code);
        if ($denial === null) {
            return $next($request);
        }

        $r = $denial;

        return match ($r['state']) {
            EntitlementService::LOCKED_BY_PLAN => $this->deny(
                'PLAN_UPGRADE_REQUIRED',
                sprintf(
                    '«%s» متاحة في باقة %s (%s %s شهرياً)',
                    $r['capability']['name'],
                    $r['unlock']['plan_name'] ?? '—',
                    $r['unlock']['price_monthly'] ?? '—',
                    \App\Support\Access\AccessConstants::PLAN_PRICE_CURRENCY,
                ),
                402,
                $r,
            ),

            EntitlementService::LIMIT_REACHED => $this->deny(
                'PLAN_LIMIT_REACHED',
                sprintf(
                    'بلغتَ حدّ باقتك في «%s»: %s من %s',
                    $r['capability']['name'],
                    $r['usage']['used'] ?? '—',
                    $r['usage']['max'] ?? '—',
                ),
                402,
                $r,
            ),

            EntitlementService::NOT_APPLICABLE => $this->deny(
                'NOT_FOR_BUSINESS_TYPE',
                'هذه الخدمة لا تخصّ نوع نشاطك',
                404,
                $r,
            ),

            EntitlementService::COMING_SOON => $this->deny(
                'CAPABILITY_COMING_SOON',
                'هذه الخدمة لم تُفعَّل بعد ولا تفتحها ترقية الباقة حالياً',
                503,
                $r,
            ),

            default => $this->deny(
                'PERMISSION_REQUIRED',
                sprintf(
                    '«%s» تحتاج صلاحية — اطلبها من %s',
                    $r['capability']['name'],
                    $r['unlock']['ask'] ?? 'مالك المنشأة',
                ),
                403,
                $r,
            ),
        };
    }

    private function deny(
        string $code,
        string $message,
        int $status,
        ?array $r = null,
    ): JsonResponse {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => $r ? [
                'capability' => $r['capability'],
                'state' => $r['state'],
                'unlock' => $r['unlock'],
                'usage' => $r['usage'],
            ] : (object) [],
        ], $status);
    }
}
