<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-AGENT-NETWORK-DOOR-001 — **خمسةُ مساراتٍ بلا شاشةٍ وبلا صلاحيّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `AdminAgentNetworkController` يُقدّم منذ v2.4: اعتمادَ تسجيل وكيل،
 * وإيقافَه، وتعديلَ حدوده، وقائمةَ الشبكة وإحصاءاتها.
 *
 * **ولا شاشةَ تناديها** — فالاعتمادُ كان يقع بتحرير القاعدة يدويّاً،
 * حيث لا صلاحيّةَ ولا سجلّ.
 *
 * **وأخطرُ من ذلك:** المجموعةُ كانت **بلا `platform:` واحد**. فأيُّ
 * حسابِ إدارة — موظّفُ دعمٍ أو صيانة — يعتمد وكيلاً فيفتح له الشبّاك،
 * أو يرفع حدَّه اليوميّ.
 *
 * ولم تظهر الثغرةُ لأنّ **لا زرَّ يقود إليها**: نامت مع الميزة. وهو
 * درسٌ في الاتّجاه المعاكس — «مبنيٌّ ولا يُوصل إليه» لا يعني «غيرُ
 * قابلٍ للاستغلال»: المسارُ مسجَّلٌ ومن يعرفه يناديه.
 */
class AgentNetworkDoorGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->create([
            'type' => AGENT_TYPE, 'role' => 'agent',
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);

        AgentProfile::create([
            'user_id' => $this->agent->id,
            'business_name' => 'صرافة الاختبار',
            // القيمةُ الحقيقيّة في تعداد العمود — و`pending` تُبتَر صامتةً.
            'status' => 'pending_approval',
            'daily_cash_in_limit' => '1000000',
        ]);
    }

    private function operator(string $role): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, $role);

        return $u;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الصلاحيّة — كانت غائبةً تماماً
    // ══════════════════════════════════════════════════════════════════

    public function test_support_cannot_approve_an_agent(): void
    {
        // اعتمادُ وكيلٍ يفتح له الشبّاك — وليس من عمل الدعم.
        $this->actingAs($this->operator(PlatformRoleService::SUPPORT), 'user')
            ->postJson("/admin/amial/agents/{$this->agent->id}/approve")
            ->assertStatus(403);
    }

    public function test_maintenance_cannot_suspend_an_agent(): void
    {
        $this->actingAs($this->operator(PlatformRoleService::MAINTENANCE), 'user')
            ->postJson("/admin/amial/agents/{$this->agent->id}/suspend", ['reason' => 'اختبار'])
            ->assertStatus(403);
    }

    public function test_risk_can_approve_and_suspend(): void
    {
        $risk = $this->operator(PlatformRoleService::RISK);

        $this->actingAs($risk, 'user')
            ->postJson("/admin/amial/agents/{$this->agent->id}/approve")
            ->assertOk();

        $this->assertSame('active',
            (string) AgentProfile::where('user_id', $this->agent->id)->value('status'));
    }

    public function test_only_money_movers_may_raise_the_daily_limit(): void
    {
        // **رفعُ الحدّ يزيد ما يمرّ من مالٍ في اليوم** — فهو من جنس
        // تحريك المال لا من جنس فتح الحساب.
        $this->actingAs($this->operator(PlatformRoleService::RISK), 'user')
            ->putJson("/admin/amial/agents/{$this->agent->id}/limits",
                ['daily_cash_in_limit' => '9000000'])
            ->assertStatus(403);

        $this->actingAs($this->operator(PlatformRoleService::ADMIN), 'user')
            ->putJson("/admin/amial/agents/{$this->agent->id}/limits",
                ['daily_cash_in_limit' => '9000000'])
            ->assertOk();
    }

    /** ولا مسارَ في المجموعة بلا صلاحيّة — نصّاً، فالثغرةُ نامت مرّة. */
    public function test_no_agent_network_route_is_left_unguarded(): void
    {
        $unguarded = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $r) {
            if (! str_starts_with((string) $r->getName(), 'admin.amial.agents.')) {
                continue;
            }

            $hasPlatform = false;

            foreach ($r->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'platform:')) {
                    $hasPlatform = true;
                }
            }

            if (! $hasPlatform) {
                $unguarded[] = $r->getName();
            }
        }

        $this->assertSame([], $unguarded,
            'مسارُ شبكةِ وكلاءَ بلا صلاحيّة — وأيُّ حسابِ إدارةٍ يعتمد '
            . 'وكيلاً أو يرفع حدَّه: ' . implode('، ', $unguarded));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الزرّ — القاعدة التاسعة
    // ══════════════════════════════════════════════════════════════════

    /**
     * **فعلٌ لا يُضغط ليس مبنيّاً.** وكانت الخمسةُ بلا زرٍّ واحد.
     */
    public function test_the_network_actions_have_buttons_in_the_agents_hub(): void
    {
        $blade = file_get_contents(resource_path(
            'views/admin-views/amial/hub/users.blade.php'));

        foreach (['net-approve', 'net-suspend', 'net-limits'] as $act) {
            $this->assertStringContainsString('data-act="' . $act . '"', $blade,
                "فعلُ الشبكة «{$act}» بلا زرّ — مسارٌ يُنادى من خارج اللوحة وحدها");
        }

        // والصفُّ يحمل الحالة، وإلّا لم يعرف الزرُّ ما يعرض.
        $this->assertStringContainsString('reg_status', $blade);
    }

    public function test_the_agents_row_carries_the_registration_status(): void
    {
        $admin = $this->operator(PlatformRoleService::ADMIN);

        $rows = $this->actingAs($admin, 'user')
            ->getJson('/admin/amial/hub/agents/users.json')
            ->assertOk()->json('data');

        $mine = collect($rows)->firstWhere('id', $this->agent->id);

        $this->assertNotNull($mine, 'الوكيلُ لا يظهر في قائمة المركز');
        $this->assertSame('pending_approval', $mine['agent']['reg_status'] ?? null,
            'الصفُّ بلا حالة تسجيل — فالزرُّ لا يعرف أيعرض «اعتماد» أم «إيقاف»');
    }

    /** **والفرعُ لا يُعتمد من هنا** — الأمُّ تدير فروعها (القاعدة ١٠). */
    public function test_a_branch_row_says_why_it_has_no_network_menu(): void
    {
        $blade = file_get_contents(resource_path(
            'views/admin-views/amial/hub/users.blade.php'));

        $this->assertStringContainsString('يُدار من الأمّ', $blade,
            'صفُّ الفرع يُخفي القائمة صامتاً بدل أن يقول لماذا');
    }
}
