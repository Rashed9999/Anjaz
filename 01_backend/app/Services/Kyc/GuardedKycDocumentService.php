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
 * AMIAL-KYC-CENTRAL-GUARD-001 — الحارس فوق خدمة القرار لا فوق زر واحد.
 *
 * كل المتحكمات والخدمات التي تطلب KycDocumentService ستحصل على هذا الصنف
 * من الحاوية. بذلك لا يمكن لمسار قديم أو تاجر أو لجنة أخرى تجاوز إثبات
 * الملكية أو طابور الخصوصية المقيد بمجرد أنه لا يستخدم شاشة KYC الجديدة.
 *
 * نترك KycDocumentService الأصلي كما هو للحفاظ على قواعده الراسخة، ثم
 * نضيف هنا القواعد الجديدة. القرار الأب يقع داخل معاملة خارجية؛ فإذا نجح
 * في كل قواعده القديمة ثم فشل إثبات الملكية، تُلغى المعاملة كلها ولا يبقى
 * الحساب موثقاً نصف توثيق.
 */
class GuardedKycDocumentService extends KycDocumentService
{
    public function __construct(
        EncryptedFileStorage $storage,
        AuditService $audit,
        private readonly KycPrivacyService $privacy,
        private readonly KycOwnershipGuardService $ownership,
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

    /**
     * فك الملف نفسه بوابة بيانات حساسة، لا مجرد دالة تخزين. الحالة المقيدة
     * لا تُفك حتى داخلياً في سياق ويب إلا لموظف يملك مفتاح العرض المقيد.
     */
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

    public function decideAccountVerification(
        User $user,
        User $reviewer,
        bool $approve,
        int $targetTier = 2,
        ?string $reason = null,
    ): User {
        $this->privacy->assertReviewerAccess($user, $reviewer, true);

        return DB::transaction(function () use ($user, $reviewer, $approve, $targetTier, $reason) {
            // أولاً قواعد النظام الحالية: الاكتمال، التكرار، الانتهاء، الحقول،
            // والمنطقة. ترتيبها يبقى كما هو حتى لا تتغير رسائل الرفض الصحيحة.
            $account = parent::decideAccountVerification(
                $user, $reviewer, $approve, $targetTier, $reason,
            );

            $ownership = null;
            if ($approve) {
                // يأتي بعد قواعد المستندات لكن داخل المعاملة نفسها. أي فشل هنا
                // يرمي استثناءً فيُرجع اعتماد الحساب الذي أجراه الأب أيضاً.
                $ownership = $this->ownership->assertReady($account);
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

    /**
     * الطابور العام لا يحتوي الحالات المقيدة أبداً، حتى للمراجع المخول.
     * للحالات المقيدة باب مستقل كي لا تختلط بطابور عادي أو تظهر بالصدفة.
     */
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

        return array_values(array_filter(
            parent::activationQueue($limit + count($restricted)),
            fn (array $row) => !isset($restricted[(int) $row['user_id']]),
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public function restrictedPendingQueue(User $reviewer, int $limit = 100): array
    {
        // لا يكفي أن endpoint نفسه محروس؛ الخدمة كذلك حتى لا يفتحها نادٍ آخر.
        if (!$reviewer->hasPlatformPermission('platform.customers.kyc.restricted.view')) {
            throw new DomainException('KYC_RESTRICTED_VIEW_REQUIRED');
        }

        $restricted = $this->restrictedUserIds();
        if ($restricted === []) {
            return [];
        }

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
        if ($restricted === []) {
            return [];
        }

        return array_values(array_filter(
            parent::activationQueue($limit + count($restricted)),
            fn (array $row) => isset($restricted[(int) $row['user_id']]),
        ));
    }

    /** @return array<int,true> user_id => true */
    private function restrictedUserIds(): array
    {
        if (!Schema::hasTable('kyc_verification_cases')) {
            return [];
        }

        return DB::table('kyc_verification_cases')
            ->where('restricted_review', true)
            ->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
