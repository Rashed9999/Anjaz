<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\KycPrivacyService;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\KycDocumentService;
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
    ): JsonResponse {
        $actor = $request->user();
        $canRestricted = (bool) $actor?->hasPlatformPermission('platform.customers.kyc.restricted.view');
        $hidden = 0;

        $rows = collect($residence->pendingQueue(200))
            ->filter(function (array $row) use ($privacy, $canRestricted, &$hidden) {
                if ($privacy->isRestricted((int) $row['user_id']) && !$canRestricted) {
                    $hidden++;
                    return false;
                }
                return true;
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
        ResidenceVerificationService $residence,
        KycPrivacyService $privacy,
        KycDocumentService $documents,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', 'in:verified,needs_more_evidence,rejected'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = DB::table('residence_verifications')->where('id', $verificationId)->first();
        if (!$row) {
            return response()->json(['message' => 'طلب إثبات السكن غير موجود.'], 404);
        }

        $actor = $request->user();
        $subject = User::findOrFail((int) $row->user_id);

        try {
            // إن كانت الحالة ضمن مسار الخصوصية فحتى قرار السكن يمر من مفتاحها.
            $privacy->assertReviewerAccess($subject, $actor, true);

            if ($data['status'] === ResidenceVerificationService::STATUS_VERIFIED
                && (string) $row->evidence_strength === 'supporting') {
                throw new DomainException('RESIDENCE_STRONGER_EVIDENCE_REQUIRED');
            }

            $doc = $row->kyc_document_id ? KycDocument::find((int) $row->kyc_document_id) : null;
            if (!$doc) {
                throw new DomainException('RESIDENCE_EVIDENCE_DOCUMENT_INVALID');
            }

            if ($data['status'] === ResidenceVerificationService::STATUS_VERIFIED) {
                if ($doc->status === KycDocument::STATUS_PENDING) {
                    $documents->approve($doc, $actor);
                } elseif ($doc->status !== KycDocument::STATUS_APPROVED) {
                    throw new DomainException('RESIDENCE_DOCUMENT_MUST_BE_APPROVED');
                }
            } elseif ($doc->status === KycDocument::STATUS_PENDING) {
                $documents->reject(
                    $doc,
                    $actor,
                    trim((string) ($data['reason'] ?? '')) ?: 'دليل السكن يحتاج استكمالاً قبل الاعتماد.'
                );
            }

            $state = $residence->decide(
                $verificationId,
                $actor,
                (string) $data['status'],
                $data['reason'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => match ($e->getMessage()) {
                    'RESIDENCE_STRONGER_EVIDENCE_REQUIRED' => 'هذا دليل مساعد فقط؛ اطلب دليلاً أقوى أو تحققاً حضوريّاً.',
                    'RESIDENCE_DOCUMENT_MUST_BE_APPROVED' => 'لا يمكن اعتماد السكن قبل اعتماد المستند نفسه.',
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
