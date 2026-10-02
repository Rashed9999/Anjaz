<?php

namespace Tests\Feature;

use App\Models\Merchant\PrintJob;
use App\Models\Merchant\PrinterProfile;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\MerchantPrintTrackingService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantPrintTrackingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function repeated_client_job_id_is_idempotent_and_does_not_duplicate_print_audit(): void
    {
        $merchant = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $payload = [
            'client_job_id' => 'flutter-job-001',
            'document_type' => 'merchant_sale_receipt',
            'document_id' => '01PRINTDOC0000000000000000',
            'document_number' => 'INV-1001',
            'status' => 'completed',
            'copies' => 1,
            'result_message' => 'تمت الطباعة',
            'started_at' => now()->subSecond()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'printer' => [
                'name' => 'POS Printer',
                'printer_type' => 'thermal',
                'connection_type' => 'network',
                'connection_identity' => '192.168.1.50:9100',
                'paper_size' => '80mm',
                'capabilities' => ['cut' => true, 'qr' => true, 'secret_capability' => 'do-not-store'],
                'settings' => ['paper_mm' => 80, 'port' => 9100, 'api_secret' => 'do-not-store'],
            ],
            'metadata' => [
                'source' => 'flutter_thermal_service',
                'payment_method' => 'cash',
                'access_token' => 'do-not-store',
            ],
        ];

        $service = app(MerchantPrintTrackingService::class);

        $first = $service->report($merchant, $payload);
        $second = $service->report($merchant, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PrintJob::where('merchant_user_id', $merchant->id)->count());
        $this->assertSame(1, PrinterProfile::where('merchant_user_id', $merchant->id)->count());

        $profile = PrinterProfile::firstOrFail();
        $this->assertSame('active', $profile->status);
        $this->assertSame('80mm', $profile->paper_size);
        $this->assertStringNotContainsString('192.168.1.50', $profile->endpoint_hash);
        $this->assertStringNotContainsString('192.168.1.50', (string) $profile->endpoint_hint);
        $this->assertSame(['cut' => true, 'qr' => true], $profile->capabilities);
        $this->assertSame(['paper_mm' => 80, 'port' => 9100], $profile->settings);
        $this->assertSame([
            'source' => 'flutter_thermal_service',
            'payment_method' => 'cash',
        ], $first->metadata);
        $this->assertArrayNotHasKey('access_token', $first->metadata);
        $this->assertArrayNotHasKey('api_secret', $profile->settings);
    }

    /** @test */
    public function failed_print_marks_profile_degraded_without_changing_financial_state(): void
    {
        $merchant = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $payload = [
            'client_job_id' => 'flutter-job-fail-001',
            'document_type' => 'merchant_sale_receipt',
            'document_id' => 'SALE-FAIL-001',
            'status' => 'failed',
            'error_code' => 'PRINT_1005',
            'error_message' => 'PRINT_1005: لا ترد الطابعة',
            'printer' => [
                'name' => 'Counter Printer',
                'printer_type' => 'thermal',
                'connection_type' => 'bluetooth',
                'connection_identity' => 'AA:BB:CC:DD:EE:FF',
                'paper_size' => '58mm',
            ],
        ];

        $job = app(MerchantPrintTrackingService::class)->report($merchant, $payload);

        $this->assertSame('failed', $job->status);
        $this->assertSame('PRINT_1005', $job->error_code);
        $this->assertSame('degraded', $job->printer->status);
        $this->assertNotNull($job->printer->last_failure_at);
    }
}
