<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P0-MONITORING — Endpoints لمراقبة صحّة النظام.
 *
 * /ping     — عام، لخدمات المراقبة الخارجية (UptimeRobot)
 * /health   — Admin only، تفاصيل كاملة
 */
class HealthController extends Controller
{
    public function __construct(private readonly SystemHealthService $svc) {}

    /**
     * Lightweight ping — للـ external monitoring.
     * يُرجع 200 إن DB يعمل، 503 إن لا.
     */
    public function ping(Request $request): JsonResponse
    {
        $ok = $this->svc->quickPing();
        return new JsonResponse([
            'status' => $ok ? 'ok' : 'down',
            'timestamp' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }

    /**
     * Full health check — للأدمن فقط.
     */
    public function full(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (($user->type ?? null) !== 1 && !($user->is_admin ?? false))) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }

        $result = $this->svc->checkAll();
        $httpCode = match($result['status']) {
            'healthy' => 200,
            'degraded' => 200, // ما زال يعمل
            default => 503,
        };

        return new JsonResponse([
            'success' => true,
            'code' => 'HEALTH',
            'message' => 'فحص النظام',
            'errors' => (object)[],
            'meta' => $result,
        ], $httpCode);
    }
}
