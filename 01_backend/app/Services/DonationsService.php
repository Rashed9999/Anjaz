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
        private readonly KycTierService $kyc,
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
            $donor, $campaign, $amountNormalized, $platformFee, $netToCharity, $isAnonymous, $message,
        ) {
            // يُعاد تحميل كل طرف تحت القفل قبل خصم المال. فحص حالة الحملة
            // خارج المعاملة يسمح لقبول تبرع لحملة أوقفها مدير في اللحظة نفسها.
            $lockedDonor = User::whereKey($donor->id)->lockForUpdate()->firstOrFail();
            $lockedCampaign = CharityCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $org = CharityOrganization::whereKey($lockedCampaign->org_id)->lockForUpdate()->first();

            $this->assertEligibleDonor($lockedDonor, $amountNormalized);
            if (!$lockedCampaign->isAccepting()) {
                throw new \RuntimeException('Campaign is not currently accepting donations');
            }
            if (!$org || !$org->isVerified()) {
                throw new \RuntimeException('Organization is not verified');
            }

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
                'campaign_id' => $lockedCampaign->id,
                'org_id' => $org->id,
                'donor_user_id' => $lockedDonor->id,
                'is_anonymous' => $isAnonymous,
                'amount' => $amountNormalized,
                'platform_fee' => $platformFee,
                'net_to_charity' => $netToCharity,
                'wallet_transaction_id' => $walletTxId,
                'donor_message' => $message ? mb_substr($message, 0, 500) : null,
                'status' => 'completed',
                'donated_at' => now(),
                'zone_code' => $lockedDonor->zone_code,
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
                $donorAcc = $ledger->getOrCreateUserWallet($lockedDonor->id);
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
                    description: "تبرّع لحملة #{$lockedCampaign->id} — {$org->name_ar}",
                    lines: $lines,
                    idempotencyKey: 'donation:' . $donation->donation_ulid,
                    createdByUserId: $lockedDonor->id,
                );

            // 3) تحديث campaign.current_amount + donor_count
            // نستخدم lockForUpdate لتجنب race conditions على العداد
            $newAmount = MoneyService::add((string)$lockedCampaign->current_amount, $netToCharity);
            $newFeeCollected = MoneyService::add((string)$lockedCampaign->platform_fee_collected, $platformFee);

            $isUniqueDonor = !Donation::where('campaign_id', $lockedCampaign->id)
                ->where('donor_user_id', $lockedDonor->id)
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

            // 5) إحصاءات الجمعية تحت قفلها وبـ BCMath. increment(float)
            // يعيد إدخال خطأ التقريب الذي أزلناه من التسويات.
            $org->total_collected = MoneyService::add((string) $org->total_collected, $netToCharity);
            if ($isUniqueDonor) {
                $globalUniqueDonor = !Donation::where('org_id', $org->id)
                ->where('donor_user_id', $lockedDonor->id)
                    ->where('id', '<>', $donation->id)
                    ->exists();
                if ($globalUniqueDonor) {
                    $org->total_donors = (int) $org->total_donors + 1;
                }
            }
            $org->save();

            // 6) audit
            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $lockedDonor->id,
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

            // قيد عكسي لا تعديلٌ للقيد التاريخي: تُعاد المحفظة، ويُطفأ
            // التزام العهدة وإيراد الرسم بالمبالغ نفسها.
            $ledger = app(\App\Services\LedgerService::class);
            $wallet = $ledger->getOrCreateUserWallet($locked->donor_user_id);
            $escrow = $ledger->getOrCreateSystemAccount('CHARITY_ESCROW', 'liability', 'عهدة التبرعات (قبل التسوية)', 'credit');
            $fees = $ledger->getOrCreateSystemAccount('PLATFORM_FEE', 'revenue', 'إيرادات رسوم المنصّة', 'credit');
            $lines = [
                ['account' => $escrow->account_code, 'direction' => 'debit', 'amount' => (string) $locked->net_to_charity],
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => (string) $locked->amount],
            ];
            if (bccomp((string) $locked->platform_fee, '0', 4) > 0) {
                $lines[] = ['account' => $fees->account_code, 'direction' => 'debit', 'amount' => (string) $locked->platform_fee];
            }
            $ledger->post(
                sourceType: 'donation_refund', sourceId: $locked->donation_ulid,
                description: "استرداد تبرع #{$locked->donation_ulid}", lines: $lines,
                idempotencyKey: 'donation_refund:' . $locked->donation_ulid,
                createdByUserId: $admin->id,
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
            if (!Donation::where('campaign_id', $locked->campaign_id)
                ->where('donor_user_id', $locked->donor_user_id)
                ->whereIn('status', ['completed', 'settled'])->exists()) {
                $campaign->decrement('donor_count');
            }

            // AMIAL-SECURITY-AUDIT-001 (v2.1): decrement آمن بدل raw SQL
            $org = CharityOrganization::whereKey($locked->org_id)->lockForUpdate()->firstOrFail();
            $org->total_collected = MoneyService::sub((string) $org->total_collected, (string) $locked->net_to_charity);
            if (!Donation::where('org_id', $locked->org_id)
                ->where('donor_user_id', $locked->donor_user_id)
                ->whereIn('status', ['completed', 'settled'])->exists()
                && (int) $org->total_donors > 0) {
                $org->total_donors = (int) $org->total_donors - 1;
            }
            $org->save();

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
            $receipt = $this->receipts->issueDebit([
                'user_id' => $donor->id,
                'counterparty_user_id' => null, // المنظمة ليست user
                'reference_transaction_id' => $donation->donation_ulid,
                'receipt_type' => 'donation',
                'amount' => (string)$donation->amount,
                'fee' => (string)$donation->platform_fee,
                // amount هو كامل ما خُصم؛ لا يضاف الرسم إليه مرةً ثانية.
                'net_amount' => (string)$donation->amount,
                'reference_type' => 'donation',
                'reference_id' => $donation->id,
                'metadata' => [
                    'org_name' => $org->name_ar,
                    'campaign_title' => $campaign->title_ar,
                    'is_anonymous' => $donation->is_anonymous,
                ],
                'zone_code' => 'SOUTH',
            ]);
            $donation->update(['receipt_id' => $receipt->id]);
        } catch (\Throwable $e) {
            Log::warning('Donation receipt failed', [
                'donation_id' => $donation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function assertEligibleDonor(User $donor, string $amount): void
    {
        if (!(bool) $donor->is_active) {
            throw new \RuntimeException('الحساب غير نشط حالياً');
        }
        if (($donor->sanction_status ?? 'clear') === 'blocked') {
            throw new \RuntimeException('الحساب مقيّد تنظيمياً');
        }
        if (($donor->zone_code ?? 'UNKNOWN') !== 'SOUTH') {
            throw new \RuntimeException('Only SOUTH users can donate');
        }
        $this->kyc->assertTransactionAllowed($donor, $amount, 'donations');
    }
}
