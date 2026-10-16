<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\CustomerTurnoverService;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-003
 * حد المستوى = أصل الحركة الواردة والصادرة، بلا رسوم. الحجز يستهلك الحد
 * مؤقتاً، والإلغاء/الاسترداد يحرره.
 */
class ProgressiveKycTurnoverLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function incoming_money_is_rejected_when_principal_would_push_monthly_turnover_over_the_limit(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $user = $this->tierOneCustomer();
        $this->postCustomerTransaction($user, '78000', 'in', fee: '5000');

        $service = app(KycTierService::class);
        $info = $service->getUserTierInfo($user);

        $this->assertSame('principal_wallet_turnover_excluding_fees', $info['usage_basis']);
        $this->assertSame(0, bccomp('78000', (string) $info['month_used'], 4));

        try {
            $service->assertCanReceive($user, '50000');
            $this->fail('استقبال 50 ألف بعد حركة 78 ألف مرّ رغم أن حد الحركة 100 ألف.');
        } catch (RuntimeException $e) {
            // Tier 1 يملك الحد نفسه يومياً وشهرياً، والحارس اليومي يُفحص أولاً.
            // المهم أن الحركة تُرفض عند 100 ألف وأن المتبقي الحقيقي ظاهر للعميل.
            $this->assertStringContainsString('إجمالي الحركة', $e->getMessage());
            $this->assertStringContainsString('22,000', $e->getMessage());
        }

        $service->assertCanReceive($user, '22000');
    }

    /** @test */
    public function inbound_and_outbound_principal_are_both_counted_while_fees_are_excluded(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $user = $this->tierOneCustomer();
        $this->postCustomerTransaction($user, '30000', 'in', fee: '2500');
        $this->postCustomerTransaction($user, '20000', 'out', fee: '1500');

        $info = app(KycTierService::class)->getUserTierInfo($user);
        $this->assertSame(0, bccomp('50000', (string) $info['month_used'], 4),
            'الاستخدام يجب أن يكون 30 ألف وارد + 20 ألف صادر فقط، دون 4 آلاف رسوم.');
    }

    /** @test */
    public function reaching_the_exact_limit_blocks_only_the_next_real_principal_movement(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $user = $this->tierOneCustomer();
        $this->postCustomerTransaction($user, '78000', 'out', fee: '9000');

        $service = app(KycTierService::class);
        $service->assertCanReceive($user, '22000');
        $this->postCustomerTransaction($user, '22000', 'in', fee: '3000');

        $info = $service->getUserTierInfo($user);
        $this->assertSame(0, bccomp('100000', (string) $info['month_used'], 4));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('المتبقي اليوم: 0');
        $service->assertCanReceive($user, '1');
    }

    /** @test */
    public function pending_reservation_consumes_capacity_and_release_returns_it(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $user = $this->tierOneCustomer();
        $turnover = app(CustomerTurnoverService::class);
        $tiers = app(KycTierService::class);

        $turnover->reserve($user, '80000', 'out', 'test:pending', 'cash_out');
        $this->assertSame(0, bccomp('80000', (string) $tiers->getUserTierInfo($user)['month_used'], 4));

        try {
            $tiers->assertCanReceive($user, '21000');
            $this->fail('الطلب المعلّق لم يحجز من الحد.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('إجمالي الحركة', $e->getMessage());
        }

        $turnover->release('test:pending', 'customer cancelled');
        $this->assertSame(0, bccomp('0', (string) $tiers->getUserTierInfo($user)['month_used'], 4));
        $tiers->assertCanReceive($user, '100000');
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

    private function postCustomerTransaction(
        User $user,
        string $amount,
        string $direction,
        string $fee = '0',
    ): Transaction {
        return Transaction::create([
            'user_id' => $user->id,
            'transaction_id' => (string) \Illuminate\Support\Str::ulid(),
            'transaction_type' => $direction === 'out' ? 'send_money' : 'received_money',
            'debit' => $direction === 'out' ? $amount : '0',
            'credit' => $direction === 'in' ? $amount : '0',
            'charge' => $fee,
            'amount' => $amount,
            'balance' => '0',
            'from_user_id' => $direction === 'out' ? $user->id : null,
            'to_user_id' => $direction === 'in' ? $user->id : null,
            'decision_code' => 'TX_OK',
            'zone_code' => 'SOUTH',
        ]);
    }
}
