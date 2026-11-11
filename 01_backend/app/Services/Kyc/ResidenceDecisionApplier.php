<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycDocumentService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * تطبيق قرار إثبات السكن — مصدر واحد للمسار الإداري القديم (KycResidenceAdminController)
 * ولقرارات موثّق (CAP-02). الترتيب كما كان في المتحكم حرفياً: صلاحية الخصوصية ← الدليل المساعد
 * ← المستند ← الاسم كما في الدليل ← اعتماد/رفض المستند ← قرار السكن.
 */
class ResidenceDecisionApplier
{
    public function __construct(
        private readonly ResidenceVerificationService $residence,
        private readonly KycPrivacyService $privacy,
        private readonly KycDocumentService $documents,
        private readonly LegalNameService $legalNames,
    ) {
    }

    /**
     * @param 'verified'|'needs_more_evidence'|'rejected' $status
     * @return array<string,mixed> حالة السكن بعد القرار
     * @throws DomainException برمز الحجب (يترجمه المستدعي)
     */
    public function apply(
        int $verificationId,
        User $actor,
        string $status,
        ?string $reason,
        ?string $documentName,
        ?string $nameReviewNote,
    ): array {
        $row = DB::table('residence_verifications')->where('id', $verificationId)->first();
        if (!$row) {
            throw new DomainException('RESIDENCE_VERIFICATION_NOT_FOUND');
        }
        $subject = User::findOrFail((int) $row->user_id);

        // إن كانت الحالة ضمن مسار الخصوصية فحتى قرار السكن يمر من مفتاحها.
        $this->privacy->assertReviewerAccess($subject, $actor, true);

        if ($status === ResidenceVerificationService::STATUS_VERIFIED
            && (string) $row->evidence_strength === 'supporting') {
            throw new DomainException('RESIDENCE_STRONGER_EVIDENCE_REQUIRED');
        }

        $doc = $row->kyc_document_id ? KycDocument::find((int) $row->kyc_document_id) : null;
        if (!$doc) {
            throw new DomainException('RESIDENCE_EVIDENCE_DOCUMENT_INVALID');
        }

        if ($status === ResidenceVerificationService::STATUS_VERIFIED) {
            $documentName = trim((string) $documentName);
            if ($documentName === '') {
                throw new DomainException('RESIDENCE_DOCUMENT_NAME_REQUIRED');
            }
            $this->legalNames->confirmResidenceDocumentName($verificationId, $actor, $documentName, $nameReviewNote);

            if ($doc->status === KycDocument::STATUS_PENDING) {
                $this->documents->approve($doc, $actor);
            } elseif ($doc->status !== KycDocument::STATUS_APPROVED) {
                throw new DomainException('RESIDENCE_DOCUMENT_MUST_BE_APPROVED');
            }
        } elseif ($doc->status === KycDocument::STATUS_PENDING) {
            $this->documents->reject(
                $doc,
                $actor,
                trim((string) $reason) ?: 'دليل السكن يحتاج استكمالاً قبل الاعتماد.'
            );
        }

        return $this->residence->decide($verificationId, $actor, $status, $reason);
    }
}
