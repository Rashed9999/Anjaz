<?php

namespace Tests\Feature;

use App\Models\FeeScheme;
use App\Services\FeeService;
use Database\Seeders\FeeSchemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MerchantFeeZeroDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_retires_one_percent_merchant_fees_without_reactivating_old_customer_prices(): void
    {
        $this->seed(FeeSchemeSeeder::class);

        foreach (['MERCHANT_QR', 'MERCHANT_POS'] as $code) {
            FeeScheme::where('code', $code)->update(['percent_rate' => '1.0000']);
        }

        $oldCustomer = FeeScheme::where('code', 'SEND_MONEY')->firstOrFail();
        $oldCustomer->update(['is_active' => false, 'effective_to' => now()]);
        FeeScheme::create(array_merge($oldCustomer->only([
            'code', 'label', 'zone_code', 'applies_to', 'plan', 'fee_type',
            'fixed_amount', 'agent_commission_percent', 'agent_commission_fixed', 'bearer',
        ]), [
            'percent_rate' => '6.0000', 'version' => 2, 'is_active' => true,
            'effective_from' => now(), 'effective_to' => null,
        ]));

        // Repeated production boot must not reactivate the archived version.
        $this->seed(FeeSchemeSeeder::class);
        $this->assertFalse($oldCustomer->fresh()->is_active);
        $this->assertSame('6.0000', (string) FeeScheme::where('code', 'SEND_MONEY')->where('is_active', true)->value('percent_rate'));

        $migration = require database_path('migrations/2026_11_10_090000_make_merchant_acceptance_fee_zero.php');
        $migration->up();
        $migration->up(); // Idempotent if an operator repeats the correction.
        Cache::flush();

        foreach (['MERCHANT_QR', 'MERCHANT_POS'] as $code) {
            $schemes = FeeScheme::where('code', $code)->orderBy('version')->get();
            $this->assertCount(2, $schemes);
            $this->assertFalse($schemes[0]->is_active);
            $this->assertTrue($schemes[1]->is_active);
            $this->assertSame('1.0000', (string) $schemes[0]->percent_rate);
            $this->assertSame('0.0000', (string) $schemes[1]->percent_rate);
            $this->assertSame('explicit_zero', app(FeeService::class)->calculate($code, '10000', [
                'applies_to' => 'merchant',
            ])['pricing_state']);
            $this->assertSame(2, DB::table('fee_change_logs')->where('code', $code)->count());
        }
    }
}
