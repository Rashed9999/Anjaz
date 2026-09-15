<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EncryptedFileStorage;
use App\Services\KycDocumentService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-CENTRAL-GUARD-002 — KYC تدريجي مع بوابة ملكية مركزية.
 *
 * Tier 2 لا يفرض سيلفي: وثيقة الهوية ورقمها المؤكد يكفيان لهذه الفئة.
 * Tier 3 يحتفظ بإثبات صاحب الهوية الأقوى + الملف الكامل + إقامة موثقة.
 */
class GuardedKycDocumentService extends KycDocumentService
{
    public function __construct(
        EncryptedFileStorage $storage,
        AuditService $audit,
        private readonly KycPrivacyService $privacy,
        private readonly KycOwnershipGuardService $ownership,
        private readonly ResidenceVerificationService $residence,
    ) {
        parent::__construct($storage, $audit);
    }

    public function approve(KycDocument $doc, User $reviewer, ?string $expiresAt = null): KycDocument
    {
        $subject = User::findOrFail($doc->user_id);
        $this->privacy->assertReviewerAccess($subject, $reviewer, true);
        return parent::approve($doc, $reviewer, $expiresAt);
    }

    public function reject(KycDocument $doc, User $reviewer, string $reason): KycDocument
    {
        $subject = User::findOrFail($doc->user_id);
        $this->privacy->assertReviewerAccess($subject, $reviewer, true);
        return parent::reject($doc, $reviewer, $reason);
    }

    public function decrypt(KycDocument $doc): string
    {
        if ($this->privacy->isRestricted((int) $doc->user_id)) {
            $reviewer = auth('user')->user();
            if (!$reviewer instanceof User) {
                throw new DomainException('KYC_RESTRICTED_VIEW_REQUIRED');
            }
            $this->privacy->assertReviewerAccess((int) $doc->user_id, $reviewer, false);
        }
        return parent::decrypt($doc);
    }

    /**
     * Tier 3 لا يفرض وسيلة إثبات واحدة على الجميع:
     * - standard/restricted: صورة شخصية معتمدة للمراجعة البشرية.
     * - automated: Liveness + Face Match من مزود حقيقي، فلا معنى لإجبار
     *   العميل على رفع سيلفي ثانٍ إلى المراجعة البشرية.
     * - in_person: المراجع المخول يثبت الحضور، فلا نطلب نسخة وجه عن بعد.
     */
    public function completenessFor(User $user, int $targetTier): array
    {
        $required = match ($targetTier) {
            2 => [KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK],
            3 => $this->tierThreeRequiredDocuments($user),
            default => [],
        };

        $approved = KycDocument::where('user_id', $user->id)
            ->where('status', KycDocument::STATUS_APPROVED)
            ->get()
            ->filter(fn (KycDocument $doc) => $doc->isUsable())
            ->pluck('doc_type')->unique()->values()->all();

        $missing = array_values(array_diff($required, $approved));

        return [
            'tier' => $targetTier,
            'required' => $required,
            'approved' => $approved,
            'missing' => $missing,
            'missing_fields' => \App\Support\Kyc\KycProfileFields::missingFor($user),
            'complete' => $required !== [] && $missing === [],
        ];
    }

    /** @return list<string> */
    private function tierThreeRequiredDocuments(User $user): array
    {
        $mode = (string) ($this->privacy->forUser($user)['review_mode'] ?? KycPrivacyService::MODE_STANDARD);
        $required = [
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_ADDRESS_PROOF,
        ];

        if (!in_array($mode, [KycPrivacyService::MODE_AUTOMATED, KycPrivacyService::MODE_IN_PERSON], true)) {
            $required[] = KycDocument::TYPE_SELFIE;
        }

        return $required;
    }

    public function decideAccountVerification(
        User $user,
        User $reviewer,
        bool $approve,
        int $targetTier = 2,
        ?string $reason = null,
    ): User {
        $this->privacy->assertReviewerAccess($user, $reviewer, true);

        return DB::transaction(function () use ($user, $reviewer, $approve, $targetTier, $reason) {
            $account = parent::decideAccountVerification(
                $user, $reviewer, $approve, $targetTier, $reason,
            );

            $ownership = null;
            if ($approve) {
                $ownership = $this->ownership->assertReady($account, $targetTier);

                if ($targetTier >= 3) {
                    $this->residence->assertVerified($account);
                }
            }

            $this->privacy->markAccountDecision(
                $account,
                $reviewer,
                $approve,
                $reason,
                $ownership['method'] ?? null,
            );

            return $account->fresh();
        });
    }

    public function pendingQueue(int $limit = 100): array
    {
        $restricted = $this->restrictedUserIds();
        return array_values(array_filter(
            parent::pendingQueue($limit + count($restricted)),
            fn (array $row) => !isset($restricted[(int) $row['user_id']]),
        ));
    }

    public function activationQueue(int $limit = 100): array
    {
        $restricted = $this->restrictedUserIds();
        $rows = array_values(array_filter(
            parent::activationQueue($limit + count($restricted)),
            fn (array $row) => !isset($restricted[(int) $row['user_id']]),
        ));
        return $this->withOwnership($rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function restrictedPendingQueue(User $reviewer, int $limit = 100): array
    {
        if (!$reviewer->hasPlatformPermission('platform.customers.kyc.restricted.view')) {
            throw new DomainException('KYC_RESTRICTED_VIEW_REQUIRED');
        }
        $restricted = $this->restrictedUserIds();
        if ($restricted === []) return [];

        return array_values(array_filter(
            parent::pendingQueue($limit + count($restricted)),
            fn (array $row) => isset($restricted[(int) $row['user_id']]),
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public function restrictedActivationQueue(User $reviewer, int $limit = 100): array
    {
        if (!$reviewer->hasPlatformPermission('platform.customers.kyc.restricted.view')) {
            throw new DomainException('KYC_RESTRICTED_VIEW_REQUIRED');
        }
        $restricted = $this->restrictedUserIds();
        if ($restricted === []) return [];

        $rows = array_values(array_filter(
            parent::activationQueue($limit + count($restricted)),
            fn (array $row) => isset($restricted[(int) $row['user_id']]),
        ));
        return $this->withOwnership($rows);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function withOwnership(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            $user = User::find((int) $row['user_id']);
            $tier = max(2, min(3, (int) ($row['target_tier'] ?? 2)));

            return $row + [
                'ownership' => $user ? $this->ownership->assess($user, $tier) : [
                    'ready' => false,
                    'method' => 'unknown',
                    'blockers' => ['الحساب غير موجود.'],
                    'evidence' => [],
                ],
                'residence' => $user ? $this->residence->forUser($user) : null,
            ];
        }, $rows));
    }

    /** @return array<int,true> */
    private function restrictedUserIds(): array
    {
        if (!Schema::hasTable('kyc_verification_cases')) return [];

        return DB::table('kyc_verification_cases')
            ->where('restricted_review', true)
            ->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
