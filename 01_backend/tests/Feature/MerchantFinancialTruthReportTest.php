<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\User;
use App\Services\MerchantFinancialTruthReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantFinancialTruthReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function report_keeps_cash_wallet_and_credit_sales_as_separate_truths(): void
    {
        $merchant = User::factory()->create(['type' => 3]);
        MerchantProfile::create([
            'user_id' => $merchant->id, 'business_type' => 'retail',
            'verification_status' => 'verified',
        ]);
        foreach ([['cash', '100'], ['amial_pay', '200'], ['credit', '300']] as [$method, $amount]) {
            MerchantSale::create([
                'sale_ulid' => (string) \Illuminate\Support\Str::ulid(),
                'merchant_user_id' => $merchant->id, 'total_amount' => $amount,
                'payment_method' => $method,
                'status' => $method === 'credit' ? 'credit_unpaid' : 'completed',
                'items' => [], 'zone_code' => 'SOUTH',
            ]);
        }

        $report = app(MerchantFinancialTruthReportService::class)->report($merchant);

        $this->assertSame('merchant-financial-truth/v1', $report['contract_version']);
        $this->assertSame('600.0000', $report['sales']['gross']);
        $this->assertSame('100.0000', $report['sales']['by_payment_method']['cash']);
        $this->assertSame('200.0000', $report['sales']['by_payment_method']['amial_pay']);
        $this->assertSame('300.0000', $report['sales']['by_payment_method']['credit']);
        $this->assertArrayHasKey('wallet', $report);
        $this->assertTrue($report['receivables']['known']);
        $this->assertSame('0.0000', $report['receivables']['amount']);
    }

    /** @test */
    public function mixed_sale_is_split_between_drawer_cash_and_amial_wallet_instead_of_other(): void
    {
        $merchant = User::factory()->create(['type' => 3]);
        MerchantProfile::create([
            'user_id' => $merchant->id, 'business_type' => 'retail',
            'verification_status' => 'verified',
        ]);
        MerchantSale::create([
            'sale_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'total_amount' => '1000',
            'cash_amount' => '400',
            'wallet_amount' => '600',
            'payment_method' => 'mixed',
            'status' => 'completed',
            'items' => [], 'zone_code' => 'SOUTH',
        ]);

        $report = app(MerchantFinancialTruthReportService::class)->report($merchant);

        $this->assertSame('1000.0000', $report['sales']['gross']);
        $this->assertSame('400.0000', $report['sales']['by_payment_method']['cash']);
        $this->assertSame('600.0000', $report['sales']['by_payment_method']['amial_pay']);
        $this->assertSame('0.0000', $report['sales']['by_payment_method']['other']);
    }
}
