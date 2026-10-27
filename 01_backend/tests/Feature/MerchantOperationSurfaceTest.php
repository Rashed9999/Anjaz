<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-MERCHANT-SURFACE-001 — تشغيل التاجر من التطبيق، لا من قالب 6cash.
 *
 * ليس «إخفاء رابط»؛ لو عاد تحميل routes/merchant.php بالخطأ فسيعود سطح
 * عمليات ثانٍ لا يملك قدرات القطاعات ولا حراسة الـAPI. لذلك نثبت أن أسماء
 * المسارات التشغيلية القديمة غير مسجّلة، بينما نقاط التطبيق هي المسار الحي.
 */
class MerchantOperationSurfaceTest extends TestCase
{
    public function test_the_legacy_merchant_web_portal_is_not_registered(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['merchant.dashboard', 'merchant.auth.login', 'merchant.withdraw.list'] as $name) {
            $this->assertNull($routes->getByName($name),
                "{$name} أعاد تشغيل بوابة تاجر ويب قديمة بجانب التطبيق");
        }
    }

    public function test_active_owner_portal_and_financial_contracts_remain_registered(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['merchant.web.login', 'merchant.web.dashboard', 'merchant.web.data.wallet',
            'merchant.web.data.debts', 'merchant.web.data.debts.collect.wallet.confirm'] as $name) {
            $this->assertNotNull($routes->getByName($name), $name);
        }
        $this->assertContains('merchant.web', $routes->getByName('merchant.web.data.wallet')->gatherMiddleware());
        $this->assertArrayNotHasKey('merchant', app('router')->getMiddleware());
    }

    public function test_the_mobile_merchant_api_remains_registered(): void
    {
        $route = app('router')->getRoutes()->getByName('amial.merchant.quote');

        $this->assertNotNull($route, 'لا يوجد مدخل API حيّ لتطبيق التاجر');
        $this->assertSame(['POST'], $route->methods());
    }
}
