<?php

namespace Tests\Feature;

use App\Services\FeeService;
use Database\Seeders\FeeSchemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnusedCustomerQrFeeRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_verified_unused_customer_price_is_removed(): void
    {
        $this->seed(FeeSchemeSeeder::class);

        // الهجرةُ تستهدف الصفَّ ١١ بعينه (يتيمُ الإنتاج). ورقمُ الصفّ في القاعدة
        // الاختباريّة يتبع عدّادَ الزيادة — وMySQL لا يُرجعه مع تراجع المعاملات —
        // فيقع ١١ أحياناً على صفٍّ مبذور. يُنقَل ذاك إلى رقمٍ حرّ ليبقى ١١ للحالة.
        if (DB::table('fee_schemes')->where('id', 11)->exists()) {
            DB::table('fee_schemes')->where('id', 11)
                ->update(['id' => (int) DB::table('fee_schemes')->max('id') + 1]);
        }

        DB::table('fee_schemes')->insert([
            'id' => 11, 'code' => 'MERCHANT_QR', 'zone_code' => 'SOUTH',
            'applies_to' => 'customer', 'plan' => null, 'version' => 1,
            'fee_type' => 'percent', 'percent_rate' => '15.0000',
            'fixed_amount' => '0.0000', 'bearer' => 'sender',
            'is_active' => true, 'effective_from' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fee_change_logs')->insert([
            'fee_scheme_id' => 11, 'code' => 'MERCHANT_QR',
            'action' => 'created', 'created_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_10_090200_remove_unused_customer_qr_fee.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseMissing('fee_schemes', ['id' => 11]);
        $this->assertDatabaseHas('fee_change_logs', ['fee_scheme_id' => 11, 'action' => 'created']);
        $this->assertSame('explicit_zero', app(FeeService::class)->calculate('MERCHANT_QR', '100', [
            'applies_to' => 'merchant',
        ])['pricing_state']);
    }
}
