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
 * **وقِيس فالجوابُ أنّ الأربعةَ موصولةٌ كلُّها**:
 *
 *     تجزئة/سريع → CashierService      → CustomerCreditService
 *     صيدليّة    → PharmacySaleService → CustomerCreditService
 *     وقود       → FuelStationService  → CustomerCreditService
 *     مطعم       → closeOrder → CashierService (تفويضٌ لا نسخة)
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
        // القطاعُ ⇒ [ملفُّ المتحكّم (لقبول `credit`)، ملفُّ الخدمة (للوصل)]
        $sectors = [
            'تجزئة/سريع' => [
                'app/Http/Controllers/Api/V1/Amial/CashierController.php',
                ['app/Services/CashierService.php'],
            ],
            'صيدليّة' => [
                'app/Http/Controllers/Api/V1/Amial/PharmacyController.php',
                ['app/Services/PharmacySaleService.php'],
            ],
            'وقود' => [
                'app/Http/Controllers/Api/V1/Amial/FuelStationController.php',
                ['app/Services/FuelStationService.php'],
            ],
            // **والمطعمُ يفوّض ولا ينسخ** — فيُقبل وصلُه عبر الكاشير.
            'مطعم' => [
                'app/Http/Controllers/Api/V1/Amial/RestaurantController.php',
                ['app/Services/RestaurantService.php', 'app/Services/CashierService.php'],
            ],
        ];

        $unwired = [];
        $checked = 0;

        foreach ($sectors as $label => [$controller, $services]) {
            $ctl = $this->backend($controller);

            // لا يقبل الآجلَ أصلاً ⇒ لا يُطالَب بوصل.
            if (!preg_match("~payment_method[^\n]*credit~", $ctl)) {
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
        $this->assertGreaterThanOrEqual(4, $checked,
            "لم يُفحَص إلّا {$checked} قطاعاً — المرشِّحُ لا يرى «credit».");

        $this->assertSame([], $unwired, sprintf(
            "**قطاعاتٌ تقبل البيعَ الآجلَ ولا تكتب ديناً:**\n  %s\n\n"
            .'فالبضاعةُ تخرج والذمّةُ لا تُقيَّد — **بيعٌ بالمجّان**، ولا '
            .'خطأَ في أيّ سجلّ: البيعةُ تُسجَّل وتُطبَع.',
            implode("\n  ", $unwired)));
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
}
