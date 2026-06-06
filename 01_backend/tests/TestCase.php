<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * TestCase — الأساس الذي ترث منه جميع اختبارات Feature/Unit.
 *
 * كان مفقوداً (انظر FIXES.md). أُعيد إنشاؤه ليطابق سكافولد Laravel القياسي.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
