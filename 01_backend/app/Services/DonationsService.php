<?php

namespace App\Services;

use App\CentralLogics\Helpers;

use App\Models\CharityCampaign;
use App\Models\CharityOrganization;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-DONATIONS-001 (v1.2)
 *
 * DonationsService — تنفيذ التبرع بشكل آمن atomic.
 *
 * **التدفق:**
 *   1. validation (campaign active + accepting + zone)
 *   2. DB::transaction:
 *      a. خصم من محفظة المتبرع (lockForUpdate + balance check)
 *      b. إنشاء donation record
 *      c. تحديث campaign.current_amount + donor_count (atomic increment)
 *      d. تحديث org stats (atomic)
 *   3. (post-commit) إيصال للمتبرع
 *
 * **أهم القرارات:**
 *   - المال يبقى في حساب المنصة (escrow conceptual)
 *   - لا يُحوَّل للمنظمة فوراً
 *   - admin يصنع settlement شهري ويحوّل بنكياً
 *   - is_anonymous فقط يخفي الاسم public — donor_user_id محفوظ دائماً
 */
class DonationsService
{
    use \App\Traits\PostsToLedger;

    public function __construct(
        private readonly FinancialGuardService $guard,
        private readonly AuditService $audit,
        private readonly ReceiptService $receipts,
    ) {}

    /**
     * تنفيذ تبرع.
     *
     * @throws \RuntimeException عند فشل validation
     * @throws \App\Exceptions\InsufficientBalanceException عند رصيد غير كافٍ
     */
    public function donate(
        User $donor,
        CharityCampaign $campaign,
        string $amount,
        bool $isAnonymous = false,
        ?string $message = null,
    ): Donation {
        // ====== Validation ======
        if ($donor->zone_code !== 'SOUTH') {
            throw new \RuntimeException('Only SOUTH users can donate');
        }

        if (!$campaign->isAccepting()) {
            throw new \RuntimeException('Campaign is not currently accepting donations');
        }

        $org = $campaign->organization;
        if (!$org || !$org->isVerified()) {
            throw new \RuntimeException('Organization is not verified');
        }

        $amountNormalized = MoneyService::normalize($amount);
        $minAmount = (string)config('amial.donations.min_amount', '1.0000');
        $maxAmount = (string)config('amial.donations.max_amount', '50000.0000');

        if (bccomp($amountNormalized, $minAmount, 4) < 0) {
            throw new \RuntimeException("الحد الأدنى للتبرع " . Helpers::money($minAmount) . " ر.ي");
        }
        if (bccomp($amountNormalized, $maxAmount, 4) > 0) {
            throw new \RuntimeException("الحد الأقصى للتبرع " . Helpers::money($maxAmount) . " ر.ي");
        }

        // حساب الـ fee
        $feePercent = (string)config('amial.donations.fee_percent', '1.0');
        $feeMultiplier = bcdiv($feePercent, '100', 6);
        $platformFee = bcmul($amountNormalized, $feeMultiplier, 4);
        $netToCharity = MoneyService::sub($amountNormalized, $platformFee);

        // ====== Execute ======
        $donation = DB::transaction(function () use (
            $donor, $campaign, $org, $amountNormalized, $platformFee, $netToCharity, $isAnonymous, $message,
        ) {
            // 1) خصم من المحفظة
            $walletTxId = (string) Str::ulid();
            $this->guard->debit(
                userId: $donor->id,
                amount: $amountNormalized,
                reason: "donation:campaign_{$campaign->id}",
            );

            // 2) إنشاء donation record
            $donation = Donation::create([
                'donation_ulid' => (string) Str::ulid(),
                'campaign_id' => $campaign->id,
                'org_id' => $org->id,
                'donor_user_id' => $donor->id,
                'is_anonymous' => $isAnonymous,
                'amount' => $amountNormalized,
                'platform_fee' => $platformFee,
                'net_to_charity' => $netToCharity,
                'wallet_transaction_id' => $walletTxId,
                'donor_message' => $message ? mb_substr($message, 0, 500) : null,
                'status' => 'completed',
                'donated_at' => now(),
                'zone_code' => 'SOUTH',
            ]);

            // AMIAL-LEDGER-RECON-001: قيد مزدوج للتبرّع داخل نفس المعاملة —
            // مدين محفظة المتبرّع = دائن عهدة التبرعات (الصافي) + رسوم المنصّة.
            // AMIAL-LEDGER-BLOCKING-003: صار مُعطِّلاً. كان الفشل يُبلع فيمرّ
            // التبرّع بلا قيد — ومالُ خيرٍ بلا أثرٍ محاسبيّ أسوأ من غيره.
            //
            // ولا يُضاف قيدٌ ثانٍ بعد commit: جُرّب في محاولةٍ سابقة فخُصم
            // المبلغ مرّتين وسقط الاختبار بـ«98 لا تكفي خصم 102». القيد
            // موجودٌ هنا أصلاً — والقراءةُ وحدها لم تكشف التكرار، بل القياس.
            $ledger = app(\App\Services\LedgerService::class);
                $donorAcc = $ledger->getOrCreateUserWallet($donor->id);
                $escrowAcc = $ledger->getOrCreateSystemAccount(
                    'CHARITY_ESCROW', 'liability', 'عهدة التبرعات (قبل التسوية)', 'credit');
                $feeAcc = $ledger->getOrCreateSystemAccount(
                    'PLATFORM_FEE', 'revenue', 'إيرادات رسوم المنصّة', 'credit');
                $lines = [
                    ['account' => $donorAcc->account_code, 'direction' => 'debit', 'amount' => $amountNormalized],
                    ['account' => $escrowAcc->account_code, 'direction' => 'credit', 'amount' => $netToCharity],
                ];
                if (bccomp($platformFee, '0', 4) > 0) {
                    $lines[] = ['account' => $feeAcc->account_code, 'direction' => 'credit', 'amount' => $platformFee];
                }
                $ledger->post(
                    sourceType: 'donation',
                    sourceId: $donation->donation_ulid,
                    description: "تبرّع لحملة #{$campaign->id} — {$org->name}",
                    lines: $lines,
                    idempotencyKey: 'donation:' . $donation->donation_ulid,
                    createdByUserId: $donor->id,
                );

            // 3) تحديث campaign.current_amount + donor_count
            // نستخدم lockForUpdate لتجنب race conditions على العداد
            $lockedCampaign = CharityCampaign::lockForUpdate()->find($campaign->id);
            $newAmount = MoneyService::add((string)$lockedCampaign->current_amount, $netToCharity);
            $newFeeCollected = MoneyService::add((string)$lockedCampaign->platform_fee_collected, $platformFee);

            $isUniqueDonor = !Donation::where('campaign_id', $campaign->id)
                ->where('donor_user_id', $donor->id)
                ->where('id', '<>', $donation->id)
                ->exists();

            $lockedCampaign->update([
                'current_amount' => $newAmount,
                'platform_fee_collected' => $newFeeCollected,
                'donor_count' => $isUniqueDonor
                    ? $lockedCampaign->donor_count + 1
                    : $lockedCampaign->donor_count,
            ]);

            // 4) تحقق إذا الحملة وصلت الهدف
            if (bccomp($newAmount, (string)$lockedCampaign->target_amount, 4) >= 0) {
                $lockedCampaign->update(['status' => 'completed']);
            }

            // 5) تحديث org stats (atomic increment آمن — AMIAL-SECURITY-AUDIT-001 v2.1)
            // $netToCharity مضمون رقمي (bcmath) لكن نستخدم increment لتجنب أي raw SQL
            CharityOrganization::where('id', $org->id)
                ->increment('total_collected', (float)$netToCharity);
            if ($isUniqueDonor) {
                $globalUniqueDonor = !Donation::where('org_id', $org->id)
                    ->where('donor_user_id', $donor->id)
                    ->where('id', '<>', $donation->id)
                    ->exists();
                if ($globalUniqueDonor) {
                    CharityOrganization::where('id', $org->id)->increment('total_donors');
                }
            }

            // 6) audit
            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $donor->id,
                'subject_type' => 'donation',
                'subject_id' => (string)$donation->id,
                'action' => 'DONATION_COMPLETED',
                'decision_code' => 'OK',
                'severity' => 'info',
                'context' => [
                    'campaign_id' => $campaign->id,
                    'org_id' => $org->id,
                    'amount' => $amountNormalized,
                    'is_anonymous' => $isAnonymous,
                ],
            ]);

            return $donation;
        });

        // ====== post-commit: receipt ======
        $this->safeIssueReceipt($donation, $donor, $org, $campaign);

        return $donation;
    }

    /**
     * Refund تبرع (نادراً، فقط من admin).
     */
    public function refundDonation(Donation $donation, User $admin, string $reason): Donation
    {
        if ($donation->status !== 'completed') {
            throw new \RuntimeException('Can only refund completed donations');
        }
        if ($donation->settlement_id) {
            throw new \RuntimeException('Cannot refund donation already settled to charity');
        }

        DB::transaction(function () use ($donation, $admin, $reason) {
            $locked = Donation::lockForUpdate()->find($donation->id);
            if ($locked->status !== 'completed') return;
            if ($locked->settlement_id) {
                throw new \RuntimeException('Already settled');
            }

            // إعادة المال للمتبرع
            $this->guard->credit(
                userId: $locked->donor_user_id,
                amount: (string)$locked->amount,
                reason: "donation_refund:{$locked->donation_ulid}",
            );

            // تحديث donation
            $locked->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_reason' => mb_substr($reason, 0, 500),
            ]);

            // عكس على campaign + org stats
            $campaign = CharityCampaign::lockForUpdate()->find($locked->campaign_id);
            $campaign->update([
                'current_amount' => MoneyService::sub((string)$campaign->current_amount, (string)$locked->net_to_charity),
                'platform_fee_collected' => MoneyService::sub((string)$campaign->platform_fee_collected, (string)$locked->platform_fee),
            ]);

            // AMIAL-SECURITY-AUDIT-001 (v2.1): decrement آمن بدل raw SQL
            CharityOrganization::where('id', $locked->org_id)
                ->where('total_collected', '>=', (float)$locked->net_to_charity)
                ->decrement('total_collected', (float)$locked->net_to_charity);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'subject_type' => 'donation',
                'subject_id' => (string)$locked->id,
                'action' => 'DONATION_REFUNDED',
                'decision_code' => 'REFUNDED',
                'reason' => mb_substr($reason, 0, 255),
                'severity' => 'warning',
            ]);
        });

        return $donation->fresh();
    }

    private function safeIssueReceipt(Donation $donation, User $donor, CharityOrganization $org, CharityCampaign $campaign): void
    {
        try {
            $this->receipts->issueDebit([
                'user_id' => $donor->id,
                'counterparty_user_id' => null, // المنظمة ليست user
                'reference_transaction_id' => $donation->donation_ulid,
                'receipt_type' => 'donation',
                'amount' => (string)$donation->amount,
                'fee' => (string)$donation->platform_fee,
                'reference_type' => 'donation',
                'reference_id' => $donation->id,
                'metadata' => [
                    'org_name' => $org->name_ar,
                    'campaign_title' => $campaign->title_ar,
                    'is_anonymous' => $donation->is_anonymous,
                ],
                'zone_code' => 'SOUTH',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Donation receipt failed', [
                'donation_id' => $donation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
