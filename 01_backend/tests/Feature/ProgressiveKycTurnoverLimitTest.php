<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-001
 *
 * حد المستوى هو إجمالي الحركة الحقيقية على محفظة العميل: وارد + صادر.
 * الرصيد الافتتاحي والتسوية الفنية لا يستهلكان الحد، والقيد الملغى لا يحسب.
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
    public function incoming_money_is_rejected_when_it_would_push_monthly_turnover_over_the_tier_limit(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $user = $this->tierOneCustomer();
        $this->postWalletMovement($user, '78000', 'credit', 'send_money', '2026-09-10 12:00:00');

        $service = app(KycTierService::class);
        $this->assertSame(0, bccomp('78000', (string) $service->getUserTierInfo($user)['month_used'], 4));

        try {
            $service->assertCanReceive($user, '50000');
            $this->fail('استقبال 50 ألف بعد حركة 78 ألف مرّ رغم أن الحد الشهري 100 ألف.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('إجمالي الحركة الشهري', $e->getMessage());
            $this->assertStringContainsString('أكمل التوثيق لرفع الحد', $e->getMessage());
        }

        // المتبقي بالضبط يجب أن يمر؛ لا نرفض العميل قبل بلوغ الحد.
        $service->assertCanReceive($user, '22000');
    }

    /** @test */
    public function turnover_counts_real_debits_and_credits_but_not_opening_adjustment_or_reversed_entries(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['amial.operational_governorates' => ['YE-AD']]);

        $user = $this->tierOneCustomer();

        $this->postWalletMovement($user, '30000', 'credit', 'send_money', '2026-09-05 10:00:00');
        $this->postWalletMovement($user, '20000', 'debit', 'merchant_pay', '2026-09-06 10:00:00');

        // قيود فنية لا تمثل استخدام العميل.
        $this->postWalletMovement($user, '90000', 'credit', 'opening_balance', '2026-09-07 10:00:00');
        $this->postWalletMovement($user, '70000', 'credit', 'external_adjustment', '2026-09-08 10:00:00');

        // أصل تم عكسه لا يبقى حركة نهائية قابلة للاحتساب.
        $this->postWalletMovement(
            $user,
            '40000',
            'credit',
            'send_money',
            '2026-09-09 10:00:00',
            status: 'reversed'
        );

        // قيد العكس نفسه لا يُحسب أيضاً.
        $this->postWalletMovement(
            $user,
            '40000',
            'debit',
            'send_money_reversal',
            '2026-09-09 10:01:00',
            isReversal: true
        );

        $info = app(KycTierService::class)->getUserTierInfo($user);

        $this->assertSame('gross_wallet_turnover', $info['usage_basis']);
        $this->assertSame(0, bccomp('50000', (string) $info['month_used'], 4),
            'يجب أن يكون الاستخدام 30 ألف وارد + 20 ألف صادر فقط.');
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

    private function postWalletMovement(
        User $user,
        string $amount,
        string $direction,
        string $sourceType,
        string $at,
        string $status = 'posted',
        bool $isReversal = false,
    ): void {
        $walletId = DB::table('ledger_accounts')->where('account_code', "USER_WALLET_{$user->id}")->value('id');
        if (!$walletId) {
            $walletId = DB::table('ledger_accounts')->insertGetId([
                'account_code' => "USER_WALLET_{$user->id}",
                'account_type' => 'liability',
                'name_ar' => 'محفظة اختبار حدود الحركة',
                'owner_user_id' => $user->id,
                'owner_type' => 'user',
                'current_balance' => '0',
                'normal_balance' => 'credit',
                'currency' => 'YER',
                'zone_code' => 'SOUTH',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $contraId = DB::table('ledger_accounts')->where('account_code', 'TURNOVER_TEST_CONTRA')->value('id');
        if (!$contraId) {
            $contraId = DB::table('ledger_accounts')->insertGetId([
                'account_code' => 'TURNOVER_TEST_CONTRA',
                'account_type' => 'asset',
                'name_ar' => 'مقابل اختبار حدود الحركة',
                'owner_user_id' => null,
                'owner_type' => 'platform',
                'current_balance' => '0',
                'normal_balance' => 'debit',
                'currency' => 'YER',
                'zone_code' => 'SOUTH',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $journalId = DB::table('ledger_journal_entries')->insertGetId([
            'entry_ulid' => (string) Str::ulid(),
            'source_type' => $sourceType,
            'source_id' => (string) Str::ulid(),
            'description_ar' => 'قيد اختبار إجمالي الحركة',
            'total_amount' => $amount,
            'is_reversal' => $isReversal,
            'status' => $status,
            'created_by_user_id' => null,
            'zone_code' => 'SOUTH',
            'posted_at' => $at,
            'created_at' => $at,
        ]);

        $opposite = $direction === 'credit' ? 'debit' : 'credit';

        DB::table('ledger_entry_lines')->insert([
            [
                'journal_entry_id' => $journalId,
                'account_id' => $walletId,
                'direction' => $direction,
                'amount' => $amount,
                'balance_before' => '0',
                'balance_after' => '0',
                'description_ar' => 'حركة محفظة العميل',
                'created_at' => $at,
            ],
            [
                'journal_entry_id' => $journalId,
                'account_id' => $contraId,
                'direction' => $opposite,
                'amount' => $amount,
                'balance_before' => '0',
                'balance_after' => '0',
                'description_ar' => 'مقابل حركة العميل',
                'created_at' => $at,
            ],
        ]);
    }
}
