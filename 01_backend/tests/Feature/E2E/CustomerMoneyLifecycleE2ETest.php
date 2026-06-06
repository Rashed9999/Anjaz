<?php

namespace Tests\Feature\E2E;

use App\Exceptions\InsufficientBalanceException;
use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\PlatformFeeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2E — دورة حياة أموال العميل الكاملة عبر القلب المالي (TransactionTrait).
 *
 * يثبت تكامل عدة أنظمة فرعية معاً end-to-end:
 *   المحفظة (EMoney) + lockForUpdate + MoneyService + رسوم الأدمن (charge_earned)
 *   + سجل المعاملات (Transaction) + سياسة الـ Zone.
 *
 * هذا E2E على مستوى الخدمة (لا HTTP) لأن متحكمات العميل المالية تأتي من قاعدة
 * Cash6؛ بينما TransactionTrait وكل ما يعتمد عليه موجود في هذه الحزمة.
 */
class CustomerMoneyLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    private function wallet(int $userId, string $balance = '0.0000'): EMoney
    {
        return EMoney::create([
            'user_id' => $userId,
            'current_balance' => $balance,
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);
    }

    private function txTrait(): object
    {
        return new class {
            use \App\Traits\TransactionTrait;
        };
    }

    /** @test */
    public function full_send_money_journey_moves_balances_and_routes_fee_to_admin(): void
    {
        $admin    = User::factory()->admin()->create(['zone_code' => 'SOUTH']);
        $sender   = User::factory()->create(['zone_code' => 'SOUTH']);
        $receiver = User::factory()->create(['zone_code' => 'SOUTH']);

        $this->wallet($admin->id);
        $this->wallet($sender->id, '1000.0000');
        $this->wallet($receiver->id, '0.0000');

        $txId = $this->txTrait()->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
            charge: '2.0000',
        );

        $this->assertNotNull($txId, 'يجب أن تُرجِع المعاملة معرّفاً عند النجاح');

        // 1) رصيد المرسل = 1000 - 100 - 2 (رسوم) = 898
        $this->assertSame('898.0000', (string) EMoney::where('user_id', $sender->id)->value('current_balance'));

        // 2) رصيد المستقبل = 100
        $this->assertSame('100.0000', (string) EMoney::where('user_id', $receiver->id)->value('current_balance'));

        // 3) الرسوم (2) تُسجَّل في platform_fee_entries (لا في charge_earned)
        $this->assertSame('2.0000', (string) PlatformFeeEntry::sum('amount'));

        // 4) العميل العادي لا يُضاف له charge_earned
        $this->assertSame('0.0000', (string) EMoney::where('user_id', $sender->id)->value('charge_earned'));

        // 5) سُجِّلت معاملة فعلاً
        $this->assertGreaterThan(0, Transaction::count());
    }

    /** @test */
    public function consecutive_transfers_accumulate_correctly(): void
    {
        $admin    = User::factory()->admin()->create(['zone_code' => 'SOUTH']);
        $sender   = User::factory()->create(['zone_code' => 'SOUTH']);
        $receiver = User::factory()->create(['zone_code' => 'SOUTH']);

        $this->wallet($admin->id);
        $this->wallet($sender->id, '500.0000');
        $this->wallet($receiver->id, '0.0000');

        $trait = $this->txTrait();
        $trait->customer_send_money_transaction($sender->id, $receiver->id, '100.0000', '1.0000');
        $trait->customer_send_money_transaction($sender->id, $receiver->id, '50.0000', '0.5000');

        // المرسل: 500 - (100+1) - (50+0.5) = 348.5
        $this->assertSame('348.5000', (string) EMoney::where('user_id', $sender->id)->value('current_balance'));
        // المستقبل: 150
        $this->assertSame('150.0000', (string) EMoney::where('user_id', $receiver->id)->value('current_balance'));
        // الرسوم المُجمَّعة في platform_fee_entries: 1.5
        $this->assertSame('1.5000', (string) PlatformFeeEntry::sum('amount'));
    }

    /** @test */
    public function insufficient_balance_is_rejected_and_nothing_changes(): void
    {
        $admin    = User::factory()->admin()->create(['zone_code' => 'SOUTH']);
        $sender   = User::factory()->create(['zone_code' => 'SOUTH']);
        $receiver = User::factory()->create(['zone_code' => 'SOUTH']);

        $this->wallet($admin->id);
        $this->wallet($sender->id, '10.0000');
        $this->wallet($receiver->id, '0.0000');

        try {
            $this->txTrait()->customer_send_money_transaction($sender->id, $receiver->id, '100.0000', '2.0000');
            $this->fail('كان يجب رفض التحويل لعدم كفاية الرصيد');
        } catch (InsufficientBalanceException | \RuntimeException $e) {
            // متوقع
        }

        // لا تغيّر في الأرصدة (الذرّية محفوظة)
        $this->assertSame('10.0000', (string) EMoney::where('user_id', $sender->id)->value('current_balance'));
        $this->assertSame('0.0000', (string) EMoney::where('user_id', $receiver->id)->value('current_balance'));
    }

    /** @test */
    public function transfer_from_user_outside_south_zone_is_blocked(): void
    {
        $sender   = User::factory()->outsideZone('NORTH')->create();
        $receiver = User::factory()->create(['zone_code' => 'SOUTH']);

        $this->wallet($sender->id, '1000.0000');
        $this->wallet($receiver->id, '0.0000');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOUTH');

        $this->txTrait()->customer_send_money_transaction($sender->id, $receiver->id, '100.0000', '2.0000');
    }
}
