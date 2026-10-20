<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-ROUTE-CONTRACT-001
 *
 * يمنع رجوع العطل «المتحكم والقالب موجودان لكن route() غير موجود».
 * هذا النوع أسقط صفحات إنتاجية بـ500 رغم أن كل قطعة بدت سليمة منفردة.
 */
class AdminCriticalRouteContractTest extends TestCase
{
    /** @test */
    public function critical_admin_centers_have_named_guarded_routes(): void
    {
        $contracts = [
            'admin.amial.saher.index' => 'platform:saher.view',
            'admin.amial.saher.show' => 'platform:saher.findings.view',
            'admin.amial.saher.scan' => 'platform:saher.scan.run',
            'admin.amial.saher.rule' => 'platform:saher.findings.suppress',

            'admin.amial.merchants.verification.page' => 'platform:platform.merchants.compliance',
            'admin.amial.merchants.verification.list' => 'platform:platform.merchants.compliance',
            'admin.amial.merchants.verification.document' => 'platform:platform.merchants.compliance',
            'admin.amial.merchants.verification.approve' => 'platform:platform.approvals.decide',
            'admin.amial.merchants.verification.reject' => 'platform:platform.approvals.decide',
            'admin.amial.merchants.verification.resubmit' => 'platform:platform.approvals.decide',

            // نقطة القرار الحديثة في مركز التوثيق الموحد؛ الأبواب القديمة باقية للتوافق.
            'admin.amial.kyc.center.queue' => 'platform:platform.customers.kyc.view',
            'admin.amial.kyc.center.merchant-decision' => 'platform:platform.approvals.decide',
            'admin.amial.kyc.center.merchant-document' => 'platform:platform.merchants.compliance',

            'admin.amial.kyc.changes.page' => 'platform:platform.customers.kyc.view',
            'admin.amial.kyc.changes.open' => 'platform:platform.customers.kyc.request',
            'admin.amial.kyc.changes.decide' => 'platform:platform.approvals.decide',
            'admin.amial.kyc.changes.identity-state' => 'platform:platform.customers.kyc.view',
        ];

        foreach ($contracts as $name => $requiredMiddleware) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "المسار {$name} غير مسجّل");
            $this->assertContains(
                $requiredMiddleware,
                $route->gatherMiddleware(),
                "المسار {$name} موجود بلا الصلاحية المطلوبة {$requiredMiddleware}"
            );
        }
    }

    /** @test */
    public function every_restored_center_is_reachable_from_the_correct_admin_surface(): void
    {
        $general = (string) file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php')
        );
        $customer = (string) file_get_contents(
            resource_path('views/admin-views/amial/customer/index.blade.php')
        );

        $this->assertStringContainsString(
            "route('admin.amial.saher.index')",
            $general,
            'رادار ساهر بلا مدخل في لوحة الإدارة'
        );

        // AMIAL-KYC-ONE-CENTER-001: توثيق المنشأة يظهر داخل مركز التحقق
        // الموحّد، ولا نعيد إنشاء رابط مستقل يتجاوز ملف العميل وصلاحياته.
        $this->assertStringContainsString(
            "route('admin.amial.kyc.page')",
            $general,
            'مركز التوثيق الموحد بلا مدخل في لوحة الإدارة'
        );

        $verificationCenter = (string) file_get_contents(
            resource_path('views/admin-views/amial/kyc/center.blade.php')
        );
        $this->assertStringContainsString(
            'data-action="merchant-approve"',
            $verificationCenter,
            'لا يظهر قرار اعتماد المنشأة داخل مركز التوثيق الموحد'
        );
        $this->assertStringContainsString(
            "/merchant-verification",
            $verificationCenter,
            'زر اعتماد المنشأة لا يصل إلى مسار القرار الموحد'
        );

        // تحديث بيانات العميل أداة داخل مركز العملاء، لا مدخل عميل ثانٍ.
        $this->assertStringContainsString(
            "route('admin.amial.kyc.changes.page')",
            $customer,
            'طلبات تحديث بيانات العميل انفصلت عن مركز العملاء الموحد'
        );
    }
}
