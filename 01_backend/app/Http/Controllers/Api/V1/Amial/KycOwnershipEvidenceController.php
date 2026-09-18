<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Services\Kyc\KycPrivacyService;
use App\Services\KycDocumentService;
use App\Services\RegistrationDossierService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AMIAL-KYC-COMPLETE-ACCOUNT-002 — دليل ملكية أقوى للمستوى الكامل. */
class KycOwnershipEvidenceController extends Controller
{
    public function selfie(
        Request $request,
        KycPrivacyService $privacy,
        KycDocumentService $documents,
        RegistrationDossierService $dossiers,
    ): JsonResponse {
        $user = $request->user();
        if (!$user || (int) $user->type !== 2) {
            return response()->json([
                'success' => false,
                'code' => 'INDIVIDUAL_CUSTOMER_REQUIRED',
                'message' => 'توثيق الأفراد متاح لحساب العميل فقط.',
            ], 403);
        }

        if ((int) ($user->kyc_tier ?? 0) < 2 || (int) ($user->is_kyc_verified ?? 0) !== 1) {
            return response()->json([
                'success' => false,
                'code' => 'TIER2_REQUIRED',
                'message' => 'أكمل توثيق الهوية أولاً.',
            ], 409);
        }

        $data = $request->validate([
            'review_mode' => ['required', Rule::in([
                KycPrivacyService::MODE_STANDARD,
                KycPrivacyService::MODE_RESTRICTED,
            ])],
            'selfie' => [
                'required', 'file', 'max:8192',
                'mimetypes:image/jpeg,image/png,image/heic,image/heif',
            ],
        ]);

        try {
            $privacy->choose($user, (string) $data['review_mode']);
            $doc = $documents->upload(
                $user,
                KycDocument::TYPE_SELFIE,
                $request->file('selfie'),
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'KYC_OWNERSHIP_EVIDENCE_REJECTED',
                'message' => $e->getMessage(),
            ], 422);
        }

        $dossier = $dossiers->archiveVerificationSubmission(
            $user->fresh(),
            3,
            [
                'ownership_method' => 'selfie',
                'review_mode' => (string) $data['review_mode'],
                'selfie_document_id' => (int) $doc->id,
            ],
        );

        return response()->json([
            'success' => true,
            'code' => 'KYC_SELFIE_PENDING_REVIEW',
            'message' => $data['review_mode'] === KycPrivacyService::MODE_RESTRICTED
                ? 'تم إرسال الصورة إلى طابور المراجعة المقيدة.'
                : 'تم إرسال الصورة للمراجعة المحمية.',
            'data' => [
                'document_id' => (int) $doc->id,
                'status' => (string) $doc->status,
                'review_mode' => (string) $data['review_mode'],
                'verification_dossier_reference' => $dossier->reference,
            ],
        ], 201);
    }
}
