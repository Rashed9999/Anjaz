<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentSupervisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-AGENT-SUPERVISION-001 — ما تراه الإدارة عن شبكة شركات الصرافة.
 */
class AgentSupervisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770001100',
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
        DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $this->admin->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );

        $this->agent = $this->makeAgent('967770002200', 'المضي', 'للصرافة');
    }

    private function makeAgent(string $phone, string $f, string $l): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => $f, 'l_name' => $l, 'phone' => $phone,
            'type' => AGENT_TYPE, 'password' => bcrypt('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1,
        ])->save();

        EMoney::create(['user_id' => $u->id, 'current_balance' => '100000']);

        return $u;
    }

    private function makeBranch(User $agent, string $code, array $till = []): AgentBranch
    {
        $branchUser = new User();
        $branchUser->forceFill([
            'f_name' => 'فرع ' . $code, 'l_name' => 'اختبار',
            'phone' => '9677700' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'type' => AGENT_TYPE, 'password' => bcrypt('secret123'), 'is_active' => 1,
        ])->save();

        EMoney::create(['user_id' => $branchUser->id, 'current_balance' => '5000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $branchUser->id, 'parent_agent_id' => $agent->id,
            'agent_level' => 2, 'business_name' => 'فرع ' . $code,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $branch = AgentBranch::create([
            'agent_user_id' => $agent->id, 'branch_user_id' => $branchUser->id,
            'name' => 'فرع ' . $code, 'code' => $code, 'city' => 'صنعاء',
            'phone' => $branchUser->phone, 'is_active' => true,
        ]);

        $branch->till()->create(array_merge([
            'cash_on_hand' => '200000',
            'max_cash_on_hand' => '1000000',
            'min_cash_alert' => '50000',
        ], $till));

        return $branch->fresh('till');
    }

    /** @test */
    public function the_network_summary_never_merges_platform_liability_with_the_agents_own_cash(): void
    {
        $this->makeBranch($this->agent, 'B1');

        $n = app(AgentSupervisionService::class)->network();

        // الرقمان موجودان ومنفصلان — ولا مفتاح يجمعهما.
        $this->assertArrayHasKey('cash_on_hand', $n);
        $this->assertArrayHasKey('emoney_branches', $n);
        $this->assertArrayNotHasKey('total_liquidity', $n);

        $this->assertSame('200000.0000', $n['cash_on_hand']);
        $this->assertSame('5000.0000', $n['emoney_branches']);

        // الوكيل الأمّ يُعدّ وكيلاً، وحساب الفرع لا يُعدّ — وإلّا صار وكيلان.
        $this->assertSame(1, $n['agents']);
        $this->assertSame(1, $n['branches']);
    }

    /** @test */
    public function an_agent_with_no_branch_is_flagged_because_it_can_serve_nobody(): void
    {
        // وكيلٌ برصيدٍ كبيرٍ وبلا فرع: الرصيد لا يفيد أحداً.
        $n = app(AgentSupervisionService::class)->network();

        $this->assertSame(1, $n['agents_without_branch'],
            'وكيلٌ بلا فرعٍ نشِط لا يُرصَد — والإدارة تحسبه عاملاً');

        $s = app(AgentSupervisionService::class)->summariesFor([$this->agent->id]);
        $this->assertTrue($s[$this->agent->id]['no_branches']);
    }

    /** @test */
    public function low_cash_and_overload_are_reported_separately_not_as_one_alarm(): void
    {
        // فرعٌ نقده تحت حدّ التنبيه: يقبل الإيداع ويعجز عن السحب.
        $this->makeBranch($this->agent, 'LOW', ['cash_on_hand' => '10000', 'min_cash_alert' => '50000']);
        // وفرعٌ فوق الحدّ الأعلى: خطرٌ أمنيّ لا نقصُ سيولة — والعلاجان متعاكسان.
        $this->makeBranch($this->agent, 'HIGH', ['cash_on_hand' => '2000000', 'max_cash_on_hand' => '1000000']);

        $n = app(AgentSupervisionService::class)->network();

        $this->assertSame(1, $n['flags']['low_cash']);
        $this->assertSame(1, $n['flags']['overloaded']);
    }

    /** @test */
    public function a_till_never_counted_reads_as_uncounted_not_as_counted_zero(): void
    {
        $b = $this->makeBranch($this->agent, 'NC');

        $rows = app(AgentSupervisionService::class)->branches();
        $row = collect($rows)->firstWhere('code', 'NC');

        $this->assertTrue($row['not_counted_today']);
        // «لم يُجرَد» غيابُ شهادةٍ لا قيمةٌ صفريّة — ولو رُدّت صفراً لقُرئت
        // «عُدَّ الدرج فوُجد فارغاً»، وهو خبرٌ مختلفٌ تماماً.
        $this->assertNull($row['last_counted_at']);
        $this->assertSame('200000.0000', $row['cash_on_hand']);

        $b->till->update(['last_counted_at' => now(), 'last_counted_amount' => '200000']);

        $row = collect(app(AgentSupervisionService::class)->branches())->firstWhere('code', 'NC');
        $this->assertFalse($row['not_counted_today']);
        $this->assertNotNull($row['last_counted_at']);
    }

    /** @test */
    public function a_branch_account_in_the_agent_list_is_labelled_with_its_parent(): void
    {
        $b = $this->makeBranch($this->agent, 'LBL');

        $s = app(AgentSupervisionService::class)
            ->summariesFor([$this->agent->id, $b->branch_user_id]);

        $this->assertFalse($s[$this->agent->id]['is_branch_account']);
        $this->assertSame(1, $s[$this->agent->id]['branches']);

        // حساب الفرع وكيلٌ في جدول المستخدمين فيظهر في القائمة. إخفاؤه يجعل
        // العدّ خاطئاً؛ فيُوسَم بأمّه بدل ذلك.
        $this->assertTrue($s[$b->branch_user_id]['is_branch_account']);
        $this->assertSame($this->agent->id, $s[$b->branch_user_id]['parent']['id']);
        $this->assertStringContainsString('المضي', $s[$b->branch_user_id]['parent']['name']);
        $this->assertFalse($s[$b->branch_user_id]['no_branches']);
    }

    /** @test */
    public function the_admin_agents_hub_shows_branches_cash_and_the_portal_link(): void
    {
        $this->makeBranch($this->agent, 'UI');

        $html = $this->actingAs($this->admin, 'user')
            ->get(route('admin.amial.hub.agents'))
            ->assertOk()
            ->getContent();

        // الشكوى كانت «لا يوجد تغيير في مركز الوكلاء» — فهذه الأربعة هي التغيير.
        $this->assertStringContainsString('نقد ورقيّ في أدراج الفروع', $html);
        $this->assertStringContainsString('الفروع والخزائن', $html);
        $this->assertStringContainsString('حركة النقد', $html);
        $this->assertStringContainsString(route('agent.login'), $html);

        // ولا تظهر هذه في مركز العملاء — الإضافة للوكلاء وحدهم.
        $customers = $this->actingAs($this->admin, 'user')
            ->get(route('admin.amial.hub.customers'))->getContent();
        $this->assertStringNotContainsString('نقد ورقيّ في أدراج الفروع', $customers);
    }

    /** @test */
    public function the_agent_list_endpoint_carries_branch_and_cash_columns(): void
    {
        $this->makeBranch($this->agent, 'JSON', ['cash_on_hand' => '77000']);

        $row = collect($this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.users.json', ['slug' => 'agents']))
            ->assertOk()->json('data'))
            ->firstWhere('id', $this->agent->id);

        $this->assertSame(1, $row['agent']['branches']);
        $this->assertSame('77000.0000', $row['agent']['cash_on_hand']);

        // ولا تُحمَّل هذه الحسبة لغير الوكلاء.
        $cust = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967770003300']);
        $crow = collect($this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.users.json', ['slug' => 'customers']))
            ->json('data'))->firstWhere('id', $cust->id);

        $this->assertNull($crow['agent']);
    }

    /** @test */
    public function cash_movements_are_visible_to_the_admin_and_filterable_by_branch(): void
    {
        $b1 = $this->makeBranch($this->agent, 'M1');
        $b2 = $this->makeBranch($this->agent, 'M2');

        foreach ([[$b1, '3000'], [$b2, '4000']] as [$b, $amt]) {
            AgentCashMovement::create([
                'branch_id' => $b->id, 'direction' => 'in', 'reason' => 'customer_deposit',
                'amount' => $amt, 'balance_before' => '200000',
                'balance_after' => bcadd('200000', $amt, 4),
                'actor_user_id' => $b->branch_user_id, 'created_at' => now(),
            ]);
        }

        $all = $this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.agents.movements'))->assertOk()->json('data');
        $this->assertCount(2, $all);
        $this->assertSame('إيداع عميل (نقد داخل)', $all[0]['reason_label']);

        $one = $this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.agents.movements', ['branch_id' => $b2->id]))
            ->json('data');
        $this->assertCount(1, $one);
        $this->assertSame('4000.0000', $one[0]['amount']);
    }

    /** @test */
    public function the_branches_endpoint_filters_by_the_flag_the_dashboard_offers(): void
    {
        $this->makeBranch($this->agent, 'OK');
        $this->makeBranch($this->agent, 'LOW2', ['cash_on_hand' => '1000', 'min_cash_alert' => '50000']);

        $codes = fn (string $flag) => collect(
            $this->actingAs($this->admin, 'user')
                ->getJson(route('admin.amial.hub.agents.branches', ['flag' => $flag]))
                ->assertOk()->json('data')
        )->pluck('code')->all();

        // زرُّ «نقدٌ منخفض» في اللوحة يمرّر هذا المرشِّح — فلو لم يعمل لقاد
        // المدير إلى قائمةٍ كاملةٍ ظنّاً منه أنّها المنبَّهة.
        $this->assertSame(['LOW2'], $codes('low_cash'));
        $this->assertEqualsCanonicalizing(['OK', 'LOW2'], $codes(''));
    }

    /** @test */
    public function the_admin_cannot_move_agent_cash_from_the_supervision_screens(): void
    {
        // النقد يتحرّك بيد موظّف الفرع ويُسجَّل باسمه. ولو حرّكته الإدارة من
        // هنا لصار في الدرج مالٌ لا يعرف الفرع من وضعه، وسقط الجرد.
        $writes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin/amial/hub/agents/')
                && str_contains($r->uri(), '.json'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()->values()->all();

        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $writes,
            'شاشات الإشراف تقرأ فقط — ظهر فيها فعلُ كتابة');
    }
}
