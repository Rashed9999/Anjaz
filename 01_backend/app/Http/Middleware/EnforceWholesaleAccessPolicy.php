<?php

namespace App\Http\Middleware;

use App\Services\Wholesale\WholesaleAccessPolicyService as Policy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-WHOLESALE-ACCESS-001 — حارس A→Z لكل سطح API للجملة.
 *
 * يعمل على مجموعة api كلها عمداً ثم يخرج فوراً من غير مسارات الجملة؛
 * بذلك لا يمكن إضافة endpoint جديد تحت wholesale ونسيان حراسته. المسار
 * الجديد غير المعرّف في Policy يسقط fail-closed بدل أن يفتح بلا قصد.
 */
final class EnforceWholesaleAccessPolicy
{
    public function __construct(private readonly Policy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $prefix = 'api/v1/amial/merchant/wholesale';

        if ($path !== $prefix && ! str_starts_with($path, $prefix . '/')) {
            return $next($request);
        }

        $user = $request->user('api') ?? auth('api')->user() ?? $request->user();
        if (! $user) {
            return $next($request); // auth:api يعطي 401 بصيغته القياسية.
        }

        // الأدمن يمرّ للتشخيص والإصلاح، كما في EnsureCapability.
        if ((int) ($user->type ?? -1) === ADMIN_TYPE) {
            return $next($request);
        }

        $action = $this->policy->actionFor($request->method(), $path);
        if ($action === null) {
            report(new \RuntimeException(sprintf(
                'Wholesale endpoint has no access-policy mapping: %s %s',
                $request->method(), $path,
            )));

            return $this->deny(
                'WHOLESALE_ACTION_UNMAPPED',
                'هذه العملية غير مربوطة بسياسة صلاحيات الجملة — أبلغ الدعم',
                503,
            );
        }

        $state = $this->policy->state($user, $action);

        return match ($state['state'] ?? Policy::UNKNOWN) {
            Policy::AVAILABLE => $next($request),

            Policy::LOCKED_BY_PLAN => $this->deny(
                'WHOLESALE_PLAN_UPGRADE_REQUIRED',
                $state['reason'] ?? 'الميزة غير مشمولة في الباقة الحالية',
                402,
                $state,
            ),

            Policy::LIMIT_REACHED => $this->deny(
                'WHOLESALE_PLAN_LIMIT_REACHED',
                $state['reason'] ?? 'بلغت حد الباقة',
                402,
                $state,
            ),

            Policy::LOCKED_BY_ROLE => $this->deny(
                'WHOLESALE_PERMISSION_REQUIRED',
                $state['reason'] ?? 'تحتاج صلاحية من مالك المنشأة',
                403,
                $state,
            ),

            Policy::NOT_APPLICABLE => $this->deny(
                'WHOLESALE_ONLY',
                $state['reason'] ?? 'هذه العملية تخص تجارة الجملة فقط',
                403,
                $state,
            ),

            default => $this->deny(
                'WHOLESALE_ACCESS_UNKNOWN',
                'تعذر التحقق من صلاحية عملية الجملة',
                503,
                $state,
            ),
        };
    }

    private function deny(
        string $code,
        string $message,
        int $status,
        ?array $state = null,
    ): JsonResponse {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => $state === null ? (object) [] : [
                'wholesale_access' => $state,
                'state' => $state['state'] ?? null,
                'action' => $state['action'] ?? null,
                'permission' => $state['permission'] ?? null,
                'unlock' => $state['unlock'] ?? null,
                'usage' => $state['usage'] ?? null,
            ],
        ], $status);
    }
}
