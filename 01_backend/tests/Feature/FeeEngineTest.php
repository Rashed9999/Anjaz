<?php

namespace Tests\Feature;

use App\Models\FeeChangeLog;
use App\Models\FeeScheme;
use App\Services\FeeService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AMIAL-FEE-ENGINE-001 — محرّك الرسوم المركزي.
 */
class FeeEngineTest extends TestCase
{
    use RefreshDatabase;

    private FeeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(FeeService::class);
    }

    private function scheme(array $overrides = []): FeeScheme
    {
        return FeeScheme::create(array_merge([
            'code' => 'SEND_MONEY',
            'label' => 'test',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent',
            'percent_rate' => '1.0000',
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'version' => 1,
            'is_active' => true,
            'effective_from' => now(),
        ], $overrides));
    }

    /** @test */
    public function percent_fee_is_computed_correctly()
    {
        $this->scheme(['percent_rate' => '1.5000']);
        $r = $this->svc->calculate('SEND_MONEY', '1000');
        $this->assertSame(MoneyService::normalize('15'), $r['fee']); // 1.5% من 1000
    }

    /** @test */
    public function fixed_fee_is_computed_correctly()
    {
        $this->scheme(['fee_type' => 'fixed', 'fixed_amount' => '25', 'percent_rate' => '0']);
        $r = $this->svc->calculate('SEND_MONEY', '1000');
        $this->assertSame(MoneyService::normalize('25'), $r['fee']);
    }

    /** @test */
    public function percent_plus_fixed_is_computed_correctly()
    {
        $this->scheme(['fee_type' => 'percent_plus_fixed', 'percent_rate' => '1.0000', 'fixed_amount' => '5']);
        $r = $this->svc->calculate('SEND_MONEY', '1000'); // 10 + 5
        $this->assertSame(MoneyService::normalize('15'), $r['fee']);
    }

    /** @test */
    public function min_fee_cap_is_applied()
    {
        $this->scheme(['percent_rate' => '0.1000', 'min_fee' => '50']); // 0.1% من 1000 = 1 → يرتفع لـ 50
        $r = $this->svc->calculate('SEND_MONEY', '1000');
        $this->assertSame(MoneyService::normalize('50'), $r['fee']);
    }

    /** @test */
    public function max_fee_cap_is_applied()
    {
        $this->scheme(['percent_rate' => '5.0000', 'max_fee' => '100']); // 5% من 10000 = 500 → يُقصّ لـ 100
        $r = $this->svc->calculate('SEND_MONEY', '10000');
        $this->assertSame(MoneyService::normalize('100'), $r['fee']);
    }

    /** @test */
    public function agent_commission_and_platform_profit_sum_to_fee_exactly()
    {
        $this->scheme(['percent_rate' => '1.0000', 'agent_commission_percent' => '40.0000']);
        $r = $this->svc->calculate('SEND_MONEY', '1000'); // fee=10, agent=4, profit=6
        $this->assertSame(MoneyService::normalize('10'), $r['fee']);
        $this->assertSame(MoneyService::normalize('4'), $r['agent_commission']);
        $this->assertSame(MoneyService::normalize('6'), $r['platform_profit']);
        // الثابت: profit + agent = fee
        $this->assertSame(
            $r['fee'],
            MoneyService::add($r['platform_profit'], $r['agent_commission'])
        );
    }

    /** @test */
    public function agent_commission_never_exceeds_fee()
    {
        $this->scheme(['percent_rate' => '1.0000', 'agent_commission_percent' => '100', 'agent_commission_fixed' => '999']);
        $r = $this->svc->calculate('SEND_MONEY', '1000'); // fee=10، الوكيل لا يتجاوز 10
        $this->assertSame(MoneyService::normalize('10'), $r['agent_commission']);
        $this->assertSame(MoneyService::normalize('0'), $r['platform_profit']);
    }

    /** @test */
    public function no_active_scheme_means_zero_fee()
    {
        $r = $this->svc->calculate('CASH_OUT', '1000'); // لا يوجد scheme
        $this->assertSame('0.0000', $r['fee']);
        $this->assertNull($r['scheme_id']);
        $this->assertSame($r['amount'], $r['total_debit']);
        $this->assertSame($r['amount'], $r['net_credit']);
    }

    /** @test */
    public function bearer_sender_adds_fee_on_top()
    {
        $this->scheme(['percent_rate' => '1.0000', 'bearer' => 'sender']);
        $r = $this->svc->calculate('SEND_MONEY', '1000');
        $this->assertSame(MoneyService::normalize('1010'), $r['total_debit']); // المبلغ + الرسم
        $this->assertSame(MoneyService::normalize('1000'), $r['net_credit']);
    }

    /** @test */
    public function bearer_merchant_deducts_fee_from_amount()
    {
        $this->scheme(['code' => 'MERCHANT_QR', 'applies_to' => 'merchant', 'percent_rate' => '1.0000', 'bearer' => 'merchant']);
        $r = $this->svc->calculate('MERCHANT_QR', '1000', ['applies_to' => 'merchant']);
        $this->assertSame(MoneyService::normalize('1000'), $r['total_debit']);
        $this->assertSame(MoneyService::normalize('990'), $r['net_credit']); // 1000 - 10
    }

    /** @test */
    public function create_version_supersedes_previous_and_increments_version()
    {
        $v1 = $this->scheme(['percent_rate' => '1.0000']);
        $this->assertTrue($v1->is_active);

        $v2 = $this->svc->createVersion([
            'code' => 'SEND_MONEY',
            'applies_to' => 'customer',
            'zone_code' => 'SOUTH',
            'fee_type' => 'percent',
            'percent_rate' => '2.0000',
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ], adminId: 7);

        $this->assertSame(2, $v2->version);
        $this->assertTrue($v2->is_active);
        $this->assertFalse($v1->fresh()->is_active);
        $this->assertNotNull($v1->fresh()->effective_to);

        // النسخة النشطة الآن هي v2
        $active = $this->svc->activeScheme('SEND_MONEY');
        $this->assertSame(2, $active->version);
    }

    /** @test */
    public function change_log_is_written_on_create_and_deactivate()
    {
        $v2 = $this->svc->createVersion([
            'code' => 'BILL_PAY', 'applies_to' => 'customer', 'zone_code' => 'SOUTH',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '10',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0', 'bearer' => 'sender',
        ], adminId: 3, ip: '1.2.3.4');

        $this->assertDatabaseHas('fee_change_logs', [
            'code' => 'BILL_PAY', 'action' => 'created', 'admin_id' => 3,
        ]);

        $this->svc->deactivate($v2->id, adminId: 3);
        $this->assertDatabaseHas('fee_change_logs', [
            'code' => 'BILL_PAY', 'action' => 'deactivated',
        ]);
        $this->assertFalse($v2->fresh()->is_active);
    }

    /** @test */
    public function simulate_matches_calculate_without_persisting()
    {
        $result = $this->svc->simulate([
            'code' => 'SEND_MONEY', 'fee_type' => 'percent', 'percent_rate' => '2.0000',
            'fixed_amount' => '0', 'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ], '500');

        $this->assertSame(MoneyService::normalize('10'), $result['fee']); // 2% من 500
        // لم يُحفظ أي شيء
        $this->assertDatabaseCount('fee_schemes', 0);
    }

    /** @test */
    public function invalid_percent_above_100_throws()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->createVersion([
            'code' => 'SEND_MONEY', 'fee_type' => 'percent', 'percent_rate' => '150',
            'fixed_amount' => '0', 'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ]);
    }

    /** @test */
    public function min_greater_than_max_throws()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->createVersion([
            'code' => 'SEND_MONEY', 'fee_type' => 'percent', 'percent_rate' => '1',
            'fixed_amount' => '0', 'min_fee' => '100', 'max_fee' => '50',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0', 'bearer' => 'sender',
        ]);
    }

    /** @test */
    public function negative_amount_throws()
    {
        $this->scheme();
        $this->expectException(InvalidArgumentException::class);
        $this->svc->calculate('SEND_MONEY', '-100');
    }

    /** @test */
    public function fee_is_never_negative()
    {
        $this->scheme(['fee_type' => 'fixed', 'fixed_amount' => '0', 'percent_rate' => '0']);
        $r = $this->svc->calculate('SEND_MONEY', '1000');
        $this->assertTrue(MoneyService::isNonNegative($r['fee']));
        $this->assertTrue(MoneyService::isNonNegative($r['platform_profit']));
    }
}
