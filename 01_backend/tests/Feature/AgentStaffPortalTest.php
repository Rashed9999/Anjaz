<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use App\Services\CustomerWithdrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-AGENT-STAFF-001 — شركة صرافةٍ لها فروعٌ وموظّفون.
 *
 * تُنمذَج الحالة الحقيقيّة: شركةٌ مركزها الشحر، فرعان، صرّافان — كلٌّ على
 * كمبيوترٍ في فرعه.
 */
class AgentStaffPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $mukalla;
    private AgentBranch $seiyun;
    private AgentStaff $tellerMukalla;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAgentUser('967771000001', 'العمقي', 'للصرافة');
        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hqpass123');

        $this->mukalla = $this->makeBranch('MKL', 'فرع المكلا');
        $this->seiyun = $this->makeBranch('SYN', 'فرع سيئون');

        $this->tellerMukalla = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'أحمد الصرّاف', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->mukalla->id, 'password' => 'teller123',
        ]);
    }

    private function makeAgentUser(string $phone, string $f, string $l): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => $f, 'l_name' => $l, 'phone' => $phone, 'type' => AGENT_TYPE,
            'password' => Hash::make('secret123'), 'is_kyc_verified' => 1, 'is_active' => 1,
        ])->save();
        EMoney::create(['user_id' => $u->id, 'current_balance' => '0']);

        return $u;
    }

    private function makeBranch(string $code, string $name, string $emoney = '5000000'): AgentBranch
    {
        $bu = new User();
        $bu->forceFill([
            'f_name' => $name, 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '9677719' . random_int(10000, 99999),
            'password' => Hash::make('secret123'), 'is_active' => 1, 'is_kyc_verified' => 1,
        ])->save();

        EMoney::create(['user_id' => $bu->id, 'current_balance' => $emoney]);

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

        $b->till()->create([
            'cash_on_hand' => '1000000', 'max_cash_on_hand' => '5000000', 'min_cash_alert' => '100000',
        ]);

        return $b->fresh('till');
    }

    private function makeCustomer(string $phone, string $balance = '100000'): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => $phone, 'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => $balance]);

        return $c;
    }

    // ── التصميم ────────────────────────────────────────────────────────

    /** @test */
    public function no_counter_route_accepts_a_branch_id_from_the_browser(): void
    {
        // العطل الأصليّ في سطرٍ واحد: كانت `POST /agent/branches/{id}/deposit`.
        // فكانت الواجهة مضطرّةً لسؤال «اختر الفرع»، وكان أيّ موظّفٍ يستطيع
        // تمرير معرّف فرعٍ ليس فرعه. وما لا يُقبل من الطلب لا يحتاج فحصاً.
        $offenders = [];

        foreach (Route::getRoutes() as $r) {
            if (!str_starts_with($r->uri(), 'agent/counter')) {
                continue;
            }
            if (str_contains($r->uri(), '{')) {
                $offenders[] = $r->uri();
            }
        }

        $this->assertSame([], $offenders,
            "مسار شبّاكٍ يقبل معرّفاً من المتصفّح: " . implode('، ', $offenders));
    }

    /** @test */
    public function a_teller_logs_in_with_a_staff_code_and_the_branch_comes_from_the_account(): void
    {
        $this->assertMatchesRegularExpression('/^MKL-\d{3}$/', $this->tellerMukalla->username);

        $this->post(route('agent.login.submit'), [
            'username' => $this->tellerMukalla->username, 'password' => 'teller123',
        ])->assertRedirect(route('agent.dashboard'));

        $state = $this->getJson(route('agent.counter.state'))->assertOk()->json();

        // الفرع يُقرأ من الحساب، ولم يُرسَل في الطلب أصلاً.
        $this->assertSame($this->mukalla->id, $state['branch']['id']);
        $this->assertSame('teller', $state['staff']['role']);
        $this->assertNull($state['shift']);
        $this->assertFalse($state['can_operate']);
        $this->assertSame('افتح ورديّتك لتبدأ العمل', $state['why_not']);
    }

    /** @test */
    public function a_teller_cannot_reach_another_branch_even_by_guessing(): void
    {
        $this->actingAs($this->tellerMukalla, 'agent_staff');

        // ورديّةٌ في سيئون، ومحاولةُ رؤيتها من صرّاف المكلا.
        $this->getJson(route('agent.staff.shifts', ['branch_id' => $this->seiyun->id]))
            ->assertStatus(403);

        $this->assertSame([$this->mukalla->id], $this->tellerMukalla->visibleBranchIds());
    }

    // ── الورديّة ───────────────────────────────────────────────────────

    /** @test */
    public function opening_a_shift_moves_the_float_out_of_the_branch_safe_into_the_drawer(): void
    {
        $safeBefore = (string) $this->mukalla->till->cash_on_hand;

        $shift = app(AgentShiftService::class)->open($this->tellerMukalla, '200000');

        $this->assertSame('200000.0000', (string) $shift->cash_on_hand);
        $this->assertSame(
            bcsub($safeBefore, '200000', 4),
            (string) $this->mukalla->till->fresh()->cash_on_hand,
            'العهدة لم تخرج من خزنة الفرع — فالنقد تضاعف على الورق',
        );

        $mv = AgentCashMovement::where('reason', 'shift_open')->first();
        $this->assertNotNull($mv);
        $this->assertSame('out', $mv->direction);
        $this->assertSame($shift->id, (int) $mv->shift_id);
    }

    /** @test */
    public function a_teller_cannot_hold_two_open_shifts(): void
    {
        app(AgentShiftService::class)->open($this->tellerMukalla, '100000');

        $this->expectExceptionMessage('لديك ورديّة مفتوحة بالفعل');
        app(AgentShiftService::class)->open($this->tellerMukalla, '50000');
    }

    /** @test */
    public function a_manager_cannot_stand_at_a_counter(): void
    {
        $manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير المكلا', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->mukalla->id, 'password' => 'mgr12345',
        ]);

        $this->expectExceptionMessage('الورديّات للصرّافين');
        app(AgentShiftService::class)->open($manager, '100000');
    }

    /** @test */
    public function withdrawal_capacity_is_the_drawer_not_the_branch_safe(): void
    {
        // خزنةٌ فيها مليون ودرجٌ فيه عشرة آلاف. السحبُ خمسون ألفاً.
        app(AgentShiftService::class)->open($this->tellerMukalla, '10000');

        $customer = $this->makeCustomer('967772000001', '500000');
        $req = app(CustomerWithdrawService::class)->request($customer, '50000');

        $r = $this->actingAs($this->tellerMukalla, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'),
                ['op_code' => $req->op_code])->assertStatus(422);

        // القياس على الخزنة كان يقبلها ويقف الصرّاف عاجزاً أمام العميل.
        $this->assertStringContainsString('درجك', $r->json('message'));
    }

    /** @test */
    public function a_deposit_lands_in_the_tellers_drawer_not_the_branch_safe(): void
    {
        $shift = app(AgentShiftService::class)->open($this->tellerMukalla, '0');
        $safeBefore = (string) $this->mukalla->till->fresh()->cash_on_hand;

        $customer = $this->makeCustomer('967772000002', '0');
        $this->actingAs($this->tellerMukalla, 'agent_staff');

        $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id, 'amount' => '30000',
        ])->assertOk();

        $this->assertSame('30000.0000', (string) $shift->fresh()->cash_on_hand);
        $this->assertSame($safeBefore, (string) $this->mukalla->till->fresh()->cash_on_hand,
            'الإيداع دخل الخزنة بدل الدرج — فعجزُ الشبّاك يصير بلا صاحب');

        $this->assertSame('30000.0000', (string) $shift->fresh()->deposits_total);
        $this->assertSame(1, (int) $shift->fresh()->deposits_count);
    }

    /** @test */
    public function nothing_can_be_done_at_the_counter_without_an_open_shift(): void
    {
        $customer = $this->makeCustomer('967772000003');
        $this->actingAs($this->tellerMukalla, 'agent_staff');

        $r = $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id, 'amount' => '5000',
        ])->assertStatus(422);

        $this->assertStringContainsString('افتح ورديّتك', $r->json('message'));
    }

    /** @test */
    public function a_per_staff_limit_blocks_the_operation_rather_than_flagging_it(): void
    {
        $junior = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّاف جديد', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->mukalla->id, 'password' => 'junior12',
            'max_txn_amount' => '20000',
        ]);

        app(AgentShiftService::class)->open($junior, '500000');
        $customer = $this->makeCustomer('967772000004', '0');

        $this->actingAs($junior, 'agent_staff');

        $r = $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id, 'amount' => '50000',
        ])->assertStatus(422);

        $this->assertStringContainsString('حدّك للعملية', $r->json('message'));
    }

    // ── الجرد ──────────────────────────────────────────────────────────

    /** @test */
    public function the_expected_cash_is_computed_from_the_float_and_the_flow_not_read_back(): void
    {
        $shift = app(AgentShiftService::class)->open($this->tellerMukalla, '100000');
        $customer = $this->makeCustomer('967772000005', '0');

        $this->actingAs($this->tellerMukalla, 'agent_staff');
        $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id, 'amount' => '25000',
        ])->assertOk();

        $fresh = $shift->fresh();

        // ١٠٠٠٠٠ عهدة + ٢٥٠٠٠ إيداع = ١٢٥٠٠٠.
        $this->assertSame('125000.0000', $fresh->expectedCash());

        // ولو قُرئ المتوقَّع من `cash_on_hand` لقارن الرقم بنفسه وخرج الفرق
        // صفراً دائماً. نُفسد العمود عمداً ونتأكّد أنّ المتوقَّع لم يتبعه.
        DB::table('agent_shifts')->where('id', $fresh->id)->update(['cash_on_hand' => '1']);
        $this->assertSame('125000.0000', $fresh->fresh()->expectedCash());
    }

    /** @test */
    public function a_shortfall_is_recorded_as_a_movement_and_needs_a_written_reason(): void
    {
        $shift = app(AgentShiftService::class)->open($this->tellerMukalla, '100000');

        try {
            app(AgentShiftService::class)->close($this->tellerMukalla, $shift, '95000', 'نقص');
            $this->fail('قُبل فرقٌ بلا تفسير');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('عشرة أحرف', $e->getMessage());
        }

        $closed = app(AgentShiftService::class)
            ->close($this->tellerMukalla, $shift, '95000', 'عجز غير مفسَّر — أُبلغ مدير الفرع');

        $this->assertSame('-5000.0000', (string) $closed->variance);
        $this->assertSame(AgentShift::STATUS_CLOSED, $closed->status);

        $adj = AgentCashMovement::where('reason', 'count_adjustment')->first();
        $this->assertNotNull($adj, 'الفرق ابتُلع بلا حركة — وهو أوّل ما يُبحث عنه في أيّ تحقيق');
        $this->assertSame('out', $adj->direction);
        $this->assertSame($this->tellerMukalla->id, (int) $adj->staff_id,
            'الفرق غير منسوبٍ إلى صرّافه');
    }

    /** @test */
    public function closing_returns_the_counted_cash_to_the_branch_safe(): void
    {
        $safeStart = (string) $this->mukalla->till->cash_on_hand;
        $shift = app(AgentShiftService::class)->open($this->tellerMukalla, '100000');

        app(AgentShiftService::class)->close($this->tellerMukalla, $shift, '100000');

        // خرجت ١٠٠٠٠٠ وعادت ١٠٠٠٠٠ — الخزنة كما بدأت.
        $this->assertSame($safeStart, (string) $this->mukalla->till->fresh()->cash_on_hand);
    }

    /** @test */
    public function a_manager_can_close_a_shift_a_teller_walked_away_from(): void
    {
        $manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير المكلا', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->mukalla->id, 'password' => 'mgr12345',
        ]);

        $shift = app(AgentShiftService::class)->open($this->tellerMukalla, '50000');

        $closed = app(AgentShiftService::class)->close($manager, $shift, '50000');
        $this->assertSame($manager->id, (int) $closed->closed_by);
    }

    // ── الموظّفون ──────────────────────────────────────────────────────

    /** @test */
    public function a_branch_manager_can_only_hire_tellers_in_their_own_branch(): void
    {
        $manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير المكلا', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->mukalla->id, 'password' => 'mgr12345',
        ]);

        // لا يرقّي نفسه بإنشاء حسابٍ أعلى منه.
        try {
            app(AgentStaffService::class)->hire($manager, [
                'name' => 'مدير آخر', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
                'branch_id' => $this->mukalla->id, 'password' => 'x1234567',
            ]);
            $this->fail('مدير فرعٍ عيّن مدير فرع');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('صرّافين فقط', $e->getMessage());
        }

        // ولا يعيّن في فرعٍ آخر ولو مرّر معرّفه.
        $hired = app(AgentStaffService::class)->hire($manager, [
            'name' => 'صرّاف', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->seiyun->id, 'password' => 'x1234567',
        ]);

        $this->assertSame($this->mukalla->id, (int) $hired->branch_id,
            'مدير الفرع عيّن موظّفاً في فرعٍ ليس فرعه');
    }

    /** @test */
    public function a_teller_cannot_manage_staff_at_all(): void
    {
        $this->expectExceptionMessage('الصرّاف لا يُدير الموظّفين');

        app(AgentStaffService::class)->hire($this->tellerMukalla, [
            'name' => 'أحد', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->mukalla->id, 'password' => 'x1234567',
        ]);
    }

    /** @test */
    public function a_staff_member_with_an_open_shift_cannot_be_disabled(): void
    {
        app(AgentShiftService::class)->open($this->tellerMukalla, '50000');

        $this->expectExceptionMessage('أغلق ورديّة الموظّف أوّلاً');
        app(AgentStaffService::class)->setActive($this->hq, $this->tellerMukalla->id, false);
    }

    /** @test */
    public function staff_codes_are_unique_and_sequential_per_branch(): void
    {
        $b = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّاف ٢', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->mukalla->id, 'password' => 'x1234567',
        ]);
        $c = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّاف سيئون', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->seiyun->id, 'password' => 'x1234567',
        ]);

        $this->assertSame('MKL-001', $this->tellerMukalla->username);
        $this->assertSame('MKL-002', $b->username);
        $this->assertSame('SYN-001', $c->username);
    }

    /** @test */
    public function a_disabled_staff_account_cannot_log_in(): void
    {
        app(AgentStaffService::class)->setActive($this->hq, $this->tellerMukalla->id, false);

        $this->post(route('agent.login.submit'), [
            'username' => $this->tellerMukalla->username, 'password' => 'teller123',
        ])->assertSessionHasErrors('username');

        $this->assertGuest('agent_staff');
    }

    /** @test */
    public function staff_of_a_suspended_company_cannot_log_in(): void
    {
        // الشبّاك لا يبقى يخدم بعد إيقاف الشركة نفسها.
        $this->company->forceFill(['is_active' => 0])->save();

        $this->post(route('agent.login.submit'), [
            'username' => $this->tellerMukalla->username, 'password' => 'teller123',
        ])->assertSessionHasErrors('username');

        $this->assertGuest('agent_staff');
    }

    // ── السحب برمز العميل ──────────────────────────────────────────────

    /**
     * @test
     *
     * لا سحبَ من محفظة عميلٍ بلا رمزٍ أصدره هو.
     *
     * **ثغرةٌ أمنيّة أدخلتُها.** في المنصّة نظامُ سحبٍ يبدأ من العميل: يطلب
     * من تطبيقه فيُحجز المبلغ ويصدر رمزٌ مؤقّت، ثمّ يذهب به إلى الصرّاف.
     * وشبّاكي كان يتجاوزه: رقمُ هاتفٍ ومبلغٌ يُدخلهما الصرّاف فيُخصم من
     * محفظة العميل — **بلا موافقةٍ منه ولا أثرٍ يُثبت أنّه طلب السحب**.
     *
     * أي أنّ أيّ صرّافٍ يستطيع السحب من أيّ عميلٍ يعرف رقمه.
     */
    public function the_old_phone_based_withdrawal_is_closed(): void
    {
        app(AgentShiftService::class)->open($this->tellerMukalla, '500000');
        $customer = $this->makeCustomer('967772900001', '400000');
        $before = (string) EMoney::where('user_id', $customer->id)->value('current_balance');

        $r = $this->actingAs($this->tellerMukalla, 'agent_staff')
            ->postJson(route('agent.counter.withdraw'), [
                'customer_id' => $customer->id, 'amount' => '100000',
            ])->assertStatus(410);

        $this->assertStringContainsString('رمز العملية', $r->json('message'));
        $this->assertSame($before,
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'),
            'خُصم من محفظة العميل بلا رمزٍ منه');
    }

    /** @test */
    public function a_withdrawal_needs_a_code_the_customer_generated(): void
    {
        $shift = app(AgentShiftService::class)->open($this->tellerMukalla, '500000');
        $customer = $this->makeCustomer('967772900002', '400000');

        // العميل يطلب من تطبيقه — فيُحجز المبلغ ويصدر الرمز.
        $req = app(CustomerWithdrawService::class)->request($customer, '100000');
        $this->assertNotEmpty($req->op_code);

        $this->actingAs($this->tellerMukalla, 'agent_staff');

        // رمزٌ مخترع: يُرفض.
        $this->getJson(route('agent.counter.withdrawal.lookup', ['op_code' => 'ZZZZZZ']))
            ->assertStatus(404);

        // والرمز الصحيح يُري الصرّاف ما سيدفع قبل أن يعدّ.
        $look = $this->getJson(route('agent.counter.withdrawal.lookup',
            ['op_code' => $req->op_code]))->assertOk()->json();

        $this->assertSame('100000.0000', $look['request']['amount']);
        $this->assertTrue($look['can_pay']);

        $this->postJson(route('agent.counter.withdrawal.execute'),
            ['op_code' => $req->op_code])->assertOk();

        // النقد خرج من **درج الصرّاف** — فيُعرَف صاحب العجز عند الجرد.
        $this->assertSame('400000.0000', (string) $shift->fresh()->cash_on_hand);
        $this->assertSame('100000.0000', (string) $shift->fresh()->withdrawals_total);

        // ولا يُنفَّذ الرمز مرّتين.
        $this->postJson(route('agent.counter.withdrawal.execute'),
            ['op_code' => $req->op_code])->assertStatus(422);
    }

    /** @test */
    public function a_withdrawal_is_refused_when_the_drawer_cannot_cover_it(): void
    {
        app(AgentShiftService::class)->open($this->tellerMukalla, '10000');
        $customer = $this->makeCustomer('967772900003', '400000');
        $req = app(CustomerWithdrawService::class)->request($customer, '100000');

        $look = $this->actingAs($this->tellerMukalla, 'agent_staff')
            ->getJson(route('agent.counter.withdrawal.lookup',
                ['op_code' => $req->op_code]))->assertOk()->json();

        // يُقال قبل أن يعدّ الأوراق لا بعد أن يَعِد العميل.
        $this->assertFalse($look['can_pay']);
        $this->assertStringContainsString('درجك', $look['why_not']);

        $this->postJson(route('agent.counter.withdrawal.execute'),
            ['op_code' => $req->op_code])->assertStatus(422);
    }
}
