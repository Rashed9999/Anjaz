<?php

namespace Tests\Feature;

use App\Services\Access\PlanComparisonService;
use App\Support\Access\AccessConstants as A;
use Tests\TestCase;

/**
 * AMIAL-PLAN-COMPARE-001 — **الفرقُ بين الباقات يُحسب، لا يُكتب مرّتين.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «أريد تحسينَ شكل الخطط، تبدو بداية. ووعودٌ أكثر — ويجب
 * التوضيحُ: الفرقُ بين كلّ باقةٍ بكلّ احترافيّة».
 *
 * **وقِيس قبل أن يُرسَم شيء، فالمشكلةُ لم تكن شكلاً:**
 *
 *     اتّحادُ ميزات الباقات               →  ٣٩
 *     المترجَمُ في خريطة `_featureLabel`  →  ١٩
 *     يُعرَض رمزاً إنجليزيّاً خاماً        →  ٢٠
 *     وله اسمٌ عربيٌّ في السجلّ           →  ٣٩ — كلُّها
 *
 * فنصفُ الجدول كان `advanced_reports` و`wholesale_credit` في وجه تاجرٍ
 * يمنيّ، **والأسماءُ العربيّةُ في الخادم منذ البداية**.
 */
class PlanComparisonGuardTest extends TestCase
{
    private function catalogue(): array
    {
        return app(PlanComparisonService::class)->catalogue();
    }

    /** @test */
    public function every_capability_shown_carries_an_arabic_name_from_the_registry(): void
    {
        // **وهذا هو العطلُ بعينه**: رمزٌ إنجليزيٌّ في وجه القارئ ليس
        // اسماً — هو غيابُ اسم. (القاعدة السابعة.)
        $undocumented = [];

        foreach ($this->catalogue()['plans'] as $plan) {
            foreach ($plan['adds'] as $cap) {
                if (! $cap['documented']) {
                    $undocumented[] = $plan['code'].' → '.$cap['code'];
                }
            }
        }

        $this->assertSame([], $undocumented,
            "قدراتٌ تُعرَض في شاشة الباقات بلا اسمٍ عربيّ:\n  "
            .implode("\n  ", $undocumented)."\n\n"
            .'أضِف `nameAr` و`descAr` لها في `CapabilityRegistry` — '
            .'ورمزٌ إنجليزيٌّ في شاشة تسعيرٍ يُوقف القارئَ ولا يبيع.');
    }

    /** @test */
    public function each_step_says_what_it_adds_over_the_one_below(): void
    {
        // ① **جوابُ السؤال الذي يسأله من يفكّر في الترقية.** وجدولٌ
        // يُعيد المشتركَ في كلّ عمود يُخفي الصفوفَ الفارقة بين المتطابقة.
        $plans = $this->catalogue()['plans'];

        $this->assertCount(count(A::ALL_PLANS), $plans);

        $free = $plans[0];
        $this->assertSame(A::PLAN_FREE, $free['code']);

        foreach (array_slice($plans, 1) as $step) {
            $this->assertGreaterThan(0, $step['adds_count'],
                "باقةُ «{$step['label']}» لا تضيف شيئاً على ما قبلها — "
                .'فهي تُباع ولا فرقَ لها، أو الحسابُ لا يقرأ الفرق');

            $this->assertGreaterThan($step['adds_count'], $step['total_count'],
                'المضافُ يساوي الكلّ — أي أنّ الباقةَ لا ترث ما تحتها، '
                .'وهو ما يجعل الجدولَ يُعيد المشترك');
        }
    }

    /** @test */
    public function the_added_list_is_exactly_the_difference_and_never_repeats(): void
    {
        // **يُقاس من `AccessPresets` نفسِها** — لا يُصدَّق ما تقوله الخدمة
        // عن نفسها.
        $plans = $this->catalogue()['plans'];
        $seen = [];

        foreach ($plans as $step) {
            $codes = array_column($step['adds'], 'code');

            $repeated = array_intersect($codes, $seen);
            $this->assertSame([], array_values($repeated),
                "«{$step['label']}» تُعيد ما ذُكر في باقةٍ أدنى: "
                .implode('، ', $repeated));

            $seen = array_merge($seen, $codes);
        }

        // والمجموعُ يساوي ميزاتِ الباقة العليا — فلا قدرةَ سقطت من العرض.
        $top = end($plans);
        $expected = \App\Support\Access\AccessPresets::planFeatures($top['code']);

        sort($seen);
        sort($expected);

        $this->assertSame($expected, $seen,
            'مجموعُ ما عُرض لا يساوي ميزاتِ الباقة العليا — فقدرةٌ '
            .'مُباعةٌ لا تظهر في أيّ درجةٍ من السلّم، أو تظهر ما لا يُباع');
    }

    /** @test */
    public function every_plan_says_who_it_is_for_before_what_is_in_it(): void
    {
        // **«وعودٌ أكثر»** — وبطاقةٌ تبدأ بقائمة ميزاتٍ تُقرأ فهرساً.
        foreach ($this->catalogue()['plans'] as $plan) {
            $this->assertNotEmpty($plan['pitch']['headline'] ?? '',
                "باقةُ «{$plan['label']}» بلا عنوانٍ يقول ما تعد به");
            $this->assertNotEmpty($plan['pitch']['for_whom'] ?? '',
                "باقةُ «{$plan['label']}» لا تقول لمن هي");
        }
    }

    /** @test */
    public function the_currency_is_carried_and_never_converted(): void
    {
        // **رقمٌ صحيحٌ بعملةٍ كاذبة** — القسمُ في `CLAUDE.md`: السعرُ
        // بالريال السعوديّ وكلُّ رصيدٍ في المنتج يمنيّ، و٣٥ ر.س ≈ ٢٤٠٠ ر.ي.
        foreach ($this->catalogue()['plans'] as $plan) {
            $this->assertSame(A::PLAN_PRICE_CURRENCY, $plan['currency'],
                "باقةُ «{$plan['label']}» تحمل عملةً غير المصدر الواحد");
        }
    }

    /** @test */
    public function an_unlimited_or_forbidden_limit_is_words_not_a_number(): void
    {
        // **«‎-1 منتج» و«0 فرع» سطران لا يُقرآن** (القاعدة السابعة).
        $texts = [];

        foreach ($this->catalogue()['plans'] as $plan) {
            foreach ($plan['limits'] as $l) {
                $texts[] = $l['text'];

                if ($l['value'] < 0) {
                    $this->assertSame('بلا حدّ', $l['text']);
                }
                if ($l['value'] === 0) {
                    $this->assertSame('غير متاح', $l['text']);
                }
            }
        }

        $this->assertNotEmpty($texts, 'لا حدودَ تُعرَض إطلاقاً — والمُطابِقُ أعمى');
        $this->assertNotContains('-1', $texts);
    }

    /** @test */
    public function the_upgrade_never_promises_what_only_the_business_type_opens(): void
    {
        // ③ **وإلّا رقّى صاحبُ البقالة ليحصل على «الوصفات الطبيّة» فلا
        // يجدها** — وعدٌ لا يستطيع النظامُ الوفاءَ به.
        $note = $this->catalogue()['vertical_note'];

        $this->assertNotEmpty($note);
        $this->assertStringContainsString('نشاطك', $note);
    }

    /** @test */
    public function the_screen_no_longer_keeps_its_own_label_map(): void
    {
        // **جذرُ العطل**: خريطةٌ مكتوبةٌ في Dart تشيخ يومَ تُضاف قدرةٌ في
        // الخادم — فتظهر برمزها الإنجليزيّ إلى الأبد، ولا شيءَ يُنبّه.
        $raw = (string) file_get_contents(base_path(
            '../02_flutter_app/lib/features/plans/screens/plans_catalog_screen.dart'));

        // **والتعليقُ ليس تنفيذاً — ويُنزَع قبل الفحص.**
        //
        // سقط هذا الحارسُ أوّلَ تشغيلٍ على **تعليقٍ كتبتُه أنا** يقتبس
        // الشيفرةَ المحذوفة ليشرح لماذا حُذفت. وهو بعينه ما تحذّر منه
        // القاعدةُ الثانية في `CLAUDE.md`: «حارسٌ مرّ لأنّ الكلمة وردت
        // في تعليقٍ عربيّ يشرح أنّ النقطة غير موصولة» — واقعاً مقلوباً
        // هذه المرّة: التعليقُ الذي يصف العطلَ **يُسقط** الحارس.
        $src = implode("\n", array_filter(
            explode("\n", $raw),
            fn ($l) => ! str_starts_with(ltrim($l), '//')));

        $this->assertStringNotContainsString('String _featureLabel(', $src,
            'عادت خريطةُ الأسماء المكتوبةُ في الشاشة — وهي رابعُ قائمةٍ '
            .'موازيةٍ تشيخ في هذا المشروع');

        $this->assertStringNotContainsString("map[f] ?? f", $src,
            'ما زال الرمزُ الإنجليزيُّ يُعرَض حين تغيب الترجمة');
    }
}
