<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerService;
use App\Services\Reporting\CustomerLedgerReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CustomerReportTruthTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function customer_report_route_uses_the_same_customer_auth_chain(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.customer.reports.summary');
        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();
        foreach (['api', 'auth:api', 'customerAuth', 'inactiveAuthCheck', 'checkDeviceId'] as $required) {
            $this->assertContains($required, $middleware, $required);
        }
    }

    /** @test */
    public function report_aggregates_the_entire_ledger_and_separates_adjustments(): void
    {
        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'phone' => '967770008811',
        ]);
        $ledger = app(LedgerService::class);
        $wallet = $ledger->getOrCreateUserWallet($user->id);
        $cash = $ledger->getOrCreateSystemAccount(
            'TREASURY_CASH_RESERVE', 'asset', 'نقد الخزينة', 'debit'
        );

        // رصيد افتتاحي/تصحيح لا ينبغي أن يظهر كدخل عميل عادي.
        $ledger->post('opening_balance', (string) $user->id, 'افتتاح', [
            ['account' => $cash->account_code, 'direction' => 'debit', 'amount' => '1000.0000'],
            ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => '1000.0000'],
        ]);

        // 620 حركة — أكثر من الحد القديم 500؛ التقرير يجب أن يجمعها كلها.
        for ($i = 0; $i < 620; $i++) {
            $ledger->post('cash_in', (string) $i, 'إيداع', [
                ['account' => $cash->account_code, 'direction' => 'debit', 'amount' => '1.0000'],
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => '1.0000'],
            ]);
        }

        $report = app(CustomerLedgerReportService::class)->summary(
            $user->id,
            now()->subDay()->toDateString(),
            now()->toDateString(),
        );
        $yer = collect($report['by_currency'])->firstWhere('currency', 'YER');

        $this->assertNotNull($yer);
        $this->assertSame('620.0000', $yer['inflows']);
        $this->assertSame(620, $yer['inflow_count']);
        $this->assertSame('1000.0000', $yer['adjustments_in']);
        $this->assertTrue($yer['reconciled']);
        $this->assertTrue($report['pagination_independent']);
    }

    /** @test */
    public function flutter_report_screen_no_longer_aggregates_a_500_row_history_page(): void
    {
        $path = dirname(base_path())
            . '/02_flutter_app/lib/features/reports/screens/amial_reports_screen.dart';
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString('/api/v1/customer/reports/summary', $source);
        $this->assertStringNotContainsString("'limit': '500'", $source);
        $this->assertStringNotContainsString('fold(0.0', $source);
        $this->assertStringNotContainsString('TransactionModel.fromJson', $source);
    }
}
