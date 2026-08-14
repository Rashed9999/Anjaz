<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-CASHIER-BARCODE-002 — **ماسحان لأمرٍ واحد، والأضعفُ للأكثر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قُورن مسحُ باركود أميال بتطبيق نقاط بيعٍ مفتوحِ المصدر (فيديو أرسله
 * صاحبُ المشروع)، فبان أنّ أميال يملك **أكثرَ** ممّا في الفيديو: طباعةً
 * حراريّةً ببلوتوث، وطابورَ بيعٍ دون اتّصال، ومخزوناً وجرداً، ومحفظةً.
 *
 * **والفرقُ الوحيدُ الحقيقيّ كان في شاشةٍ واحدة**: الفيديو يعرض السلّةَ
 * تحت الكاميرا وهي تنمو — بالأسماء والأسعار وأزرار الكمّيّة والمجموع.
 * وأميال يعرض في التجزئة سطراً واحداً: «أُضيف: ٧ صنف».
 *
 * **وهو رقمٌ لا يُراجَع**: من مسح صنفاً مرّتين لا يعرف، ومن مسح الصنفَ
 * الخطأ لا يجد حذفاً، ومن سأله الزبونُ عن المجموع لا يجيب حتّى يخرج من
 * الشاشة.
 *
 * **وأمرُّ ما في القياس**: العلاجُ كان مبنيّاً في المشروع منذ جولات —
 * `ContinuousScannerScreen` تعرض كلَّ ذلك — **وتستعملها الجملةُ
 * والصيدليّةُ، والتجزئةُ لا.** فأُصلح مدخلٌ وتُرك الآخر، والمتروكُ يخدم
 * أكثرَ الأصناف عدداً.
 *
 * (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها. وهذا الحارسُ
 * يُلزم **التكافؤ**: أيُّ ماسحٍ يبني سلّةً يعرضها ويسمح بتصحيحها.)
 */
class ScanReviewParityGuardTest extends TestCase
{
    /** الماسحاتُ التي تبني سلّةً — ولكلٍّ مدخلُه في التطبيق. */
    private const CART_SCANNERS = [
        'التجزئة (الكاشير)' => 'lib/features/merchant/screens/cashier_scan_screen.dart',
        'الجملة والصيدليّة' => 'lib/features/barcode/screens/continuous_scanner_screen.dart',
    ];

    private function source(string $rel): string
    {
        $path = base_path('../02_flutter_app/' . $rel);

        $this->assertFileExists($path, "ملفٌّ مفقود: {$rel}");

        // التعليقاتُ تُنزع — **حارسٌ مرّ ثلاثَ مرّاتٍ في هذا المشروع على
        // تعليقٍ عربيٍّ يشرح العطلَ فأخفاه.**
        return preg_replace('#^\s*//.*$#m', '', (string) file_get_contents($path)) ?? '';
    }

    /**
     * **كلُّ ماسحٍ يعرض ما جمعه وهو يجمعه.**
     */
    public function test_every_cart_scanner_shows_the_cart_while_scanning(): void
    {
        foreach (self::CART_SCANNERS as $label => $rel) {
            $src = $this->source($rel);

            $this->assertMatchesRegularExpression(
                '/ListView\.(builder|separated)/', $src,
                "ماسحُ «{$label}» بلا قائمةٍ للأصناف — يمسح الكاشيرُ أعمى");

            $this->assertStringContainsString('maxHeight', $src,
                "قائمةُ «{$label}» بلا ارتفاعٍ محدود — تأكل الكاميرا فيتوقّف المسح");
        }
    }

    /** **والمجموعُ يُرى قبل الخروج** — لا بعده. */
    public function test_every_cart_scanner_shows_a_running_total(): void
    {
        foreach (self::CART_SCANNERS as $label => $rel) {
            $src = $this->source($rel);

            $this->assertMatchesRegularExpression(
                '/(cartTotal|_total)\b/', $src,
                "ماسحُ «{$label}» لا يعرض مجموعاً — والزبونُ يسأل كم عليه");

            $this->assertStringContainsString('ر.ي', $src,
                "مبلغٌ بلا عملة في ماسح «{$label}»");
        }
    }

    /** **والخطأُ يُصحَّح من حيث وقع** — لا بالخروج وإعادة الدخول. */
    public function test_every_cart_scanner_can_fix_a_mis_scan(): void
    {
        foreach (self::CART_SCANNERS as $label => $rel) {
            $src = $this->source($rel);

            foreach (['Icons.add' => 'زيادةُ كمّيّة',
                      'Icons.remove' => 'إنقاصُ كمّيّة',
                      'Icons.delete_outline' => 'حذفُ سطر'] as $needle => $what) {
                $this->assertStringContainsString($needle, $src,
                    "ماسحُ «{$label}» بلا {$what} — ومسحةٌ خاطئةٌ تُصحَّح "
                    . 'بالخروج من الشاشة أو لا تُصحَّح');
            }
        }
    }

    /**
     * **ولا نسخةَ ثانيةً من سلّة التجزئة.**
     *
     * سلّةُ `CashierController` هي التي تُقرأ في نقطة البيع وفي تسجيل
     * البيع. فلو بنى الماسحُ سلّةً محلّيّةً خاصّةً به لصار رقمان لا
     * يلتقيان: يعرض الماسحُ ٨٠٠٠ وتحسب نقطةُ البيع غيرَها.
     * (القاعدة السادسة: الرقمُ يُحسب من مصدره.)
     */
    public function test_the_retail_scanner_reads_the_one_cart(): void
    {
        $src = $this->source(self::CART_SCANNERS['التجزئة (الكاشير)']);

        $this->assertStringContainsString('c.cart', $src,
            'ماسحُ التجزئة لا يقرأ سلّةَ المتحكّم');

        $this->assertStringContainsString('c.cartTotal', $src,
            'المجموعُ محسوبٌ في الشاشة لا مقروءٌ من مصدره');

        $this->assertMatchesRegularExpression('/c\.(incLine|decLine|removeLine)/', $src,
            'الشاشةُ تعدّل السلّةَ بنفسها بدل أن تنادي المتحكّم');

        // ولا سلّةَ محلّيّةً تُنشأ هنا.
        $this->assertStringNotContainsString('final List<CartLine> _cart', $src,
            'سلّةٌ ثانيةٌ في الماسح — رقمان لا يلتقيان');
    }

    /**
     * **وما في الفيديو موجودٌ عندنا — يُثبَت لا يُدَّعى.**
     *
     * الطباعةُ الحراريّةُ والعملُ دون اتّصال هما ما يميّز ذلك التطبيق،
     * وكلاهما مبنيٌّ في أميال. والحارسُ يمنع سقوطَهما صامتين.
     */
    public function test_thermal_printing_and_offline_selling_exist(): void
    {
        $pubspec = (string) file_get_contents(base_path('../02_flutter_app/pubspec.yaml'));

        $this->assertStringContainsString('print_bluetooth_thermal', $pubspec,
            'حزمةُ الطباعة الحراريّة سقطت — والإيصالُ الورقيّ لا يُطبع');

        foreach ([
            'lib/features/printer/services/thermal_print_service.dart' => 'خدمةُ الطباعة',
            'lib/features/printer/screens/printer_settings_screen.dart' => 'شاشةُ الطابعة',
            'lib/features/merchant/services/offline_sale_queue.dart' => 'طابورُ البيع دون اتّصال',
            'lib/features/merchant/screens/offline_sales_screen.dart' => 'شاشةُ المبيعات المعلّقة',
        ] as $rel => $what) {
            $this->assertFileExists(base_path('../02_flutter_app/' . $rel),
                "{$what} مفقودة");
        }

        // **وطابورٌ لا يُملأ ليس طابوراً**: البيعُ يدخله عند انقطاع الشبكة.
        $ctrl = $this->source('lib/features/merchant/controllers/cashier_controller.dart');

        $this->assertStringContainsString('OfflineSaleQueue', $ctrl,
            'الطابورُ مبنيٌّ ولا يُوصَل إليه من مسار البيع');

        $this->assertStringContainsString('enqueue(', $ctrl);
    }
}
