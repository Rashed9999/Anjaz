<?php

namespace App\Services\Verification;

use App\Models\AgentProfile;
use App\Models\User;
use App\Services\KycDocumentService;
use App\Services\KycTierService;
use App\Services\NotificationService;
use App\Services\ZoneAssignmentService;
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
        $this->assertIdentityReviewer($account, $reviewer);

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

    /**
     * قرار تشغيل الوكيل لا يبدّل توثيق هويته ولا حالة منشأته. الاعتماد
     * هنا يفتح ملفه التشغيلي فقط بعد تعيين منطقة آمنة وتحقق رباعي العيون.
     */
    public function decideAgent(
        User $agent,
        User $reviewer,
        bool $approve,
        ?string $requestedGovernorate = null,
        ?string $reason = null,
    ): AgentProfile {
        if ((int) $agent->type !== AGENT_TYPE) {
            throw new DomainException('AGENT_ACCOUNT_REQUIRED');
        }
        $this->assertIdentityReviewer($agent, $reviewer);
        if (!$approve && mb_strlen(trim((string) $reason)) < 5) {
            throw new DomainException('AGENT_SUSPENSION_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($agent, $reviewer, $approve, $requestedGovernorate, $reason) {
            $locked = User::query()->lockForUpdate()->findOrFail($agent->id);
            $profile = AgentProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();

            if ($approve) {
                $governorate = YemenGovernorates::codeFromName((string) (
                    $requestedGovernorate ?: $locked->residence_governorate ?: $locked->origin_governorate
                ));
                if ($governorate === null) {
                    throw new DomainException('MISSING_RESIDENCE_GOVERNORATE');
                }
                if (trim((string) $locked->agent_number) === '') {
                    // رقم دخول الوكيل يُنشأ عند التسجيل فقط؛ توليده هنا
                    // قد يخلق رقماً ثانياً أو يخرق تسلسل النظام.
                    throw new DomainException('AGENT_NUMBER_REQUIRED');
                }

                $locked->residence_governorate = $governorate;
                $locked->save();
                app(ZoneAssignmentService::class)->assignFromKyc($locked, $governorate, (int) $reviewer->id);
                $profile = $profile ?: new AgentProfile(['user_id' => $locked->id]);
                $profile->zone_code = $locked->zone_code ?: ZoneAssignmentService::ZONE_UNKNOWN;
                $profile->status = 'active';
                $profile->save();
            } else {
                $profile = $profile ?: new AgentProfile(['user_id' => $locked->id]);
                $profile->zone_code = $locked->zone_code ?: ZoneAssignmentService::ZONE_UNKNOWN;
                $profile->status = 'suspended';
                $profile->save();
            }

            app(VerificationCaseService::class)->recordAgentDecision(
                agent: $locked,
                reviewer: $reviewer,
                approved: $approve,
                reason: $reason,
            );

            return $profile->fresh();
        });
    }

    /** قرار الهوية/التشغيل لا يصدره صاحب الحساب ولا أي دور غير إداري. */
    private function assertIdentityReviewer(User $account, User $reviewer): void
    {
        if ((int) $account->id === (int) $reviewer->id) {
            throw new DomainException('FOUR_EYES_VIOLATION');
        }
        if ((int) $reviewer->type !== ADMIN_TYPE) {
            throw new DomainException('KYC_REVIEWER_REQUIRED');
        }
    }
}
