<?php

namespace Tests\Unit;

use App\Services\FeeService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AMIAL-FEE-ENGINE-001 — اختبارات وحدة لمحرّك الرسوم (المنطق النقي عبر simulate()).
 *
 * يثبت: دقة النسبة/الثابت، الحدّين (min/max)، أن platform_profit + agent_commission =
 * fee بالضبط، سقف حصة الوكيل عند الرسم، منطق من يتحمّل الرسم (sender/receiver)،
 * ورفض المدخلات غير الصالحة. لا يلمس قاعدة البيانات.
 */
class FeeServiceTest extends TestCase
{
    private FeeService $fees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fees = new FeeService();
    }

    private function scheme(array $overrides = []): array
    {
        return array_merge([
            'code' => 'SEND_MONEY',
            'fee_type' => 'percent',
            'percent_rate' => '2.5',
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
        ], $overrides);
    }

    public function test_percent_fee_is_exact(): void
    {
        $r = $this->fees->simulate($this->scheme(['percent_rate' => '2.5']), '100');

        $this->assertSame('100.0000', $r['amount']);
        $this->assertSame('2.5000', $r['fee']);            // 100 * 2.5 / 100
        $this->assertSame('SEND_MONEY', $r['code']);
    }

    public function test_fixed_fee_ignores_amount(): void
    {
        $r = $this->fees->simulate($this->scheme(['fee_type' => 'fixed', 'fixed_amount' => '3']), '1000');

        $this->assertSame('3.0000', $r['fee']);            // ثابت بغضّ النظر عن المبلغ
    }

    public function test_percent_plus_fixed(): void
    {
        $r = $this->fees->simulate(
            $this->scheme(['fee_type' => 'percent_plus_fixed', 'percent_rate' => '1', 'fixed_amount' => '5']),
            '100'
        );

        $this->assertSame('6.0000', $r['fee']);            // (100*1/100) + 5
    }

    public function test_min_fee_floor_is_applied(): void
    {
        // 1% من 100 = 1، لكن الحدّ الأدنى 5
        $r = $this->fees->simulate($this->scheme(['percent_rate' => '1', 'min_fee' => '5']), '100');

        $this->assertSame('5.0000', $r['fee']);
    }

    public function test_max_fee_cap_is_applied(): void
    {
        // 10% من 100 = 10، لكن الحدّ الأقصى 7
        $r = $this->fees->simulate($this->scheme(['percent_rate' => '10', 'max_fee' => '7']), '100');

        $this->assertSame('7.0000', $r['fee']);
    }

    public function test_platform_profit_plus_agent_commission_equals_fee_exactly(): void
    {
        $r = $this->fees->simulate(
            $this->scheme(['percent_rate' => '2.5', 'agent_commission_percent' => '40']),
            '100'
        );

        $this->assertSame('2.5000', $r['fee']);
        $this->assertSame('1.0000', $r['agent_commission']);   // 40% من 2.5
        $this->assertSame('1.5000', $r['platform_profit']);    // 2.5 - 1.0
        // الثبات الحاسم: لا انحراف تقريب
        $this->assertSame(
            $r['fee'],
            \App\Services\MoneyService::add($r['platform_profit'], $r['agent_commission'])
        );
    }

    public function test_agent_commission_never_exceeds_fee(): void
    {
        // عمولة ثابتة 10 أكبر من الرسم 2.5 => تُسقَف عند الرسم
        $r = $this->fees->simulate(
            $this->scheme(['percent_rate' => '2.5', 'agent_commission_fixed' => '10']),
            '100'
        );

        $this->assertSame('2.5000', $r['fee']);
        $this->assertSame('2.5000', $r['agent_commission']);
        $this->assertSame('0.0000', $r['platform_profit']);
    }

    public function test_bearer_sender_adds_fee_to_debit(): void
    {
        $r = $this->fees->simulate($this->scheme(['percent_rate' => '2', 'bearer' => 'sender']), '100');

        $this->assertSame('102.0000', $r['total_debit']);  // المبلغ + الرسم
        $this->assertSame('100.0000', $r['net_credit']);   // المستلم يأخذ كامل المبلغ
    }

    public function test_bearer_receiver_deducts_fee_from_credit(): void
    {
        $r = $this->fees->simulate($this->scheme(['percent_rate' => '2', 'bearer' => 'receiver']), '100');

        $this->assertSame('100.0000', $r['total_debit']);  // الدافع يدفع المبلغ فقط
        $this->assertSame('98.0000', $r['net_credit']);    // الرسم يُخصم من المستلم
    }

    public function test_zero_amount_yields_zero_fee(): void
    {
        $r = $this->fees->simulate($this->scheme(['percent_rate' => '2.5']), '0');

        $this->assertSame('0.0000', $r['amount']);
        $this->assertSame('0.0000', $r['fee']);
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fees->simulate($this->scheme(), '-10');
    }

    public function test_invalid_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fees->simulate($this->scheme(['code' => 'NOT_A_REAL_CODE']), '100');
    }

    public function test_invalid_fee_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fees->simulate($this->scheme(['fee_type' => 'magic']), '100');
    }

    public function test_min_greater_than_max_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fees->simulate($this->scheme(['min_fee' => '10', 'max_fee' => '5']), '100');
    }

    public function test_percent_over_100_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fees->simulate($this->scheme(['percent_rate' => '150']), '100');
    }
}
