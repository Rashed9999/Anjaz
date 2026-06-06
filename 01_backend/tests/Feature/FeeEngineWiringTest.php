<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FEE-ENGINE-001 — ربط المحرّك بمساري التحويل والسحب.
 */
class FeeEngineWiringTest extends TestCase
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

    private function trait()
    {
        return new class {
            use \App\Traits\TransactionTrait;
        };
    }

    /** @test */
    public function send_money_uses_fee_engine_and_stores_snapshot(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $sender = User::factory()->create(['type' => 2]);
        $receiver = User::factory()->create(['type' => 2]);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '1000.0000');
        $this->wallet($receiver->id);

        // نسخة رسم: 2% على التحويل، يتحمّلها المرسل
        $scheme = FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '2.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $txId = $this->trait()->send_money_with_fee_engine(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
        );

        $this->assertNotNull($txId);

        // الرسم = 2% من 100 = 2 → المرسل 1000-102=898، المستلم 100، الأدمن 2
        $this->assertSame('898.0000', (string)EMoney::where('user_id', $sender->id)->first()->current_balance);
        $this->assertSame('100.0000', (string)EMoney::where('user_id', $receiver->id)->first()->current_balance);
        $this->assertSame('2.0000', (string)\App\Models\PlatformFeeEntry::sum('amount'));

        // snapshot النسخة مخزّن على صف الخصم الأساسي
        $primary = Transaction::where('transaction_id', $txId)->first();
        $this->assertSame($scheme->id, (int)$primary->fee_scheme_id);
        $this->assertSame(1, (int)$primary->fee_scheme_version);
        $this->assertSame('2.0000', (string)$primary->charge);
    }

    /** @test */
    public function cash_out_uses_fee_engine_for_fee_and_agent_split(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $customer = User::factory()->create(['type' => 2]);
        $agent = User::factory()->create(['type' => 3]);
        $this->wallet($admin->id);
        $this->wallet($customer->id, '1000.0000');
        $this->wallet($agent->id);

        // 5% رسم، 40% منه للوكيل
        FeeScheme::create([
            'code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '5.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '40.0000', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $txId = $this->trait()->cash_out_with_fee_engine(
            from_user_id: $customer->id,
            to_user_id: $agent->id,
            amount: '100.0000',
        );

        $this->assertNotNull($txId);

        // fee=5، عمولة الوكيل=2، حصة الأدمن=3
        // العميل: 1000 - 100 - 5 = 895
        $this->assertSame('895.0000', (string)EMoney::where('user_id', $customer->id)->first()->current_balance);
        // الوكيل: 100 (المبلغ) + 2 (عمولة) = 102
        $this->assertSame('102.0000', (string)EMoney::where('user_id', $agent->id)->first()->current_balance);
        // الأدمن: charge_earned = 3
        $this->assertSame('3.0000', (string)\App\Models\PlatformFeeEntry::sum('amount'));
    }

    /** @test */
    public function legacy_call_without_fee_meta_still_works_and_snapshot_is_null(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $sender = User::factory()->create(['type' => 2]);
        $receiver = User::factory()->create(['type' => 2]);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '1000.0000');
        $this->wallet($receiver->id);

        // الاستدعاء القديم (بدون feeMeta) — يجب أن يعمل كما كان
        $txId = $this->trait()->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
            charge: '2.0000',
        );

        $this->assertNotNull($txId);
        $primary = Transaction::where('transaction_id', $txId)->first();
        $this->assertNull($primary->fee_scheme_id);
        $this->assertNull($primary->fee_scheme_version);
        $this->assertSame('898.0000', (string)EMoney::where('user_id', $sender->id)->first()->current_balance);
    }

    /** @test */
    public function no_active_scheme_means_zero_fee_transfer(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $sender = User::factory()->create(['type' => 2]);
        $receiver = User::factory()->create(['type' => 2]);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '1000.0000');
        $this->wallet($receiver->id);

        // لا توجد نسخة SEND_MONEY نشطة → رسم صفر
        $txId = $this->trait()->send_money_with_fee_engine(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
        );

        $this->assertNotNull($txId);
        $this->assertSame('900.0000', (string)EMoney::where('user_id', $sender->id)->first()->current_balance);
        $this->assertSame('0', (string)(int)\App\Models\PlatformFeeEntry::sum('amount'));
    }
}
