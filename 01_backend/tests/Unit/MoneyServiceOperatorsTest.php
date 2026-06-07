<?php

namespace Tests\Unit;

use App\Services\MoneyService;
use PHPUnit\Framework\TestCase;

/**
 * تكملة لـ MoneyServiceTest: تغطّي المعاملات غير المختبَرة (mul/gt/eq/compare/
 * isPositive/isNonNegative) والأرقام السالبة — كلها bcmath نقي بلا قاعدة بيانات.
 */
class MoneyServiceOperatorsTest extends TestCase
{
    public function test_multiplication_is_precise(): void
    {
        $this->assertSame('150.0000', MoneyService::mul('12.5', '12'));
        $this->assertSame('0.0000', MoneyService::mul('0', '999999'));
    }

    public function test_strict_greater_than(): void
    {
        $this->assertTrue(MoneyService::gt('5.0001', '5.0000'));
        $this->assertFalse(MoneyService::gt('5.0000', '5.0000')); // ليست أكبر تماماً
    }

    public function test_equality(): void
    {
        $this->assertTrue(MoneyService::eq('100', '100.0000'));
        $this->assertTrue(MoneyService::eq('100.00', '100'));
        $this->assertFalse(MoneyService::eq('100.0001', '100.0000'));
    }

    public function test_compare_returns_sign(): void
    {
        $this->assertSame(-1, MoneyService::compare('1', '2'));
        $this->assertSame(0, MoneyService::compare('2.0000', '2'));
        $this->assertSame(1, MoneyService::compare('3', '2'));
    }

    public function test_is_positive_and_non_negative(): void
    {
        $this->assertTrue(MoneyService::isPositive('0.0001'));
        $this->assertFalse(MoneyService::isPositive('0'));
        $this->assertFalse(MoneyService::isPositive('-0.0001'));

        $this->assertTrue(MoneyService::isNonNegative('0'));
        $this->assertTrue(MoneyService::isNonNegative('0.0001'));
        $this->assertFalse(MoneyService::isNonNegative('-0.0001'));
    }

    public function test_negative_values_normalize_and_subtract(): void
    {
        $this->assertSame('-50.2500', MoneyService::normalize('-50.25'));
        $this->assertSame('-30.0000', MoneyService::sub('-10', '20'));
        $this->assertSame('10.0000', MoneyService::add('-10', '20'));
    }
}
