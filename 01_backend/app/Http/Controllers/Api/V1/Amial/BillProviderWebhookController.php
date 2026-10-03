<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Models\BillProvider;
use App\Services\BillPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public callback endpoint; authenticity is checked by the bill service. */
class BillProviderWebhookController
{
    public function __construct(private readonly BillPayService $service) {}

    public function freeSadad(Request $request): JsonResponse
    {
        $provider = BillProvider::where('code', 'free_sadad')->first();
        if (!$provider) {
            return response()->json(['accepted' => false], 404);
        }

        try {
            $event = $this->service->acceptFreeSadadWebhook($provider, $request->query());
        } catch (\RuntimeException $e) {
            // Do not reveal whether a provider/order exists to unauthenticated
            // callers. Free Sadad only needs a non-2xx response to retry.
            return response()->json(['accepted' => false], 403);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => $event->processing_result === 'queued' && $event->wasRecentlyCreated === false,
        ], 202);
    }
}
