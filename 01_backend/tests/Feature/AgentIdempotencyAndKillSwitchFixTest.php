<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\AgentProfile;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use App\Services\CustomerWithdrawService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AGT-GAP-005 / AGT-GAP-006 / AGT-GAP-013 — عدم التكرار، الإيقاف الشامل، وإبطال الجلسات.
 */
class AgentIdempotencyAndKillSwitchFixTest extends TestCase
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
        $this->company->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->company->forceFill([
            'f_name' => 'شركة', 'l_name' => 'الاختبار', 'phone' => '967774000001',
            'type' => AGENT_TYPE, 'password' => Hash::make('company123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        AgentProfile::create([
            'user_id' => $this->company->id,
            'business_name' => 'شركة الاختبار للصرافة',
            'status' => 'active',
            'daily_cash_in_limit' => '10000000',
            'daily_cash_out_limit' => '10000000',
            'single_transaction_limit' => '5000000',
        ]);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hqpass123');

        $bu = new User();
        $bu->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $bu->forceFill([
            'f_name' => 'فرع', 'l_name' => 'عدن', 'type' => AGENT_TYPE,
            'phone' => '967774000099', 'password' => Hash::make('branch123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع عدن', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->branch = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع عدن', 'code' => 'ADN', 'city' => 'عدن',
            'phone' => $bu->phone, 'is_active' => true,
        ]);
        $this->branch->till()->create([
            'cash_on_hand' => '3000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);
        $this->branch = $this->branch->fresh('till');

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صراف عدن', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
    }

    private function customer(string $balance = '200000'): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '96777500' . random_int(1000, 9999),
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => $balance]);

        return $c;
    }

    /** @test */
    public function a_replayed_counter_deposit_with_the_same_idempotency_key_executes_once_only(): void
    {
        app(AgentShiftService::class)->open($this->teller, '100000');
        $customer = $this->customer('0');
        $key = 'idem-deposit-' . bin2hex(random_bytes(8));

        $this->actingAs($this->teller, 'agent_staff');

        $first = $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id,
            'amount' => '10000',
        ], ['Idempotency-Key' => $key])->assertOk();

        $second = $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id,
            'amount' => '10000',
        ], ['Idempotency-Key' => $key])->assertOk();

        $second->assertHeader('X-Idempotent-Replay', '1');
        $this->assertSame($first->json('result.reference'), $second->json('result.reference'));
        $this->assertSame($first->json('result.receipt_number'), $second->json('result.receipt_number'));

        // الرصيد تحرّك مرّة واحدة فقط.
        $this->assertSame('10000.0000', bcadd((string) EMoney::where('user_id', $customer->id)->value('current_balance'), '0', 4));
        $this->assertSame('4990000.0000', bcadd((string) EMoney::where('user_id', $this->branch->branch_user_id)->value('current_balance'), '0', 4));

        $this->assertDatabaseHas('ledger_journal_entries', [
            'idempotency_key' => 'agent_deposit_idem_' . substr(hash('sha256', $this->teller->id . ':' . $key), 0, 56),
        ]);

        // استخدام المفتاح نفسه بمبلغ مختلف يردّ 409 Conflict.
        $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id,
            'amount' => '25000',
        ], ['Idempotency-Key' => $key])->assertStatus(409);

        // السجل في idempotency_keys معرّف ببادئة agent_staff حتى لا يتصادم مع users.id.
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $key,
            'user_id' => $this->teller->id,
            'endpoint' => 'agent_staff:POST agent/counter/deposit',
            'status' => 'completed',
        ]);
    }

    /** @test */
    public function agent_views_all_wire_the_shared_idempotency_post_helper(): void
    {
        $helper = (string) file_get_contents(resource_path('views/agent-views/_post_helper.blade.php'));
        $this->assertStringContainsString("'Idempotency-Key': key", $helper);
        $this->assertStringContainsString('btn.disabled = true', $helper);

        foreach (['dashboard', '_counter', '_workspace', '_staff', '_settings'] as $view) {
            $blade = (string) file_get_contents(resource_path("views/agent-views/{$view}.blade.php"));
            $this->assertStringContainsString("_post_helper", $blade, "{$view} لا يضمّن _post_helper");
            $this->assertStringContainsString('window.__amialPost', $blade, "{$view} لا يستعمل __amialPost");
        }
    }

    /** @test */
    public function suspending_an_agent_company_evicts_active_sessions_blocks_login_and_stops_operations_with_audit(): void
    {
        app(AgentShiftService::class)->open($this->teller, '200000');
        $customer = $this->customer('300000');

        // الصراف يدخل فعلياً عبر نموذج الدخول.
        $this->post(route('agent.login.submit'), [
            'username' => $this->teller->username,
            'password' => 'teller12',
        ])->assertRedirect(route('agent.dashboard'));

        $this->getJson(route('agent.counter.state'))->assertOk();

        // مشرف المخاطر يوقف الشركة عبر لوحة الإدارة.
        $risk = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($risk, PlatformRoleService::RISK);

        // نستدعي مسار الأدمن بحارس user ثم نرجع لاختبار جلسة الصراف (أو نستعمل طلب منفصل للمدير).
        $adminReq = \Illuminate\Http\Request::create(
            "/admin/amial/agents/{$this->company->id}/suspend",
            'POST',
            ['reason' => 'إيقاف احترازي للاشتباه المالي']
        );
        $adminReq->setUserResolver(fn () => $risk);
        app(\App\Http\Controllers\Admin\AdminAgentNetworkController::class)
            ->suspend($adminReq, $this->company->id);

        // قرار التدقيق سُجّل.
        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'agent.network.suspend',
            'decision_code' => 'SUSPENDED',
            'subject_id' => (string) $this->company->id,
        ]);

        // الجلسة النشطة للصراف تُطرد فوراً في أول طلب تالٍ.
        $this->getJson(route('agent.counter.state'))->assertStatus(401);
        $this->assertGuest('agent_staff');

        // الدخول مجدداً ممنوع سواء برمز الصراف أو بهاتف الشركة.
        $this->post(route('agent.login.submit'), [
            'username' => $this->teller->username,
            'password' => 'teller12',
        ])->assertSessionHasErrors('username');

        $this->post(route('agent.login.submit'), [
            'username' => $this->company->phone,
            'password' => 'company123',
        ])->assertSessionHasErrors('username');

        // المسارات المالية ترفض التنفيذ حتى لو استُدعيت مباشرةً من الخدمة.
        try {
            app(AgentCounterService::class)->deposit(
                $this->branch, $customer, '5000', $this->branch->account,
            );
            $this->fail('مرّ الإيداع رغم إيقاف الشركة');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('موقوف', $e->getMessage());
        }

        FeeScheme::create([
            'code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '1.00', 'fixed_amount' => '0',
            'agent_commission_percent' => '60', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true,
        ]);
        $req = app(CustomerWithdrawService::class)->request($customer, '10000');

        try {
            app(CustomerWithdrawService::class)->execute($this->branch->account, $req->op_code, '');
            $this->fail('مرّ السحب رغم إيقاف الشركة');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('موقوف', $e->getMessage());
        }

        // إعادة التفعيل من الأدمن تُعيد العمل وتُسجّل قرار تدقيق.
        $approveReq = \Illuminate\Http\Request::create("/admin/amial/agents/{$this->company->id}/approve", 'POST');
        $approveReq->setUserResolver(fn () => $risk);
        app(\App\Http\Controllers\Admin\AdminAgentNetworkController::class)
            ->approve($approveReq, $this->company->id);

        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'agent.network.approve',
            'decision_code' => 'APPROVED',
            'subject_id' => (string) $this->company->id,
        ]);

        $this->post(route('agent.login.submit'), [
            'username' => $this->teller->username,
            'password' => 'teller12',
        ])->assertRedirect(route('agent.dashboard'));
    }

    /** @test */
    public function resetting_a_staff_password_invalidates_their_active_session_immediately(): void
    {
        $this->post(route('agent.login.submit'), [
            'username' => $this->teller->username,
            'password' => 'teller12',
        ])->assertRedirect(route('agent.dashboard'));

        $this->getJson(route('agent.counter.state'))->assertOk();

        // المدير يصفّر كلمة مرور الصراف من الخلفية دون مساس بجلسة الصراف.
        app(AgentStaffService::class)->resetPassword($this->hq, $this->teller->id, 'newpass99');

        // الطلب التالي للصراف بالجلسة القديمة يُرفض ويُطرد فوراً.
        $this->getJson(route('agent.counter.state'))->assertStatus(401);
        $this->assertGuest('agent_staff');
    }
}
