<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\Agent\AgentTellerRequest;
use App\Models\AgentProfile;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentCounterService;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use App\Services\AgentTellerRequestService;
use App\Services\AgentTellerWorkspaceService;
use App\Services\CustomerWithdrawService;
use App\Services\PlatformRoleService;
use App\Services\Reconciliation\LedgerFlowCoverageService;
use App\Services\Whatsapp\AgentWhatsappService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AGT-GAP-007 .. AGT-GAP-012 — ضوابط الأمان والحوكمة والحدود (الدفعة الثالثة P1).
 */
class AgentControlsAndGovernanceFixTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $manager;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->company->forceFill([
            'f_name' => 'شركة', 'l_name' => 'الحوكمة', 'phone' => '967776000001',
            'type' => AGENT_TYPE, 'password' => Hash::make('company123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        AgentProfile::create([
            'user_id' => $this->company->id,
            'business_name' => 'شركة الحوكمة للصرافة',
            'status' => 'active',
            'daily_cash_in_limit' => '1000000',
            'daily_cash_out_limit' => '1000000',
            'single_transaction_limit' => '500000',
        ]);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hqpass123');

        $bu = new User();
        $bu->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $bu->forceFill([
            'f_name' => 'فرع', 'l_name' => 'الشحر', 'type' => AGENT_TYPE,
            'phone' => '967776000099', 'password' => Hash::make('branch123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 'sub_agent',
            'business_name' => 'فرع الشحر', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        config()->set('amial.branch_working_hours.timezone', 'Asia/Riyadh');

        $this->branch = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع الشحر', 'code' => 'SHR', 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
            'working_hours' => array_fill_keys(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], ['open' => '00:00', 'close' => '23:59']),
        ]);
        $this->branch->till()->create([
            'cash_on_hand' => '3000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);
        $this->branch = $this->branch->fresh('till');

        $this->manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير الشحر', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->branch->id, 'password' => 'mgr12345',
        ]);

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صراف الشحر', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
    }

    private function customer(string $balance = '200000'): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '96777610' . random_int(1000, 9999),
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => $balance]);

        return $c;
    }

    /** @test */
    public function agt_gap_007_post_lookup_works_and_bruteforce_opcode_attempts_are_throttled_and_audited(): void
    {
        RateLimiter::clear('agent-opcode-staff:' . $this->teller->id);
        app(AgentShiftService::class)->open($this->teller, '300000');
        $customer = $this->customer('300000');
        $req = app(CustomerWithdrawService::class)->request($customer, '10000');

        $this->actingAs($this->teller, 'agent_staff');

        // مسار POST يعمل دون تمرير الرمز في URL.
        $this->postJson(route('agent.counter.withdrawal.lookup.post'), ['op_code' => $req->op_code])
            ->assertOk()
            ->assertJsonPath('request.op_code', $req->op_code);

        // 10 محاولات خاطئة متتالية تستنفد الحد، والحادية عشرة ترد 429 وتُسجّل تنبيهاً أمنياً.
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson(route('agent.counter.withdrawal.lookup.post'), [
                'op_code' => 'BAD' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            ])->assertStatus(404);
        }

        $this->postJson(route('agent.counter.withdrawal.lookup.post'), [
            'op_code' => $req->op_code,
        ])->assertStatus(429);

        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'agent.counter.opcode_bruteforce_blocked',
            'decision_code' => 'RATE_LIMITED',
            'subject_id' => (string) $this->teller->id,
        ]);
    }

    /** @test */
    public function agt_gap_008_over_limit_approval_is_customer_bound_and_never_burned_when_deposit_fails(): void
    {
        $this->teller->forceFill(['max_txn_amount' => '10000'])->save();
        $this->teller = $this->teller->fresh();
        app(AgentShiftService::class)->open($this->teller, '100000');

        $customerA = $this->customer('0');
        $customerB = $this->customer('0');

        // موافقة بمبلغ 50,000 مرتبطة بالعميل A.
        $svc = app(AgentTellerRequestService::class);
        $req = $svc->submit($this->teller, [
            'kind' => AgentTellerRequest::KIND_OVER_LIMIT,
            'operation' => 'deposit',
            'amount' => '50000',
            'customer_user_id' => $customerA->id,
            'reason' => 'إيداع للعميل أ يتجاوز الحد',
        ]);
        $svc->decide($this->manager, $req->id, true, 'موافق للعميل أ');

        // لا تغطي عميلاً آخر (B).
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.deposit'), [
                'customer_id' => $customerB->id,
                'amount' => '50000',
            ])->assertStatus(422)->assertJsonPath('needs_approval', true);
        $this->assertSame(AgentTellerRequest::STATUS_APPROVED, $req->fresh()->status);

        // ولا تُستهلك إذا فشلت العملية (رصيد الفرع لا يكفي مؤقتاً).
        EMoney::where('user_id', $this->branch->branch_user_id)->update(['current_balance' => '1000']);
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.deposit'), [
                'customer_id' => $customerA->id,
                'amount' => '50000',
            ])->assertStatus(422);
        $this->assertSame(AgentTellerRequest::STATUS_APPROVED, $req->fresh()->status);
        $this->assertNull($req->fresh()->used_at);

        // عند توفر الرصيد تنجح وتُستهلك مرة واحدة فقط.
        EMoney::where('user_id', $this->branch->branch_user_id)->update(['current_balance' => '5000000']);
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.deposit'), [
                'customer_id' => $customerA->id,
                'amount' => '50000',
            ])->assertOk();
        $this->assertSame(AgentTellerRequest::STATUS_USED, $req->fresh()->status);
        $this->assertNotNull($req->fresh()->used_at);
    }

    /** @test */
    public function agt_gap_009_manager_who_closed_a_shift_with_variance_cannot_self_approve_it(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '100000');

        // مدير الفرع يغلق الوردية بالنيابة عن الصراف بعجز 5,000.
        $closed = app(AgentShiftService::class)->close($this->manager, $shift, '95000', 'جرد نهاية اليوم');
        $this->assertSame((int) $this->manager->id, (int) $closed->closed_by);
        $this->assertSame(AgentShift::REVIEW_PENDING, $closed->review_status);

        // نفس المدير يحاول قبول فرق العدّ الذي أغلق به -> مرفوض بـ403.
        $this->actingAs($this->manager, 'agent_staff')
            ->postJson(route('agent.staff.shifts.review', ['id' => $closed->id]), [
                'decision' => 'accepted',
                'note' => 'موافقة ذاتية على عجز الصندوق',
            ])->assertStatus(403);

        // الإدارة العامة تملك المصادقة.
        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.shifts.review', ['id' => $closed->id]), [
                'decision' => 'accepted',
                'note' => 'مراجعة الإدارة العامة واعتماد التسوية',
            ])->assertOk();
    }

    /** @test */
    public function agt_gap_010_safe_is_hidden_from_teller_and_commission_today_reads_live_withdrawals(): void
    {
        FeeScheme::create([
            'code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '1.00', 'fixed_amount' => '0',
            'agent_commission_percent' => '60', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true,
        ]);

        app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->customer('300000');
        $req = app(CustomerWithdrawService::class)->request($customer, '100000');

        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code])
            ->assertOk();

        // مساحة عمل الصراف تخفي أرقام الخزنة وتعيد عمولة السحب الحيّ الحقيقية (600.0000).
        $ws = app(AgentTellerWorkspaceService::class)->build($this->teller->fresh());
        $this->assertNull($ws['kpis']['branch_safe']);
        $this->assertNull($ws['kpis']['branch_emoney']);
        $this->assertFalse($ws['kpis']['safe_known']);
        $this->assertTrue($ws['kpis']['can_refill_from_safe']);
        $this->assertSame('600.0000', $ws['kpis']['commission_today']);

        // رسالة واتساب الصراف لا تسرّب مبلغ خزنة الفرع (2,800,000).
        $wa = app(AgentWhatsappService::class)->handle($this->teller->fresh(), 'درجك');
        $this->assertIsString($wa);
        $this->assertStringContainsString('درجك', $wa);
        $this->assertStringNotContainsString('خزنة الفرع', $wa);
        $this->assertStringNotContainsString('2,800,000', $wa);
    }

    /** @test */
    public function agt_gap_011_counter_deposit_enforces_company_limits_and_updates_daily_stats_and_coverage(): void
    {
        AgentProfile::where('user_id', $this->company->id)->update([
            'daily_cash_in_limit' => '50000',
            'single_transaction_limit' => '40000',
        ]);

        $shift = app(AgentShiftService::class)->open($this->teller, '100000');
        $customer = $this->customer('0');

        app(AgentCounterService::class)->deposit(
            $this->branch, $customer, '30000', $this->branch->account, null, $shift->fresh(),
        );

        // العملية الثانية (25,000) تجعل المجموع 55,000 > الحد اليومي 50,000 -> تُرفض.
        try {
            app(AgentCounterService::class)->deposit(
                $this->branch, $customer, '25000', $this->branch->account, null, $shift->fresh(),
            );
            $this->fail('مرّ الإيداع رغم تجاوز الحد اليومي لشركة الصرافة');
        } catch (DomainException $e) {
            $this->assertStringContainsString('حدك اليومي', $e->getMessage());
        }

        // سطر transactions سُجّل ويظهر في API dailyStats، ومغطّى 100% في الدفتر.
        Passport::actingAs($this->branch->account);
        $stats = $this->getJson('/api/v1/amial/agent/daily-stats')->assertOk()->json('meta');
        $this->assertSame(0, bccomp('30000', (string) $stats['today_cash_in'], 4));
        $this->assertSame(1, (int) $stats['today_count']);

        $cov = app(LedgerFlowCoverageService::class)->coverage(now()->subHour(), now()->addHour());
        $row = collect($cov['rows'])->firstWhere('type', 'cash_in');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['uncovered']);
    }

    /** @test */
    public function agt_gap_012_limits_update_is_audited_with_reason_and_branch_profile_uses_valid_enum(): void
    {
        // إنشاء فرع جديد يكتب agent_level = 'sub_agent' بدلاً من 2.
        $branch2 = app(AgentBranchService::class)->create($this->company, [
            'name' => 'فرع المكلا الجديد',
            'code' => 'MKN',
            'phone' => '967776000077',
            'email' => 'branch-mkn@example.test',
            'password' => 'secret1234',
            'city' => 'المكلا',
        ]);
        $this->assertDatabaseHas('agent_profiles', [
            'user_id' => $branch2->branch_user_id,
            'parent_agent_id' => $this->company->id,
            'agent_level' => 'sub_agent',
        ]);

        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($admin, PlatformRoleService::ADMIN);

        // رفض التحديث بدون سبب.
        $this->actingAs($admin, 'user')
            ->putJson("/admin/amial/agents/{$this->company->id}/limits", [
                'daily_cash_in_limit' => '2000000',
            ])->assertStatus(422);

        // رفض single > daily.
        $this->actingAs($admin, 'user')
            ->putJson("/admin/amial/agents/{$this->company->id}/limits", [
                'daily_cash_in_limit' => '200000',
                'single_transaction_limit' => '300000',
                'reason' => 'تجربة حد مخالف',
            ])->assertStatus(422);

        // التحديث الصحيح يُحفظ ويكتب قرار تدقيق.
        $this->actingAs($admin, 'user')
            ->putJson("/admin/amial/agents/{$this->company->id}/limits", [
                'daily_cash_in_limit' => '2000000',
                'single_transaction_limit' => '400000',
                'reason' => 'توسعة سقف العمل الموسمي للشركة',
            ])->assertOk();

        $profile = AgentProfile::where('user_id', $this->company->id)->firstOrFail();
        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'AGENT_OPERATIONAL_LIMITS_UPDATED',
            'subject_type' => 'agent_profile',
            'subject_id' => (string) $profile->id,
        ]);
    }
}
