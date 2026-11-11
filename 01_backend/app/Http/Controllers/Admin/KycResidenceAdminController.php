<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\MuwathaqReviewRequest;
use App\Services\Kyc\KycPrivacyService;
use App\Services\Kyc\LegalNameService;
use App\Services\Kyc\ResidenceDecisionApplier;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\KycOcrService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** AMIAL-RESIDENCE-ADMIN-001 — مراجعة الإقامة منفصلة عن محافظة الأصل. */
class KycResidenceAdminController extends Controller
{
    public function index()
    {
        return view('admin-views.amial.kyc.residence');
    }

    public function queue(
        Request $request,
        ResidenceVerificationService $residence,
        KycPrivacyService $privacy,
        KycOcrService $ocr,
        LegalNameService $legalNames,
    ): JsonResponse {
        $actor = $request->user();
        $canRestricted = (bool) $actor?->hasPlatformPermission('platform.customers.kyc.restricted.view');
        $hidden = 0;

        $rows = collect($residence->pendingQueue(200))
            ->filter(function (array $row) use ($privacy, $canRestricted, &$hidden) {
                // CAP-02: ما يراجعه موثّق لا يظهر في الطابور القديم.
                if (isset($row['id']) && MuwathaqReviewRequest::ownsResidenceVerification((int) $row['id'])) {
                    return false;
                }
                if ($privacy->isRestricted((int) $row['user_id']) && !$canRestricted) {
                    $hidden++;
                    return false;
                }
                return true;
            });

        $docs = KycDocument::whereIn(
            'id',
            $rows->pluck('kyc_document_id')->filter()->unique()->values()->all()
        )->get()->keyBy('id');

        $rows = $rows->map(function (array $row) use ($docs, $ocr, $legalNames) {
            $suggested = '';
            $doc = $row['kyc_document_id'] ? $docs->get($row['kyc_document_id']) : null;
            if ($doc) {
                try {
                    $review = $ocr->forReviewer($doc);
                    $suggested = trim((string) data_get($review, 'fields.full_name.value', ''));
                } catch (\Throwable) {
                    $suggested = '';
                }
            }

            $preview = $suggested !== ''
                ? $legalNames->compare((string) $row['declared_legal_name'], $suggested)
                : null;

            return $row + [
                'ocr_name_suggestion' => $suggested,
                'ocr_name_match_status' => $preview['status'] ?? null,
                'ocr_name_match_score' => $preview['score'] ?? null,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => ['restricted_hidden' => $hidden],
        ]);
    }

    public function decide(
        Request $request,
        int $verificationId,
        ResidenceDecisionApplier $applier,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', 'in:verified,needs_more_evidence,rejected'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'document_name' => ['nullable', 'string', 'min:2', 'max:300'],
            'name_review_note' => ['nullable', 'string', 'max:500'],
        ]);

        if (!DB::table('residence_verifications')->where('id', $verificationId)->exists()) {
            return response()->json(['message' => 'طلب إثبات السكن غير موجود.'], 404);
        }
        // CAP-02: طلب يراجعه موثّق لا يُحسم من هنا (لا قرار مزدوج).
        if (MuwathaqReviewRequest::ownsResidenceVerification($verificationId)) {
            return response()->json([
                'success' => false, 'code' => 'MUWATHAQ_OWNS_CASE',
                'message' => 'هذا الطلب قيد المراجعة في موثّق؛ القرار يُتخذ هناك.',
            ], 409);
        }

        try {
            $state = $applier->apply(
                $verificationId,
                $request->user(),
                (string) $data['status'],
                $data['reason'] ?? null,
                $data['document_name'] ?? null,
                $data['name_review_note'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => match ($e->getMessage()) {
                    'RESIDENCE_STRONGER_EVIDENCE_REQUIRED' => 'هذا دليل مساعد فقط؛ اطلب دليلاً أقوى أو تحققاً حضوريّاً.',
                    'RESIDENCE_DOCUMENT_MUST_BE_APPROVED' => 'لا يمكن اعتماد السكن قبل اعتماد المستند نفسه.',
                    'RESIDENCE_DOCUMENT_NAME_REQUIRED' => 'اكتب الاسم كما يظهر فعلياً في إثبات السكن قبل الاعتماد.',
                    'RESIDENCE_NAME_MISMATCH' => 'الاسم في إثبات السكن يختلف جوهرياً عن الاسم القانوني المصرّح به. اطلب تصحيح الاسم أو دليلاً مناسباً.',
                    'LEGAL_NAME_PARTIAL_MATCH_REQUIRES_NOTE' => 'التطابق جزئي؛ اكتب ملاحظة مراجعة واضحة (10 أحرف على الأقل) قبل الاعتماد.',
                    'FOUR_EYES_VIOLATION' => 'لا يجوز للمراجع اعتماد ملفه الشخصي.',
                    default => $e->getMessage(),
                },
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $data['status'] === ResidenceVerificationService::STATUS_VERIFIED
                ? 'تم تثبيت محل الإقامة وإعادة احتساب نطاق التشغيل.'
                : 'تم تسجيل قرار المراجعة.',
            'data' => $state,
        ]);
    }
}
