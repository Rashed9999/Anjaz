<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-CONTROL-CENTER-001
 *
 * WRITE operations have fail-closed, uncached feature gating on the shared DB,
 * including administrator writes. Read-only routes preserve the historical
 * admin inspection exemption so operators can inspect disabled services.
 */
class RequireFeatureEnabled
{
    public function __construct(
        private readonly FeatureFlagService $features,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $write = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if ($write) {
            try {
                if (! $this->features->isEnabledFreshStrict($featureKey)) {
                    return $this->unavailable($featureKey);
                }
            } catch (\Throwable $e) {
                Log::error('Feature guard DB check failed for write route', [
                    'feature' => $featureKey,
                    'method' => $request->method(),
                    'route' => $request->route()?->getName(),
                    'exception' => get_class($e),
                ]);
                return $this->unavailable($featureKey, 'FEATURE_GUARD_UNAVAILABLE');
            }
        } else {
            try {
                $user = $request->user('api') ?? $request->user();
                $isAdmin = $user && ((int) ($user->type ?? -1) === 0
                    || in_array($user->role ?? null, ['admin', 'super_admin'], true));

                if (! $isAdmin && ! $this->features->isEnabled($featureKey)) {
                    return $this->unavailable($featureKey);
                }
            } catch (\Throwable $e) {
                // A read-only inspection is never a financial write.
                Log::warning('Feature read gate unavailable', ['feature' => $featureKey]);
            }
        }

        return $next($request);
    }

    private function unavailable(string $key, string $code = 'FEATURE_UNDER_MAINTENANCE'): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => 'هذه الخدمة غير متاحة حالياً؛ لم تُقبل العملية.',
            'errors' => (object) [],
            'meta' => ['feature' => $key],
        ], 503);
    }
}
