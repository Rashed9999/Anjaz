<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Merchant\MerchantPrintTrackingService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MerchantPrintController extends Controller
{
    public function __construct(
        private readonly MerchantPrintTrackingService $tracking,
    ) {}

    public function report(Request $request): JsonResponse
    {
        $actor = $request->user('merchant_web') ?? $request->user();
        if (! $actor) {
            return $this->error('UNAUTHENTICATED', 'انتهت الجلسة', 401);
        }

        $v = Validator::make($request->all(), [
            'client_job_id' => 'required|string|max:80',
            'document_type' => 'required|string|max:64',
            'document_id' => 'sometimes|nullable|string|max:120',
            'document_number' => 'sometimes|nullable|string|max:120',
            'status' => 'required|in:queued,printing,completed,failed,cancelled,retrying',
            'copies' => 'sometimes|integer|min:1|max:20',
            'retry_count' => 'sometimes|integer|min:0|max:100',
            'error_code' => 'sometimes|nullable|string|max:32',
            'error_message' => 'sometimes|nullable|string|max:1000',
            'result_message' => 'sometimes|nullable|string|max:1000',
            'queued_at' => 'sometimes|nullable|date',
            'started_at' => 'sometimes|nullable|date',
            'finished_at' => 'sometimes|nullable|date',
            'metadata' => 'sometimes|array',
            'printer' => 'required|array',
            'printer.name' => 'required|string|max:120',
            'printer.printer_type' => 'sometimes|string|in:thermal,system,network',
            'printer.connection_type' => 'required|string|in:bluetooth,network,system',
            'printer.connection_identity' => 'required|string|max:180',
            'printer.paper_size' => 'required|string|in:58mm,80mm,A4,A5,A6,custom',
            'printer.capabilities' => 'sometimes|array',
            'printer.settings' => 'sometimes|array',
        ]);

        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422, $v->errors()->toArray());
        }

        try {
            $job = $this->tracking->report($actor, $v->validated());

            return $this->ok([
                'job_ulid' => $job->job_ulid,
                'status' => $job->status,
                'printer_profile_id' => $job->printer_profile_id,
            ], 'PRINT_JOB_RECORDED', 'تم تسجيل نتيجة الطباعة', 201);
        } catch (\RuntimeException $e) {
            return $this->error('PRINT_JOB_REJECTED', $e->getMessage(), 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $request->user('merchant_web') ?? $request->user();
        if (! $owner || ($owner->role !== A::ROLE_MERCHANT && (int) $owner->type !== MERCHANT_TYPE)) {
            return $this->error('OWNER_ONLY', 'مراقبة الطباعة متاحة لمالك المنشأة فقط', 403);
        }

        $days = max(1, min(90, (int) $request->query('days', 30)));

        return $this->ok(
            $this->tracking->dashboard($owner, $days),
            'OK',
            'مركز مراقبة الطباعة',
        );
    }

    private function ok(array $meta, string $code, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => $meta,
        ], $status);
    }

    private function error(
        string $code,
        string $message,
        int $status,
        array $errors = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) $errors,
            'meta' => (object) [],
        ], $status);
    }
}
