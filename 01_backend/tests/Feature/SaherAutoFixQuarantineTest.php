<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use App\Saher\Remediation\QuarantineAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** SAHER-AUTOFIX-001: real HTTP containment, RBAC, audit and safe reversal. */
class SaherAutoFixQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private string $routeName = 'admin.saher.autofix.test-write';

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('admin/saher-autofix-probe/write', fn () => response()->json(['executed' => true]))
            ->middleware(['web', 'admin', 'platform:platform.customers.view'])
            ->name($this->routeName);
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($actor, $role);

        return $actor->refresh();
    }

    private function finding(array $changes = []): int
    {
        $rule = 'SAHER.GUARD.WRITE_BEHIND_READ_PERMISSION';
        $name = $this->routeName;
        $fingerprint = hash('sha256', $rule.'|'.$name);
        $now = now();

        return (int) DB::table('saher_findings')->insertGetId(array_merge([
            'reference' => 'SAHER-GUARD-9010',
            'fingerprint' => $fingerprint,
            'rule_id' => $rule,
            'source_code' => 'guards',
            'category' => 'security',
            'title' => 'مسار كتابة خلف قراءة',
            'expected_behavior' => 'عملية الكتابة تحتاج صلاحية فعل',
            'actual_behavior' => 'المسار محروس بالقراءة فقط',
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
        ], $changes));
    }

    private function actionUrl(int $id): string
    {
        return '/admin/amial/saher/findings/'.$id.'/quarantine';
    }

    public function test_admin_can_quarantine_a_live_vulnerable_route_and_reverse_it(): void
    {
        $findingId = $this->finding();
        $actor = $this->actor('platform_admin');

        $this->assertTrue($actor->hasPlatformPermission('saher.autofix.quarantine'),
            'platform_admin missing quarantine permission');
        $plan = app(QuarantineAdvisor::class)->assess(DB::table('saher_findings')->find($findingId));
        $this->assertTrue($plan['eligible'], $plan['reason']);

        $this->actingAs($actor, 'user')
            ->postJson('/admin/saher-autofix-probe/write')->assertOk()
            ->assertJsonPath('executed', true);

        $this->actingAs($actor, 'user')->post($this->actionUrl($findingId), [
            'confirm' => 'QUARANTINE',
            'reason' => 'عزل مسار الكتابة حتى إصلاح صلاحياته في الشيفرة',
        ])->assertRedirect('/')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('saher_route_quarantines', [
            'route_name' => $this->routeName,
            'status' => 'ACTIVE',
            'created_by_user_id' => $actor->id,
        ]);

        $this->actingAs($actor, 'user')->postJson('/admin/saher-autofix-probe/write')
            ->assertStatus(403)->assertJsonPath('code', 'SAHER_ROUTE_QUARANTINED');

        $this->assertDatabaseHas('saher_findings', [
            'id' => $findingId, 'status' => 'OPEN',
        ]);
        $this->assertDatabaseHas('saher_finding_events', [
            'finding_id' => $findingId,
            'event' => 'AUTOFIX_QUARANTINED',
            'actor_user_id' => $actor->id,
        ]);

        $this->actingAs($actor, 'user')
            ->post($this->actionUrl($findingId).'/unblock', [
                'confirm' => 'RELEASE',
                'reason' => 'مراجعة التغيير واعتماد رفع الحظر على هذا المسار',
            ])->assertRedirect('/')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('saher_route_quarantines', [
            'route_name' => $this->routeName,
            'status' => 'RELEASED',
            'released_by_user_id' => $actor->id,
        ]);

        $this->actingAs($actor, 'user')
            ->postJson('/admin/saher-autofix-probe/write')->assertOk();
    }

    public function test_repeated_containment_is_idempotent(): void
    {
        $id = $this->finding();
        $actor = $this->actor('platform_admin');
        $data = [
            'confirm' => 'QUARANTINE',
            'reason' => 'تم تحديد مسار كتابة خطير ويجب إيقافه حتى الإصلاح',
        ];

        $this->actingAs($actor, 'user')->post($this->actionUrl($id), $data)
            ->assertRedirect('/')->assertSessionHasNoErrors();
        $this->actingAs($actor, 'user')->post($this->actionUrl($id), $data)
            ->assertRedirect('/')->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('saher_route_quarantines')->count());
        $this->assertSame(1, DB::table('saher_finding_events')
            ->where('event', 'AUTOFIX_QUARANTINED')->count());
    }

    public function test_support_operator_cannot_activate_quarantine(): void
    {
        $id = $this->finding();
        $support = $this->actor('platform_support');

        $this->actingAs($support, 'user')->postJson($this->actionUrl($id), [
            'confirm' => 'QUARANTINE',
            'reason' => 'ليس لدى هذا المستخدم صلاحية احتواء المسارات الخطيرة',
        ])->assertForbidden();

        $this->assertDatabaseCount('saher_route_quarantines', 0);
    }

    public function test_an_unverified_finding_cannot_quarantine_a_route(): void
    {
        $id = $this->finding(['confidence' => 'SUSPECTED']);
        $admin = $this->actor('platform_admin');

        $this->actingAs($admin, 'user')->post($this->actionUrl($id), [
            'confirm' => 'QUARANTINE',
            'reason' => 'المسار يحتاج عزلًا ولكن الثقة في نتيجة الفحص ضعيفة',
        ])->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('saher_route_quarantines', 0);
    }

    public function test_an_unrelated_rule_is_not_eligible(): void
    {
        $id = $this->finding(['rule_id' => 'SAHER.DATA.SERVICE_METHOD_UNREACHED']);
        $admin = $this->actor('platform_admin');

        $this->actingAs($admin, 'user')->post($this->actionUrl($id), [
            'confirm' => 'QUARANTINE',
            'reason' => 'رفض إعادة استخدام الاحتواء لاكتشاف غير مرتبط بمسار',
        ])->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('saher_route_quarantines', 0);
    }

    public function test_advisor_rechecks_the_live_route_not_only_the_saved_finding(): void
    {
        $id = $this->finding(['asset_key' => 'admin.nonexistent.stale-route']);
        $finding = DB::table('saher_findings')->find($id);
        $result = app(QuarantineAdvisor::class)->assess($finding);

        $this->assertFalse($result['eligible']);
        $this->assertNull($result['route_name']);
    }

    public function test_active_containments_are_visible_in_saher_dashboard(): void
    {
        $id = $this->finding();
        $admin = $this->actor('platform_admin');
        $this->actingAs($admin, 'user')->post($this->actionUrl($id), [
            'confirm' => 'QUARANTINE',
            'reason' => 'تقييد وظيفة خطرة حتى التحقق من صلاحية الكتابة في المسار',
        ])->assertRedirect();

        $this->actingAs($admin, 'user')->get('/admin/amial/saher')
            ->assertOk()
            ->assertSee('saher-active-quarantines', false)
            ->assertSee($this->routeName);

        // Auditors can see the count, but not quarantine-specific reason details.
        $auditor = $this->actor('platform_auditor');
        $this->actingAs($auditor, 'user')->get('/admin/amial/saher')
            ->assertOk()
            ->assertSee('هناك 1 احتواء نشط')
            ->assertDontSee('تقييد وظيفة خطرة حتى التحقق من صلاحية الكتابة في المسار');
    }

    public function test_route_and_ui_are_connected_and_guarded(): void
    {
        $route = Route::getRoutes()->getByName('admin.amial.saher.quarantine');
        $this->assertNotNull($route);
        $this->assertContains('platform:saher.autofix.quarantine', $route->gatherMiddleware());

        $view = file_get_contents(resource_path('views/admin-views/amial/saher/show.blade.php'));
        $this->assertStringContainsString('saher-autofix-quarantine', $view);
        $this->assertStringContainsString("hasPlatformPermission('saher.autofix.quarantine')", $view);
    }
}
