<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-PROFIT-REPORT-001 — صحّة بيانات تقرير الأرباح.
 *
 * نشغّل عمليات حقيقية عبر الـ trait ثم نتحقق من المصادر التي يقرأها التقرير:
 *   - الربح الصافي = charge_earned لمحفظة الأدمن.
 *   - إجمالي الرسوم = SUM(charge) على صفوف charge>0.
 *   - عمولات الوكلاء = الإجمالي − الصافي.
 */
class ProfitReportTest extends TestCase
{
    use RefreshDatabase;

    private function wallet(int $userId, string $balance = '0.0000'): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance, 'charge_earned' => '0.0000',
            'pending_balance' => '0.0000', 'held_balance' => '0.0000',
            'zone_code' => 'SOUTH', 'version' => 0,
        ]);
    }

    private function trait()
    {
        return new class { use \App\Traits\TransactionTrait; };
    }

    /** @test */
    public function profit_data_reflects_fees_and_agent_commissions(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $sender = User::factory()->create(['type' => 2]);
        $receiver = User::factory()->create(['type' => 2]);
        $agent = User::factory()->create(['type' => 3]);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '5000.0000');
        $this->wallet($receiver->id);
        $this->wallet($agent->id);

        // تحويل 2% (كله ربح منصّة، لا وكيل)
        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '2.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);
        // سحب 5% مع 40% عمولة وكيل
        FeeScheme::create([
            'code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '5.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '40.0000', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $t = $this->trait();
        $t->send_money_with_fee_engine(from_user_id: $sender->id, to_user_id: $receiver->id, amount: '1000.0000'); // fee 20
        $t->cash_out_with_fee_engine(from_user_id: $sender->id, to_user_id: $agent->id, amount: '100.0000');       // fee 5، وكيل 2، أدمن 3

        // إجمالي الرسوم = 20 + 5 = 25
        $gross = (string) Transaction::where('charge', '>', 0)->sum('charge');
        $this->assertSame(MoneyService::normalize('25'), MoneyService::normalize($gross));

        // الربح الصافي = charge_earned للأدمن = 20 + 3 = 23
        $net = (string) \App\Models\PlatformFeeEntry::sum('amount');
        $this->assertSame(MoneyService::normalize('23'), MoneyService::normalize($net));

        // عمولات الوكلاء = 25 − 23 = 2
        $agentCommissions = MoneyService::sub(MoneyService::normalize($gross), MoneyService::normalize($net));
        $this->assertSame(MoneyService::normalize('2'), $agentCommissions);
    }

    /** @test */
    public function gross_grouped_by_operation_type(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $sender = User::factory()->create(['type' => 2]);
        $receiver = User::factory()->create(['type' => 2]);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '5000.0000');
        $this->wallet($receiver->id);

        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '1.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $t = $this->trait();
        $t->send_money_with_fee_engine(from_user_id: $sender->id, to_user_id: $receiver->id, amount: '1000.0000'); // 10
        $t->send_money_with_fee_engine(from_user_id: $sender->id, to_user_id: $receiver->id, amount: '500.0000');  // 5

        $rows = Transaction::where('charge', '>', 0)
            ->selectRaw('transaction_type, SUM(charge) as gross, COUNT(*) as cnt')
            ->groupBy('transaction_type')
            ->get();

        // كل العمليات هنا من نوع واحد → مجموعة واحدة، عدّتها 2، إجمالي رسومها 15
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int)$rows[0]->cnt);
        $this->assertSame(MoneyService::normalize('15'), MoneyService::normalize((string)$rows[0]->gross));
    }
}
