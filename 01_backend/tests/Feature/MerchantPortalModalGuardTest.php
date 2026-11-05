<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-MERCHANT-MODAL-GUARD-001
 *
 * بوابة التاجر واجهة منتج واحدة؛ لا نسمح برجوع حوارات المتصفح
 * الأصلية التي تختلف بين Chrome/Android وتكسر تجربة الهاتف.
 */
class MerchantPortalModalGuardTest extends TestCase
{
    public function test_merchant_portal_never_uses_browser_native_dialogs(): void
    {
        $source = file_get_contents(
            base_path('resources/views/merchant-web/partials/dashboard-script.blade.php')
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('window.prompt(', $source);
        $this->assertStringNotContainsString('window.confirm(', $source);
        $this->assertStringNotContainsString('window.alert(', $source);

        $this->assertStringContainsString('function openMerchantModal(', $source);
        $this->assertStringContainsString('function amialConfirm(', $source);
        $this->assertStringContainsString('function amialPrompt(', $source);
        $this->assertStringContainsString('function amialSelect(', $source);
    }

    public function test_staff_and_pos_device_workflows_use_amial_modals(): void
    {
        $source = file_get_contents(
            base_path('resources/views/merchant-web/partials/dashboard-script.blade.php')
        );

        $this->assertStringContainsString("'إضافة موظف نقطة بيع'", $source);
        $this->assertStringContainsString("'الأدوار والصلاحيات'", $source);
        $this->assertStringContainsString("'تعديل جهاز POS · '", $source);
        $this->assertStringContainsString("'تفعيل جهاز نقطة بيع جديد'", $source);
        $this->assertStringContainsString("'مصدر الحركة النقدية'", $source);
    }
}
