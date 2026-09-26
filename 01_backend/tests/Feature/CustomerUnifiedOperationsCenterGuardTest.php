<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-CENTER-OPS-001 + AMIAL-CUSTOMER-CENTER-NAV-003
 *
 * العميل له باب إداري واحد. الطوابير المتخصصة لا تختفي؛ تُحمّل من مصادرها
 * الأصلية داخل مركز العملاء وبالصلاحيات نفسها.
 */
class CustomerUnifiedOperationsCenterGuardTest extends TestCase
{
    public function test_embedded_customer_operation_routes_keep_their_permissions(): void
    {
        $expected = [
            'admin.amial.customer.ops.kyc' => 'platform:platform.customers.kyc.view',
            'admin.amial.customer.ops.changes' => 'platform:platform.customers.kyc.view',
            'admin.amial.customer.ops.systems' => 'platform:platform.audit.view',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "المسار {$name} غير مسجل");
            $this->assertContains(
                $permission,
                $route->gatherMiddleware(),
                "المسار {$name} فقد صلاحية {$permission}"
            );
        }
    }

    public function test_unified_customer_screen_contains_the_operational_tabs_and_inline_loaders(): void
    {
        $view = (string) file_get_contents(
            resource_path('views/admin-views/amial/customer/index.blade.php')
        );

        foreach ([
            'data-op="customers"',
            'data-op="kyc"',
            'data-op="changes"',
            'data-op="systems"',
            "get('/ops/' + name)",
            'renderOpsKyc',
            'renderOpsChanges',
            'renderOpsSystems',
            'js-ops-open-customer',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $view,
                "جزء من مركز العملاء الموحد غير موصول: {$needle}"
            );
        }
    }

    public function test_sidebar_has_one_customer_door_not_the_old_customer_surfaces(): void
    {
        $sidebar = (string) file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php')
        );

        $this->assertSame(
            1,
            substr_count($sidebar, "route('admin.amial.customer.page')"),
            'مركز العملاء يجب أن يظهر مرة واحدة في الشريط الجانبي'
        );

        foreach ([
            'admin.amial.hub.customers',
            'admin.amial.customer-systems.index',
            'admin.amial.kyc.changes.page',
            'admin.amial.hub.verification',
        ] as $route) {
            $this->assertStringNotContainsString(
                "route('{$route}'",
                $sidebar,
                "عاد باب عميل مكرر إلى الشريط: {$route}"
            );
        }
    }

    public function test_cross_role_account_approval_stays_outside_customer_only_kyc(): void
    {
        $sidebar = (string) file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php')
        );

        // بعد دمج العميل والتاجر والوكيل، لم يعد قرارهم في صفحة قديمة ثانية.
        $this->assertSame(
            1,
            substr_count($sidebar, "route('admin.amial.kyc.page')"),
            'مركز التحقق والهوية يجب أن يكون باب المراجعة الوحيد في الشريط'
        );
        $center = (string) file_get_contents(
            app_path('Http/Controllers/Admin/UnifiedVerificationCenterController.php')
        );
        $this->assertStringContainsString(
            '[CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE]',
            $center,
            'ملف التحقق الموحّد يجب أن يشمل العملاء والوكلاء والتجار'
        );
    }
}
