<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-QUICK-SALE-DOC-001
 *
 * بيعٌ بلا أصناف لا يجوز أن يطبع «لا توجد بنود» كأن بيانات الفاتورة
 * ناقصة. القطاع نفسه هو الذي يحدد أن المستند مبلغ مباشر.
 */
class QuickSaleInvoiceDocumentGuardTest extends TestCase
{
    public function test_quick_sale_pdf_is_a_direct_amount_invoice_not_an_empty_product_invoice(): void
    {
        $service = (string) file_get_contents(
            app_path('Services/CashierSaleInvoicePdfService.php')
        );
        $template = (string) file_get_contents(
            resource_path('views/pdf/cashier-sale-invoice.blade.php')
        );

        $this->assertStringContainsString(
            "'quick_sale' => 'فاتورة بيع سريع'",
            $service,
        );
        $this->assertStringContainsString(
            "@if(\$vertical === 'quick_sale')",
            $template,
        );
        $this->assertStringContainsString(
            'بيع سريع بمبلغ مباشر',
            $template,
        );

        $quickStart = strpos($template, "@if(\$vertical === 'quick_sale')");
        $quickEnd = strpos($template, '@else', $quickStart);
        $this->assertNotFalse($quickStart);
        $this->assertNotFalse($quickEnd);

        $quickBlock = substr($template, $quickStart, $quickEnd - $quickStart);
        $this->assertStringNotContainsString('لا توجد بنود مسجلة', $quickBlock);
        $this->assertStringNotContainsString('<th style="width:6%">#</th>', $quickBlock);
    }
}
