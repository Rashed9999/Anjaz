<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\RecipientVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-RECIPIENT-VERIFY-001 (v2.6)
 *
 * التحقق من المستلم قبل التحويل (يمنع التحويل بالغلط).
 */
class RecipientVerificationController extends Controller
{
    public function __construct(
        private readonly RecipientVerificationService $service,
    ) {}

    /** POST /api/v1/amial/transfer/verify-recipient */
    public function verify(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'phone' => 'required|string|min:6|max:20',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $result = $this->service->verifyRecipient(
                $request->input('phone'),
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return $this->error('RECIPIENT_INVALID', $e->getMessage(), 422);
        }

        return $this->ok($result, 'RECIPIENT_VERIFIED',
            'تأكد من اسم المستلم قبل الإرسال');
    }

    private function ok(array $meta, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
