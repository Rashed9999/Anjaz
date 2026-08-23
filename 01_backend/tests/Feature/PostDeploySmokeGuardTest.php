<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-PROD-READINESS-006 — **سكربتٌ لا يعرف أحدٌ بوجوده ليس مبنيّاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في تدقيق الجاهزيّة:**
 *
 *   $ grep -inE 'rollback|smoke' docs/DEPLOYMENT_GUIDE.md
 *   (صفرُ نتائج)
 *
 * دليلُ النشر كان يقول **كيف تُنشَر** ولا يقول كيف تعرف أنّها نجحت، ولا
 * ماذا تفعل إن لم تنجح.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الحارسُ على النمطِ نفسِه الذي جاء `smoke.sh` ليعالجه**: القاعدةُ
 * الثانيةَ عشرة — «مبنيٌّ ولا يُوصَل إليه». فسكربتٌ ممتازٌ في `scripts/`
 * لا يذكره دليلٌ ولا مرشدٌ يُقرأ = سكربتٌ غيرُ موجود، **ويُعاد بناؤه بعد
 * ستّة أشهرٍ باسمٍ آخر.**
 */
class PostDeploySmokeGuardTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = base_path($rel);

        $this->assertFileExists($path, "ملفٌّ مفقود: {$rel}");

        return (string) file_get_contents($path);
    }

    /** @test */
    public function the_smoke_script_exists_and_runs_as_a_gate(): void
    {
        $src = $this->read('scripts/smoke.sh');

        // **رمزُ خروجٍ حقيقيّ.** سكربتٌ يخرج بصفرٍ مهما وقع لا تستطيع خطوةٌ
        // آليّةٌ أن تقرأه — وهو بعينه ما كان يفعله `run_dr.sh` حين كذب.
        $this->assertMatchesRegularExpression('~^exit 1~m', $src,
            'فحصُ الدخان لا يخرج بواحدٍ عند الفشل — فلا تستطيع خطوةٌ آليّةٌ أن تقرأه');

        $this->assertStringContainsString('railway-health', $src,
            'فحصُ الدخان لا يسأل مسبارَ الجاهزيّة — فهو يفحص nginx لا التطبيق');
    }

    /**
     * @test
     *
     * **ويُوصَل إليه من الموضعين اللذين يفتحهما إنسانٌ عند النشر.**
     */
    public function the_smoke_script_is_reachable_from_the_deploy_path(): void
    {
        foreach ([
            'docs/DEPLOYMENT_GUIDE.md' => 'دليلُ النشر',
            'scripts/wizard-production-blockers.sh' => 'مرشدُ ما قبل الإطلاق',
            'CLAUDE.md' => 'قواعدُ العمل',
        ] as $rel => $label) {
            $this->assertStringContainsString('smoke.sh', $this->read($rel),
                "«{$label}» لا يذكر فحصَ الدخان — فلن يُشغَّل بعد نشرة");
        }
    }

    /**
     * @test
     *
     * **ودليلُ النشر يقول ماذا يُفعل إن سقطت النشرة.**
     *
     * فحصٌ يقول «سقطت» ولا يقول «ثمّ ماذا» يترك من يقرؤه في منتصف الطريق —
     * والعودةُ في Coolify ليست زرّاً، فتُكتَب.
     */
    public function the_guide_says_what_to_do_when_the_smoke_test_fails(): void
    {
        $guide = $this->read('docs/DEPLOYMENT_GUIDE.md');

        $this->assertStringContainsString('العودةُ إلى الوراء', $guide,
            'دليلُ النشر لا يذكر العودةَ إلى الوراء — فيُقال «سقطت» ولا يُقال ثمّ ماذا');

        $this->assertStringContainsString('التعافي.md', $guide,
            'دليلُ النشر لا يُحيل إلى صفحة التعافي — وعطلُ البيانات غيرُ عطلِ الشيفرة');
    }

    /**
     * @test
     *
     * **والمرشدُ يعدّ خطواتِه صحيحاً.**
     *
     * `TOTAL_STAGES` رقمٌ مكتوبٌ بيد، ومكتبةُ المرشد تطبعه في كلّ خطوة
     * («٣/٦»). فمن أضاف خطوةً ونسي الرقمَ يُخرج «٧ من ٦» — **ومرشدٌ يعدّ
     * خطأً يُقرأ ناقصاً**، فيُظنّ أنّ خطوةً زائدةٌ عن الحاجة.
     */
    public function the_wizard_counts_its_own_stages_correctly(): void
    {
        $src = $this->read('scripts/wizard-production-blockers.sh');

        // **الأخيرُ لا الأوّل.** مكتبةُ المرشد تُصفّر `TOTAL_STAGES=0` في
        // رأسها، والمؤلِّفُ يضبطه بعدها. فقراءةُ أوّلِ مطابقةٍ تقرأ الصفرَ
        // **فيمرّ الحارسُ على مرشدٍ بلا خطوات** — وقد وقع هذا هنا في أوّل
        // تشغيل، وأخرج «٧ ليست ٠».
        $this->assertNotSame(0, preg_match_all('~^TOTAL_STAGES=(\d+)$~m', $src, $m),
            'المرشدُ لا يعلن عددَ خطواته');

        $this->assertSame(
            (int) end($m[1]),
            preg_match_all('~^stage "~m', $src),
            'عددُ الخطوات المعلَن يخالف عددَ الخطوات المكتوبة — فالمرشدُ يعدّ خطأً'
        );
    }
    /**
     * @test
     *
     * **ومرشدٌ يُغلق ستّةَ حواجزَ ولا يقول «انشر» يترك أهمَّها مفتوحاً.**
     *
     * لوحةُ Coolify تقول `Source: Manual` — **فالدفعُ إلى الفرع لا يُطلق
     * نشرةً**. وكلُّ ما أُصلح في هذه الجلسة (سقفُ التزامنٍ من ٥ إلى ٢٤،
     * وحدُّ معدّلٍ كان يردّ ٤٢٩ على ٨٣٪ من الطلبات تحت CGNAT، وقفلُ رمز
     * العمليّات، وحدُّ استلام التاجر) **قائمٌ في المستودع ولا يعمل عند
     * أحد** حتّى تُضغط.
     *
     * وهو نمطُ «مبنيٌّ ولا يُوصَل إليه» واقعاً على المشروع كلِّه دفعةً
     * واحدة — وأخطرُ صوره: أن يُقرأ التقريرُ «أُصلح» ويُفهَم «يعمل».
     */
    public function the_wizard_tells_the_human_to_actually_deploy(): void
    {
        $src = $this->read('scripts/wizard-production-blockers.sh');

        $this->assertMatchesRegularExpression('~^stage ".*النشر~mu', $src,
            'المرشدُ لا يذكر النشرَ — والدفعُ إلى git لا يَنشُر، فيبقى كلُّ إصلاحٍ معطَّلاً');

        $this->assertStringContainsString('claude/project-code-review-yjagv', $src,
            'المرشدُ لا يسمّي فرعَ النشر — فيُنشَر فرعُ التطوير أو لا يُنشَر شيء');

        // **والقياسُ على الخادم الحقيقيّ** — كلُّ رقمِ طاقةٍ حتّى الآن من
        // `php -S`، وهو لا يمرّ بـphp-fpm ولا nginx. و«يتحمّل ألفي
        // مستخدم» بلا هذا الرقم دعوىً لا قياس.
        $this->assertStringContainsString('BASE=https://amialpay.com', $src,
            'المرشدُ لا يطلب قياسَ الطاقة على الخادم — فالسقفُ الحقيقيُّ مجهول');
    }
}
