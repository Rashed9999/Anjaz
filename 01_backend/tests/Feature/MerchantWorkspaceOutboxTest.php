<?php

namespace Tests\Feature;

use App\Models\{EMoney, Merchant, User};
use App\Services\Merchant\MerchantWorkspaceOutbox;
use App\Support\Access\AccessConstants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\Support\ProvisionsAccountsFromMuwathaq;
use Tests\TestCase;

final class MerchantWorkspaceOutboxTest extends TestCase
{
    use RefreshDatabase, ProvisionsAccountsFromMuwathaq;

    public function test_failure_for_one_merchant_does_not_block_another_merchants_events(): void
    {
        $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390011');
        $second = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390012');
        $secondTenant = 'legacy_merchant_'.Merchant::where('user_id', $second->id)->value('id');
        $this->enableTransport();
        Http::fake(function ($request) use ($secondTenant) {
            $context = json_decode(base64_decode(strtr($request->header('X-Amial-Context')[0], '-_', '+/')), true);
            return $context['tenant_id'] !== $secondTenant ? Http::response([], 409)
                : Http::response(['code' => 'MERCHANT_PROVISIONED', 'tenant_id' => $secondTenant]);
        });
        $this->assertSame(1, app(MerchantWorkspaceOutbox::class)->deliverPending());
    }

    public function test_a_newer_nonapproved_case_suspends_an_existing_workspace(): void
    {
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390013');
        $case = \App\Models\MuwathaqAccountOpeningApplication::where('user_id', $owner->id)->first();
        $case->status = 'rejected';
        $case->save();
        app(MerchantWorkspaceOutbox::class)->recordFor($owner);
        $this->assertDatabaseHas('merchant_workspace_events', ['kind' => 'lifecycle', 'state' => 'suspended']);
    }

    public function test_initially_suspended_snapshot_carries_its_state_in_provisioning(): void
    {
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390014');
        DB::table('merchant_workspace_events')->delete(); // isolated test database only
        $owner->is_active = 0;
        $owner->save();
        app(MerchantWorkspaceOutbox::class)->recordFor($owner);
        $event = DB::table('merchant_workspace_events')->first();
        $this->assertSame('suspended', json_decode($event->payload, true)['access_status'] ?? null);
        $this->assertDatabaseCount('merchant_workspace_events', 1);
    }


    public function test_admin_freeze_and_unfreeze_are_recorded_before_the_next_poll(): void
    {
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390015');
        $actor = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        $actions = app(\App\Services\AdminCenter\MerchantAdminActionService::class);
        foreach ([0, 1] as $active) {
            $actions->perform($actor, $owner->id, $active ? 'account.unfreeze' : 'account.freeze',
                'اختبار انتقال حالة التاجر', [], function () use ($owner, $active) {
                    $owner->is_active = $active;
                    $owner->save();
                    return ['is_active' => $active];
                });
        }
        $states = DB::table('merchant_workspace_events')->where('kind', 'lifecycle')
            ->orderBy('id')->pluck('state')->all();
        $this->assertSame(['suspended', 'active'], $states);
    }

    public function test_sync_command_reports_attempted_delivery_failure(): void
    {
        $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390016');
        $this->enableTransport();
        Http::fake(['*' => Http::response([], 503)]);
        $this->artisan('merchant:workspace-sync')->assertExitCode(1);
    }


    public function test_web_login_rejects_a_security_hold_even_with_valid_credentials(): void
    {
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390017');
        $owner->password = \Illuminate\Support\Facades\Hash::make('TestPass!234');
        $owner->security_hold_until = now()->addHour();
        $owner->save();
        $this->from('/merchant/login')->post('/merchant/login', [
            'identifier' => $owner->email, 'password' => 'TestPass!234',
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest('merchant_web');
        app(MerchantWorkspaceOutbox::class)->recordFor($owner);
        $this->assertDatabaseHas('merchant_workspace_events', ['kind' => 'lifecycle', 'state' => 'suspended']);
    }

    public function test_web_login_cannot_use_a_historical_approval_after_current_rejection(): void
    {
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390018');
        $owner->password = \Illuminate\Support\Facades\Hash::make('TestPass!234');
        $owner->save();
        $case = \App\Models\MuwathaqAccountOpeningApplication::where('user_id', $owner->id)->first();
        $case->status = 'rejected';
        $case->save();
        $this->from('/merchant/login')->post('/merchant/login', [
            'identifier' => $owner->email, 'password' => 'TestPass!234',
        ])->assertSessionHasErrors('identifier');
        $this->assertGuest('merchant_web');
    }

    public function test_queued_active_provision_is_narrowed_if_merchant_is_frozen_before_delivery(): void
    {
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390019');
        $owner->is_active = 0;
        $owner->save();
        $outbox = app(MerchantWorkspaceOutbox::class);
        $outbox->recordFor($owner);
        $this->enableTransport();
        Http::fake(function ($request) {
            if (! str_ends_with($request->url(), '/provision')) return Http::response([], 503);
            $payload = json_decode($request->body(), true);
            $this->assertSame('suspended', $payload['access_status']);
            return Http::response(['code' => 'MERCHANT_PROVISIONED', 'tenant_id' => $payload['tenant_id']]);
        });
        $this->assertSame(1, $outbox->deliverPending());
        $this->assertSame(1, $outbox->failedDeliveries());
    }

    private function enableTransport(): void
    {
        config()->set('amial.merchant_workspace.enabled', true);
        config()->set('amial.merchant_workspace.base_url', 'https://merchant-test.example.test');
        config()->set('amial.merchant_workspace.integration_secret', str_repeat('test-only', 6));
    }

    public function test_approval_records_commercial_provisioning_atomically_without_network_or_duplicate_wallet(): void
    {
        Http::fake();
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390001');
        $wallets = EMoney::count();
        app(MerchantWorkspaceOutbox::class)->recordFor($owner);
        $this->assertDatabaseCount('merchant_workspace_events', 1);
        $row = DB::table('merchant_workspace_events')->first();
        $payload = json_decode($row->payload, true);
        $this->assertSame((string) $owner->id, $payload['owner_amial_account_id']);
        $this->assertSame((string) Merchant::where('user_id', $owner->id)->value('merchant_number'), $payload['merchant_number']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertSame($wallets, EMoney::count());
        Http::assertNothingSent();
    }

    public function test_failure_is_durable_and_blocks_later_lifecycle_until_provision_acknowledged(): void
    {
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390002');
        $owner->is_active = 0;
        $owner->save();
        $outbox = app(MerchantWorkspaceOutbox::class);
        $outbox->recordFor($owner);
        $this->enableTransport();
        $attempt = 0;
        Http::fake(function ($request) use (&$attempt) {
            if (++$attempt === 1) return Http::response(['error' => 'unavailable'], 503);
            $tenant = json_decode(base64_decode(strtr($request->header('X-Amial-Context')[0], '-_', '+/')), true)['tenant_id'];
            return Http::response(['code' => str_ends_with($request->url(), '/provision')
                ? 'MERCHANT_PROVISIONED' : 'MERCHANT_LIFECYCLE_ACCEPTED', 'tenant_id' => $tenant]);
        });
        $this->assertSame(0, $outbox->deliverPending());
        $this->assertDatabaseHas('merchant_workspace_events', ['kind' => 'provision', 'attempts' => 1, 'delivered_at' => null]);
        $this->assertDatabaseHas('merchant_workspace_events', ['kind' => 'lifecycle', 'attempts' => 0]);
        $this->assertSame(2, $outbox->deliverPending());
        $this->assertSame(0, $outbox->deliverPending());
        $this->assertSame(0, DB::table('merchant_workspace_events')->whereNull('delivered_at')->count());
    }

    public function test_state_changes_have_monotonic_sequences_and_no_repeated_snapshots(): void
    {
        $owner = $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390003');
        $outbox = app(MerchantWorkspaceOutbox::class);
        foreach ([0, 0, 1, 1] as $active) {
            $owner->is_active = $active;
            $owner->save();
            $outbox->recordFor($owner);
        }
        $events = DB::table('merchant_workspace_events')->where('kind', 'lifecycle')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $first = json_decode($events[0]->payload, true);
        $second = json_decode($events[1]->payload, true);
        $this->assertSame('suspended', $first['status']);
        $this->assertSame('active', $second['status']);
        $this->assertGreaterThan($first['sequence'], $second['sequence']);
        Http::fake();
        config()->set('amial.merchant_workspace.enabled', false);
        $this->assertSame(0, $outbox->deliverPending());
        Http::assertNothingSent();
    }

    public function test_rolled_back_account_creation_leaves_no_outbox_or_wallet(): void
    {
        $wallets = EMoney::count();
        DB::beginTransaction();
        $this->provisionMerchantFromMuwathaq(AccessConstants::BIZ_RETAIL, '777390004');
        DB::rollBack();
        $this->assertDatabaseCount('merchant_workspace_events', 0);
        $this->assertSame($wallets, EMoney::count());
    }
}
