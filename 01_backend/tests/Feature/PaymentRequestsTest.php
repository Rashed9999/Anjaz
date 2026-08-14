<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\AmialNotification;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\MoneyService;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-PAYMENT-REQUESTS-001 — اختبارات.
 */
class PaymentRequestsTest extends TestCase
{
    use RefreshDatabase;

    private PaymentRequestService $svc;
    private User $requester;
    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PaymentRequestService::class);

        $this->requester = User::factory()->create(['type' => 2, 'phone' => '+967700001', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1]);
        $this->payer = User::factory()->create(['type' => 2, 'phone' => '+967700002', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1]);

        foreach ([$this->requester->id => '1000', $this->payer->id => '50000'] as $uid => $bal) {
            EMoney::create([
                'user_id' => $uid, 'current_balance' => $bal . '.0000',
                'held_balance' => '0.0000', 'pending_balance' => '0.0000',
                'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
            ]);
        }
    }

    /** @test */
    public function create_generates_unique_short_code(): void
    {
        $req = $this->svc->create($this->requester, '5000', note: 'فاتورة العشاء');

        $this->assertSame('pending', $req->status);
        $this->assertNotEmpty($req->short_code);
        $this->assertSame(6, strlen($req->short_code));
        $this->assertStringContainsString("/req/{$req->short_code}", $req->publicUrl());
        $this->assertSame(MoneyService::normalize('5000'), (string)$req->amount);
    }

    /** @test */
    public function find_by_code_works_case_insensitive(): void
    {
        $req = $this->svc->create($this->requester, '1000');
        $found = $this->svc->findByCode(strtolower($req->short_code));
        $this->assertNotNull($found);
        $this->assertSame($req->id, $found->id);
    }

    /** @test */
    public function pay_transfers_money_and_marks_paid(): void
    {
        $req = $this->svc->create($this->requester, '3000');

        $result = $this->svc->pay($this->payer, $req);

        $this->assertArrayHasKey('transaction_id', $result);

        // الأرصدة تحرّكت
        $reqBalance = EMoney::where('user_id', $this->requester->id)->value('current_balance');
        $payerBalance = EMoney::where('user_id', $this->payer->id)->value('current_balance');
        $this->assertSame(MoneyService::normalize('4000'), (string)$reqBalance);
        $this->assertSame(MoneyService::normalize('47000'), (string)$payerBalance);

        // الطلب تحوّل لـ paid
        $req->refresh();
        $this->assertSame('paid', $req->status);
        $this->assertSame($this->payer->id, $req->paid_by_user_id);
        $this->assertNotNull($req->paid_at);
    }

    /** @test */
    public function pay_rejects_self_payment(): void
    {
        $req = $this->svc->create($this->requester, '1000');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('طلب أنشأته بنفسك');
        $this->svc->pay($this->requester, $req);
    }

    /** @test */
    public function pay_rejects_wrong_recipient(): void
    {
        $third = User::factory()->create(['type' => 2, 'phone' => '+967700003', 'is_active' => 1, 'is_kyc_verified' => 1]);
        EMoney::create([
            'user_id' => $third->id, 'current_balance' => '10000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        // طلب موجّه للـ payer فقط
        $req = $this->svc->create($this->requester, '500', recipientPhone: $this->payer->phone);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('موجّه لشخص آخر');
        $this->svc->pay($third, $req);
    }

    /** @test */
    public function pay_rejects_expired_request(): void
    {
        $req = $this->svc->create($this->requester, '500');
        // اجعله منتهي الصلاحية
        $req->update(['expires_at' => now()->subDay()]);

        $this->expectException(\RuntimeException::class);
        $this->svc->pay($this->payer, $req);
    }

    /** @test */
    public function pay_rejects_double_payment(): void
    {
        $req = $this->svc->create($this->requester, '500');
        $this->svc->pay($this->payer, $req);

        // محاولة دفع ثانية
        $this->expectException(\RuntimeException::class);
        // **النصُّ صار «هذا الطلب مدفوع أو غير صالح»**، والمطابقةُ تُشدّ إلى
        // جذرِ المعنى لا إلى صياغةٍ كاملةٍ تتغيّر مع كلّ تحسينِ رسالة.
        $this->expectExceptionMessage('مدفوع');
        $this->svc->pay($this->payer, $req);
    }

    /** @test */
    public function cancel_only_allowed_by_requester(): void
    {
        $req = $this->svc->create($this->requester, '500');

        // الدافع يحاول الإلغاء
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->cancel($this->payer, $req);
    }

    /** @test */
    public function paid_dispatches_notifications_to_both(): void
    {
        $req = $this->svc->create($this->requester, '500');
        $this->svc->pay($this->payer, $req);

        $recvNotif = AmialNotification::where('user_id', $this->requester->id)
            ->where('type', 'transfer_received')->first();
        $sentNotif = AmialNotification::where('user_id', $this->payer->id)
            ->where('type', 'transfer_sent')->first();

        $this->assertNotNull($recvNotif);
        $this->assertNotNull($sentNotif);
    }

    /** @test */
    public function expire_stale_marks_old_pending_as_expired(): void
    {
        $r1 = $this->svc->create($this->requester, '100');
        $r2 = $this->svc->create($this->requester, '200');
        $r1->update(['expires_at' => now()->subHour()]);

        $count = $this->svc->expireStale();
        $this->assertSame(1, $count);

        $this->assertSame('expired', $r1->fresh()->status);
        $this->assertSame('pending', $r2->fresh()->status);
    }
}
