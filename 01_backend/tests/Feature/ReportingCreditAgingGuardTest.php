<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CreditSourceSettlementService;
use App\Services\CustomerCreditService;
use App\Services\Reporting\P1MerchantOperationsReportService;
use App\Services\Reporting\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-REPORTING-CREDIT-AGING-001
 *
 * يمنع رجوع ثلاثة أخطاء محاسبية خطرة في تقرير الذمم:
 *  - سداد فاتورة محددة لا يتحول إلى FIFO عند إعادة قراءة التقرير.
 *  - التعديل الموجب بلا due_date لا يُعطى عمراً مخترعاً.
 *  - جاهزية التقرير تعني أن الرصيد غير القابل للتقادم معلن، لا مخفي.
 */
class ReportingCreditAgingGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function batch_replay_respects_selected_sale_and_aging_reconciles_to_account_balance(): void
    {
        $merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        $customer = User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
            'phone' => '+967771799901',
        ]);

        $credit = app(CustomerCreditService::class);
        $account = $credit->findOrCreateAccount(
            $merchant->id,
            $customer->phone,
            'عميل التقادم',
        );

        $older = $credit->recordSale(
            $account,
            '1000',
            now()->subDays(45)->toDateString(),
            referenceNumber: 'AGING-OLD',
        );
        $selected = $credit->recordSale(
            $account,
            '500',
            now()->subDays(10)->toDateString(),
            referenceNumber: 'AGING-NEW',
        );

        // هذه الدفعة يجب أن تبقى مرتبطة بالفاتورة الأحدث التي اختيرت، لا
        // أن تُخصم من الأقدم حين يعيد التقرير تشغيل دفتر الديون.
        $credit->recordPayment(
            $account,
            '200',
            referenceType: 'debt_payment',
            referenceId: 'TX-AGING-1',
            referenceNumber: 'TXN-AGING-1',
            saleMovementUlid: $selected->movement_ulid,
        );

        $open = app(CreditSourceSettlementService::class)
            ->openInvoicesForAccounts([$account->id]);
        $byReference = collect($open[$account->id])->keyBy('reference_number');

        $this->assertSame('1000.0000', $byReference['AGING-OLD']['remaining']);
        $this->assertSame('300.0000', $byReference['AGING-NEW']['remaining']);
        $this->assertSame($older->movement_ulid, $byReference['AGING-OLD']['movement_ulid']);

        $report = app(P1MerchantOperationsReportService::class)->creditControl();
        $this->assertSame('1300.0000', $report['unified_credit']['receivable']);
        $this->assertSame('1300.0000', $report['unified_credit']['invoice_backed_receivable']);
        $this->assertSame('300.0000', $report['unified_credit']['buckets']['1_30']);
        $this->assertSame('1000.0000', $report['unified_credit']['buckets']['31_60']);
        $this->assertSame('1300.0000', $report['total_known_receivable']);
        $this->assertSame('100.00', $report['aging_coverage_pct']);
        $this->assertTrue($report['fully_unified_aging']);
        $this->assertTrue($report['controls']['selected_sale_payments_respected']);
        $this->assertTrue($report['controls']['wholesale_double_count_prevented']);
    }

    /** @test */
    public function positive_non_invoice_adjustment_is_explicitly_unaged_instead_of_given_a_fake_due_date(): void
    {
        $merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        $customer = User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
            'phone' => '+967771799902',
        ]);

        $credit = app(CustomerCreditService::class);
        $account = $credit->findOrCreateAccount($merchant->id, $customer->phone, 'عميل تعديل');
        $credit->recordSale($account, '1000', now()->subDays(20)->toDateString());
        $credit->recordAdjustment($account, '50', 'تصحيح موثق بلا فاتورة أصلية');

        $report = app(P1MerchantOperationsReportService::class)->creditControl();

        $this->assertSame('1050.0000', $report['unified_credit']['receivable']);
        $this->assertSame('1000.0000', $report['unified_credit']['invoice_backed_receivable']);
        $this->assertSame('50.0000', $report['unified_credit']['unaged_non_invoice_receivable']);
        $this->assertSame(1, $report['unified_credit']['accounts_with_unaged_balance']);
        $this->assertSame('1050.0000', $report['total_known_receivable']);
        $this->assertSame('1000.0000', $report['aged_receivable']);
        $this->assertFalse($report['fully_unified_aging']);
        $this->assertTrue($report['invoice_aging_available']);
        $this->assertTrue($report['controls']['non_invoice_positive_adjustments_are_unaged_not_guessed']);
    }

    /** @test */
    public function catalog_marks_credit_aging_ready_but_inventory_valuation_stays_partial_until_cost_policy_exists(): void
    {
        $merchant = collect(app(ReportCatalogService::class)->catalog()['merchant']['reports'])->keyBy('code');

        $this->assertSame('ready', $merchant['credit_aging']['status']);
        $this->assertSame('partial', $merchant['inventory_valuation']['status']);
    }
}
