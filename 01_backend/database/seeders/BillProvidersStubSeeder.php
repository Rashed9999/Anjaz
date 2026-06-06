<?php

namespace Database\Seeders;

use App\Models\BillProvider;
use App\Models\BillService;
use App\Models\BillServiceProduct;
use Illuminate\Database\Seeder;

/**
 * AMIAL-BILL-PAY-001 (v0.9-C)
 *
 * Seeder للـ stub provider — للتطوير والاختبار فقط.
 * **لا يُشغَّل في production.**
 *
 * Run:
 *   php artisan db:seed --class=BillProvidersStubSeeder
 */
class BillProvidersStubSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('Skipping stub seeder in production');
            return;
        }

        // ---- المزود الوهمي ----
        $provider = BillProvider::updateOrCreate(
            ['code' => 'stub_telecom'],
            [
                'name' => 'Stub Telecom (DEV ONLY)',
                'display_name_ar' => 'مزود تجريبي',
                'integration_type' => 'stub',
                'endpoint_url' => null,
                'is_active' => true,
                'zone_code' => 'SOUTH',
                'config' => [
                    'description' => 'مزود وهمي للاختبار. 90% نجاح، 5% فشل، 5% pending.',
                ],
            ],
        );

        // ---- خدمة: شحن الجوال ----
        $rechargeService = BillService::updateOrCreate(
            ['provider_id' => $provider->id, 'code' => 'mobile_recharge'],
            [
                'name' => 'Mobile Recharge',
                'display_name_ar' => 'شحن جوال',
                'service_type' => 'recharge',
                'is_active' => true,
                'requires_account_number' => true,
                'account_validation_rules' => [
                    'regex' => '^\+?[0-9]{7,15}$',
                    'min_length' => 7,
                    'max_length' => 15,
                ],
            ],
        );

        // فئات الشحن الثابتة
        foreach ([
            ['code' => 'recharge_100', 'name' => 'شحن 100 ر.س', 'amount' => '100.0000', 'fee' => '1.0000'],
            ['code' => 'recharge_200', 'name' => 'شحن 200 ر.س', 'amount' => '200.0000', 'fee' => '2.0000'],
            ['code' => 'recharge_500', 'name' => 'شحن 500 ر.س', 'amount' => '500.0000', 'fee' => '3.0000'],
            ['code' => 'recharge_1000', 'name' => 'شحن 1000 ر.س', 'amount' => '1000.0000', 'fee' => '5.0000'],
        ] as $i => $p) {
            BillServiceProduct::updateOrCreate(
                ['service_id' => $rechargeService->id, 'product_code' => $p['code']],
                [
                    'name' => $p['name'],
                    'amount_type' => 'fixed',
                    'fixed_amount' => $p['amount'],
                    'fee_amount' => $p['fee'],
                    'fee_percent' => 0,
                    'is_active' => true,
                    'sort_order' => $i,
                ],
            );
        }

        // ---- خدمة: فاتورة جوال (variable) ----
        $billService = BillService::updateOrCreate(
            ['provider_id' => $provider->id, 'code' => 'postpaid_bill'],
            [
                'name' => 'Postpaid Bill',
                'display_name_ar' => 'فاتورة جوال شهرية',
                'service_type' => 'postpaid_bill',
                'is_active' => true,
                'requires_account_number' => true,
            ],
        );

        BillServiceProduct::updateOrCreate(
            ['service_id' => $billService->id, 'product_code' => 'postpaid_pay'],
            [
                'name' => 'دفع فاتورة (مبلغ متغير)',
                'amount_type' => 'variable',
                'min_amount' => '10.0000',
                'max_amount' => '5000.0000',
                'fee_amount' => '2.0000',
                'fee_percent' => 0,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        $this->command->info('✓ Stub provider seeded:');
        $this->command->info('  - Provider: stub_telecom (active in SOUTH)');
        $this->command->info('  - Services: mobile_recharge (4 products), postpaid_bill (1 variable product)');
        $this->command->info('  WARNING: STUB ONLY — 90% success simulation. Replace with real provider before pilot.');
    }
}
