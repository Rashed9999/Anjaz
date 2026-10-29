<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SECTOR-PAY-UNIFY-001 — **«لا أعلم أيٌّ منهم مرتبطٌ الآجلُ فيه
 * بنظام الديون».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * أرسل صاحبُ المشروع شاشتَي دفعٍ متباعدتين — تجزئةٍ وصيدليّة — وسأل عن
 * الآجل، وطلب **التوحيد لكلّ القطاعات**.
 *
 * **وقِيس فالجوابُ أنّ القطاعات التجارية الأربعة موصولةٌ كلُّها**:
 *
 *     تجزئة/سريع → CashierService      → CustomerCreditService
 *     صيدليّة    → PharmacySaleService → CustomerCreditService
 *     وقود       → FuelStationService  → CustomerCreditService
 *     جملة       → WholesaleInvoiceService → CustomerCreditService
 *
 * **فالعطلُ ليس في الوصل بل في الصمت عنه** — وفي أنّ الصيدليّة بلا خصم.
 *
 * وهذا الحارسُ يُثبّت الأمرين: **أن يبقى الوصلُ**، وأن **تقوله الشاشة**.
 */
class SectorPaymentUnifiedGuardTest extends TestCase
{
    private function backend(string $rel): string
    {
        $p = base_path($rel);
        $this->assertFileExists($p, "مفقود: {$rel}");

        return (string) file_get_contents($p);
    }

    private function app(string $rel): string
    {
        $p = dirname(base_path()).'/02_flutter_app/'.$rel;
        $this->assertFileExists($p, "مفقود: {$rel}");

        return (string) file_get_contents($p);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① كلُّ قطاعٍ يقبل «آجل» يفتح ديناً في النظام الموحّد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فقطاعٌ يقبل الآجلَ ولا يكتب ديناً **يبيع بالمجّان**: البضاعةُ تخرج،
     * والذمّةُ لا تُقيَّد في أيّ مكان، ولا يعرف صاحبُ المتجر أنّ له عند
     * أحدٍ شيئاً. **ولا خطأَ في أيّ سجلّ** — البيعةُ تُسجَّل وتُطبَع.
     */
    /** @test */
    public function every_sector_that_accepts_credit_writes_it_to_the_debt_ledger(): void
    {
        // القطاعُ ⇒ [ملفُّ المتحكّم، اسم الحقل، ملفات الخدمة].
        // الجملة تستعمل `payment_type`، لا `payment_method`؛ تجاهلُ الفرق
        // يجعل الحارس أخضر بينما أهم فاتورة آجل لا تُفحَص أصلاً.
        $sectors = [
            'تجزئة/سريع' => [
                'app/Http/Controllers/Api/V1/Amial/CashierController.php',
                'payment_method',
                ['app/Services/CashierService.php'],
            ],
            'صيدليّة' => [
                'app/Http/Controllers/Api/V1/Amial/PharmacyController.php',
                'payment_method',
                ['app/Services/PharmacySaleService.php'],
            ],
            'وقود' => [
                'app/Http/Controllers/Api/V1/Amial/FuelStationController.php',
                'payment_method',
                ['app/Services/FuelStationService.php'],
            ],
            'جملة' => [
                'app/Http/Controllers/Api/V1/Amial/WholesaleController.php',
                'payment_type',
                ['app/Services/WholesaleInvoiceService.php'],
            ],
        ];

        $unwired = [];
        $checked = 0;

        foreach ($sectors as $label => [$controller, $paymentField, $services]) {
            $ctl = $this->backend($controller);

            if (!preg_match("~{$paymentField}[^\n]*credit~", $ctl)) {
                continue;
            }

            $checked++;

            $wired = false;
            foreach ($services as $svc) {
                if (str_contains($this->backend($svc), 'CustomerCreditService')) {
                    $wired = true;
                    break;
                }
            }

            if (!$wired) {
                $unwired[] = sprintf('%s (%s)', $label, implode(' · ', $services));
            }
        }

        // **ولا يمرّ الحارسُ على لا شيء** — مرشِّحٌ عمي يخرج أخضرَ على صفر.
        $this->assertSame(4, $checked,
            "لم يُفحَص إلّا {$checked} قطاعاً — المرشِّحُ لا يرى «credit» في عقد قطاع تجاري.");

        $this->assertSame([], $unwired, sprintf(
            "**قطاعاتٌ تقبل البيعَ الآجلَ ولا تكتب ديناً:**\n  %s\n\n"
            .'فالبضاعةُ تخرج والذمّةُ لا تُقيَّد — **بيعٌ بالمجّان**، ولا '
            .'خطأَ في أيّ سجلّ: البيعةُ تُسجَّل وتُطبَع.',
            implode("\n  ", $unwired)));
    }

    /**
     * ①-ب البيع الآجل ليس قيداً وحيد الاتجاه. إن أُبطلت الفاتورة أو قبل
     * التاجر مرتجعاً، يجب أن يتغيّر كشف الديون الموحد كما يتغيّر رصيد
     * عميل الجملة؛ وإلا صارت الفاتورة «ملغاة» والعميل ما زال مديناً بها.
     */
    /** @test */
    public function wholesale_credit_has_a_complete_unified_debt_lifecycle(): void
    {
        $invoice = $this->backend('app/Services/WholesaleInvoiceService.php');
        $returns = $this->backend('app/Services/WholesaleReturnService.php');

        $this->assertStringContainsString('بيع الجملة الآجل يحتاج رقم هاتف العميل', $invoice,
            'لا تقبل فاتورة آجل بلا هوية عميل قابلة للربط بالدفتر الموحد');
        $this->assertStringContainsString("referenceType: 'wholesale_invoice'", $invoice);
        $this->assertStringContainsString("referenceType: 'wholesale_invoice_void'", $invoice,
            'إبطال فاتورة الآجل لا يعكس قيد الدفتر الموحد');
        $this->assertStringContainsString('recordReturn(', $invoice);
        $this->assertStringContainsString("referenceType: 'wholesale_return'", $returns,
            'مرتجع الجملة لا يترك ديناً قائماً في الدفتر الموحد');
        $this->assertStringContainsString('recordReturn(', $returns);
    }

    /**
     * **② وخصمُ الصيدليّة له حقلٌ في الشاشة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `_discountCtrl` كان **معرَّفاً ويُرسَل في نداءي البيع كليهما** ولا
     * حقلَ له إطلاقاً — فيُرسَل **فارغاً أبداً**، والصيدليُّ لا يستطيع
     * خصمَ ريالٍ واحد. والخادمُ يقبله منذ البداية.
     *
     * **مبنيٌّ ولا يُوصَل إليه** — وهو نمطُ العطل الأكثر تكراراً هنا.
     */
    /** @test */
    public function the_pharmacy_discount_field_is_actually_on_screen(): void
    {
        $screen = $this->app('lib/features/pharmacy/screens/pharmacy_sale_screen.dart');

        $this->assertStringContainsString('discountAmount: _discountCtrl', $screen,
            'الخصمُ لم يعد يُرسَل — راجع الحارس');

        // **والقياسُ على وجود حقلِ إدخالٍ مربوطٍ به**، لا على وجود المتغيّر:
        // المتغيّرُ كان موجوداً طوال الوقت، وهو بعينه ما أخفى العطل.
        $this->assertMatchesRegularExpression(
            '~TextField\(\s*controller:\s*_discountCtrl~',
            $screen,
            '**لا حقلَ إدخالٍ للخصم في شاشة الصيدليّة** — `_discountCtrl` '
            .'معرَّفٌ ويُرسَل، فيذهب فارغاً أبداً. (والخادمُ يقبل '
            .'`discount_amount` منذ البداية.)');

        // والخادمُ ما زال يقبله — فحقلٌ يُرسِل ما يُرفَض ليس إصلاحاً.
        $this->assertStringContainsString('discount_amount',
            $this->backend('app/Http/Controllers/Api/V1/Amial/PharmacyController.php'),
            'الخادمُ لم يعد يقبل الخصمَ في الصيدليّة');
    }

    /**
     * **③ ولافتةُ الآجل نصٌّ واحدٌ مشترك.**
     *
     * فنصّان في شاشتين يفترقان بعد أوّل تعديل، فيقول أحدُهما ما لا يقوله
     * الآخر **عن الفعل نفسِه**. وهو ما طلبه صاحبُ المشروع بـ«التوحيد».
     *
     * ── وحدُّ هذا الحارس يُقال ولا يُدَّعى غيرُه ────────────────────────
     *
     * **يمسك الحذفَ ولا يمسك الإخفاء.** جُرّب بالعكس: أُحيطت اللافتةُ
     * بـ`if (false)` **فبقي أخضر** — لأنّه يقرأ وجودَ الاسم في الملفّ لا
     * أنّه يُرسَم على الشاشة.
     *
     * وهو **الانحدارُ الواقعيّ** الذي يحميه (حذفُ الاستعمال في إعادة
     * صياغة)، أمّا إثباتُ الرسم فيحتاج اختبارَ عنصرٍ يبني الشاشةَ بحالة
     * `credit` — وهو غيرُ مبنيٍّ هنا. **يُقال لئلّا يُقرأ الحارسُ أقوى
     * ممّا هو.**
     */
    /** @test */
    public function the_credit_notice_is_one_shared_widget_across_sectors(): void
    {
        $widget = dirname(base_path())
            .'/02_flutter_app/lib/features/merchant/widgets/credit_sale_notice.dart';

        $this->assertFileExists($widget,
            '**لا أداةَ مشتركةً للافتة الآجل** — فكلُّ شاشةٍ تكتب نصَّها.');

        $missing = [];
        foreach ([
            'الكاشير (تجزئة · سريع · مطعم)' => 'lib/features/merchant/screens/cashier_payment_screen.dart',
            'الصيدليّة' => 'lib/features/pharmacy/screens/pharmacy_sale_screen.dart',
            'الوقود' => 'lib/features/fuel_station/screens/fuel_sale_screen.dart',
            'الجملة' => 'lib/features/wholesale/screens/wholesale_workflow_screens.dart',
        ] as $label => $rel) {
            if (!str_contains($this->app($rel), 'CreditSaleNotice')) {
                $missing[] = "{$label} ({$rel})";
            }
        }

        $this->assertSame([], $missing, sprintf(
            "**شاشاتٌ لا تقول ما يفعله «آجل»:**\n  %s\n\n"
            .'وسؤالُ صاحب المشروع كان بنصّه: «لا أعلم أيٌّ منهم مرتبطٌ '
            .'الآجلُ فيه بنظام الديون» — وهو سؤالٌ لا يُجاب من الشاشة.',
            implode("\n  ", $missing)));
    }

    /**
     * ④ لا تعود كل شاشة إلى أزرارها الخاصة، ولا يضيع إيصال الوقود بعد
     * النقد أو الآجل. الحارس بنيوي؛ التحقق التفاعلي يبقى ضمن بناء Flutter.
     */
    /** @test */
    public function every_commercial_sale_screen_uses_the_shared_payment_contract(): void
    {
        $picker = 'lib/features/merchant/widgets/merchant_payment_method_picker.dart';
        $this->assertFileExists(dirname(base_path()).'/02_flutter_app/'.$picker,
            'مكوّن وسائل الدفع الموحّد غير موجود');

        $missing = [];
        foreach ([
            'تجزئة/سريع' => 'lib/features/merchant/screens/cashier_payment_screen.dart',
            'صيدليّة' => 'lib/features/pharmacy/screens/pharmacy_sale_screen.dart',
            'وقود' => 'lib/features/fuel_station/screens/fuel_sale_screen.dart',
            'جملة' => 'lib/features/wholesale/screens/wholesale_workflow_screens.dart',
        ] as $label => $rel) {
            $src = $this->app($rel);
            if (!str_contains($src, 'MerchantPaymentMethodPicker')) {
                $missing[] = "{$label} ({$rel})";
            }
        }
        $this->assertSame([], $missing, sprintf(
            "**شاشات بيع عادت لخيارات دفع منفصلة:**\n  %s",
            implode("\n  ", $missing)));

        $fuelSale = $this->app('lib/features/fuel_station/screens/fuel_sale_screen.dart');
        $fuelReceipt = $this->app('lib/features/fuel_station/screens/fuel_receipt_screen.dart');
        $wholesale = $this->app('lib/features/wholesale/screens/wholesale_workflow_screens.dart');

        $this->assertStringContainsString('void _openReceipt()', $fuelSale);
        $this->assertStringContainsString('FuelReceiptScreen(', $fuelSale,
            'بيع الوقود الناجح لا يصل إلى الفاتورة الموحدة');
        $this->assertStringContainsString("'credit' => 'آجل'", $fuelReceipt,
            'فاتورة الوقود تعرض كود payment_method بدلاً من «آجل»');
        $this->assertStringContainsString('double get _taxAmount', $wholesale);
        $this->assertStringContainsString('taxRate: _taxRateSource', $wholesale,
            'QR الجملة لا يثبّت ضريبة الإجمالي قبل إنشاء الفاتورة');
    }
}
