<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentNetworkService;
use App\Services\AgentSettlementEngine;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-SETTLEMENT-ENGINE-001 — محرّك تسوية الوكلاء.
 *
 * **ما يُثبته هذا الملفّ قبل كلّ شيء: أنّ المحرّك يكشف الانحراف فعلاً.**
 *
 * محرّكُ تسويةٍ يقول «متوازن» دائماً أسوأ من غيابه: يُغلق الملفّ على مالٍ
 * ضائع. ولذلك يُفسَد الرصيد عمداً هنا في اختبارَين، ويُتأكّد أنّ الفرق
 * يظهر بمقداره — لا أنّه يظهر فقط.
 */
class AgentSettlementEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009900',
        ]);

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'العمقي', 'l_name' => 'للصرافة', 'phone' => '967771400001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1,
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq12345');
        $this->branch = $this->makeBranch('SHR', 'فرع الشحر');
        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّاف', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
    }

    private function makeBranch(string $code, string $name): AgentBranch
    {
        $bu = new User();
        $bu->forceFill([
            'f_name' => $name, 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '9677714' . random_int(10000, 99999),
            'password' => Hash::make('secret123'), 'is_active' => 1, 'is_kyc_verified' => 1,
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '0']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => $name, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $b = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => $name, 'code' => $code, 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
        ]);
        $b->till()->create(['cash_on_hand' => '0', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '10000']);

        return $b->fresh('till');
    }

    private function engine(): AgentSettlementEngine
    {
        return app(AgentSettlementEngine::class);
    }

    /** شحنٌ حقيقيّ: الوكيل يدفع نقداً ويستلم رصيداً. */
    private function topup(string $amount): void
    {
        $net = app(AgentNetworkService::class);
        $net->approveSettlement(
            $net->requestTopup($this->company, $amount, null, 'cash', 'إيداع نقديّ'),
            $this->admin,
        );
    }

    // ── المعادلة ───────────────────────────────────────────────────────

    /** @test */
    public function a_new_agent_with_no_activity_is_not_called_balanced(): void
    {
        $p = $this->engine()->position($this->company);

        // **«لا حركة بعد» ليست «متوازناً».** وكيلٌ لم يعمل غيرُ مُقيَّم، وعرضُه
        // أخضرَ يجعل المدير يظنّ الشبكة سليمةً وهي ساكنة.
        $this->assertSame(AgentSettlementEngine::STATUS_NO_DATA, $p['status']);
    }

    /** @test */
    public function buying_float_shows_up_as_expected_and_actual_together(): void
    {
        $this->topup('1000000');

        $p = $this->engine()->position($this->company);

        $this->assertSame('1000000.0000', $p['float']['breakdown']['bought']);
        $this->assertSame('1000000.0000', $p['float']['expected']);
        $this->assertSame('1000000.0000', $p['float']['actual']);
        $this->assertSame('0.0000', $p['float']['difference']);
        $this->assertSame(AgentSettlementEngine::STATUS_BALANCED, $p['status']);

        // التزام المنصّة = ما تدين به للوكيل.
        $this->assertSame('1000000.0000', $p['net']['company_liability']);
    }

    /**
     * @test
     *
     * مثال المواصفة حرفيّاً: يشتري رصيداً، يدفع نقداً لعملاء، فينقلب الرصيدان.
     */
    public function serving_customers_moves_float_into_cash_and_the_two_stay_balanced(): void
    {
        $this->topup('1000000');

        // الوكيل يموّل فرعه ثمّ يفتح الصرّاف ورديّته بعهدةٍ نقديّة.
        app(\App\Services\AgentBranchService::class)->fundBranch(
            $this->branch, $this->company, '500000', 'تمويل تشغيليّ');

        // نقدٌ يدخل الفرع من خزينة الشركة (توريد) — لا يُخلق من العدم.
        app(\App\Services\AgentTillService::class)->record(
            $this->branch, 'in', 'treasury_in', '300000', $this->company,
            note: 'توريد نقديّ افتتاحيّ');

        $shift = app(AgentShiftService::class)->open($this->teller, '100000');

        // عميلٌ يودع نقداً: النقد يدخل الدرج والرصيد يخرج من الفرع.
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '967773400001',
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $customer->id], ['current_balance' => '0']);

        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.deposit'),
                ['customer_id' => $customer->id, 'amount' => '60000'])->assertOk();

        $p = $this->engine()->position($this->company);

        // النقد الفعليّ = خزنة الفرع + درج الصرّاف. والدرج جزءٌ منه.
        $this->assertSame(
            bcadd($p['cash']['in_safes'], $p['cash']['in_drawers'], 4),
            $p['cash']['actual'],
        );

        // **والمعادلتان تتوازنان: لا فرق في أيٍّ منهما.**
        $this->assertSame('0.0000', $p['float']['difference'],
            'الرصيد الإلكترونيّ لا يطابق ما يفسّره الدفتر');
        $this->assertSame('0.0000', $p['cash']['difference'],
            'النقد لا يطابق ما يفسّره سجلّ الحركة');
        $this->assertSame(AgentSettlementEngine::STATUS_BALANCED, $p['status']);
    }

    // ── الكشف: أهمّ ما في المحرّك ───────────────────────────────────────

    /**
     * @test
     *
     * رصيدٌ يُزاد بالسرّ يُكشف بمقداره.
     *
     * **هذا هو الاختبار الذي يُثبت أنّ المحرّك محرّك.** يُغيَّر العمود مباشرةً
     * بلا قيدٍ في الدفتر — كما يقع بتدخّلٍ يدويّ في قاعدة البيانات أو بعطلٍ
     * في مسارٍ لا يُرحّل. والمتوقَّع محسوبٌ من الدفتر فلا يتحرّك معه.
     *
     * ولو حُسب المتوقَّع من العمود نفسه لخرج الفرق صفراً ولمرّ هذا بصمت.
     */
    public function float_added_without_a_ledger_entry_is_detected(): void
    {
        $this->topup('1000000');
        $this->assertSame('0.0000', $this->engine()->position($this->company)['float']['difference']);

        // تدخّلٌ صامت: عمودٌ يُزاد بلا قيد.
        EMoney::where('user_id', $this->company->id)->increment('current_balance', 250000);

        $p = $this->engine()->position($this->company);

        $this->assertSame('250000.0000', $p['float']['difference'],
            'المحرّك لم يكشف رصيداً أُضيف بلا قيد — فهو يصدّق العمود لا يدقّقه');
        $this->assertSame(AgentSettlementEngine::STATUS_OVER, $p['status']);
    }

    /**
     * @test
     *
     * ونقدٌ يختفي من الخزنة يُكشف كذلك.
     */
    public function cash_missing_from_the_safe_is_detected_as_a_shortage(): void
    {
        $this->topup('500000');

        app(\App\Services\AgentTillService::class)->record(
            $this->branch, 'in', 'treasury_in', '200000', $this->company,
            note: 'توريد نقديّ');

        $this->assertSame('0.0000', $this->engine()->position($this->company)['cash']['difference']);

        // نقدٌ يختفي بلا حركةٍ تشرحه — سرقةٌ أو خطأُ إدخال.
        DB::table('agent_cash_tills')->where('branch_id', $this->branch->id)
            ->decrement('cash_on_hand', 75000);

        $p = $this->engine()->position($this->company);

        $this->assertSame('-75000.0000', $p['cash']['difference'],
            'المحرّك لم يكشف نقداً اختفى بلا حركة');
        $this->assertSame(AgentSettlementEngine::STATUS_SHORT, $p['status']);
    }

    // ── التسوية اليوميّة ────────────────────────────────────────────────

    /** @test */
    public function shortages_and_overages_are_counted_separately_and_never_netted(): void
    {
        $this->topup('500000');
        app(\App\Services\AgentTillService::class)->record(
            $this->branch, 'in', 'treasury_in', '400000', $this->company, note: 'توريد');

        $b2 = $this->makeBranch('MKL', 'فرع المكلا');
        app(\App\Services\AgentTillService::class)->record(
            $b2, 'in', 'treasury_in', '400000', $this->company, note: 'توريد');

        $t2 = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّاف ٢', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $b2->id, 'password' => 'teller22',
        ]);

        // ورديّةٌ تنقص خمسة آلاف وأخرى تزيد خمسة آلاف.
        $s1 = app(AgentShiftService::class)->open($this->teller, '100000');
        app(AgentShiftService::class)->close($this->teller, $s1, '95000', 'عجز غير مفسَّر يُراجَع');

        $s2 = app(AgentShiftService::class)->open($t2, '100000');
        app(AgentShiftService::class)->close($t2, $s2, '105000', 'فائض غير مفسَّر يُراجَع');

        $d = $this->engine()->dailySettlement($this->company);

        // **لا مقاصّة.** المجموع صفر، والحادثتان اثنتان.
        $this->assertSame(1, $d['shortage_count']);
        $this->assertSame('5000.0000', $d['shortage_total']);
        $this->assertSame(1, $d['overage_count']);
        $this->assertSame('5000.0000', $d['overage_total']);
    }

    /** @test */
    public function an_open_drawer_is_reported_not_treated_as_no_difference(): void
    {
        $this->topup('500000');
        app(\App\Services\AgentTillService::class)->record(
            $this->branch, 'in', 'treasury_in', '300000', $this->company, note: 'توريد');

        app(AgentShiftService::class)->open($this->teller, '100000');

        $d = $this->engine()->dailySettlement($this->company);

        // درجٌ مفتوحٌ لم يُجرَد ليس «بلا فرق» — هو غيابُ شهادةِ إنسان.
        $this->assertSame(1, $d['unclosed_drawers']);
        $this->assertSame(0, $d['shifts_closed']);
    }

    // ── الشبكة ─────────────────────────────────────────────────────────

    /** @test */
    public function the_network_scan_lists_unbalanced_agents_first(): void
    {
        $this->topup('1000000');
        EMoney::where('user_id', $this->company->id)->increment('current_balance', 5000);

        $clean = new User();
        $clean->forceFill([
            'f_name' => 'وكيل', 'l_name' => 'نظيف', 'phone' => '967771400099',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1,
        ])->save();
        EMoney::create(['user_id' => $clean->id, 'current_balance' => '0']);

        $rows = $this->engine()->networkScan();

        $this->assertGreaterThanOrEqual(2, count($rows));
        // من يقرأ الشاشة يبحث عن المشكلة لا عن السلامة.
        $this->assertNotSame(AgentSettlementEngine::STATUS_BALANCED, $rows[0]['status']);
        $this->assertSame($this->company->id, $rows[0]['agent']['id']);
    }

    /** @test */
    public function the_admin_panel_serves_the_settlement_scan(): void
    {
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
        DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $this->admin->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );

        $this->topup('300000');

        $j = $this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.agents.settlement'))->assertOk()->json();

        $this->assertNotEmpty($j['data']);
        $this->assertArrayHasKey('float_difference', $j['data'][0]);

        $one = $this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.agents.settlement.one', ['id' => $this->company->id]))
            ->assertOk()->json();

        foreach (['expected', 'actual', 'difference', 'breakdown'] as $k) {
            $this->assertArrayHasKey($k, $one['position']['float']);
            $this->assertArrayHasKey($k, $one['position']['cash']);
        }
        $this->assertArrayHasKey('shortage_total', $one['daily']);
    }
}
