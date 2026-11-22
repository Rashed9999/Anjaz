<?php

namespace Tests\Feature;

use App\Exceptions\FeatureBalanceUnknownException;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\ControlCenterService;
use App\Services\FeatureFlagService;
use App\Services\PlatformRoleService;
use Database\Seeders\FeatureFlagsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AmialControlCenterTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role = 'platform_admin'): User
    {
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($admin, $role);
        return $admin->refresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureFlagsSeeder::class);
    }

    private function reason(): string
    {
        return 'قرار تشغيلي يستلزم تحققًا من أثر الخدمة وموافقة ثانية';
    }

    public function test_three_control_center_pages_render_real_data(): void
    {
        $admin = $this->operator();
        $this->actingAs($admin, 'user')
            ->get('/admin/amial/control-center')->assertOk()
            ->assertSee('مركز التحكم في أميال')
            ->assertSee('غير متصل');
        $this->actingAs($admin, 'user')
            ->get('/admin/amial/control-center/services')->assertOk()
            ->assertSee('control-services-table');
        $this->actingAs($admin, 'user')
            ->get('/admin/amial/control-center/emergency')->assertOk()
            ->assertSee('control-approval-table');
    }

    public function test_financial_switch_requires_two_distinct_admins_and_does_not_change_on_request(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();

        $this->assertTrue(app(ControlCenterService::class)->overview() !== []);

        $response = $this->actingAs($maker, 'user')->postJson(
            '/admin/amial/control-center/features/safe_payment',
            ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(202)->assertJsonPath('code', 'PENDING');

        $id = (int) $response->json('request_id');
        $this->assertTrue((bool) FeatureFlag::where('key', 'safe_payment')->value('enabled'));

        $this->actingAs($maker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$id,
            ['decision' => 'approve', 'reason' => $this->reason()]
        )->assertStatus(409)->assertJsonPath('code', 'SELF_APPROVAL_FORBIDDEN');

        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$id,
            ['decision' => 'approve', 'reason' => $this->reason()]
        )->assertOk()->assertJsonPath('code', 'APPROVED');

        $this->assertFalse((bool) FeatureFlag::where('key', 'safe_payment')->value('enabled'));
        $this->assertDatabaseHas('control_center_requests', [
            'id' => $id, 'status' => 'approved',
            'requested_by' => $maker->id, 'reviewed_by' => $checker->id,
        ]);
        $this->assertDatabaseHas('control_center_events', [
            'feature_key' => 'safe_payment', 'action' => 'APPROVED',
            'actor_id' => $checker->id,
        ]);
    }

    public function test_non_connected_flag_has_no_fake_toggle_or_write_effect(): void
    {
        $admin = $this->operator();
        $rows = collect(app(ControlCenterService::class)->overview())->keyBy('key');
        $this->assertFalse($rows['receipts']['connected']);

        $this->actingAs($admin, 'user')->postJson(
            '/admin/amial/control-center/features/receipts',
            ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(409)->assertJsonPath('code', 'FEATURE_NOT_CONNECTED');

        $this->assertTrue((bool) FeatureFlag::where('key', 'receipts')->value('enabled'));
    }

    public function test_failed_live_balance_probe_never_counts_as_zero(): void
    {
        FeatureFlag::where('key', 'safe_payment')
            ->update(['money_source' => 'unmapped_balance_probe']);

        $item = collect(app(FeatureFlagService::class)->overview())
            ->firstWhere('key', 'safe_payment');
        $this->assertFalse($item['can_disable']);
        $this->assertFalse($item['balance_verified']);

        $this->expectException(FeatureBalanceUnknownException::class);
        app(FeatureFlagService::class)->disable($this->operator(), 'safe_payment', $this->reason());
    }

    public function test_financial_legacy_web_route_cannot_bypass_two_person_governance(): void
    {
        $admin = $this->operator();
        $this->actingAs($admin, 'user')->postJson(
            '/admin/maintenance/safe_payment/disable',
            ['note' => $this->reason()]
        )->assertStatus(409)->assertJsonPath('code', 'CONTROL_CENTER_APPROVAL_REQUIRED');

        $this->assertTrue((bool) FeatureFlag::where('key', 'safe_payment')->value('enabled'));
    }

    public function test_stale_request_cannot_flip_changed_flag(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();
        $request = $this->actingAs($maker, 'user')->postJson(
            '/admin/amial/control-center/features/safe_payment',
            ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(202);
        $id = $request->json('request_id');

        FeatureFlag::where('key', 'safe_payment')->update(['enabled' => false]);
        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$id,
            ['decision' => 'approve', 'reason' => $this->reason()]
        )->assertStatus(409)->assertJsonPath('code', 'STALE_STATE');

        $this->assertDatabaseHas('control_center_requests', ['id' => $id, 'status' => 'pending']);
    }

    public function test_missing_or_invalid_reason_does_not_create_request(): void
    {
        $admin = $this->operator();
        $this->actingAs($admin, 'user')->postJson(
            '/admin/amial/control-center/features/safe_payment',
            ['enabled' => false, 'reason' => 'x']
        )->assertUnprocessable();

        $this->assertDatabaseCount('control_center_requests', 0);
    }

    public function test_writes_require_distinct_route_permissions(): void
    {
        $change = Route::getRoutes()->getByName('admin.amial.control-center.features.change');
        $review = Route::getRoutes()->getByName('admin.amial.control-center.approvals.decide');
        $this->assertNotNull($change);
        $this->assertNotNull($review);
        $this->assertContains('platform:platform.settings.update', $change->gatherMiddleware());
        $this->assertContains('platform:platform.control.approve', $review->gatherMiddleware());
    }

    public function test_pending_requests_are_idempotent_and_can_be_rejected_without_effect(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();
        $url = '/admin/amial/control-center/features/safe_payment';
        $first = $this->actingAs($maker, 'user')->postJson(
            $url, ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(202)->json('request_id');
        $second = $this->actingAs($maker, 'user')->postJson(
            $url, ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(202)->json('request_id');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('control_center_requests', 1);

        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$first,
            ['decision' => 'reject', 'reason' => $this->reason()]
        )->assertOk()->assertJsonPath('code', 'REJECTED');

        $this->assertTrue((bool) FeatureFlag::where('key', 'safe_payment')->value('enabled'));
    }
}
