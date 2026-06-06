<?php

namespace App\Services;

use App\Models\CharityCampaign;
use App\Models\CharityOrganization;
use App\Models\CharitySettlement;
use App\Models\Donation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-DONATIONS-001 (v1.2)
 *
 * CharityService — إدارة المنظمات والحملات والتسويات (admin operations).
 */
class CharityService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    // ============================================================
    // المنظمات
    // ============================================================

    public function createOrganization(array $data, User $admin): CharityOrganization
    {
        return DB::transaction(function () use ($data, $admin) {
            $org = CharityOrganization::create(array_merge($data, [
                'org_ulid' => (string) Str::ulid(),
                'verification_status' => 'pending_verification',
                'zone_code' => 'SOUTH',
            ]));

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'subject_type' => 'charity_organization',
                'subject_id' => (string)$org->id,
                'action' => 'CHARITY_ORG_CREATED',
                'decision_code' => 'OK',
                'severity' => 'info',
            ]);

            return $org;
        });
    }

    public function verifyOrganization(CharityOrganization $org, User $admin): CharityOrganization
    {
        if ($org->verification_status === 'verified') {
            return $org;
        }

        $org->update([
            'verification_status' => 'verified',
            'verified_by_admin_id' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => null,
            'is_active' => true,
        ]);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'charity_organization',
            'subject_id' => (string)$org->id,
            'action' => 'CHARITY_ORG_VERIFIED',
            'decision_code' => 'VERIFIED',
            'severity' => 'info',
        ]);

        return $org;
    }

    public function rejectOrganization(CharityOrganization $org, User $admin, string $reason): CharityOrganization
    {
        $org->update([
            'verification_status' => 'rejected',
            'rejection_reason' => mb_substr($reason, 0, 500),
            'is_active' => false,
        ]);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'charity_organization',
            'subject_id' => (string)$org->id,
            'action' => 'CHARITY_ORG_REJECTED',
            'decision_code' => 'REJECTED',
            'reason' => mb_substr($reason, 0, 255),
            'severity' => 'warning',
        ]);

        return $org;
    }

    public function suspendOrganization(CharityOrganization $org, User $admin, string $reason): CharityOrganization
    {
        $org->update([
            'verification_status' => 'suspended',
            'suspension_reason' => mb_substr($reason, 0, 500),
            'is_active' => false,
        ]);

        // الحملات النشطة → paused
        CharityCampaign::where('org_id', $org->id)
            ->whereIn('status', ['active', 'pending_approval'])
            ->update(['status' => 'paused']);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'charity_organization',
            'subject_id' => (string)$org->id,
            'action' => 'CHARITY_ORG_SUSPENDED',
            'decision_code' => 'SUSPENDED',
            'reason' => mb_substr($reason, 0, 255),
            'severity' => 'critical',
        ]);

        return $org;
    }

    // ============================================================
    // الحملات
    // ============================================================

    public function createCampaign(CharityOrganization $org, array $data, User $admin): CharityCampaign
    {
        if (!$org->isVerified()) {
            throw new \RuntimeException('Organization must be verified before creating campaigns');
        }

        return DB::transaction(function () use ($org, $data, $admin) {
            $campaign = CharityCampaign::create(array_merge($data, [
                'campaign_ulid' => (string) Str::ulid(),
                'org_id' => $org->id,
                'status' => 'pending_approval',
                'current_amount' => '0',
                'platform_fee_collected' => '0',
                'view_count' => 0,
                'donor_count' => 0,
                'zone_code' => 'SOUTH',
            ]));

            CharityOrganization::where('id', $org->id)->increment('total_campaigns');

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'subject_type' => 'charity_campaign',
                'subject_id' => (string)$campaign->id,
                'action' => 'CHARITY_CAMPAIGN_CREATED',
                'decision_code' => 'OK',
                'severity' => 'info',
            ]);

            return $campaign;
        });
    }

    public function approveCampaign(CharityCampaign $campaign, User $admin): CharityCampaign
    {
        if (!in_array($campaign->status, ['pending_approval', 'paused'], true)) {
            throw new \RuntimeException('Campaign is not in approvable state');
        }

        $campaign->update([
            'status' => 'active',
            'approved_by_admin_id' => $admin->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'charity_campaign',
            'subject_id' => (string)$campaign->id,
            'action' => 'CHARITY_CAMPAIGN_APPROVED',
            'decision_code' => 'ACTIVE',
            'severity' => 'info',
        ]);

        return $campaign;
    }

    public function pauseCampaign(CharityCampaign $campaign, User $admin, string $reason): CharityCampaign
    {
        if ($campaign->status !== 'active') {
            throw new \RuntimeException('Only active campaigns can be paused');
        }

        $campaign->update([
            'status' => 'paused',
            'cancellation_reason' => mb_substr($reason, 0, 500),
        ]);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'charity_campaign',
            'subject_id' => (string)$campaign->id,
            'action' => 'CHARITY_CAMPAIGN_PAUSED',
            'decision_code' => 'PAUSED',
            'reason' => mb_substr($reason, 0, 255),
            'severity' => 'warning',
        ]);

        return $campaign;
    }

    // ============================================================
    // التسويات
    // ============================================================

    /**
     * إنشاء تسوية شهرية لمنظمة.
     *
     * تجمع كل donations المكتملة (status=completed، settlement_id=null) في الفترة
     * وتحسب الـ payable_amount = sum(net_to_charity).
     */
    public function generateSettlement(
        CharityOrganization $org,
        Carbon $periodStart,
        Carbon $periodEnd,
        User $admin,
    ): CharitySettlement {
        if ($periodStart->gte($periodEnd)) {
            throw new \RuntimeException('Period start must be before end');
        }

        return DB::transaction(function () use ($org, $periodStart, $periodEnd, $admin) {
            // اقفل donations لتجنب race
            $donations = Donation::where('org_id', $org->id)
                ->where('status', 'completed')
                ->whereNull('settlement_id')
                ->whereBetween('donated_at', [$periodStart, $periodEnd])
                ->lockForUpdate()
                ->get();

            if ($donations->isEmpty()) {
                throw new \RuntimeException('No unsettled donations in this period');
            }

            $totalDonations = (string)$donations->sum('amount');
            $totalFees = (string)$donations->sum('platform_fee');
            $payableAmount = (string)$donations->sum('net_to_charity');
            $campaignIds = $donations->pluck('campaign_id')->unique();

            $settlement = CharitySettlement::create([
                'settlement_ulid' => (string) Str::ulid(),
                'org_id' => $org->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'donation_count' => $donations->count(),
                'campaign_count' => $campaignIds->count(),
                'total_donations' => $totalDonations,
                'total_platform_fees' => $totalFees,
                'payable_amount' => $payableAmount,
                'status' => 'pending',
                'generated_by_admin_id' => $admin->id,
            ]);

            // علم الـ donations
            Donation::whereIn('id', $donations->pluck('id'))->update([
                'settlement_id' => $settlement->id,
                'status' => 'settled',
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'subject_type' => 'charity_settlement',
                'subject_id' => (string)$settlement->id,
                'action' => 'CHARITY_SETTLEMENT_GENERATED',
                'decision_code' => 'OK',
                'severity' => 'info',
                'context' => [
                    'org_id' => $org->id,
                    'payable' => $payableAmount,
                    'donation_count' => $donations->count(),
                ],
            ]);

            return $settlement;
        });
    }

    public function markSettlementTransferred(
        CharitySettlement $settlement,
        User $admin,
        string $bankReference,
        ?string $notes = null,
    ): CharitySettlement {
        if ($settlement->status !== 'pending') {
            throw new \RuntimeException('Settlement is not pending');
        }

        $settlement->update([
            'status' => 'transferred',
            'transferred_at' => now(),
            'bank_transfer_reference' => mb_substr($bankReference, 0, 100),
            'transfer_notes' => $notes ? mb_substr($notes, 0, 500) : null,
            'transferred_by_admin_id' => $admin->id,
        ]);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'charity_settlement',
            'subject_id' => (string)$settlement->id,
            'action' => 'CHARITY_SETTLEMENT_TRANSFERRED',
            'decision_code' => 'TRANSFERRED',
            'severity' => 'info',
            'context' => [
                'bank_reference' => $bankReference,
                'amount' => (string)$settlement->payable_amount,
            ],
        ]);

        return $settlement;
    }
}
