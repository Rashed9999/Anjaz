<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Services\AuditService;
use App\Services\KycDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * AMIAL-PROGRESSIVE-KYC-UPGRADE-001
 *
 * ترقية Tier 1 -> Tier 2 ليست إعادة تسجيل وليست KYC كامل.
 * المطلوب هنا: رقم الهوية + نوعها + وجه/ظهر الوثيقة فقط. لا selfie ولا
 * عنوان ولا دخل ولا توقيع. الصور تدخل KycDocumentService المشفّر مباشرةً
 * ولا تُنسخ إلى identification_image[] القديم.
 */
class KycIdentityUpgradeController extends Controller
{
    public function submit(
        Request $request,
        KycDocumentService $documents,
        AuditService $audit,
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'identification_number' => ['required', 'string', 'min:5', 'max:50'],
            'identification_type' => ['required', Rule::in(['nid', 'passport', 'driving_licence'])],
            'id_front' => ['required', 'file', 'max:8192',
                'mimetypes:image/jpeg,image/png,image/heic,image/heif,application/pdf'],
            'id_back' => ['required', 'file', 'max:8192',
                'mimetypes:image/jpeg,image/png,image/heic,image/heif,application/pdf'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION_FAILED',
                'message' => 'تحقّق من رقم الهوية وصور الوثيقة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        if (!$user || (int) $user->type !== 2) {
            return response()->json([
                'success' => false,
                'code' => 'CUSTOMER_REQUIRED',
                'message' => 'هذا المسار مخصص لحساب العميل.',
            ], 403);
        }
        if (!(bool) ($user->is_phone_verified ?? false)) {
            return response()->json([
                'success' => false,
                'code' => 'PHONE_VERIFICATION_REQUIRED',
                'message' => 'أثبت ملكية رقم هاتفك قبل رفع الهوية.',
            ], 403);
        }
        if ((int) ($user->kyc_tier ?? 0) >= 2 && (int) ($user->is_kyc_verified ?? 0) === 1) {
            return response()->json([
                'success' => false,
                'code' => 'IDENTITY_ALREADY_VERIFIED',
                'message' => 'هويتك معتمدة بالفعل. تغيير الهوية يتم من طلب تغيير البيانات.',
            ], 409);
        }

        $number = trim((string) $request->input('identification_number'));
        $existing = trim((string) ($user->identification_number ?? ''));
        if ($existing !== '' && $existing !== $number) {
            return response()->json([
                'success' => false,
                'code' => 'IDENTITY_CHANGE_REQUIRES_REVIEW',
                'message' => 'يوجد رقم هوية مختلف مسجل للحساب. استخدم طلب تغيير بيانات الهوية.',
            ], 409);
        }

        // المعاملة هنا قصيرة: بيانات الهوية المهيكلة فقط. OCR/فك الملف لا
        // يجريان داخلها حتى لا نحجز صفوفاً أثناء قراءة صور قد تستغرق ثواني.
        DB::transaction(function () use ($request, $user, $number): void {
            $locked = $user->newQuery()->lockForUpdate()->findOrFail($user->id);
            $locked->identification_number = $number;
            $locked->identification_type = (string) $request->input('identification_type');
            $locked->is_kyc_verified = 0;
            // لا نرفع المستوى هنا؛ المراجع هو من يمنح Tier 2 بعد اعتماد الدليل.
            $locked->save();
        });

        try {
            $uploaded = [
                $documents->uploadAndRead(
                    $user->fresh(),
                    KycDocument::TYPE_ID_FRONT,
                    $request->file('id_front'),
                ),
                $documents->uploadAndRead(
                    $user->fresh(),
                    KycDocument::TYPE_ID_BACK,
                    $request->file('id_back'),
                ),
            ];
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'KYC_DOCUMENT_REJECTED',
                'message' => $e->getMessage(),
            ], 422);
        }

        $audit->record([
            'actor_type' => 'customer',
            'actor_user_id' => (int) $user->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'KYC_TIER2_SUBMITTED',
            'decision_code' => 'KYC_TIER2_PENDING_REVIEW',
            'severity' => 'info',
            'context' => [
                'target_tier' => 2,
                'documents' => array_map(fn ($doc) => (int) $doc->id, $uploaded),
                'legacy_identification_images_written' => false,
            ],
        ]);

        return response()->json([
            'success' => true,
            'code' => 'KYC_TIER2_PENDING_REVIEW',
            'message' => 'تم إرسال الهوية للمراجعة. لا نطلب صورة شخصية لهذه الفئة.',
            'data' => [
                'target_tier' => 2,
                'status' => 'pending_review',
                'required_documents' => ['national_id_front', 'national_id_back'],
                'selfie_required' => false,
            ],
        ], 201);
    }
}
