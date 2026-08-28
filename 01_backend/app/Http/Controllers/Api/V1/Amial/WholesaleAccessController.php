<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Wholesale\WholesaleAccessPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AMIAL-WHOLESALE-ACCESS-001 — snapshot واحد ترسم منه واجهة الجملة. */
final class WholesaleAccessController extends Controller
{
    public function __construct(private readonly WholesaleAccessPolicyService $policy) {}

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'code' => 'WHOLESALE_ACCESS_READY',
            'message' => '',
            'errors' => (object) [],
            'meta' => [
                'wholesale_access' => $this->policy->snapshot($request->user()),
            ],
        ]);
    }
}
