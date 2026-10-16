<?php

namespace Tests\Feature;

use App\Models\PendingTransfer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CustomerTurnoverService;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-004
 *
 * الحجز لا يتحول إلى عدٍّ ثانٍ عند إنشاء صفوف Transaction النهائية، والأصل
 * يبقى المبلغ المتفق عليه مهما اختلف حقل amount التاريخي بسبب رسم أو عمولة.
 */
class CustomerTurnoverLifecycleGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function transaction_projection_uses_principal_not_total_debit_or_bonus(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $customer = $this->tierOneCustomer();

        Transaction::create([
            'user_id' => $customer->id,
            'transaction_id' => (string) Str::ulid(),
            'transaction_type' => 'merchant_payment',
            'debit' => '1000',
            'credit' => '0',
            // بعض المسارات التاريخية تسجل total_debit هنا، لا الأصل.
            'amount' => '1050',
            'charge' => '50',
            'balance' => '0',
            'from_user_id' => $customer->id,
            'to_user_id' => 999,
            'decision_code' => 'TX_OK',
            'zone_code' => 'SOUTH',
        ]);
        Transaction::create([
            'user_id' => $customer->id,
            'transaction_id' => (string) Str::ulid(),
            'transaction_type' => 'add_money_bonus',
            'debit' => '0',
            'credit' => '100',
            'amount' => '100',
            'balance' => '0',
            'from_user_id' => 999,
            'to_user_id' => $customer->id,
            'decision_code' => 'TX_OK',
            'zone_code' => 'SOUTH',
        ]);

        $info = app(KycTierService::class)->getUserTierInfo($customer->fresh());

        $this->assertSame(0, bccomp('1000', (string) $info['month_used'], 4));
    }

    /** @test */
    public function pending_transfer_reserves_at_hold_and_counts_each_leg_once_on_delivery(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $sender = $this->tierOneCustomer();
        $recipient = $this->tierOneCustomer();
        $releaseId = (string) Str::ulid();

        $transfer = PendingTransfer::create([
            'transfer_ulid' => (string) Str::ulid(),
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount' => '25000',
            'fee' => '500',
            'total_debited' => '25500',
            'status' => 'holding',
            'releasable_at' => now()->addMinute(),
            'zone_code' => 'SOUTH',
        ]);

        $tiers = app(KycTierService::class);
        $this->assertSame(0, bccomp('25000', (string) $tiers->getUserTierInfo($sender->fresh())['month_used'], 4));
        $this->assertSame(0, bccomp('0', (string) $tiers->getUserTierInfo($recipient->fresh())['month_used'], 4));

        // نفس ترتيب الخدمة الحية: الربط قبل صفوف Transaction النهائية.
        $transfer->update(['release_transaction_id' => $releaseId]);
        $this->postTransaction($sender, $releaseId, 'send_money', '25000', '0', '25000');
        $this->postTransaction($recipient, (string) Str::ulid(), 'received_money', '0', '25000', '25000', $releaseId);
        $transfer->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertSame(0, bccomp('25000', (string) $tiers->getUserTierInfo($sender->fresh())['month_used'], 4));
        $this->assertSame(0, bccomp('25000', (string) $tiers->getUserTierInfo($recipient->fresh())['month_used'], 4));
    }

    /** @test */
    public function customer_withdrawal_finalization_does_not_double_count_its_existing_reservation(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $customer = $this->tierOneCustomer();
        $transactionId = (string) Str::ulid();

        $request = WithdrawalRequest::create([
            'op_code' => 'WD' . random_int(100000, 999999),
            'customer_user_id' => $customer->id,
            'amount' => '15000',
            'fee' => '500',
            'agent_commission' => '0',
            'platform_profit' => '500',
            'total_debit' => '15500',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
            'zone_code' => 'SOUTH',
        ]);

        $request->update(['transaction_id' => $transactionId]);
        $this->postTransaction($customer, $transactionId, 'cash_out', '15000', '0', '15500');
        $request->update(['status' => 'completed', 'completed_at' => now()]);

        $info = app(KycTierService::class)->getUserTierInfo($customer->fresh());
        $this->assertSame(0, bccomp('15000', (string) $info['month_used'], 4));
    }

    /** @test */
    public function individual_tier_helpers_do_not_apply_customer_kyc_to_agents_or_merchants(): void
    {
        $tiers = app(KycTierService::class);
        $agent = User::factory()->create(['type' => 1]);
        $merchant = User::factory()->create(['type' => 3]);

        $tiers->assertIndividualTransactionAllowed($agent, '1', 'send_money');
        $tiers->assertIndividualCanReceive($merchant, '1');

        $customer = User::factory()->create(['type' => 2, 'kyc_tier' => 0]);
        $this->expectException(RuntimeException::class);
        $tiers->assertIndividualTransactionAllowed($customer, '1', 'send_money');
    }

    private function tierOneCustomer(): User
    {
        $user = User::factory()->create(['type' => 2]);
        $user->forceFill([
            'kyc_tier' => 1,
            'is_phone_verified' => 1,
            'is_kyc_verified' => 0,
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
            'zone_code' => 'SOUTH',
        ])->save();

        return $user->fresh();
    }

    private function postTransaction(
        User $user,
        string $transactionId,
        string $type,
        string $debit,
        string $credit,
        string $amount,
        ?string $refTransactionId = null,
    ): Transaction {
        return Transaction::create([
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'ref_trans_id' => $refTransactionId,
            'transaction_type' => $type,
            'debit' => $debit,
            'credit' => $credit,
            'amount' => $amount,
            'charge' => '0',
            'balance' => '0',
            'from_user_id' => $debit !== '0' ? $user->id : null,
            'to_user_id' => $credit !== '0' ? $user->id : null,
            'decision_code' => 'TX_OK',
            'zone_code' => 'SOUTH',
        ]);
    }
}
