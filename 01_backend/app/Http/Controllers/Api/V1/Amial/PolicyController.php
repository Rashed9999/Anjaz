<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\ZonePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-ZONE-001
 *
 * GET /api/v1/amial/policy/session
 *
 * يعيد سياسة المستخدم الحالي. يستخدمها التطبيق ليعرف:
 *   - هل العمليات المالية مفعّلة؟
 *   - هل يعرض read-only banner؟
 *   - ما الـ actions المتاحة؟
 *
 * يجب أن يستدعى:
 *   - عند الـ login
 *   - عند الـ resume (foreground)
 *   - كل 5 دقائق إن كان التطبيق نشطاً (cache في side عميل أيضاً)
 */
class PolicyController extends Controller
{
    public function __construct(
        private readonly ZonePolicyService $service,
    ) {}

    /**
     * GET /api/v1/amial/policy/session
     */
    public function session(Request $request): JsonResponse
    {
        $payload = $this->service->buildSessionPolicy($request->user(), $request);

        return new JsonResponse([
            'success' => true,
            'message' => 'Session policy',
            'code' => 'POLICY_OK',
            'errors' => (object)[],
            'meta' => $payload,
        ]);
    }
}
