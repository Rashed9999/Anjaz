<?php

namespace Tests\Unit;

use App\Services\MoneyService;
use PHPUnit\Framework\TestCase;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * MoneyServiceTest — يتأكد من أن العمليات المالية بدون أخطاء تقريب float.
 *
 * كل اختبار يصادف مشكلة float محددة وكان الـ legacy code يقع فيها.
 */
class MoneyServiceTest extends TestCase
{
    /** المشكلة الأشهر: 0.1 + 0.2 != 0.3 في float */
    public function test_zero_point_one_plus_zero_point_two_equals_zero_point_three(): void
    {
        $result = MoneyService::add('0.1', '0.2');
        $this->assertSame('0.3000', $result);
        $this->assertTrue(MoneyService::eq($result, '0.3'));
    }

    public function test_normalize_keeps_4_decimal_places(): void
    {
        $this->assertSame('100.0000', MoneyService::normalize('100'));
        $this->assertSame('100.5000', MoneyService::normalize('100.5'));
        $this->assertSame('100.1234', MoneyService::normalize('100.1234'));
    }

    public function test_normalize_rejects_invalid_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoneyService::normalize('abc');
    }

    public function test_normalize_handles_integer(): void
    {
        $this->assertSame('100.0000', MoneyService::normalize(100));
    }

    public function test_normalize_handles_float_safely(): void
    {
        // 0.1 + 0.2 = 0.30000000000000004 لو عبرناه float
        $tricky = 0.1 + 0.2;
        $this->assertSame('0.3000', MoneyService::normalize($tricky));
    }

    public function test_subtraction_precise(): void
    {
        // 100.00 - 99.99 = 0.01 (لو float، تنحرف)
        $this->assertSame('0.0100', MoneyService::sub('100.00', '99.99'));
    }

    public function test_gte_strict(): void
    {
        $this->assertTrue(MoneyService::gte('100.0001', '100.0000'));
        $this->assertTrue(MoneyService::gte('100.0000', '100.0000'));
        $this->assertFalse(MoneyService::gte('99.9999', '100.0000'));
    }

    public function test_division_by_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoneyService::div('100', '0');
    }

    /** AMIAL-SPLIT-BILL-001: توزيع المبالغ */
    public function test_distribute_handles_rounding_difference(): void
    {
        // 100 / 3 = 33.33 each, but 33.33 × 3 = 99.99 → فرق سنت
        $shares = MoneyService::distribute('100.00', 3);
        $this->assertCount(3, $shares);

        // المجموع يجب أن يساوي 100.00 بدقة (الفرق يُضاف على المشارك الأول)
        $sum = '0';
        foreach ($shares as $s) {
            $sum = bcadd($sum, $s, 2);
        }
        $this->assertSame('100.00', $sum);
        // المشارك الأول يأخذ الفرق
        $this->assertSame('33.34', $shares[0]);
        $this->assertSame('33.33', $shares[1]);
        $this->assertSame('33.33', $shares[2]);
    }

    public function test_distribute_evenly(): void
    {
        $shares = MoneyService::distribute('120.00', 4);
        foreach ($shares as $s) {
            $this->assertSame('30.00', $s);
        }
    }

    public function test_display_rounds_half_up(): void
    {
        $this->assertSame('100.13', MoneyService::display('100.125'));
        $this->assertSame('100.12', MoneyService::display('100.124'));
        $this->assertSame('100.00', MoneyService::display('100.0000'));
    }

    /** اختبار حالات الحدود: مجاميع كبيرة (10 million transactions × 0.01) */
    public function test_aggregation_stable_over_many_operations(): void
    {
        $total = '0';
        for ($i = 0; $i < 10_000; $i++) {
            $total = MoneyService::add($total, '0.01');
        }
        // 10,000 × 0.01 = 100.00 exactly (لو float، تنحرف)
        $this->assertSame('100.0000', $total);
    }
}
