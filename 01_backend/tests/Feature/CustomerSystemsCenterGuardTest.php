<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\CustomerSystemsCenterService;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class CustomerSystemsCenterGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_systems_center_routes_are_visible_and_permission_guarded(): void
    {
        $page = Route::getRoutes()->getByName('admin.amial.customer-systems.index');
        $snapshot = Route::getRoutes()->getByName('admin.amial.customer-systems.snapshot');

        $this->assertNotNull($page);
        $this->assertNotNull($snapshot);

        foreach ([$page, $snapshot] as $route) {
            $this->assertContains(
                'platform:platform.audit.view',
                $route->gatherMiddleware(),
                'مركز أنظمة العميل يجب ألا يفتح دون صلاحية رقابية.'
            );
        }
    }

    public function test_kyc_policy_rejection_leaves_an_admin_visible_audit_event(): void
    {
        $customer = User::factory()->create([
            'type' => 2,
            'kyc_tier' => 0,
            'is_phone_verified' => 0,
            'is_kyc_verified' => 0,
        ]);

        try {
            app(KycTierService::class)->assertFeatureAllowed($customer, 'safe_payment');
            $this->fail('Tier 0 نفّذ ميزة محمية دون رفض.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('أكمل إثبات الهاتف', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_decisions', [
            'subject_type' => 'user',
            'subject_id' => (string) $customer->id,
            'action' => 'CUSTOMER_POLICY_BLOCKED',
            'decision_code' => 'KYC_WALLET_NOT_ACTIVATED',
        ]);

        $snapshot = app(CustomerSystemsCenterService::class)->snapshot();
        $this->assertGreaterThanOrEqual(1, $snapshot['summary']['policy_blocks_24h']);
        $this->assertSame(
            (string) $customer->id,
            (string) $snapshot['policy_blocks'][0]['customer_id']
        );
        $this->assertSame('safe_payment', $snapshot['policy_blocks'][0]['feature']);
    }

    public function test_center_covers_the_core_customer_layer_instead_of_only_recent_work(): void
    {
        $snapshot = app(CustomerSystemsCenterService::class)->snapshot();
        $keys = collect($snapshot['systems'])->pluck('key')->all();

        foreach ([
            'kyc',
            'limits',
            'guards',
            'wallet_transfers',
            'merchant_payments',
            'safe_payment',
            'donations',
            'family_funds',
            'withdrawals',
            'idempotency',
            'installments',
            'gift_cards',
            'split_bills',
            'credits',
            'payment_requests',
            'bill_pay',
            'receipts',
            'notifications',
            'reports',
            'quick_pay',
        ] as $key) {
            $this->assertContains(
                $key,
                $keys,
                "نظام العميل {$key} اختفى من مركز الأنظمة الإداري."
            );
        }
    }

    public function test_provider_call_trace_never_exposes_provider_payloads(): void
    {
        $snapshot = app(CustomerSystemsCenterService::class)->snapshot();

        foreach ($snapshot['bill_provider_requests'] as $row) {
            $this->assertArrayNotHasKey('request_payload', $row);
            $this->assertArrayNotHasKey('response_payload', $row);
        }
    }

    public function test_center_declares_notification_delivery_gap_instead_of_claiming_delivery(): void
    {
        $snapshot = app(CustomerSystemsCenterService::class)->snapshot();
        $notifications = collect($snapshot['systems'])->firstWhere('key', 'notifications');

        $this->assertNotNull($notifications);
        $hasDeliveryProof = DB::getSchemaBuilder()->hasTable('notification_deliveries')
            || DB::getSchemaBuilder()->hasTable('notification_delivery_logs');

        if (!$hasDeliveryProof) {
            $this->assertSame('partial', $notifications['state']);
            $this->assertNotEmpty($notifications['gap']);
        }
    }
}
