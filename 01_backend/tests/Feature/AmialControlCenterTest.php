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

        // Intake pause does NOT disable the underlying Safe Payment engine:
        // lifecycle/settlement of funded payments must remain available.
        $this->assertTrue((bool) FeatureFlag::where('key', 'safe_payment')->value('enabled'));
        $this->assertDatabaseHas('control_center_policies', [
            'feature_key' => 'safe_payment', 'mode' => 'PAUSE_NEW', 'revision' => 2,
        ]);
        $this->assertDatabaseHas('control_center_requests', [
            'id' => $id, 'status' => 'approved',
            'requested_by' => $maker->id, 'reviewed_by' => $checker->id,
        ]);
        $this->assertDatabaseHas('control_center_events', [
            'feature_key' => 'safe_payment', 'action' => 'APPROVED',
            'actor_id' => $checker->id,
        ]);
    }

    public function test_pause_new_is_allowed_with_funded_existing_payments(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();

        // One real held Safe Payment must not block pausing *new* intake.
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('safe_payments');
        $row = [
            'amount' => '7500.0000',
            'held_amount' => '7500.0000',
            'status' => 'funded',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        foreach ([
            'buyer_user_id' => $maker->id,
            'seller_user_id' => $checker->id,
            'payment_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'title' => 'دفعة قائمة',
            'description' => 'تسوية دفعة ممولة عند تعليق الاستقبال',
            'zone_code' => 'SOUTH',
            'fee_amount' => '0',
        ] as $field => $value) {
            if (in_array($field, $columns, true)) {
                $row[$field] = $value;
            }
        }
        DB::table('safe_payments')->insert($row);
        $this->assertSame('7500.0000',
            app(FeatureFlagService::class)->outstandingBalance('safe_payment')['amount']);

        $info = collect(app(ControlCenterService::class)->overview())
            ->firstWhere('key', 'safe_payment');
        $this->assertTrue($info['can_pause_new']); // safe even with held funds
        $this->assertFalse($info['can_disable']); // full shutdown would trap funds

        $id = $this->actingAs($maker, 'user')->postJson(
            '/admin/amial/control-center/features/safe_payment',
            ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(202)->json('request_id');
        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$id,
            ['decision' => 'approve', 'reason' => $this->reason()]
        )->assertOk();

        $this->assertDatabaseHas('control_center_policies', [
            'feature_key' => 'safe_payment',
            'mode' => 'PAUSE_NEW',
        ]);
        $this->assertDatabaseHas('safe_payments', [
            'status' => 'funded', 'held_amount' => '7500.0000',
        ]);
        $this->assertTrue((bool) FeatureFlag::where('key', 'safe_payment')->value('enabled'));
    }

    public function test_paused_new_payment_intake_returns_503_but_allows_existing_lifecycle(): void
    {
        $admin = $this->operator();
        Route::middleware('feature:safe_payment')
            ->post('/api/control-center-test/intake-new', fn () => response()->json(['created' => true]))
            ->name('test.amial.safe-pay.create');
        Route::middleware('feature:safe_payment')
            ->post('/api/control-center-test/confirmed', fn () => response()->json(['confirmed' => true]))
            ->name('test.amial.safe-pay.buyer-confirm');
        Route::middleware('feature:safe_payment')
            ->get('/api/control-center-test/existing', fn () => response()->json(['viewable' => true]))
            ->name('test.amial.safe-pay.show');

        DB::table('control_center_policies')->where('feature_key', 'safe_payment')
            ->update(['mode' => 'PAUSE_NEW']);

        $this->actingAs($admin, 'user')->postJson('/api/control-center-test/intake-new')
            ->assertStatus(503)->assertJsonPath('code', 'FEATURE_INTAKE_PAUSED');
        $this->actingAs($admin, 'user')->postJson('/api/control-center-test/confirmed')
            ->assertOk()->assertJsonPath('confirmed', true);
        $this->actingAs($admin, 'user')->getJson('/api/control-center-test/existing')
            ->assertOk()->assertJsonPath('viewable', true);
    }

    public function test_pause_new_does_not_block_future_settlement_endpoint_with_normal_auth(): void
    {
        // Regression: a new lifecycle operation must not require a change
        // to the intake toggle just to settle money already held.
        $admin = $this->operator();
        Route::middleware('feature:safe_payment')
            ->post('/api/control-center-test/future-settlement', fn () => response()->json(['settled' => true]))
            ->name('test.amial.safe-pay.new-seller-refund');

        DB::table('control_center_policies')->where('feature_key', 'safe_payment')
            ->update(['mode' => 'PAUSE_NEW']);

        $this->actingAs($admin, 'user')
            ->postJson('/api/control-center-test/future-settlement')
            ->assertOk()
            ->assertJsonPath('settled', true);
    }

    public function test_resume_intake_needs_new_maker_checker_approval_and_increments_policy_revision(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();
        $base = '/admin/amial/control-center/features/safe_payment';

        $pause = $this->actingAs($maker, 'user')->postJson($base, [
            'enabled' => false, 'reason' => $this->reason(),
        ])->assertStatus(202)->json('request_id');
        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$pause,
            ['decision' => 'approve', 'reason' => $this->reason()]
        )->assertOk();

        $resume = $this->actingAs($maker, 'user')->postJson($base, [
            'enabled' => true, 'reason' => $this->reason(),
        ])->assertStatus(202)->json('request_id');
        $this->assertNotEquals($pause, $resume);
        $this->assertDatabaseHas('control_center_policies', [
            'feature_key' => 'safe_payment', 'mode' => 'PAUSE_NEW',
        ]);

        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$resume,
            ['decision' => 'approve', 'reason' => $this->reason()]
        )->assertOk();

        $this->assertDatabaseHas('control_center_policies', [
            'feature_key' => 'safe_payment', 'mode' => 'RUNNING', 'revision' => 3,
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

    public function test_stale_policy_revision_blocks_second_approval_even_if_mode_looks_unchanged(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();
        $id = $this->actingAs($maker, 'user')->postJson(
            '/admin/amial/control-center/features/safe_payment',
            ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(202)->json('request_id');

        $this->assertDatabaseHas('control_center_requests', [
            'id' => $id, 'expected_revision' => 1,
        ]);
        // Simulate a separate reviewed change that advanced the policy
        // revision and returned to the same mode.
        DB::table('control_center_policies')->where('feature_key', 'safe_payment')
            ->update(['revision' => 2, 'mode' => 'RUNNING']);
        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$id,
            ['decision' => 'approve', 'reason' => $this->reason()]
        )->assertStatus(409)->assertJsonPath('code', 'STALE_POLICY_REVISION');

        $this->assertDatabaseHas('control_center_policies', [
            'feature_key' => 'safe_payment', 'mode' => 'RUNNING', 'revision' => 2,
        ]);
    }

    public function test_pre_upgrade_request_cannot_be_approved_under_new_pause_mode(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();
        $id = $this->actingAs($maker, 'user')->postJson(
            '/admin/amial/control-center/features/safe_payment',
            ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(202)->json('request_id');

        DB::table('control_center_requests')->where('id', $id)
            ->update(['expected_revision' => null]);

        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$id,
            ['decision' => 'approve', 'reason' => $this->reason()]
        )->assertStatus(409)->assertJsonPath('code', 'STALE_POLICY_REVISION');

        // The stale request may be rejected to clean up the queue.
        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$id,
            ['decision' => 'reject', 'reason' => $this->reason()]
        )->assertOk()->assertJsonPath('code', 'REJECTED');
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

    public function test_disabled_feature_blocks_admin_write_at_http_boundary(): void
    {
        $admin = $this->operator();
        \Illuminate\Support\Facades\Route::middleware('feature:safe_payment')
            ->post('/api/control-center-test/write', fn () => response()->json(['executed' => true]))
            ->name('test.amial.safe-pay.create');

        FeatureFlag::where('key', 'safe_payment')->update(['enabled' => false]);
        $this->actingAs($admin, 'user')
            ->postJson('/api/control-center-test/write')->assertStatus(503)
            ->assertJsonPath('code', 'FEATURE_UNDER_MAINTENANCE');
    }

    public function test_existing_safe_payment_lifecycle_continues_when_new_intake_is_disabled(): void
    {
        $admin = $this->operator();
        \Illuminate\Support\Facades\Route::middleware('feature:safe_payment')
            ->post('/api/control-center-test/settle', fn () => response()->json(['settled' => true]))
            ->name('test.amial.safe-pay.buyer-confirm');

        FeatureFlag::where('key', 'safe_payment')->update(['enabled' => false]);
        $this->actingAs($admin, 'user')->postJson('/api/control-center-test/settle')
            ->assertOk()->assertJsonPath('settled', true);
    }

    public function test_every_registered_safe_payment_creation_route_is_under_the_intake_guard(): void
    {
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true)) {
                continue;
            }
            if (! str_contains((string) $route->getActionName(), 'SafePaymentController@create')) {
                continue;
            }
            $count++;
            $this->assertContains(
                'feature:safe_payment', $route->gatherMiddleware(),
                'Every new Safe Payment creation endpoint must respect PAUSE_NEW.'
            );
            $this->assertStringEndsWith('amial.safe-pay.create', (string) $route->getName());
        }
        $this->assertSame(1, $count, 'Unexpected Safe Payment creation entrypoint inventory change.');
    }

    public function test_undefined_feature_is_fail_closed_on_write_even_for_admin(): void
    {
        $admin = $this->operator();
        \Illuminate\Support\Facades\Route::middleware('feature:feature_missing_from_catalog')
            ->post('/api/control-center-test/unknown', fn () => response()->json(['executed' => true]));

        $this->actingAs($admin, 'user')
            ->postJson('/api/control-center-test/unknown')->assertStatus(503);
    }

    public function test_declined_approval_does_not_disable_service_or_create_money_action(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();

        $requestId = $this->actingAs($maker, 'user')->postJson(
            '/admin/amial/control-center/features/safe_payment',
            ['enabled' => false, 'reason' => $this->reason()]
        )->assertStatus(202)->json('request_id');

        $this->actingAs($checker, 'user')->postJson(
            '/admin/amial/control-center/approvals/'.$requestId,
            ['decision' => 'reject', 'reason' => $this->reason()]
        )->assertOk();

        $this->assertTrue((bool) FeatureFlag::where('key', 'safe_payment')->value('enabled'));
        $this->assertDatabaseHas('control_center_requests', [
            'id' => $requestId, 'status' => 'rejected',
        ]);
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
