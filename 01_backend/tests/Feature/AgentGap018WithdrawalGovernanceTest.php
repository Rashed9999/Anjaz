<?php

namespace Tests\Feature;

use App\Http\Controllers\Agent\AgentCounterController;
use App\Http\Controllers\Agent\AgentPortalController;
use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentDailySettlement;
use App\Models\Agent\AgentStaff;
use App\Models\Agent\AgentTellerRequest;
use App\Models\Agent\AgentWorkEvent;
use App\Models\AuditDecision;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentDailySettlementService;
use App\Services\AgentNetworkService;
use App\Services\AgentSettlementEngine;
use App\Services\AgentShiftService;
use App\Services\AgentShiftStatementService;
use App\Services\AgentStaffService;
use App\Services\AgentTellerRequestService;
use App\Services\AgentTillService;
use App\Services\AgentWorkTimeService;
use App\Services\CustomerWithdrawService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * AGT-GAP-018 — التحقق من مسار السحب الحيّ (CASH_OUT) وحوكمة إغلاق المسارات الميّتة.
 *
 * يثبت هذا الاختبار بالوقائع:
 * 1) إغلاق المسارات البديلة غير المعتمدة (`POST /agent/counter/withdraw` = 410، وإزالة
 *    طرق `AgentPortalController::deposit/withdraw/counterOp` وفرع السحب الميّت في `operate`).
 * 2) السحب الحيّ (`POST /agent/counter/withdrawal`) يقرأ رسمه من `CASH_OUT` حصراً ولا
 *    يتأثر بتفعيل أو تغيير `AGENT_WITHDRAW` (مما يوثّق سبب الحاجة لقرار المالك).
 * 3) ارتباط السحب الحيّ بمحفظة العميل، محفظة الفرع، درج الوردية، خزنة الفرع،
 *    التسوية اليومية (مع Hard Block للورديّات المفتوحة وفروق الجرد)، والأستاذ العام.
 */
class AgentGap018WithdrawalGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private User $branchAccount;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'super_admin',
            'phone' => '967770018800',
        ]);
        EMoney::updateOrCreate(['user_id' => $this->admin->id], ['current_balance' => '9000000']);
        app(PlatformRoleService::class)->assign($this->admin, PlatformRoleService::ADMIN);

        $this->company = new User();
        $this->company->email = 'company-gap018@example.test';
        $this->company->forceFill([
            'f_name' => 'شركة',
            'l_name' => 'أميال',
            'phone' => '967771818001',
            'type' => AGENT_TYPE,
            'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);
        app(AgentNetworkService::class)->adminCreditAgent($this->company, '500000', $this->admin, 'رصيد ابتدائي للاختبار');

        DB::table('agent_profiles')->insert([
            'user_id' => $this->company->id,
            'agent_level' => 1,
            'business_name' => 'شركة أميال',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $this->branchAccount = new User();
        $this->branchAccount->email = 'branch-gap018@example.test';
        $this->branchAccount->forceFill([
            'f_name' => 'فرع',
            'l_name' => 'عدن',
            'phone' => '967771818099',
            'type' => AGENT_TYPE,
            'password' => Hash::make('secret123'),
            'is_active' => 1,
            'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->branchAccount->id, 'current_balance' => '100000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $this->branchAccount->id,
            'parent_agent_id' => $this->company->id,
            'agent_level' => 2,
            'business_name' => 'فرع عدن',
            'status' => 'active',
            'single_transaction_limit' => '500000',
            'daily_cash_out_limit' => '1000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branch = AgentBranch::create([
            'agent_user_id' => $this->company->id,
            'branch_user_id' => $this->branchAccount->id,
            'name' => 'فرع عدن',
            'code' => 'ADN',
            'city' => 'عدن',
            'phone' => $this->branchAccount->phone,
            'is_active' => true,
        ]);
        $this->branch->till()->create([
            'cash_on_hand' => '0',
            'max_cash_on_hand' => '9000000',
            'min_cash_alert' => '50000',
        ]);
        app(AgentTillService::class)->record($this->branch, 'in', 'opening', '500000', $this->branchAccount);
        $this->branch = $this->branch->fresh('till');

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صراف عدن',
            'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id,
            'password' => 'teller12',
        ]);
    }

    private function createCustomer(string $balance): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'phone' => '9677729' . random_int(10000, 99999),
            'is_active' => 1,
            'is_kyc_verified' => 1,
            'kyc_tier' => 3,
            'zone_code' => 'SOUTH',
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => $balance]);

        return $c;
    }

    private function configureOpenWorkingHours(string $timezone = 'Asia/Riyadh'): void
    {
        config()->set('amial.branch_working_hours.timezone', $timezone);
        $this->branch->working_hours = array_fill_keys(
            ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
            ['open' => '00:00', 'close' => '23:59'],
        );
        $this->branch->save();
    }

    private function configureFourEyes(
        string $threshold = '500000',
        string $currency = 'YER',
        string $firstPerm = 'platform.settlements.decide',
        string $secondPerm = 'platform.settlements.decide',
    ): void {
        config()->set('amial.daily_settlement.four_eyes', [
            'threshold' => $threshold,
            'currency' => $currency,
            'first_approver_permission' => $firstPerm,
            'second_approver_permission' => $secondPerm,
        ]);
    }

    public function test_dead_counter_and_portal_withdrawal_bypasses_are_closed(): void
    {
        // طرق AgentPortalController الميتة (/branches/{id}/deposit وwithdraw) محذوفة.
        $this->assertFalse(method_exists(AgentPortalController::class, 'deposit'));
        $this->assertFalse(method_exists(AgentPortalController::class, 'withdraw'));
        $this->assertFalse(method_exists(AgentPortalController::class, 'counterOp'));

        // operate() في AgentCounterController لا يقبل السحب؛ الإيداع فقط له operateDeposit().
        $ref = new ReflectionClass(AgentCounterController::class);
        $this->assertFalse($ref->hasMethod('operate'));
        $this->assertTrue($ref->hasMethod('operateDeposit'));
        $this->assertTrue(Route::has('agent.counter.withdrawal.execute'));

        // المسار القديم POST /agent/counter/withdraw يظل 410 Gone صريحاً.
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdraw'), [])
            ->assertStatus(410);
    }

    public function test_live_counter_withdrawal_prices_via_cash_out_and_ignores_agent_withdraw_scheme(): void
    {
        $this->configureOpenWorkingHours();

        FeeScheme::create([
            'code' => 'CASH_OUT',
            'label' => 'سحب نقدي عملاء',
            'fee_type' => 'percent',
            'percent_rate' => '1.00',
            'fixed_amount' => '0',
            'agent_commission_percent' => '60',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'version' => 1,
            'is_active' => true,
        ]);

        // تفعيل AGENT_WITHDRAW بنسبة مختلفة تماماً (5% بدل 1%) يثبت عدم استهلاكه حياً.
        FeeScheme::create([
            'code' => 'AGENT_WITHDRAW',
            'label' => 'سحب شباك غير مستهلك حياً',
            'fee_type' => 'percent',
            'percent_rate' => '5.00',
            'fixed_amount' => '0',
            'agent_commission_percent' => '80',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'zone_code' => 'SOUTH',
            'applies_to' => 'agent',
            'version' => 1,
            'is_active' => true,
        ]);

        app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->createCustomer('200000');
        $req = app(CustomerWithdrawService::class)->request($customer, '50000');

        // 1% من 50,000 = 500، عمولة الوكيل 60% = 300، حصة المنصة = 200 (لا 5% = 2,500).
        $this->assertSame('500.0000', bcadd((string) $req->fee, '0', 4));
        $this->assertSame('300.0000', bcadd((string) $req->agent_commission, '0', 4));
        $this->assertSame('200.0000', bcadd((string) $req->platform_profit, '0', 4));

        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertOk();

        $this->assertSame(
            '149500.0000',
            bcadd((string) EMoney::where('user_id', $customer->id)->value('current_balance'), '0', 4),
        );
        $this->assertSame(
            '150300.0000', // 100,000 + 50,000 + 300
            bcadd((string) EMoney::where('user_id', $this->branchAccount->id)->value('current_balance'), '0', 4),
        );
        // محفظة الشركة الأم لم تتغيّر مباشرة عند تنفيذ الفرع للسحب.
        $this->assertSame(
            '500000.0000',
            bcadd((string) EMoney::where('user_id', $this->company->id)->value('current_balance'), '0', 4),
        );
    }

    public function test_live_withdrawal_respects_break_and_capability_blocks_and_reconciles_shift_till_and_daily_settlement(): void
    {
        $this->configureOpenWorkingHours();
        $this->configureFourEyes();

        FeeScheme::create([
            'code' => 'CASH_OUT',
            'label' => 'سحب نقدي',
            'fee_type' => 'percent',
            'percent_rate' => '2.00',
            'fixed_amount' => '0',
            'agent_commission_percent' => '50',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'version' => 1,
            'is_active' => true,
        ]);

        $shift = app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->createCustomer('200000');
        $req = app(CustomerWithdrawService::class)->request($customer, '40000');

        // 1) المنع المعتمد أثناء الاستراحة (422).
        app(AgentWorkTimeService::class)->record($this->teller, AgentWorkEvent::BREAK_START, (int) $shift->id);
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
        app(AgentWorkTimeService::class)->record($this->teller, AgentWorkEvent::BREAK_END, (int) $shift->id);

        // 2) المنع المعتمد عند سحب صلاحية السحب من الموظف (403).
        $this->teller->forceFill(['capabilities' => ['withdraw' => false]])->save();
        $this->actingAs($this->teller->fresh(), 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertStatus(403);
        $this->teller->forceFill(['capabilities' => null])->save();

        // 3) التنفيذ الصحيح عبر البوابة.
        $this->actingAs($this->teller->fresh(), 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertOk();

        // الدرج نقص 40,000؛ وسُجلت حركة درج لـ customer_withdraw.
        $shift->refresh();
        $this->assertSame('160000.0000', bcadd((string) $shift->cash_on_hand, '0', 4));
        $this->assertSame('40000.0000', bcadd((string) $shift->withdrawals_total, '0', 4));
        $this->assertTrue(AgentCashMovement::where('shift_id', $shift->id)
            ->where('reason', 'customer_withdraw')
            ->where('reference', $req->op_code)
            ->exists());

        // خزنة الفرع بعد الفتح كانت 300,000 (500,000 - 200,000 عهدة).
        $this->assertSame('300000.0000', bcadd((string) $this->branch->till->fresh()->cash_on_hand, '0', 4));

        // 4) التسوية اليومية ترفض الإغلاق (Hard Block) طالما الوردية مفتوحة.
        $today = now()->toDateString();
        $inWindow = now()->startOfDay()->addHours(22)->addMinutes(30);
        try {
            app(AgentDailySettlementService::class)->submit($this->company, $this->hq, $today, $inWindow);
            $this->fail('تم السماح برفع التسوية اليومية والوردية ما زالت مفتوحة');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ورديات مفتوحة', $e->getMessage());
        }

        // إغلاق الوردية يعيد الـ 160,000 المعدودة إلى الخزنة (لتصبح 460,000).
        app(AgentShiftService::class)->close($this->teller->fresh(), $shift, '160000');
        $this->assertSame('460000.0000', bcadd((string) $this->branch->till->fresh()->cash_on_hand, '0', 4));

        // رفع التسوية وقبولها يتحول إلى payout بقيمة 40,000 (عجز نقد 40,000- يُعاد من رصيد الشركة).
        $row = app(AgentDailySettlementService::class)->submit($this->company, $this->hq, $today, $inWindow);
        $this->assertSame(AgentDailySettlement::STATUS_SUBMITTED, $row->status);
        $this->assertSame('payout', $row->conversion);
        $this->assertSame('40000.0000', bcadd((string) $row->conversion_amount, '0', 4));
        $this->assertSame('800.0000', bcadd((string) $row->fees_collected, '0', 4));
        $this->assertSame('400.0000', bcadd((string) $row->agent_commission, '0', 4));

        $accepted = app(AgentDailySettlementService::class)->accept($row, $this->admin, 'اعتماد تسوية عدن');
        $this->assertSame(AgentDailySettlement::STATUS_ACCEPTED, $accepted->status);
        $this->assertNotEmpty($accepted->linked_settlement_ulid);
        $this->assertSame(
            '460000.0000',
            bcadd((string) EMoney::where('user_id', $this->company->id)->value('current_balance'), '0', 4),
        );
    }

    public function test_missing_working_hours_or_timezone_fails_closed_without_defaults(): void
    {
        app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->createCustomer('200000');
        $req = app(CustomerWithdrawService::class)->request($customer, '20000');

        // 1) غياب المنطقة الزمنية المعتمدة (config = null والفرع بلا timezone).
        $this->branch->update([
            'working_hours' => array_fill_keys(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], ['open' => '00:00', 'close' => '23:59']),
        ]);

        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertStatus(422);
        $this->assertTrue(AuditDecision::where('decision_code', 'MISSING_TIMEZONE_CONFIG')->exists());

        // 2) اعتماد المنطقة الزمنية مع غياب جدول ساعات عمل الفرع -> يرفض أيضاً ولا يفترض ساعات.
        config()->set('amial.branch_working_hours.timezone', 'Asia/Aden');
        $this->branch->update(['working_hours' => null]);

        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertStatus(422);
        $this->assertTrue(AuditDecision::where('decision_code', 'MISSING_WORKING_HOURS')->exists());

        // رصيد العميل المحجوز وحالته لم يُمسّا.
        $this->assertSame('pending', $req->fresh()->status);
    }

    public function test_outside_working_hours_blocks_without_approved_policy_and_enforces_auditable_one_time_exception(): void
    {
        // نضبط الوقت إلى 22:00 بتوقيت عدن (خارج نافذة 08:00–16:00).
        $this->travelTo(\Carbon\Carbon::create(2026, 11, 17, 22, 0, 0, 'Asia/Aden')->utc());

        config()->set('amial.branch_working_hours.timezone', 'Asia/Aden');
        $this->branch->update([
            'working_hours' => [
                'sun' => ['open' => '08:00', 'close' => '16:00'],
                'mon' => ['open' => '08:00', 'close' => '16:00'],
                'tue' => ['open' => '08:00', 'close' => '16:00'],
                'wed' => ['open' => '08:00', 'close' => '16:00'],
                'thu' => ['open' => '08:00', 'close' => '16:00'],
                'fri' => null,
                'sat' => null,
            ],
        ]);

        app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->createCustomer('200000');
        $req1 = app(CustomerWithdrawService::class)->request($customer, '30000');
        $req2 = app(CustomerWithdrawService::class)->request($customer, '10000');

        // 1) غياب صلاحيات الاستثناء المعتمدة يمنع التجاوز وطلب الاستثناء نفسه (fail-closed).
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req1->op_code])
            ->assertStatus(422);
        $this->assertTrue(AuditDecision::where('decision_code', 'OUTSIDE_HOURS_NO_EXCEPTION_POLICY')->exists());

        try {
            app(AgentTellerRequestService::class)->submit($this->teller, [
                'kind' => AgentTellerRequest::KIND_AFTER_HOURS_WITHDRAWAL,
                'operation' => 'withdraw',
                'amount' => '30000',
                'customer_user_id' => $customer->id,
                'reason' => 'حالة طوارئ موثقة للعميل خارج الدوام',
            ]);
            $this->fail('سُمح بطلب استثناء دون اعتماد صلاحيات الاستثناء');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('غير معتمدة', $e->getMessage());
        }

        // 2) اعتماد سياسة الاستثناء صراحةً في التهيئة للاختبار.
        config()->set('amial.branch_working_hours.exception_roles', [AgentStaff::ROLE_BRANCH_MANAGER]);
        config()->set('amial.branch_working_hours.exception_capability', 'override_limit');

        $manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير فرع عدن',
            'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->branch->id,
            'password' => 'mgr12345',
        ]);
        $treasuryOfficer = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مسؤول خزنة عدن',
            'role' => AgentStaff::ROLE_TREASURY_OFFICER,
            'branch_id' => $this->branch->id,
            'password' => 'trs12345',
        ]);

        // بدون استثناء معتمد يُرفض السحب.
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req1->op_code])
            ->assertStatus(422);
        $this->assertTrue(AuditDecision::where('decision_code', 'OUTSIDE_WORKING_HOURS')->exists());

        // الصراف يقدّم طلب استثناء خارج ساعات العمل.
        $exception = app(AgentTellerRequestService::class)->submit($this->teller, [
            'kind' => AgentTellerRequest::KIND_AFTER_HOURS_WITHDRAWAL,
            'operation' => 'withdraw',
            'amount' => '30000',
            'customer_user_id' => $customer->id,
            'reason' => 'عميل مسافر وبحاجة صرف عاجل بعد الدوام',
        ]);

        // منع الموافقة الذاتية.
        try {
            app(AgentTellerRequestService::class)->decide($this->teller, $exception->id, true);
            $this->fail('تمكن الصراف من اعتماد استثناء لنفسه');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('الإدارة المعتمدة', $e->getMessage());
        }

        // منع دور غير معتمد (treasury_officer غير مدرج في exception_roles) من الاعتماد.
        try {
            app(AgentTellerRequestService::class)->decide($treasuryOfficer, $exception->id, true);
            $this->fail('تمكن دور غير معتمد من اعتماد استثناء ساعات العمل');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('الدور أو الصلاحية المعتمدة', $e->getMessage());
        }

        // منع مدير مقلّص الصلاحية (override_limit = false) على الخادم.
        $manager->forceFill(['capabilities' => ['override_limit' => false]])->save();
        try {
            app(AgentTellerRequestService::class)->decide($manager->fresh(), $exception->id, true);
            $this->fail('تمكن مدير بلا صلاحية override_limit من اعتماد الاستثناء');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('الدور أو الصلاحية المعتمدة', $e->getMessage());
        }
        $manager->forceFill(['capabilities' => null])->save();

        // اعتماد المدير المخوّل بنجاح.
        app(AgentTellerRequestService::class)->decide($manager->fresh(), $exception->id, true, 'موافقة مدير الفرع للصرف العاجل');

        // تنفيذ السحب الأول ينجح ويُستهلك الاستثناء ويُسجَّل في التدقيق.
        $this->actingAs($this->teller->fresh(), 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req1->op_code])
            ->assertOk();

        $this->assertSame(AgentTellerRequest::STATUS_USED, $exception->fresh()->status);
        $this->assertNotNull($exception->fresh()->used_at);
        $this->assertTrue(AuditDecision::where('decision_code', 'AFTER_HOURS_EXCEPTION_USED')
            ->where('subject_id', $exception->request_number)->exists());

        // السحب الثاني بنفس الاستثناء المستهلك يُرفض فوراً (مرّة واحدة فقط).
        $this->actingAs($this->teller->fresh(), 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req2->op_code])
            ->assertStatus(422);
        $this->assertSame('pending', $req2->fresh()->status);
    }

    public function test_daily_settlement_missing_four_eyes_config_stays_pending_and_moves_no_money(): void
    {
        $this->configureOpenWorkingHours();

        $shift = app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->createCustomer('200000');
        app(AgentCounterService::class)->deposit(
            $this->branch, $customer, '100000', $this->branch->account, 'إيداع', $shift->fresh(),
        );
        $closed = $shift->fresh();
        app(AgentShiftService::class)->close($this->teller, $closed, $closed->expectedCash());

        $today = now()->toDateString();
        $inWindow = now()->startOfDay()->addHours(22)->addMinutes(30);
        $row = app(AgentDailySettlementService::class)->submit($this->company, $this->hq, $today, $inWindow);

        $beforeBalance = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');

        try {
            app(AgentDailySettlementService::class)->accept($row, $this->admin);
            $this->fail('تم اعتماد التسوية رغم غياب إعدادات Four-Eyes المعتمدة');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('غير معتمدة', $e->getMessage());
        }

        // بقيت التسوية معلّقة ولم يتحرّك ريال واحد.
        $this->assertSame(AgentDailySettlement::STATUS_SUBMITTED, $row->fresh()->status);
        $this->assertNull($row->fresh()->linked_settlement_ulid);
        $this->assertSame(
            $beforeBalance,
            (string) EMoney::where('user_id', $this->company->id)->value('current_balance'),
        );
        $this->assertTrue(AuditDecision::where('decision_code', 'MISSING_GOVERNANCE_CONFIG')->exists());
    }

    public function test_daily_settlement_four_eyes_enforces_second_approver_anti_self_approval_permissions_and_currency(): void
    {
        $this->configureOpenWorkingHours();
        $this->configureFourEyes(
            threshold: '100000',
            currency: 'YER',
            firstPerm: 'platform.settlements.decide',
            secondPerm: 'platform.settlements.decide',
        );

        $secondAdmin = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'super_admin',
            'phone' => '967770018899',
        ]);
        app(PlatformRoleService::class)->assign($secondAdmin, PlatformRoleService::FINANCE);

        $unauthorizedAdmin = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'super_admin',
            'phone' => '967770018877',
        ]);
        app(PlatformRoleService::class)->assign($unauthorizedAdmin, PlatformRoleService::AUDITOR);

        // مبلغ تحويل فوق الحد المعتمد (150,000 >= 100,000).
        $shift = app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->createCustomer('200000');
        $req = app(CustomerWithdrawService::class)->request($customer, '150000');
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertOk();
        $closed = $shift->fresh();
        app(AgentShiftService::class)->close($this->teller, $closed, $closed->expectedCash());

        $today = now()->toDateString();
        $inWindow = now()->startOfDay()->addHours(22)->addMinutes(30);
        $row = app(AgentDailySettlementService::class)->submit($this->company, $this->hq, $today, $inWindow, $this->company);

        $companyBefore = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');

        // 1) منع مقدم/صاحب الطلب من اعتماد عمليته (حتى لو مُنح الصلاحية).
        app(PlatformRoleService::class)->assign($this->company, PlatformRoleService::ADMIN);
        try {
            app(AgentDailySettlementService::class)->accept($row->fresh(), $this->company);
            $this->fail('تمكّن مقدم الطلب من اعتماد تسويته');
        } catch (\DomainException $e) {
            $this->assertSame('FOUR_EYES_VIOLATION', $e->getMessage());
        }

        // 2) منع مشرف بلا صلاحية معتمدة (auditor لا يملك platform.settlements.decide).
        try {
            app(AgentDailySettlementService::class)->accept($row->fresh(), $unauthorizedAdmin);
            $this->fail('تمكّن مدقّق بلا صلاحية اعتماد من الموافقة');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('صلاحيّة', $e->getMessage());
        }

        // 3) التحقق من رفض اختلاف العملة.
        $row->update(['currency' => 'USD']);
        try {
            app(AgentDailySettlementService::class)->accept($row->fresh(), $this->admin);
            $this->fail('تم اعتماد تسوية بعملة مختلفة عن المعتمدة');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('عملة التسوية', $e->getMessage());
        }
        $row->update(['currency' => 'YER']);

        // 4) الموافقة الأولى: تنتقل إلى pending_second_approval دون تحريك ريال واحد.
        $afterFirst = app(AgentDailySettlementService::class)->accept($row->fresh(), $this->admin, 'موافقة أولى');
        $this->assertSame(AgentDailySettlement::STATUS_PENDING_SECOND_APPROVAL, $afterFirst->status);
        $this->assertSame(2, $afterFirst->approvals_required);
        $this->assertSame((int) $this->admin->id, (int) $afterFirst->first_approved_by_user_id);
        $this->assertNull($afterFirst->linked_settlement_ulid);
        $this->assertSame(
            $companyBefore,
            (string) EMoney::where('user_id', $this->company->id)->value('current_balance'),
        );

        // 5) منع المعتمد الأول من منح الموافقة الثانية لنفسه.
        try {
            app(AgentDailySettlementService::class)->accept($afterFirst->fresh(), $this->admin, 'محاولة موافقة ثانية ذاتية');
            $this->fail('تمكّن المعتمد الأول من إكمال الموافقة الثانية لنفسه');
        } catch (\DomainException $e) {
            $this->assertSame('FOUR_EYES_VIOLATION', $e->getMessage());
        }

        // 6) المعتمد الثاني المختلف والمخوّل يكمل الاعتماد ويتحرّك المال مرّة واحدة فقط.
        $final = app(AgentDailySettlementService::class)->accept($afterFirst->fresh(), $secondAdmin, 'موافقة ثانية نهائية');
        $this->assertSame(AgentDailySettlement::STATUS_ACCEPTED, $final->status);
        $this->assertSame((int) $secondAdmin->id, (int) $final->second_approved_by_user_id);
        $this->assertNotNull($final->linked_settlement_ulid);
        $this->assertSame(
            '350000.0000', // 500,000 - 150,000
            bcadd((string) EMoney::where('user_id', $this->company->id)->value('current_balance'), '0', 4),
        );

        // 7) منع إعادة التشغيل / التزامن باستخدام نسخة قديمة من الصف (لا قيد مزدوج ولا خصم مزدوج).
        try {
            app(AgentDailySettlementService::class)->accept($afterFirst, $secondAdmin, 'إعادة إرسال مكرّرة');
            $this->fail('تم تنفيذ الاعتماد مرتين عبر نسخة قديمة');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ليست بانتظار القرار', $e->getMessage());
        }
        $this->assertSame(
            '350000.0000',
            bcadd((string) EMoney::where('user_id', $this->company->id)->value('current_balance'), '0', 4),
        );
    }

    public function test_cash_out_and_agent_withdraw_are_accounted_separately_and_reconcile_without_drift_or_duplicates(): void
    {
        $this->configureOpenWorkingHours();

        // رسوم مختلفة لكلا الرمزين مع حصة منصة موجبة (> 0) لاختبار التسوية بدون انحراف.
        FeeScheme::create([
            'code' => 'CASH_OUT',
            'label' => 'سحب عميل بالرمز',
            'fee_type' => 'percent',
            'percent_rate' => '1.00',
            'fixed_amount' => '0',
            'agent_commission_percent' => '60',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'version' => 1,
            'is_active' => true,
        ]);
        FeeScheme::create([
            'code' => 'AGENT_WITHDRAW',
            'label' => 'سحب مباشر مفصول',
            'fee_type' => 'percent',
            'percent_rate' => '2.00',
            'fixed_amount' => '0',
            'agent_commission_percent' => '50',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'zone_code' => 'SOUTH',
            'applies_to' => 'agent',
            'version' => 1,
            'is_active' => true,
        ]);

        // نبدأ برصيد فرع صفريّ لاختبار تطابق المحرّك بدقّة من أثر عمليتَي السحب وحدهما.
        EMoney::where('user_id', $this->branchAccount->id)->update(['current_balance' => '0']);

        $shift = app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->createCustomer('200000');

        // 1) عملية CASH_OUT حيّة: 40,000، رسم 400 (عمولة 240، منصة 160).
        $req = app(CustomerWithdrawService::class)->request($customer, '40000');
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertOk();

        // 2) عملية AGENT_WITHDRAW مباشرة: 10,000، رسم 200 (عمولة 100، منصة 100) بمفتاح idempotency.
        $idem = 'wdr-gap018-unique-1';
        app(AgentCounterService::class)->withdraw(
            $this->branch, $customer->fresh(), '10000', $this->branch->account, 'سحب مباشر مفصول', $shift->fresh(), $idem,
        );

        // منع التكرار بنفس مفتاح idempotency (لا قيد مزدوج ولا خصم إضافي).
        try {
            app(AgentCounterService::class)->withdraw(
                $this->branch, $customer->fresh(), '10000', $this->branch->account, 'تكرار مرفوض', $shift->fresh(), $idem,
            );
            $this->fail('سُمح بتكرار عملية السحب بنفس مفتاح منع الازدواج');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('منع تكرار القيد', $e->getMessage());
        }

        // التحقق من الفصل المحاسبي في الأستاذ العام والمعاملات.
        $this->assertSame(1, LedgerJournalEntry::where('source_type', 'cash_out_executed')->count());
        $this->assertSame(1, LedgerJournalEntry::where('source_type', 'agent_withdraw')->count());
        $this->assertSame(1, LedgerJournalEntry::where('source_type', 'agent_withdraw_fee')->count());
        $this->assertSame(2, Transaction::where('transaction_type', 'cash_out')->count());
        $this->assertSame(1, Transaction::where('transaction_type', 'agent_withdraw')->count());

        // التحقق من صفر انحراف في محرّك التسوية وفصل المجاميع بين المسارين.
        $pos = app(AgentSettlementEngine::class)->position($this->company);
        $this->assertSame('0.0000', $pos['float']['difference']);
        $this->assertSame('0.0000', $pos['cash']['difference']);
        $this->assertSame('40240.0000', $pos['float']['breakdown']['from_cash_out']);
        $this->assertSame('10100.0000', $pos['float']['breakdown']['from_agent_withdraw']);

        // كشف الوردية يتطابق هو الآخر مع رصيد الفرع الفعلي (`amount + commission` لكليهما).
        $stmt = app(AgentShiftStatementService::class)->build($shift->fresh());
        $this->assertSame('50340.0000', $stmt['emoney']['in_on_withdrawals']);
        $this->assertSame('600.0000', $stmt['fees']['collected']);
        $this->assertSame('340.0000', $stmt['fees']['agent_commission']);
    }
}
