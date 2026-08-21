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
                'created_by_admin_id' => $admin->id,
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
        if ($org->created_by_admin_id && (int) $org->created_by_admin_id === (int) $admin->id) {
            throw new \RuntimeException('لا يجوز لمن أنشأ الجمعية اعتمادها بنفسه');
        }
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
                'created_by_admin_id' => $admin->id,
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
        if ($campaign->created_by_admin_id && (int) $campaign->created_by_admin_id === (int) $admin->id) {
            throw new \RuntimeException('لا يجوز لمن أنشأ الحملة اعتمادها بنفسه');
        }
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

    /**
     * حذف حملة أُوقفت قبل أن تستقبل أي تبرع.
     *
     * لا نحذف حملةً لها تاريخ مالي: التبرعات وإيصالاتها وقيودها تبقى
     * قابلة للتدقيق، لذلك الحملة التي تلقت مالاً تُوقف فقط ولا تُمحى.
     */
    public function deleteCampaign(CharityCampaign $campaign, User $admin, string $reason): void
    {
        DB::transaction(function () use ($campaign, $admin, $reason) {
            $locked = CharityCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'paused') {
                throw new \RuntimeException('يجب إيقاف الحملة قبل حذفها');
            }
            if (Donation::where('campaign_id', $locked->id)->exists()) {
                throw new \RuntimeException('لا يمكن حذف حملة لها تبرعات؛ أبقها موقوفة لحفظ السجل المالي');
            }

            $org = CharityOrganization::whereKey($locked->org_id)->lockForUpdate()->firstOrFail();

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'subject_type' => 'charity_campaign',
                'subject_id' => (string) $locked->id,
                'action' => 'CHARITY_CAMPAIGN_DELETED',
                'decision_code' => 'DELETED',
                'reason' => mb_substr($reason, 0, 255),
                'severity' => 'warning',
                'context' => [
                    'campaign_ulid' => $locked->campaign_ulid,
                    'organization_id' => $org->id,
                    'title_ar' => $locked->title_ar,
                ],
            ]);

            $locked->delete();
            if ((int) $org->total_campaigns > 0) {
                $org->total_campaigns = (int) $org->total_campaigns - 1;
                $org->save();
            }
        });
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

            // Collection::sum يحوّل decimal إلى float؛ التسوية تجمع DECIMAL
            // حصراً بـ BCMath كي لا تضيع كسور المال مع كثرة التبرعات.
            $totalDonations = $this->sumMoney($donations, 'amount');
            $totalFees = $this->sumMoney($donations, 'platform_fee');
            $payableAmount = $this->sumMoney($donations, 'net_to_charity');
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
        // توافقٌ مع العميل الإداري القديم، لكن بلا باب خلفي يغيّر الحالة
        // من دون دفتر: الحوالة البنكية تمر بمحرك الصرف نفسه.
        return $this->payoutSettlement($settlement, $admin, 'bank', $bankReference, null, $notes);
    }

    /**
     * AMIAL-CHARITY-PAYOUT-001 — **صرفُ التسوية: العهدةُ تُدان فعلاً.**
     *
     * ══════════════════════════════════════════════════════════════
     * كان الصرفُ حقلَ نصّ. `markSettlementTransferred` تكتب رقمَ حوالةٍ
     * وتُدوّن تدقيقاً، **ولا تمسّ الدفتر**. و`CHARITY_ESCROW` — الذي
     * يُدائَن في كلّ تبرّع — لم يكن يُدان في المشروع كلِّه، فالعهدةُ
     * تكبر بلا سقفٍ والالتزامُ لا يُطفأ.
     *
     * وثلاثُ قنواتٍ يسألها من يتسلّم المال:
     *
     *  · `wallet` — إلى محفظة أميال باي: رصيدٌ يزيد فوراً، والقيدُ
     *    مدين العهدة / دائن محفظة المستلم.
     *  · `agent`  — الوكيل يدفع نقداً ورقيّاً من درجه ويأخذ رصيداً
     *    إلكترونيّاً مقابله. نفسُ القيد، والمستلمُ حسابُ الوكيل.
     *  · `bank`   — حوالةٌ بنكيّة: مدين العهدة / دائن حساب المنصّة
     *    البنكيّ (أصلٌ ينقص).
     *
     * **والقيدُ يُربَط بالتسوية** (`payout_journal_entry_id`) — فبلا
     * رابطٍ يبقى الصرفُ رقماً في شاشةٍ لا أثراً يُتتبَّع.
     * ══════════════════════════════════════════════════════════════
     *
     * @param  'bank'|'wallet'|'agent'  $method
     */
    public function payoutSettlement(
        CharitySettlement $settlement,
        User $admin,
        string $method,
        string $reference,
        ?User $recipient = null,
        ?string $notes = null,
    ): CharitySettlement {
        if (!in_array($method, ['bank', 'wallet', 'agent'], true)) {
            throw new \RuntimeException('قناةُ صرفٍ غير معروفة');
        }
        if ($settlement->status !== 'pending') {
            throw new \RuntimeException('التسوية ليست معلَّقة');
        }
        if ($settlement->generated_by_admin_id && (int) $settlement->generated_by_admin_id === (int) $admin->id) {
            throw new \RuntimeException('لا يجوز لمن ولّد التسوية صرفها بنفسه');
        }

        $payable = MoneyService::normalize((string) $settlement->payable_amount);
        if (bccomp($payable, '0', 4) <= 0) {
            throw new \RuntimeException('مبلغُ التسوية صفرٌ — لا شيء يُصرَف');
        }

        // **المستلمُ يُطلب لِما يحتاجه، ويُرفض لِما لا يحتاجه.** ولو قُبل
        // مستلمٌ مع الحوالة البنكيّة لظنّ من يقرأ الصفَّ أنّ محفظتَه
        // شُحنت — ولم تُشحن.
        if ($method !== 'bank' && !$recipient) {
            throw new \RuntimeException('لا بدّ من تحديد المستلم لهذه القناة');
        }
        // **العمودُ `type` لا `user_type`.** كُتب `user_type` من الذاكرة فقرأ
        // `null`، و`(int) null !== 1` صحيحٌ دائماً — فكان يرفض **كلَّ** وكيل.
        // حارسٌ واحدٌ كشفه، ولا قراءةٌ كانت لتكشفه.
        if ($method === 'agent' && $recipient && (int) $recipient->type !== AGENT_TYPE) {
            throw new \RuntimeException('الحساب المحدَّد ليس وكيلاً');
        }
        if ($method === 'wallet' && $recipient) {
            $orgPhone = (string) ($settlement->organization?->contact_phone ?? '');
            if ($orgPhone === '' || !in_array((string) $recipient->phone, \App\Support\Phone::variants($orgPhone), true)) {
                throw new \RuntimeException('محفظة الصرف يجب أن تكون مرتبطة برقم الجمعية الموثّق');
            }
        }

        return DB::transaction(function () use ($settlement, $admin, $method, $reference, $recipient, $notes, $payable) {
            // ══════════════════════════════════════════════════════════
            //  **الفحصُ يُعاد داخل القفل** — القاعدة الأولى، الطبقة التاسعة.
            //
            //  كان فحصُ «معلَّقة» أعلاه **خارج المعاملة وبلا قفل**، وهو
            //  يمرّ في كلّ اختبارٍ متتابعٍ لأنّ لا أحدَ يتحرّك بين
            //  السطرين. وتحت طلبين متزامنين يمرّان معاً:
            //
            //    · `FinancialGuardService::credit` **يشحن الرصيد مرّتين**،
            //    · و`LedgerService::post` يجد المفتاحَ نفسَه فيُرجع القيدَ
            //      القائم **بصمتٍ ولا يرمي**.
            //
            //  فالنتيجة: مالٌ يُخلق من العدم بقيدٍ واحد — **كسرُ حِفظ
            //  المال**، وهو أخطرُ ما يقع في هذا المشروع.
            //
            //  والقفلُ على صفّ التسوية يُسلسل الطلبين، فيرى الثاني حالةَ
            //  الأوّل بعد كتابتها. (كشفه محورُ المعايير في مراجعةٍ آليّة.)
            // ══════════════════════════════════════════════════════════
            $locked = CharitySettlement::whereKey($settlement->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'pending') {
                throw new \RuntimeException('التسوية ليست معلَّقة');
            }

            $settlement = $locked;

            /** @var LedgerService $ledger */
            $ledger = app(LedgerService::class);

            $escrow = $ledger->getOrCreateSystemAccount(
                'CHARITY_ESCROW', 'liability', 'عهدة التبرعات (قبل التسوية)', 'credit');

            if ($method === 'bank') {
                $creditAccount = $ledger->getOrCreateSystemAccount(
                    'TREASURY_BANK', 'asset', 'حساب المنصّة البنكيّ', 'debit')->account_code;
            } else {
                // **الرصيدُ يُشحن قبل القيد وداخل نفس المعاملة** — فلو سقط
                // القيد رُدّت الشحنة معه. والعكسُ يُنتج رصيداً بلا قيد.
                app(FinancialGuardService::class)->credit(
                    $recipient->id, $payable, 'charity_settlement_payout');

                $creditAccount = $ledger->getOrCreateUserWallet($recipient->id)->account_code;
            }

            $entry = $ledger->post(
                sourceType: 'charity_settlement',
                sourceId: $settlement->settlement_ulid,
                description: "صرفُ تسوية تبرّعات #{$settlement->id} عبر {$method}",
                lines: [
                    ['account' => $escrow->account_code, 'direction' => 'debit', 'amount' => $payable],
                    ['account' => $creditAccount, 'direction' => 'credit', 'amount' => $payable],
                ],
                idempotencyKey: 'charity_payout:' . $settlement->settlement_ulid,
                createdByUserId: $admin->id,
                metadata: ['method' => $method, 'reference' => $reference],
            );

            $settlement->update([
                'status' => 'transferred',
                'transferred_at' => now(),
                'bank_transfer_reference' => mb_substr($reference, 0, 100),
                'transfer_notes' => $notes ? mb_substr($notes, 0, 500) : null,
                'transferred_by_admin_id' => $admin->id,
                'payout_method' => $method,
                'payout_user_id' => $recipient?->id,
                'payout_journal_entry_id' => $entry->id,
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'subject_type' => 'charity_settlement',
                'subject_id' => (string) $settlement->id,
                'action' => 'CHARITY_SETTLEMENT_PAID_OUT',
                'decision_code' => 'PAID',
                'severity' => 'warning',
                'context' => [
                    'method' => $method,
                    'amount' => $payable,
                    'recipient_user_id' => $recipient?->id,
                    'reference' => $reference,
                    'journal_entry_id' => $entry->id,
                ],
            ]);

            return $settlement->refresh();
        });
    }

    /** @param \Illuminate\Support\Collection<int, Donation> $donations */
    private function sumMoney($donations, string $column): string
    {
        $total = '0.0000';
        foreach ($donations as $donation) {
            $total = bcadd($total, (string) $donation->{$column}, 4);
        }
        return $total;
    }
}
