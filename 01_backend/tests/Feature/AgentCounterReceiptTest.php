<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\Receipt;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use App\Services\CustomerWithdrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-COUNTER-RECEIPT-001 — إيصال عمليات الشبّاك.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **كان الإيداع في الفرع بلا إيصالٍ إطلاقاً.**
 *
 * لا ورقةً في يد العميل، ولا سطراً في قائمة إيصالاته بالتطبيق. فمن أودع
 * مئة ألفٍ في فرعٍ يخرج بلا ما يُثبت أنّه أودع — ولو أُنكرت العملية لما
 * كان بيده إلّا كلامه.
 *
 * وهو يُصدَر بنفس `ReceiptService` الذي يُصدر بقيّة إيصالات المنصّة —
 * فللعملية الواحدة **مستندٌ واحدٌ برقمٍ واحد**، لا نسخةٌ للشبّاك وأخرى
 * للتطبيق تفترقان يوماً.
 */
class AgentCounterReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967772000001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967772000099', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع المكلا', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->branch = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع المكلا', 'code' => 'MKL', 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
        ]);
        $this->branch->till()->create([
            'cash_on_hand' => '3000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);
        $this->branch = $this->branch->fresh('till');

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'محمد علي', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
    }

    private function customer(string $balance = '0'): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '96777300' . random_int(1000, 9999),
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => $balance]);

        return $c;
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_counter_deposit_issues_a_receipt_for_the_customer(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->customer();

        $out = app(AgentCounterService::class)->deposit(
            $this->branch, $customer, '50000',
            $this->branch->account, 'إيداع نقديّ', $shift->fresh(),
        );

        $this->assertNotNull($out['receipt_number'], 'العملية تمّت بلا إيصال');

        $receipt = Receipt::where('receipt_number', $out['receipt_number'])->first();

        $this->assertNotNull($receipt);
        $this->assertSame('cash_in', $receipt->receipt_type);
        $this->assertSame('credit', $receipt->direction);
        // الإيصالُ **للعميل**: هو من يحتاج ما يُثبت إيداعه.
        $this->assertSame((int) $customer->id, (int) $receipt->user_id);
        $this->assertSame((int) $this->branch->branch_user_id, (int) $receipt->counterparty_user_id);
        $this->assertNotEmpty($receipt->verification_code);
    }

    /**
     * @test
     *
     * الإيصال يحمل **من نفّذ وأين** — فورقةٌ بلا فرعٍ ولا صرّافٍ لا تُثبت
     * شيئاً في نزاع.
     */
    public function the_receipt_names_the_branch_and_the_teller(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '200000');

        $out = app(AgentCounterService::class)->deposit(
            $this->branch, $this->customer(), '50000',
            $this->branch->account, null, $shift->fresh(),
        );

        $meta = (array) Receipt::where('receipt_number', $out['receipt_number'])->first()->metadata;

        $this->assertSame((int) $this->branch->id, (int) $meta['branch_id']);
        $this->assertSame('فرع المكلا', $meta['branch_name']);
        $this->assertSame('MKL', $meta['branch_code']);
        $this->assertSame('محمد علي', $meta['teller_name']);
        $this->assertSame($this->teller->username, $meta['teller_code']);
    }

    /** @test */
    public function the_deposit_receipt_carries_the_net_the_customer_received(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '200000');

        $out = app(AgentCounterService::class)->deposit(
            $this->branch, $this->customer(), '50000',
            $this->branch->account, null, $shift->fresh(),
        );

        $receipt = Receipt::where('receipt_number', $out['receipt_number'])->first();

        // ما وصل المحفظة هو الصافي — والعميل يقارن الورقة برصيده.
        $this->assertSame(bcadd($out['net'], '0', 4), bcadd((string) $receipt->amount, '0', 4));
    }

    /** @test */
    public function a_code_withdrawal_returns_its_receipt_number_so_it_can_be_printed(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '500000');
        $customer = $this->customer('400000');

        $req = app(CustomerWithdrawService::class)->request($customer, '100000');

        $out = $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertOk()->json('result');

        $this->assertNotNull($out['receipt_number'] ?? null,
            'السحب تمّ بلا رقم إيصالٍ يُطبَع — كان الإيصال يُصدَر ويُنسى');

        $receipt = Receipt::where('receipt_number', $out['receipt_number'])->first();
        $this->assertSame('cash_out', $receipt->receipt_type);
        $this->assertSame((int) $customer->id, (int) $receipt->user_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // صفحة الطباعة ونطاقها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_teller_can_open_the_printable_receipt(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->customer();

        $out = app(AgentCounterService::class)->deposit(
            $this->branch, $customer, '50000',
            $this->branch->account, null, $shift->fresh(),
        );

        $html = $this->actingAs($this->teller, 'agent_staff')
            ->get(route('agent.counter.receipt', ['number' => $out['receipt_number']]))
            ->assertOk()->getContent();

        // ما يجب أن يكون على الورقة.
        $this->assertStringContainsString($out['receipt_number'], $html);
        $this->assertStringContainsString($out['reference'], $html);
        $this->assertStringContainsString('فرع المكلا', $html);
        $this->assertStringContainsString('محمد علي', $html);
        $this->assertStringContainsString($customer->phone, $html);
        $this->assertStringContainsString('للتحقّق من صحّة هذا الإيصال', $html);
        // وزرّ الطباعة نفسه.
        $this->assertStringContainsString('btn-print', $html);
    }

    /**
     * @test
     *
     * **رقمُ الإيصال يأتي من المتصفّح.** فصرّافٌ يبدّل رقماً يقرأ إيصال
     * عميلٍ في فرعٍ ليس فرعه — ما لم يُفحص الفرع لا الرقم.
     */
    public function a_receipt_from_another_branch_is_refused(): void
    {
        // فرعٌ ثانٍ بصرّافه، وعمليّةٌ فيه.
        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع سيحوت', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967772000077', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع سيحوت', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $other = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع سيحوت', 'code' => 'SHT', 'city' => 'المهرة',
            'phone' => $bu->phone, 'is_active' => true,
        ]);
        $other->till()->create(['cash_on_hand' => '1000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '1000']);
        $other = $other->fresh('till');

        $otherTeller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّاف سيحوت', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $other->id, 'password' => 'other123',
        ]);
        $otherShift = app(AgentShiftService::class)->open($otherTeller, '100000');

        $out = app(AgentCounterService::class)->deposit(
            $other, $this->customer(), '10000',
            $other->account, null, $otherShift->fresh(),
        );

        // صرّاف المكلا يحاول فتح إيصال سيحوت.
        $this->actingAs($this->teller, 'agent_staff')
            ->get(route('agent.counter.receipt', ['number' => $out['receipt_number']]))
            ->assertStatus(403);

        // وصاحبُه يفتحه.
        $this->actingAs($otherTeller, 'agent_staff')
            ->get(route('agent.counter.receipt', ['number' => $out['receipt_number']]))
            ->assertOk();
    }

    /** @test */
    public function an_unknown_receipt_number_is_not_found(): void
    {
        $this->actingAs($this->teller, 'agent_staff')
            ->get(route('agent.counter.receipt', ['number' => 'NOPE1234']))
            ->assertStatus(404);
    }

    /**
     * @test
     *
     * **الإيصال لا يُسقط عمليّةً تمّت.** المال انتقل وقُيّد في الدفتر،
     * وإسقاطُ العملية لأنّ ورقةً لم تُطبع خطأٌ أفدح من غياب الورقة.
     */
    public function a_failing_receipt_does_not_undo_the_money(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->customer();

        // تُكسَر خدمةُ الإيصالات عمداً.
        $this->app->bind(\App\Services\ReceiptService::class, function () {
            throw new \RuntimeException('تعطّل مولّد الإيصالات');
        });

        $before = (string) EMoney::where('user_id', $customer->id)->value('current_balance');

        $out = app(AgentCounterService::class)->deposit(
            $this->branch, $customer, '50000',
            $this->branch->account, null, $shift->fresh(),
        );

        $after = (string) EMoney::where('user_id', $customer->id)->value('current_balance');

        $this->assertNull($out['receipt_number']);
        $this->assertSame(bcadd($out['net'], '0', 4), bcsub($after, $before, 4),
            'العملية أُلغيت لأنّ الإيصال فشل — والمال كان قد انتقل');
    }
}
