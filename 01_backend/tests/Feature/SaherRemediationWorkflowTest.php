<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use App\Saher\Remediation\RemediationPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SaherRemediationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        $actor = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($actor, $role);

        return $actor->refresh();
    }

    private function finding(array $overrides = []): int
    {
        $rule = 'SAHER.GUARD.WRITE_BEHIND_READ_PERMISSION';
        $name = 'admin.amial.test-fake-write-route';
        $now = now();

        return (int) DB::table('saher_findings')->insertGetId(array_merge([
            'reference' => 'SAHER-GUARD-9020',
            'fingerprint' => hash('sha256', $rule.'|'.$name),
            'rule_id' => $rule,
            'source_code' => 'guards',
            'category' => 'security',
            'title' => 'مسار كتابة بصلاحية قراءة',
            'expected_behavior' => 'حارس كتابة',
            'actual_behavior' => 'حارس قراءة فقط',
            'severity' => 'HIGH',
            'confidence' => 'HIGH_CONFIDENCE',
            'status' => 'OPEN',
            'asset_type' => 'route',
            'asset_key' => $name,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'occurrence_count' => 1,
            'risk_score' => 70,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function endpoint(int $id): string
    {
        return '/admin/amial/saher/findings/'.$id.'/remediation';
    }

    public function test_security_role_can_open_repair_case_without_closing_finding(): void
    {
        $id = $this->finding();
        $security = $this->actor('platform_security');

        $this->actingAs($security, 'user')->post($this->endpoint($id), [
            'confirm' => 'OPEN_REMEDIATION_CASE',
            'reason' => 'نحتاج فصل صلاحيات القراءة والكتابة قبل إعادة فتح المسار',
        ])->assertRedirect();

        $this->assertDatabaseHas('saher_remediation_cases', [
            'finding_id' => $id,
            'strategy' => 'CODE_REVIEW',
            'status' => 'OPEN',
            'created_by_user_id' => $security->id,
        ]);

        $this->assertDatabaseHas('saher_finding_events', [
            'finding_id' => $id,
            'event' => 'REMEDIATION_CASE_CREATED',
            'actor_user_id' => $security->id,
        ]);

        $this->assertDatabaseHas('saher_findings', ['id' => $id, 'status' => 'OPEN']);

        $case = DB::table('saher_remediation_cases')->where('finding_id', $id)->first();
        $plan = json_decode($case->plan, true);
        $this->assertSame('CODE_REVIEW', $plan['strategy']);
        $this->assertNotEmpty($plan['verification']);
    }

    public function test_duplicate_clicks_do_not_create_duplicate_cases(): void
    {
        $id = $this->finding();
        $admin = $this->actor('platform_admin');
        $data = [
            'confirm' => 'OPEN_REMEDIATION_CASE',
            'reason' => 'تحتاج الثغرة لتعديل صلاحية وفتح طلب مراجعة مع اختبارات',
        ];

        $this->actingAs($admin, 'user')->post($this->endpoint($id), $data)->assertRedirect();
        $this->actingAs($admin, 'user')->post($this->endpoint($id), $data)->assertRedirect();

        $this->assertDatabaseCount('saher_remediation_cases', 1);
        $this->assertSame(1, DB::table('saher_finding_events')
            ->where('event', 'REMEDIATION_CASE_CREATED')->count());
    }

    public function test_read_only_auditor_cannot_create_remediation_case(): void
    {
        $id = $this->finding();
        $auditor = $this->actor('platform_auditor');

        $this->actingAs($auditor, 'user')->postJson($this->endpoint($id), [
            'confirm' => 'OPEN_REMEDIATION_CASE',
            'reason' => 'أنا مدقق قراءة فقط ولا أملك صلاحية إنشاء طلبات معالجة',
        ])->assertForbidden();

        $this->assertDatabaseCount('saher_remediation_cases', 0);
    }

    public function test_resolved_finding_cannot_be_reopened_as_a_new_case(): void
    {
        $id = $this->finding(['status' => 'RESOLVED']);
        $admin = $this->actor('platform_admin');

        $this->actingAs($admin, 'user')->post($this->endpoint($id), [
            'confirm' => 'OPEN_REMEDIATION_CASE',
            'reason' => 'ينبغي منع إنشاء طلب معالجة لاكتشاف مغلق بالفعل',
        ])->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('saher_remediation_cases', 0);
    }

    public function test_finding_text_cannot_inject_code_or_commands_into_the_plan(): void
    {
        $id = $this->finding(['suggested_action' => 'rm -rf /; put shell command in PR']);
        $finding = DB::table('saher_findings')->find($id);
        $plan = app(RemediationPlanner::class)->forFinding($finding);

        $this->assertSame('CODE_REVIEW', $plan['strategy']);
        $this->assertStringNotContainsString('rm -rf', json_encode($plan));
        $this->assertStringContainsString('صلاحيات', implode(' ', $plan['steps']));
    }

    public function test_route_is_guarded_and_ui_is_connected(): void
    {
        $route = Route::getRoutes()->getByName('admin.amial.saher.remediation.open');

        $this->assertNotNull($route);
        $this->assertContains('platform:saher.findings.view', $route->gatherMiddleware());
        $this->assertContains('platform:saher.autofix.plan', $route->gatherMiddleware());

        $view = file_get_contents(resource_path('views/admin-views/amial/saher/show.blade.php'));
        $this->assertStringContainsString('saher-remediation-open', $view);
        $this->assertStringContainsString("hasPlatformPermission('saher.autofix.plan')", $view);
    }
}
