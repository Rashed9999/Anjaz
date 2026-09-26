<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-NEGATIVE-STOCK-001 — **حقلٌ يُرسَل ولا يُرسَم ليس ميزةً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `NegativeStockReachesTheMerchantGuardTest` يثبت أنّ الخادم **يحسب**
 * السالبَ ويضعه في الردّ. و`retail_ops_negative_stock_test.dart` يثبت أنّ
 * المتحكّم **يقرؤه**. **ولا واحدٌ منهما يثبت أنّ الشاشةَ ترسمه** — وهذا
 * بالضبط ما وقع: الحقلُ كان في الردّ منذ بُني، وقِيس بالبحث فلم يوجد في
 * التطبيق سطرٌ واحدٌ يذكر اسمَه.
 *
 * **وهذا الحارسُ يمسح الشيفرة ولا يعمل في وقت التشغيل** — وهو مقصود:
 * اختبارُ عنصرٍ يحتاج متحكّماً كاملاً وصلاحيّاتٍ ومحاكاةَ شبكة، فيصير
 * الحارسُ أثقلَ من الميزة ويُهمَل. **والمسحُ يُمسك العطلَ الواقع فعلاً**:
 * غيابَ الاسم من الشاشة.
 *
 * @see \Tests\Feature\NegativeStockReachesTheMerchantGuardTest
 */
class RetailOpsScreenReachabilityGuardTest extends TestCase
{
    private function screen(): string
    {
        $path = base_path('../02_flutter_app/lib/features/retail/screens/retail_ops_center_screen.dart');

        $this->assertFileExists($path, 'شاشةُ مركز التجزئة غير موجودة');

        return (string) file_get_contents($path);
    }

    /** @test */
    public function the_operations_screen_draws_the_negative_stock_the_server_sends(): void
    {
        $src = $this->screen();

        $this->assertStringContainsString('negativeStock', $src,
            'الخادمُ يُرسل `negative_stock` والشاشةُ لا تذكره — '
            .'فالميزةُ تنتهي عند JSON، ولا خطأَ في أيّ سجلّ');

        $this->assertStringContainsString('shortfall', $src,
            'يُعرض السالبُ بلا مقدارِ النقص — فيُقال «هناك خلل» ولا يُقال كم');

        // **ويُستدعى فعلاً لا يُعرَّف وحسب.** دالّةٌ مبنيّةٌ لا تُنادى هي
        // العطلُ نفسُه بثوبٍ أطول — وقد وقع في هذه الشاشة سلفاً حين بُني
        // زرُّ التعليق ولم يُوصَل.
        $this->assertMatchesRegularExpression('/_negativeStock\(\)\s*,/', $src,
            'دالّةُ العرض معرَّفةٌ ولا تُستدعى في شجرة العناصر — '
            .'فلا تُرسَم شيئاً (القاعدة التاسعة: ما لم يُضغط لم يُبنَ)');
    }

    /** @test */
    public function a_locked_section_says_it_is_locked_and_never_reads_as_zero(): void
    {
        $src = $this->screen();

        $this->assertStringContainsString('lowStockLocked', $src,
            'الخادمُ يُرسل `low_stock_locked` لتقول الشاشةُ «مقفول» — '
            .'وبدونه تُعرَض قائمةٌ فارغةٌ يقرؤها التاجرُ «مخزوني سليم» '
            .'وهو لم يُفحَص أصلاً (القاعدة السابعة)');

        $this->assertStringContainsString('unlock', $src,
            'يُقال إنّه مقفولٌ ولا يُقال بكم يُفتح — فقفلٌ بلا باب');
    }

    /**
     * AMIAL-SALES-BREAKDOWN-001 — **وشاشةٌ لا يُوصل إليها ليست مبنيّة.**
     *
     * تقريرُ المبيعات بالصنف بُني ومعه شاشتُه، **والمسارُ المسجَّل ليس
     * ظهوراً**: لا بدّ من زرٍّ يقود إليه من مكانٍ يمرّ به التاجر.
     * (القاعدة الثانية عشرة، وأختُها التاسعة.)
     *
     * @test
     */
    public function the_sales_breakdown_screen_has_a_door_a_merchant_walks_through(): void
    {
        $screen = base_path('../02_flutter_app/lib/features/merchant/screens/sales_breakdown_screen.dart');
        $this->assertFileExists($screen, 'شاشةُ المبيعات بالصنف غير موجودة');

        $entry = (string) file_get_contents(
            base_path('../02_flutter_app/lib/features/merchant/screens/profit_report_screen.dart'));

        $this->assertStringContainsString('SalesBreakdownScreen', $entry,
            'الشاشةُ مبنيّةٌ ولا بابَ إليها — وهذا نمطُ العطل الأكثر '
            .'تكراراً في أميال باي: مبنيٌّ ولا يُوصَل إليه');

        // **والصنفُ يُعرض بربحه لا بإيراده وحدَه** — وإلّا صار التقريرُ
        // نسخةً ثانيةً من «أكثر المبيعات» بلا الجواب الذي بُني له.
        $src = (string) file_get_contents($screen);
        $this->assertStringContainsString('breakdownCategories', $src,
            'تبويبُ التصنيفات لا يقرأ شيئاً');
        $this->assertStringContainsString('returned_qty', $src,
            'المرتجعُ مطروحٌ في الخادم ولا يُقال في الشاشة — '
            .'فيرى التاجرُ رقماً صغيراً ولا يعرف لماذا صغُر');
    }
}
