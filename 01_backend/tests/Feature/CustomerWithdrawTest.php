<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CustomerWithdrawService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-WITHDRAW-001 — السحب المبدوء من العميل.
 */
class CustomerWithdrawTest extends TestCase
{
    use RefreshDatabase;

    private CustomerWithdrawService $svc;
    private User $admin;
    private User $customer;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CustomerWithdrawService::class);

        $this->admin = User::factory()->create(['type' => 0]);
        $this->customer = User::factory()->create(['type' => 2, 'phone' => '+967700555000', 'zone_code' => 'SOUTH']);
        $this->agent = User::factory()->create(['type' => 1, 'zone_code' => 'SOUTH']);

        $this->wallet($this->admin->id);
        $this->wallet($this->customer->id, '100000.0000');
        $this->wallet($this->agent->id);

        // سحب 5% مع 40% عمولة وكيل
        FeeScheme::create([
            'code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '5.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '40.0000', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);
    }

    private function wallet(int $userId, string $balance = '0.0000'): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance, 'charge_earned' => '0.0000',
            'pending_balance' => '0.0000', 'held_balance' => '0.0000',
            'zone_code' => 'SOUTH', 'version' => 0,
        ]);
    }

    /** @test */
    public function request_holds_amount_plus_fee(): void
    {
        $req = $this->svc->request($this->customer, '10000');

        // الرسم 5% من 10000 = 500 → المحجوز = 10500
        $this->assertSame('pending', $req->status);
        $this->assertSame(MoneyService::normalize('500'), (string)$req->fee);
        $this->assertSame(MoneyService::normalize('10500'), (string)$req->total_debit);

        $w = EMoney::where('user_id', $this->customer->id)->first();
        $this->assertSame(MoneyService::normalize('89500'), (string)$w->current_balance); // 100000 - 10500
        $this->assertSame(MoneyService::normalize('10500'), (string)$w->held_balance);
    }

    /** @test */
    public function cancel_releases_hold(): void
    {
        $req = $this->svc->request($this->customer, '10000');
        $this->svc->cancel($this->customer, $req->id);

        $w = EMoney::where('user_id', $this->customer->id)->first();
        $this->assertSame(MoneyService::normalize('100000'), (string)$w->current_balance);
        $this->assertSame(MoneyService::normalize('0'), (string)$w->held_balance);
        $this->assertSame('cancelled', $req->fresh()->status);
    }

    /** @test */
    public function agent_executes_and_money_flows_correctly(): void
    {
        $req = $this->svc->request($this->customer, '10000'); // fee 500، عمولة 200، منصّة 300

        $result = $this->svc->execute($this->agent, $req->op_code, $this->customer->phone);
        $this->assertNotNull($result['transaction_id']);

        // العميل: خرج المحجوز كاملاً
        $cw = EMoney::where('user_id', $this->customer->id)->first();
        $this->assertSame(MoneyService::normalize('89500'), (string)$cw->current_balance);
        $this->assertSame(MoneyService::normalize('0'), (string)$cw->held_balance);

        // الوكيل: المبلغ + العمولة = 10000 + 200 = 10200
        $aw = EMoney::where('user_id', $this->agent->id)->first();
        $this->assertSame(MoneyService::normalize('10200'), (string)$aw->current_balance);

        // المنصّة: ربح 300
        $adminW = EMoney::where('user_id', $this->admin->id)->first();
        $this->assertSame(MoneyService::normalize('300'), MoneyService::normalize((string)\App\Models\PlatformFeeEntry::sum('amount')));

        $this->assertSame('completed', $req->fresh()->status);
    }

    /** @test */
    public function cannot_execute_twice(): void
    {
        $req = $this->svc->request($this->customer, '10000');
        $this->svc->execute($this->agent, $req->op_code, $this->customer->phone);

        $this->expectException(\RuntimeException::class);
        $this->svc->execute($this->agent, $req->op_code, $this->customer->phone);
    }

    /** @test */
    public function wrong_identifier_is_rejected(): void
    {
        $req = $this->svc->request($this->customer, '10000');
        $this->expectException(\RuntimeException::class);
        $this->svc->execute($this->agent, $req->op_code, '+967700999999'); // ليس العميل
    }

    /**
     * @test AMIAL-FIX(WITHDRAW-CANCEL) — الإلغاء لا يعلق عند نقص المحجوز.
     * الحجز قد يُحرَّر من مسار آخر (كنس انتهاء الصلاحية) بينما الطلب pending —
     * كان الإلغاء يرمي «لا يوجد محجوز كافٍ لفكّه» ويحبس المستخدم في الشاشة.
     */
    public function cancel_succeeds_even_if_hold_already_released(): void
    {
        $req = $this->svc->request($this->customer, '10000');

        // محاكاة تحرير الحجز من مسار آخر (الطلب ما زال pending)
        EMoney::where('user_id', $this->customer->id)
            ->update(['held_balance' => '0.0000', 'current_balance' => '100000.0000']);

        $cancelled = $this->svc->cancel($this->customer, $req->id);
        $this->assertContains($cancelled->status, ['cancelled', 'expired']);

        // الرصيد لم يُنفخ (لا فكّ لحجز غير موجود)
        $this->assertSame('100000.0000',
            (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'));
    }

    /** @test AMIAL-FIX(WITHDRAW-CANCEL) — إلغاء الملغى مسبقاً نجاح صامت (idempotent). */
    public function cancel_is_idempotent(): void
    {
        $req = $this->svc->request($this->customer, '10000');
        $this->svc->cancel($this->customer, $req->id);

        $again = $this->svc->cancel($this->customer, $req->id);
        $this->assertSame('cancelled', $again->status);

        // الرصيد عاد كاملاً مرة واحدة فقط
        $this->assertSame('100000.0000',
            (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'));
    }
}
