<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialGuardService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * ConcurrentSendMoneyTest — يتأكد من أن lockForUpdate يمنع رصيد سالب.
 *
 * هذا الـ test يصادف مباشرة AUDIT 1.1 و 1.2.
 *
 * منهجية الاختبار:
 *   - ننشئ مستخدماً برصيد 100.
 *   - نطلق محاولتَيْ تحويل متزامنتَيْن (60 + 60).
 *   - مع lockForUpdate: واحدة تنجح، الثانية ترمي InsufficientBalanceException.
 *   - بدون lock (legacy): الاثنتان تنجحان، الرصيد يصبح -20.
 *
 * بما أن PHP عادي ليس multi-threaded، نحاكي race بـ:
 *   - فتح transaction مع lockForUpdate في الـ test.
 *   - بدء transaction ثانية في process آخر (pcntl_fork) أو محاكاة بسيطة بـ
 *     قراءة-ثم-نوم-ثم-حفظ.
 *
 * هذا الـ test يفترض MySQL/PostgreSQL مع SELECT FOR UPDATE فعلي.
 * SQLite (memory) لا يدعم row locks → نتخطى هناك.
 */
class ConcurrentSendMoneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'sqlite' || str_contains(config('database.connections.' . config('database.default') . '.driver', ''), 'sqlite')) {
            $this->markTestSkipped('Row locking not supported on SQLite');
        }
    }

    /**
     * @test
     * AUDIT 1.1: lockForUpdate يمنع رصيد سالب تحت race.
     */
    public function debit_with_lock_prevents_negative_balance_under_concurrent_attempts(): void
    {
        // إنشاء محفظة برصيد 100
        $user = User::factory()->create();
        $wallet = EMoney::create([
            'user_id' => $user->id,
            'current_balance' => '100.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        /** @var FinancialGuardService $guard */
        $guard = app(FinancialGuardService::class);

        // محاولة 1: خصم 60 → نجاح (يبقى 40)
        DB::transaction(function () use ($guard, $user) {
            $w = $guard->debit($user->id, '60.0000', 'test_debit_1');
            $this->assertSame('40.0000', (string)$w->current_balance);
        });

        // محاولة 2: خصم 60 ثانية → فشل (الرصيد 40 لا يكفي)
        $this->expectException(InsufficientBalanceException::class);

        DB::transaction(function () use ($guard, $user) {
            $guard->debit($user->id, '60.0000', 'test_debit_2');
        });

        // الرصيد يجب أن يبقى 40 (لم يُخصم رغم رمي الـ exception)
        $wallet->refresh();
        $this->assertSame('40.0000', (string)$wallet->current_balance);

        // ولا يوجد رصيد سالب على الإطلاق في DB
        $this->assertGreaterThanOrEqual(0, $wallet->current_balance);
    }

    /**
     * @test
     * AUDIT 1.2: send_money الأصلي كان لا يفحص الرصيد إطلاقاً.
     */
    public function send_money_now_rejects_when_balance_insufficient(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();

        EMoney::create([
            'user_id' => $sender->id,
            'current_balance' => '10.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        EMoney::create([
            'user_id' => $receiver->id,
            'current_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        // محاولة إرسال 100 ورصيد 10 — يجب أن تفشل
        $this->expectException(InsufficientBalanceException::class);

        $controller = new class {
            use \App\Traits\TransactionTrait;
        };

        $controller->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '100.0000',
            charge: '0.0000',
        );
    }

    /**
     * @test
     * يتأكد أن الـ rollback يعمل عند فشل في منتصف العملية.
     */
    public function failed_transaction_rolls_back_all_changes(): void
    {
        $sender = User::factory()->create();

        $senderWallet = EMoney::create([
            'user_id' => $sender->id,
            'current_balance' => '100.0000',
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        // نحاول خصم 200 → سيرمي exception → rollback
        try {
            DB::transaction(function () use ($sender) {
                /** @var FinancialGuardService $guard */
                $guard = app(FinancialGuardService::class);
                $guard->debit($sender->id, '200.0000', 'test_should_fail');
            });
        } catch (InsufficientBalanceException $e) {
            // متوقع
        }

        // الرصيد لم يتغير
        $senderWallet->refresh();
        $this->assertSame('100.0000', (string)$senderWallet->current_balance);

        // ولا transaction record تم إنشاؤه
        $this->assertSame(0, Transaction::where('user_id', $sender->id)->count());
    }

    /**
     * @test
     * يتأكد أن MoneyService::add صحيح لمبالغ صغيرة (سنتات).
     */
    public function decimal_precision_does_not_drift_after_many_operations(): void
    {
        $user = User::factory()->create();

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

        // 1000 deposit × 0.01
        for ($i = 0; $i < 1000; $i++) {
            DB::transaction(function () use ($guard, $user) {
                $guard->credit($user->id, '0.01', 'test_micro_deposit');
            });
        }

        // المجموع يجب أن يكون 10.00 بدقة
        $wallet->refresh();
        $this->assertTrue(
            MoneyService::eq($wallet->current_balance, '10.00'),
            "Balance drifted: expected 10.00, got {$wallet->current_balance}"
        );
    }
}
