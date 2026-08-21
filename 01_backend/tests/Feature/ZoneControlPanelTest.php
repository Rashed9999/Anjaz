<?php

namespace Tests\Feature;

use App\Models\Aml\AmlAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-ZONE-PANEL-001 — لوحة المناطق.
 *
 * سياسة المناطق أخطر ما في النظام وكانت كلها غير مرئية: لا أحد يعرف كم
 * حساباً عالق بلا منطقة، ولا كم عملية رُفضت، ولا أن وكيلاً زاول من خارج
 * النطاق. هذه الاختبارات تُثبت أن اللوحة تقرأ من الجداول الحقيقية.
 */
class ZoneControlPanelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * AMIAL-ZONE-RBAC-001 — **نوعُ الحساب وحدَه لم يعد يفتح اللوحة.**
     *
     * كانت مساراتُ النطاقات التسعةُ بلا `platform:` واحد، فكان أيُّ حسابِ
     * إدارةٍ — دعماً أو صيانةً — ينقل حساباً بين نطاقين. **ونقلُ الحساب
     * يفتح أو يُغلق حركةَ ماله** (`EnforceZonePolicy` تقرأ النطاق).
     *
     * فصار للّوحة صلاحيّة، ويُسنَد للمشغّل دورٌ يحملها. وحسابٌ بلا دورٍ
     * يُردّ ٤٠٣ الآن — وهو الصواب، ومحروسٌ في `ZonePermissionGuardTest`.
     */
    private function admin(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE]);

        app(\App\Services\PlatformRoleService::class)
            ->assign($u, \App\Services\PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    /** ملاحظة: لا نسمّيها get() — TestCase تعرّفها public فيتعارض التوقيع. */
    private function panel(string $path)
    {
        return $this->actingAs($this->admin(), 'user')->getJson("/admin/amial/hub/zones{$path}");
    }

    public function test_page_renders_with_the_full_governorate_table(): void
    {
        $this->actingAs($this->admin(), 'user')
            ->get('/admin/amial/hub/zones')
            ->assertOk()
            ->assertSee('لوحة المناطق')
            ->assertSee('عدن')
            ->assertSee('صنعاء')      // خارج النطاق ويجب أن يظهر
            ->assertSee('سقطرى');
    }

    public function test_guest_cannot_open_the_panel(): void
    {
        $this->get('/admin/amial/hub/zones')->assertRedirect();
    }

    public function test_summary_counts_accounts_by_zone(): void
    {
        User::factory()->count(3)->create(['type' => 2, 'zone_code' => 'SOUTH']);
        User::factory()->count(2)->create(['type' => 2, 'zone_code' => 'NORTH']);

        $j = $this->panel('/summary.json')->assertOk()->json();

        $this->assertSame(3, $j['zones']['SOUTH']);
        $this->assertSame(2, $j['zones']['NORTH']);
    }

    public function test_summary_surfaces_accounts_stranded_without_a_zone(): void
    {
        // العطل الذي أُصلح خلّف بقايا: معتمدة ولا تستطيع عملية واحدة.
        // إن لم تظهر في اللوحة بقيت عالقة إلى الأبد.
        $stranded = User::factory()->create([
            'type' => 2, 'is_kyc_verified' => 1,
            'zone_code' => 'UNKNOWN', 'residence_governorate' => 'YE-AD',
        ]);
        User::factory()->create(['type' => 2, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH']);

        $j = $this->panel('/summary.json')->assertOk()->json();

        $this->assertSame(1, $j['stranded']['count']);
        $this->assertSame($stranded->id, $j['stranded']['sample'][0]['id']);
        $this->assertTrue($j['stranded']['sample'][0]['fixable']);
        $this->assertSame('عدن', $j['stranded']['sample'][0]['governorate']);
    }

    public function test_stranded_account_without_a_governorate_is_marked_unfixable(): void
    {
        // لا نعرض زر إصلاح لا يعمل — نطلب تسجيل المحافظة أولاً.
        User::factory()->create(['type' => 2, 'is_kyc_verified' => 1, 'zone_code' => 'UNKNOWN']);

        $j = $this->panel('/summary.json')->assertOk()->json();

        $this->assertFalse($j['stranded']['sample'][0]['fixable']);
    }

    public function test_reassign_unsticks_a_stranded_account(): void
    {
        $user = User::factory()->create([
            'type' => 2, 'is_kyc_verified' => 1,
            'zone_code' => 'UNKNOWN', 'residence_governorate' => 'YE-AD',
        ]);

        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/zones/users/{$user->id}/reassign")
            ->assertOk()
            ->assertJsonPath('zone', 'SOUTH');

        $this->assertSame('SOUTH', $user->fresh()->zone_code);
    }

    public function test_reassign_refuses_when_there_is_nothing_to_read(): void
    {
        $user = User::factory()->create(['type' => 2, 'zone_code' => 'UNKNOWN']);

        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/zones/users/{$user->id}/reassign")
            ->assertStatus(422);

        $this->assertSame('UNKNOWN', $user->fresh()->zone_code);
    }

    public function test_reassign_of_a_northern_residence_does_not_grant_south(): void
    {
        $user = User::factory()->create([
            'type' => 2, 'zone_code' => 'UNKNOWN', 'residence_governorate' => 'YE-SN',
        ]);

        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/zones/users/{$user->id}/reassign")->assertOk();

        $this->assertNotSame('SOUTH', $user->fresh()->zone_code);
    }

    public function test_agent_location_violations_are_visible(): void
    {
        // أخطر سطر في اللوحة: وكيل زاول نقداً من خارج النطاق.
        $agent = User::factory()->create(['type' => AGENT_TYPE, 'phone' => '967771112233']);

        DB::table('audit_decisions')->insert([
            'decision_id' => (string) Str::ulid(),
            'actor_type' => 'agent', 'actor_user_id' => $agent->id,
            'subject_type' => 'user', 'subject_id' => $agent->id,
            'action' => 'AGENT_CASH_OUTSIDE_ZONE', 'decision_code' => 'BLOCKED',
            'reason' => 'خارج النطاق', 'severity' => 'critical',
            'context' => json_encode(['governorate' => 'YE-SN']),
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->panel('/summary.json')->json('agent_violations_30d'));

        $rows = $this->panel('/events.json?type=violations')->assertOk()->json('data');
        $this->assertSame($agent->id, $rows[0]['user_id']);
        $this->assertSame('صنعاء', $rows[0]['governorate']);
        $this->assertSame('critical', $rows[0]['severity']);
    }

    public function test_zone_blocked_operations_are_visible(): void
    {
        $user = User::factory()->create(['type' => 2]);

        DB::table('audit_decisions')->insert([
            'decision_id' => (string) Str::ulid(),
            'actor_type' => 'customer', 'actor_user_id' => $user->id,
            'subject_type' => 'user', 'subject_id' => $user->id,
            'action' => 'cash_out', 'decision_code' => 'TX_ZONE_BLOCKED',
            'reason' => 'خارج المنطقة التشغيلية', 'zone_code' => 'NORTH',
            'severity' => 'warning', 'created_at' => now(),
        ]);

        $this->assertSame(1, $this->panel('/summary.json')->json('blocked_30d'));

        $rows = $this->panel('/events.json?type=blocked')->assertOk()->json('data');
        $this->assertSame('NORTH', $rows[0]['zone']);
    }

    public function test_zone_assignment_history_is_visible(): void
    {
        $user = User::factory()->create(['type' => 2]);
        app(\App\Services\ZoneAssignmentService::class)->assignFromKyc($user, 'عدن');

        $rows = $this->panel('/events.json?type=assignments')->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        $this->assertSame('SOUTH', $rows[0]['zone']);
        $this->assertSame('kyc_verification', $rows[0]['method']);
    }

    public function test_sink_account_alerts_are_visible(): void
    {
        $user = User::factory()->create(['type' => 2]);
        AmlAlert::create([
            'alert_ulid' => (string) Str::ulid(), 'alert_code' => 'SINK_ACCOUNT',
            'severity' => 'medium', 'subject_type' => 'user', 'subject_id' => $user->id,
            'title_ar' => 'حساب يستقبل ولا يُخرج', 'message_ar' => 'تفاصيل',
            'context' => [], 'status' => 'open',
        ]);

        $this->assertSame(1, $this->panel('/summary.json')->json('sink_alerts_open'));
        $this->assertNotEmpty($this->panel('/events.json?type=sink')->json('data'));
    }

    public function test_panel_reports_the_agent_location_mode(): void
    {
        // الوضع يقرّر هل الحدّ مُنفَّذ فعلاً — إخفاؤه يجعل اللوحة تكذب.
        config(['amial.agent_location_mode' => 'strict']);

        $this->assertSame('strict', $this->panel('/summary.json')->json('agent_location_mode'));
    }

    public function test_geo_check_endpoint_returns_the_comparison(): void
    {
        $user = User::factory()->create([
            'type' => 2, 'origin_governorate' => 'YE-IB', 'residence_governorate' => 'YE-AD',
        ]);

        $j = $this->panel("/users/{$user->id}/geo-check.json")->assertOk()->json();

        $this->assertSame('إب', $j['origin']['name']);
        $this->assertSame('عدن', $j['residence']['name']);
        $this->assertTrue($j['operational']);
    }
}
