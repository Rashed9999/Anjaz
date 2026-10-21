<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Muwathaq\MuwathaqOpeningProgressService;
use App\Services\Muwathaq\MuwathaqEvidenceBridgeService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AccountOpeningProgressController extends Controller
{
    public function __construct(private readonly MuwathaqOpeningProgressService $progress,
        private readonly MuwathaqEvidenceBridgeService $bridge) {}

    public function show(Request $request, string $applicationUlid): JsonResponse
    {
        return $this->respond($request, $applicationUlid, fn ($application) => [
            'success' => true,
            'data' => $this->progress->status($application),
        ]);
    }

    public function confirmPhone(Request $request, string $applicationUlid): JsonResponse
    {
        $validator = Validator::make($request->all(), ['code' => ['required', 'string', 'min:4', 'max:12']]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        return $this->respond($request, $applicationUlid, function ($application) use ($request) {
            $evidence = $this->progress->confirmPilotPhone($application, (string) $request->input('code'));
            $this->bridge->tryDeliver($evidence);
            return ['success' => true, 'data' => $this->progress->status($application->fresh())];
        });
    }

    public function submitResidence(Request $request, string $applicationUlid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'governorate' => ['required', 'string', 'max:30'],
            'district' => ['required', 'string', 'min:2', 'max:120'],
            'area' => ['required', 'string', 'min:2', 'max:120'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'evidence_type' => ['required', 'string', 'max:60'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        return $this->respond($request, $applicationUlid, function ($application) use ($request) {
            $evidence = $this->progress->submitResidence($application, $request->all(), $request->file('evidence'));
            $this->bridge->tryDeliver($evidence);
            return ['success' => true, 'data' => $this->progress->status($application->fresh())];
        });
    }

    private function respond(Request $request, string $applicationUlid, callable $callback): JsonResponse
    {
        try {
            $application = $this->progress->authorize($applicationUlid, $this->accessToken($request));
            return response()->json($callback($application));
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => 'OPENING_ACCESS_DENIED', 'message' => $e->getMessage()], 403);
        }
    }

    private function accessToken(Request $request): ?string
    {
        return $request->bearerToken() ?: $request->header('X-Account-Opening-Token');
    }
}
