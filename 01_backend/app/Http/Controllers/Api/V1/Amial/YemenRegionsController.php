<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Geo\YemenRegionsService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YemenRegionsController extends Controller
{
    public function districts(Request $request, YemenRegionsService $regions): JsonResponse
    {
        $data = $request->validate([
            'governorate' => ['required', 'string', 'max:16'],
        ]);

        try {
            return response()->json([
                'success' => true,
                'data' => $regions->districts((string) $data['governorate']),
                'source' => $regions->source(),
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => 'تعذر تحميل مديريات المحافظة المختارة.',
            ], 422);
        }
    }
}
