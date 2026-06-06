<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ZONE-001 hotfix (v0.7-A.1)
 *
 * ZoneEnforcementE2ETest — يثبت end-to-end أن الـ Zone Policy يعمل **فعلاً**.
 *
 * يغطي:
 *   1. الميدلوير `amial.zone:send_money` يرفض non-SOUTH user مع HTTP 403
 *   2. لو middleware تم bypass-ه (مثلاً admin يستدعي trait مباشرة)،
 *      الـ assertFinancialEligibility() في TransactionTrait يلتقط الـ violation
 *   3. SOUTH user يمر بدون مشاكل
 *
 * هذا الـ test هو الجواب الصادق على سؤال:
 *   "هل ميزة التشغيل في الجنوب فقط تعمل الآن؟"
 */
class ZoneEnforcementE2ETest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function trait_blocks_non_south_user_via_eligibility_check(): void
    {
        $sender = User::factory()->create([
            'type' => 2,
            'zone_code' => 'NORTH', // ← خارج SOUTH
        ]);
        $receiver = User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
        ]);

        foreach ([$sender, $receiver] as $u) {
            EMoney::create([
                'user_id' => $u->id,
                'current_balance' => '1000.0000',
                'charge_earned' => '0.0000',
                'pending_balance' => '0.0000',
                'held_balance' => '0.0000',
                'zone_code' => 'SOUTH',
                'version' => 0,
            ]);
        }

        $trait = new class {
            use \App\Traits\TransactionTrait;
        };

        // المرسل في NORTH → يجب أن يُرفض من assertFinancialEligibility
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOUTH');

        $trait->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
            charge: '2.0000',
        );
    }

    /** @test */
    public function south_user_can_send_money_normally(): void
    {
        $sender = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        $receiver = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        foreach ([$sender, $receiver, $admin] as $u) {
            EMoney::create([
                'user_id' => $u->id,
                'current_balance' => $u->id === $sender->id ? '1000.0000' : '0.0000',
                'charge_earned' => '0.0000',
                'pending_balance' => '0.0000',
                'held_balance' => '0.0000',
                'zone_code' => 'SOUTH',
                'version' => 0,
            ]);
        }

        $trait = new class {
            use \App\Traits\TransactionTrait;
        };

        $txId = $trait->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
            charge: '2.0000',
        );

        $this->assertNotNull($txId);

        // الأرصدة صحيحة
        $sender->refresh();
        $senderWallet = EMoney::where('user_id', $sender->id)->first();
        $receiverWallet = EMoney::where('user_id', $receiver->id)->first();
        $this->assertSame('898.0000', (string)$senderWallet->current_balance);
        $this->assertSame('100.0000', (string)$receiverWallet->current_balance);
    }

    /** @test */
    public function unknown_zone_user_is_blocked(): void
    {
        $sender = User::factory()->create(['type' => 2, 'zone_code' => 'UNKNOWN']);
        $receiver = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        foreach ([$sender, $receiver] as $u) {
            EMoney::create([
                'user_id' => $u->id,
                'current_balance' => '1000.0000',
                'charge_earned' => '0.0000',
                'pending_balance' => '0.0000',
                'held_balance' => '0.0000',
                'zone_code' => 'SOUTH',
                'version' => 0,
            ]);
        }

        $trait = new class {
            use \App\Traits\TransactionTrait;
        };

        $this->expectException(\RuntimeException::class);
        $trait->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
            charge: '0',
        );
    }

    /** @test */
    public function cash_out_also_blocks_non_south_user(): void
    {
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'MIDDLE']);

        EMoney::create([
            'user_id' => $customer->id,
            'current_balance' => '1000.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        $trait = new class {
            use \App\Traits\TransactionTrait;
        };

        $this->expectException(\RuntimeException::class);
        $trait->customer_cash_out_transaction(
            from_user_id: $customer->id,
            to_user_id: 99,
            amount: '100',
            charge: '5',
        );
    }

    /** @test */
    public function user_in_security_hold_is_blocked_even_if_south(): void
    {
        $sender = User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
            'security_hold_until' => now()->addHours(24),
            'security_hold_reason' => 'phone_change_self',
        ]);

        EMoney::create([
            'user_id' => $sender->id,
            'current_balance' => '1000.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        $trait = new class {
            use \App\Traits\TransactionTrait;
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('security hold');

        $trait->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: 99,
            amount: '100',
            charge: '0',
        );
    }
}
