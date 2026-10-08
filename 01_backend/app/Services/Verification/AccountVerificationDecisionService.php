<?php

namespace App\Services\Verification;

use App\Models\User;
use App\Services\KycDocumentService;
use App\Services\KycTierService;
use App\Services\NotificationService;
use App\Support\YemenGovernorates;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The single application boundary for final identity decisions.
 *
 * It deliberately does not alter MerchantProfile verification: a personal
 * identity decision and business-registration decision are different legal
 * facts and have separate guards and timelines.
 */
class AccountVerificationDecisionService
{
    public function __construct(
        private readonly KycDocumentService $documents,
        private readonly KycTierService $tiers,
        private readonly NotificationService $notifications,
    ) {}

    public function decide(
        User $account,
        User $reviewer,
        bool $approve,
        int $targetTier,
        ?string $requestedGovernorate = null,
        ?string $reason = null,
    ): User {
        if ($approve && !in_array($targetTier, [2, 3], true)) {
            throw new DomainException('KYC_TIER_TARGET_INVALID');
        }

        $governorate = null;
        if ($approve) {
            $governorate = YemenGovernorates::codeFromName(
                (string) ($requestedGovernorate
                    ?: $account->residence_governorate
                    ?: $account->origin_governorate)
            );
            if ($governorate === null) {
                throw new DomainException('MISSING_RESIDENCE_GOVERNORATE');
            }
            if ((int) $account->type === CUSTOMER_TYPE) {
                $this->tiers->assertSequentialVerificationDecision($account, $targetTier);
            }
        }

        $result = DB::transaction(function () use (
            $account, $reviewer, $approve, $targetTier, $governorate, $reason
        ) {
            if ($approve) {
                $account->residence_governorate = $governorate;
                $account->save();
            }

            return $this->documents->decideAccountVerification(
                user: $account,
                reviewer: $reviewer,
                approve: $approve,
                targetTier: $targetTier,
                reason: $reason,
            );
        });

        // Notification is a post-commit side effect: failure here must never
        // roll back the verified identity state or its audit event.
        try {
            $this->notifications->dispatch(
                $result,
                'kyc_verification',
                $approve ? 'تم اعتماد حسابك ✅' : 'تعذّر اعتماد حسابك',
                $approve
                    ? 'وثّقنا حسابك بنجاح. يمكنك الآن استخدام الخدمات المتاحة لمستوى توثيقك.'
                    : 'راجعنا وثائقك ولم نتمكّن من اعتمادها. يرجى رفع وثائق واضحة أو مراجعة الدعم.',
                data: ['is_kyc_verified' => $approve ? 1 : 2],
            );
        } catch (\Throwable) {
            // Notification delivery is intentionally non-blocking.
        }

        return $result;
    }
}
