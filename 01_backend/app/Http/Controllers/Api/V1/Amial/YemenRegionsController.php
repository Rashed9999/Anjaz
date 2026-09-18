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

        return $this->respond(fn () => $regions->districts((string) $data['governorate']), $regions);
    }

    public function uzaal(Request $request, YemenRegionsService $regions): JsonResponse
    {
        $data = $request->validate([
            'district_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(fn () => $regions->uzaal((int) $data['district_id']), $regions);
    }

    public function villages(Request $request, YemenRegionsService $regions): JsonResponse
    {
        $data = $request->validate([
            'uzlah_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(fn () => $regions->villages((int) $data['uzlah_id']), $regions);
    }

    private function respond(callable $callback, YemenRegionsService $regions): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $callback(),
                'source' => $regions->source(),
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => 'تعذر تحميل بيانات الموقع المختار.',
            ], 422);
        }
    }
}
