<?php

namespace Tests\Feature;

use App\Models\MerchantSale;
use App\Models\User;
use App\Services\CashierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashierDailyReportParityTest extends TestCase
{
    use RefreshDatabase;

    private function sale(User $merchant, string $method, string $amount, string $status, array $extra = []): void
    {
        MerchantSale::create(array_merge([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'total_amount' => $amount,
            'payment_method' => $method,
            'status' => $status,
            'items' => [],
            'zone_code' => 'SOUTH',
        ], $extra));
    }

    /** @test */
    public function pos_daily_report_uses_the_same_financial_truth_as_merchant_dashboard(): void
    {
        $merchant = User::factory()->create(['type' => 3]);

        $this->sale($merchant, 'cash', '100', 'completed');
        $this->sale($merchant, 'amial_pay', '300', 'completed');
        $this->sale($merchant, 'credit', '200', 'credit_unpaid');
        $this->sale($merchant, 'mixed', '1000', 'completed', [
            'cash_amount' => '400',
            'wallet_amount' => '600',
        ]);
        $this->sale($merchant, 'corporate', '700', 'completed');

        // طلب QR لم يُدفع بعد: يظهر في قائمة العمليات، لكن لا يجوز أن
        // يرفع مبيعات اليوم أو الإيراد الفعلي قبل اكتمال الدفع.
        $this->sale($merchant, 'amial_pay', '900', 'pending_payment');

        $report = app(CashierService::class)->dailyReport($merchant);

        $this->assertSame(5, $report['sales_count']);
        $this->assertSame('2300.0000', $report['total_all']);
        $this->assertSame('500.0000', $report['by_method']['cash']);
        $this->assertSame('900.0000', $report['by_method']['amial_pay']);
        $this->assertSame('900.0000', $report['by_method']['credit']);
        $this->assertSame('1400.0000', $report['realized_revenue']);
    }
}
