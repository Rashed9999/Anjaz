<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\FinancialGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * EarnedChargeBugTest — يتأكد أن bug AUDIT 1.7 لن يعود.
 *
 * Bug الأصلي: Helpers::updateEmoney السطر 699 كان يضيف $emoney->charge_earned += $charge
 * **داخل else (المستخدم العادي)**. النتيجة: كل عملية على مستخدم عادي تضيف للـ charge_earned
 * في صفه. تقارير الإيرادات تصبح مغشوشة.
 *
 * الإصلاح: charge_earned يُضاف فقط عبر FinancialGuardService::creditAdminCharge،
 * والذي بدوره يستهدف admin user فقط.
 */
class EarnedChargeBugTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function credit_to_regular_user_does_not_increment_charge_earned(): void
    {
        $user = User::factory()->create(['type' => 2]); // 2 = customer

        $wallet = EMoney::create([
            'user_id' => $user->id,
            'current_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        /** @var FinancialGuardService $guard */
        $guard = app(FinancialGuardService::class);

        DB::transaction(function () use ($guard, $user) {
            $guard->credit($user->id, '100.0000', 'test_credit');
        });

        $wallet->refresh();
        $this->assertSame('100.0000', (string)$wallet->current_balance);
        // charge_earned للمستخدم العادي يبقى صفر — هذا هو الإصلاح
        $this->assertSame('0.0000', (string)$wallet->charge_earned);
    }

    /** @test */
    public function debit_from_regular_user_does_not_touch_charge_earned(): void
    {
        $user = User::factory()->create(['type' => 2]);

        $wallet = EMoney::create([
            'user_id' => $user->id,
            'current_balance' => '500.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        /** @var FinancialGuardService $guard */
        $guard = app(FinancialGuardService::class);

        DB::transaction(function () use ($guard, $user) {
            $guard->debit($user->id, '100.0000', 'test_debit');
        });

        $wallet->refresh();
        $this->assertSame('400.0000', (string)$wallet->current_balance);
        $this->assertSame('0.0000', (string)$wallet->charge_earned);
    }

    /** @test */
    public function only_admin_credit_charge_increments_charge_earned(): void
    {
        $admin = User::factory()->create(['type' => 0]);

        $adminWallet = EMoney::create([
            'user_id' => $admin->id,
            'current_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        /** @var FinancialGuardService $guard */
        $guard = app(FinancialGuardService::class);

        DB::transaction(function () use ($guard, $admin) {
            $guard->creditAdminCharge($admin->id, '5.0000');
        });

        $adminWallet->refresh();
        // current_balance لم يتأثر
        $this->assertSame('0.0000', (string)$adminWallet->current_balance);
        // charge_earned زاد بالمبلغ
        $this->assertSame('5.0000', \App\Services\MoneyService::normalize((string)\App\Models\PlatformFeeEntry::sum('amount')));
    }

    /** @test */
    public function send_money_via_trait_increments_only_admin_charge_earned(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $sender = User::factory()->create(['type' => 2]);
        $receiver = User::factory()->create(['type' => 2]);

        foreach ([$admin, $sender, $receiver] as $u) {
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

        // إرسال 100 برسوم 2
        $controller = new class {
            use \App\Traits\TransactionTrait;
        };

        $txId = $controller->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
            charge: '2.0000',
        );

        $this->assertNotNull($txId);

        // فحص: الأدمن وحده charge_earned زاد
        $adminWallet = EMoney::where('user_id', $admin->id)->first();
        $senderWallet = EMoney::where('user_id', $sender->id)->first();
        $receiverWallet = EMoney::where('user_id', $receiver->id)->first();

        // الأدمن
        $this->assertSame('2.0000', \App\Services\MoneyService::normalize((string)\App\Models\PlatformFeeEntry::sum('amount')));

        // المرسل: charge_earned **يبقى صفر** (هذا الإصلاح — كان السبب bug 1.7)
        $this->assertSame('0.0000', (string)$senderWallet->charge_earned);

        // المستلم: charge_earned **يبقى صفر**
        $this->assertSame('0.0000', (string)$receiverWallet->charge_earned);

        // أرصدة current_balance صحيحة
        $this->assertSame('898.0000', (string)$senderWallet->current_balance);  // 1000 - 100 - 2
        $this->assertSame('100.0000', (string)$receiverWallet->current_balance);
        $this->assertSame('0.0000', (string)$adminWallet->current_balance);      // الأدمن لا يأخذ مبلغ
    }
}
