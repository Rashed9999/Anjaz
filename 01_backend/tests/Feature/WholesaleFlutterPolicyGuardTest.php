<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-WHOLESALE-ACCESS-001 — يمنع رجوع المداخل العامة إلى Pro مباشرةً
 * أو عودة أزرار مالية بلا action-level gate.
 */
class WholesaleFlutterPolicyGuardTest extends TestCase
{
    /** @test */
    public function public_wholesale_routes_only_open_policy_screens(): void
    {
        $src = file_get_contents(base_path(
            '../02_flutter_app/lib/features/wholesale/screens/wholesale_screens.dart'
        ));

        $this->assertStringContainsString('wholesale_policy_screens.dart', $src);
        $this->assertStringNotContainsString('wholesale_pro_screens.dart', $src);

        foreach ([
            'WholesalePolicyDashboardScreen',
            'WholesalePolicyProductsScreen',
            'WholesalePolicyCustomersScreen',
            'WholesalePolicyInvoiceCreateScreen',
            'WholesalePolicyInvoicesScreen',
            'WholesalePolicyAgingScreen',
            'WholesalePolicyCustomerStatementScreen',
        ] as $screen) {
            $this->assertStringContainsString($screen, $src, "المدخل العام لا يمر عبر {$screen}");
        }
    }

    /** @test */
    public function money_buttons_are_bound_to_exact_wholesale_actions(): void
    {
        $src = file_get_contents(base_path(
            '../02_flutter_app/lib/features/wholesale/screens/wholesale_policy_screens.dart'
        ));

        foreach ([
            "access.allows('invoice.create')",
            "access.allows('collection.record')",
            "access.allows('invoice.void')",
            "access.allows('product.update')",
            "access.allows('customer.manage')",
        ] as $guard) {
            $this->assertStringContainsString($guard, $src,
                "زر/فعل حساس بلا حارس Flutter: {$guard}");
        }
    }

    /** @test */
    public function wholesale_dashboard_does_not_advertise_fake_return_or_fake_multi_pricing_ui(): void
    {
        $src = file_get_contents(base_path(
            '../02_flutter_app/lib/features/wholesale/screens/wholesale_policy_screens.dart'
        ));

        // المرتجع الحالي العام ليس دورة مرتجع جملة، وواجهة التسعير القديمة
        // كانت SnackBar يحيل إلى مكان آخر. لا نبيعهما كبطاقتين تعملان.
        $this->assertStringNotContainsString('MerchantRefundScreen', $src);
        $this->assertStringNotContainsString("action: 'price.view'", $src);
    }

    /** @test */
    public function flutter_consumes_server_action_snapshot_instead_of_plan_name_switches(): void
    {
        $src = file_get_contents(base_path(
            '../02_flutter_app/lib/features/wholesale/controllers/wholesale_access_controller.dart'
        ));

        $this->assertStringContainsString('/merchant/wholesale/access', $src);
        $this->assertStringContainsString("state(String action)", $src);
        $this->assertStringNotContainsString("switch (plan.value)", $src);
        $this->assertStringNotContainsString("plan.value == 'business'", $src);
        $this->assertStringNotContainsString("plan.value == 'merchant_pro'", $src);
    }
}
