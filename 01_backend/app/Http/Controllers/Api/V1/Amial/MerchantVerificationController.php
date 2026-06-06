<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantVerificationRequest;
use App\Services\MerchantVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMIAL-MERCHANT-VERIFY-001 — Controller توثيق التاجر.
 *
 *   GET  /api/v1/amial/merchant/verification           حالة التوثيق الحالية
 *   POST /api/v1/amial/merchant/verification           تقديم/تحديث الطلب (multipart)
 *   GET  /api/v1/amial/merchant/verification/document/{type}   مستند خاص (private)
 *
 *   Admin (لاحقاً):
 *   POST /api/v1/amial/admin/verifications/{id}/approve
 *   POST /api/v1/amial/admin/verifications/{id}/reject
 *   POST /api/v1/amial/admin/verifications/{id}/request-resubmission
 */
class MerchantVerificationController extends Controller
{
    public function __construct(
        private readonly MerchantVerificationService $svc,
    ) {}

    /** حالة التوثيق الحالية للتاجر. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->svc->currentRequest($user);
        $profile = $this->svc->ensureMerchantProfile($user);

        return $this->ok([
            'profile_status' => $profile->verification_status,
            'tier' => $profile->tier,
            'current_request' => $current,
            'required_docs' => MerchantVerificationService::REQUIRED_DOCS,
            'optional_docs' => MerchantVerificationService::OPTIONAL_DOCS,
        ]);
    }

    /** تقديم/تحديث طلب التوثيق (multipart). */
    public function submit(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'business_name' => 'required|string|max:255',
            'commercial_register_number' => 'sometimes|nullable|string|max:64',
            'business_category' => 'sometimes|nullable|string|max:80',
            'city' => 'sometimes|nullable|string|max:80',
            'address' => 'sometimes|nullable|string|max:1000',
            'contact_phone' => 'sometimes|nullable|string|max:32',
            'bank_name' => 'sometimes|nullable|string|max:120',
            'bank_account_number' => 'sometimes|nullable|string|max:64',
            'bank_account_holder' => 'sometimes|nullable|string|max:120',

            // الملفات (image/pdf ≤ 5MB)
            'id_card_front' => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'id_card_back' => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'commercial_register' => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'store_photo' => 'sometimes|file|mimes:jpg,jpeg,png|max:5120',
            'address_proof' => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'profession_license' => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'optional_document' => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $files = [];
            foreach (['id_card_front', 'id_card_back', 'commercial_register',
                      'store_photo', 'address_proof', 'profession_license',
                      'optional_document'] as $key) {
                if ($request->hasFile($key)) {
                    $files[$key] = $request->file($key);
                }
            }
            $req = $this->svc->submit($request->user(), $request->all(), $files);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        }

        return $this->ok([
            'request' => $req,
        ], 'SUBMITTED', 'تم استلام الطلب — سنراجعه خلال 24-48 ساعة');
    }

    /** عرض مستند خاص (private — التاجر فقط). */
    public function document(Request $request, string $type)
    {
        $user = $request->user();
        $req = $this->svc->currentRequest($user);
        if (!$req) return $this->error('NOT_FOUND', 'لا يوجد طلب توثيق', 404);

        $column = match ($type) {
            'id_card_front' => 'id_card_front_path',
            'id_card_back' => 'id_card_back_path',
            'commercial_register' => 'commercial_register_path',
            'store_photo' => 'store_photo_path',
            'address_proof' => 'address_proof_path',
            'profession_license' => 'profession_license_path',
            'optional_document' => 'optional_document_path',
            default => null,
        };
        if (!$column) return $this->error('INVALID', 'نوع مستند غير صحيح', 422);

        $path = $req->{$column};
        if (!$path || !Storage::disk('local')->exists($path)) {
            return $this->error('NOT_FOUND', 'المستند غير موجود', 404);
        }
        return Storage::disk('local')->download($path);
    }

    // ====== Admin endpoints ======

    public function adminApprove(Request $request, int $id): JsonResponse
    {
        $req = MerchantVerificationRequest::find($id);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);
        try {
            $updated = $this->svc->approve($req, $request->user()->id, $request->input('tier'));
        } catch (\RuntimeException $e) {
            return $this->error('CANNOT_APPROVE', $e->getMessage(), 422);
        }
        return $this->ok(['request' => $updated], 'APPROVED', 'تمّ التوثيق');
    }

    public function adminReject(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|max:1000']);
        if ($v->fails()) return $this->validationError($v);

        $req = MerchantVerificationRequest::find($id);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        try {
            $updated = $this->svc->reject($req, $request->user()->id, $request->input('reason'));
        } catch (\RuntimeException $e) {
            return $this->error('CANNOT_REJECT', $e->getMessage(), 422);
        }
        return $this->ok(['request' => $updated], 'REJECTED', 'تم الرفض');
    }

    public function adminRequestResubmission(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|max:1000']);
        if ($v->fails()) return $this->validationError($v);

        $req = MerchantVerificationRequest::find($id);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        try {
            $updated = $this->svc->requestResubmission($req, $request->user()->id, $request->input('reason'));
        } catch (\RuntimeException $e) {
            return $this->error('CANNOT_REQUEST', $e->getMessage(), 422);
        }
        return $this->ok(['request' => $updated], 'RESUBMISSION_REQUIRED', 'طلب إعادة رفع');
    }

    // ====== helpers ======

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
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
