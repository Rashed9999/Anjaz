<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\PlatformFeeEntry;
use App\Models\User;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AMIAL-SCALE-FEES-001 — دفتر الرسوم append-only + التسوية.
 *
 * يتحقق أن الرسوم تُدرَج بلا قفل صف الأدمن، وأن التسوية تجمعها بدقة في charge_earned.
 */
class FeeReconcileTest extends TestCase
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
    public function fees_are_appended_not_written_live(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $sender = User::factory()->create(['type' => 2]);
        $receiver = User::factory()->create(['type' => 2]);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '5000.0000');
        $this->wallet($receiver->id);

        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '2.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $this->trait()->send_money_with_fee_engine(from_user_id: $sender->id, to_user_id: $receiver->id, amount: '1000.0000'); // رسم 20

        // الرسم في الدفتر، ورصيد الأدمن ما زال صفراً (لم يُكتب لحظياً — لا قفل ساخن)
        $this->assertSame(MoneyService::normalize('20'), MoneyService::normalize((string)PlatformFeeEntry::sum('amount')));
        $this->assertSame('0.0000', (string)EMoney::where('user_id', $admin->id)->first()->charge_earned);
        $this->assertSame(1, PlatformFeeEntry::where('reconciled', false)->count());
    }

    /** @test */
    public function reconcile_sums_into_admin_wallet(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $sender = User::factory()->create(['type' => 2]);
        $receiver = User::factory()->create(['type' => 2]);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '5000.0000');
        $this->wallet($receiver->id);

        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '2.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $t = $this->trait();
        $t->send_money_with_fee_engine(from_user_id: $sender->id, to_user_id: $receiver->id, amount: '1000.0000'); // 20
        $t->send_money_with_fee_engine(from_user_id: $sender->id, to_user_id: $receiver->id, amount: '500.0000');  // 10

        Artisan::call('amial:reconcile-fees');

        // بعد التسوية: رصيد الأدمن = 30، والقيود معلّمة مسوّاة
        $this->assertSame(MoneyService::normalize('30'), (string)EMoney::where('user_id', $admin->id)->first()->charge_earned);
        $this->assertSame(0, PlatformFeeEntry::where('reconciled', false)->count());

        // تسوية ثانية بلا قيود جديدة لا تضاعف
        Artisan::call('amial:reconcile-fees');
        $this->assertSame(MoneyService::normalize('30'), (string)EMoney::where('user_id', $admin->id)->first()->charge_earned);
    }
}
