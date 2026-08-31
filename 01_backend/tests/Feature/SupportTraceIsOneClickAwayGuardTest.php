<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SUPPORT-TRACE-REACH-001 — **سطرٌ ميّتٌ فوق تتبّعٍ كامل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس:** قال صاحبُ المشروع «دخلتُ الدعمَ وأدخلتُ رقمَ
 * العمليّة فأعطاني قيمتَها فقط، لا معلوماتٍ أخرى».
 *
 * **والتتبّعُ الكاملُ مبنيٌّ كلُّه** — قِيس في `SupportConsoleController
 * ::transaction()`: هويّةُ العمليّة وقرارُها ومناطقُها الثلاث، والأثرُ
 * الماليُّ بالرصيد بعدَها، والأطراف، ومنفّذُ POS، والإيصالُ بتنزيلاته،
 * وسجلّاتُ النشاط، **وقيودُ الدفتر بأرصدةٍ قبلَ وبعدَ كلِّ سطر**،
 * والنزاعاتُ والتذاكرُ وخطٌّ زمنيٌّ مرتَّب.
 *
 * **وكان في تبويبٍ آخرَ يطلب إعادةَ كتابة الرقم**، ونتيجةُ البحث
 * `<li class="list-group-item">` — عنصرٌ **لا يُضغَط**. فمن بحث ووجد
 * السطرَ ظنّ أنّ هذا كلُّ ما عند المنصّة.
 *
 * **وهو نمطُ العطل الأكثرُ تكراراً في أميال باي**: مبنيٌّ ولا يُوصَل
 * إليه. (القاعدة الثانيةَ عشرة: صفحةٌ لا يُوصل إليها ليست مبنيّة.)
 */
class SupportTraceIsOneClickAwayGuardTest extends TestCase
{
    private const VIEW = 'resources/views/admin-views/support/console.blade.php';

    private const CTRL = 'app/Http/Controllers/Api/V1/Amial/SupportConsoleController.php';

    private function console(): string
    {
        $p = base_path(self::VIEW);

        $this->assertFileExists($p, 'منصّةُ الدعم اختفت من مكانها.');

        return (string) file_get_contents($p);
    }

    /**
     * **① صفُّ العمليّة في نتيجة البحث يُضغَط.**
     */
    /** @test */
    public function a_transaction_search_result_is_clickable(): void
    {
        $src = $this->console();

        $this->assertStringContainsString('data-testid="search-tx-row"', $src,
            '**صفُّ العمليّة عادَ عنصراً لا يُضغَط.** فمن بحث ووجده يقرأ '
            .'مبلغاً ويظنّ أنّ هذا كلُّ ما عند المنصّة — والتتبّعُ الكاملُ '
            .'مبنيٌّ خلف تبويبٍ يطلب إعادةَ كتابة الرقم.');

        $this->assertStringContainsString('data-trace=', $src,
            'الصفُّ بلا مرجعٍ يحمله — فالضغطةُ لا تعرف ماذا تفتح.');
    }

    /**
     * **② والضغطةُ لها معالجٌ ينقل ويشغّل معاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فنقلٌ بلا تشغيلٍ يترك الدعمَ أمام **حقلٍ مملوءٍ وزرٍّ لم يُضغَط** —
     * فيقرأ فراغاً ويظنّ أن لا نتيجة. وهو تبديلُ عطلٍ صامتٍ بآخر.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_click_both_switches_the_tab_and_runs_the_trace(): void
    {
        $src = $this->console();

        $this->assertStringContainsString("closest('[data-trace]')", $src,
            '**السمةُ موجودةٌ ولا معالجَ لها** — فالصفُّ يبدو قابلاً للضغط '
            .'ولا يفعل شيئاً، وهو أسوأُ من سطرٍ لا يُغري بالضغط.');

        $this->assertStringContainsString("getElementById('tx-ref')", $src,
            'الضغطةُ لا تملأ خانةَ المرجع.');

        $this->assertStringContainsString("getElementById('btn-tx').click()", $src,
            '**تُملأ الخانةُ ولا يُضغط الزرّ** — فيرى الدعمُ رقماً مكتوباً '
            .'ونتيجةً فارغة.');
    }

    /**
     * **③ ومرجعُ العمليّة يصل من الخادم مع الإيصال.**
     *
     * فصفُّ إيصالٍ يَعِد بالتتبّع بلا مرجعٍ يفتح **صفحةَ «غير موجودة»** —
     * ورقمُ الإيصال لا تطابقه نقطةُ التتبّع أصلاً.
     */
    /** @test */
    public function the_search_sends_the_receipts_transaction_reference(): void
    {
        $ctrl = (string) file_get_contents(base_path(self::CTRL));

        $this->assertStringContainsString("'reference_transaction_id' => \$r->reference_transaction_id", $ctrl,
            '**الإيصالُ يُرسَل بلا مرجعِ عمليّته** — فصفُّه إمّا سطرٌ ميّتٌ '
            .'وإمّا زرٌّ يفتح «العمليّة غير موجودة».');
    }

    /**
     * **④ والتتبّعُ نفسُه ما زال يُخرج ما بُني له.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فوصلةٌ إلى شاشةٍ أُفرغت من محتواها إصلاحٌ نصفيّ. والمحروسُ هنا
     * **الطبقاتُ التي تجعل التتبّعَ تتبّعاً**: الدفترُ هو الدليلُ الماليُّ
     * لا سجلُّ العمليّة وحدَه.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_trace_still_returns_every_layer_it_was_built_for(): void
    {
        $ctrl = (string) file_get_contents(base_path(self::CTRL));

        $missing = [];

        foreach ([
            "'parties'" => 'الأطراف',
            "'pos_actor'" => 'منفّذ نقطة البيع',
            "'receipt'" => 'الإيصال',
            "'ledger_entries'" => 'قيودُ الدفتر',
            "'timeline'" => 'الخطُّ الزمنيّ',
        ] as $needle => $label) {
            if (! str_contains($ctrl, $needle)) {
                $missing[] = $label;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "**طبقاتٌ سقطت من ردّ التتبّع:**\n  %s\n\n"
            .'فالوصلةُ تفتح شاشةً أفقرَ ممّا وُعد بها.',
            implode('، ', $missing)));
    }

    /**
     * **⑤ ورسمُ الفواتير صار موصولاً — والشاشةُ تقول ذلك.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان `BILL_PAY` مُعلَناً «غيرَ موصول» بسببٍ مكتوب: **قرارٌ ينتظر** —
     * أرسمُ المنصّة فوق رسم المزوّد أم بدله؟ وقاله صاحبُ المشروع:
     * **فوقه**. فرسمُ المزوّد تكلفةٌ تمرّ إليه، ورسمُ المنصّة أجرُ الخدمة.
     *
     * **وسببٌ باقٍ بعد وصله يكذب على الأدمن**: يقرأ «غيرُ موصولة» فلا
     * يضبط سعراً، والمحرّكُ ينتظره.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function bill_pay_is_wired_and_no_longer_declared_unwired(): void
    {
        $svc = (string) file_get_contents(base_path('app/Services/BillPayService.php'));

        $this->assertStringContainsString("calculate('BILL_PAY'", $svc,
            '**رسمُ المنصّة لا يُحسب في سداد الفواتير** — فالأدمنُ يضبطه '
            .'ولا يُخصَم منه ريال.');

        $this->assertNotContains('BILL_PAY',
            array_keys(\App\Support\Fees\FeeOperationRegistry::notWired()),
            '**`BILL_PAY` ما زال مُعلَناً «غيرَ موصول»** — فتقول الشاشةُ '
            .'للأدمن إنّ ضبطَه لا يُغيّر شيئاً، وهو موصولٌ الآن.');
    }

    /**
     * **⑥ ورسمُ المزوّد يبقى — لا يُستبدَل.**
     *
     * فـ«فوقه» تعني الجمعَ لا الإحلال. ومن حذف رسمَ المزوّد جعل المنصّةَ
     * تدفع تكلفتَه من جيبها في كلّ فاتورة.
     */
    /** @test */
    public function the_provider_fee_is_still_added_not_replaced(): void
    {
        $svc = (string) file_get_contents(base_path('app/Services/BillPayService.php'));

        $this->assertStringContainsString('fee_amount', $svc);
        $this->assertStringContainsString('fee_percent', $svc);

        $this->assertMatchesRegularExpression(
            '/add\(\$providerFee,\s*\$platformFee\)/u', $svc,
            '**رسمُ المنصّة حلَّ محلَّ رسم المزوّد بدل أن يُضاف فوقه** — '
            .'فتدفع المنصّةُ تكلفةَ المزوّد من جيبها في كلّ فاتورة.');
    }
}
