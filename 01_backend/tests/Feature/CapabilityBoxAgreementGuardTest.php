<?php

namespace Tests\Feature;

use App\Domain\Verticals\VerticalRegistry;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-OOP-002 — **المصدران يقولان الشيءَ نفسَه، أو يسقط هذا.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لحقيقةِ «ماذا يملك هذا القطاع» مصدران، ولكلٍّ سببُ وجود:**
 *
 *   · `CapabilityRegistry` — يحمل **ما يُعرَض**: الاسمُ والأيقونةُ
 *     والمجموعةُ والشاشةُ ومسارُ الحراسة. ومنه تُرسَم شاشةُ «خدماتي»
 *     وصفحةُ التسعير ومصفوفةُ الإدارة.
 *   · `VerticalRegistry` — يحمل **ما يُمنَح**: نواةُ القطاع وعمقُه المُباع.
 *
 * **ودمجُهما خطأ**: الأوّلُ سجلُّ عرضٍ والثاني سجلُّ منح، وخلطُهما يجعل
 * تغييرَ أيقونةٍ يمسّ اشتقاقَ الصلاحيّات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لكنّ مصدرين لحقيقةٍ واحدةٍ يفترقان أوّلَ ما يتغيّر أحدُهما — وقد
 * افترقا فعلاً.**
 *
 * `pharmacy_customers` أُعلنت `comingSoon()` في السجلّ (بلا نقطة نهاية)،
 * **وكانت تُمنَح من مصدرٍ آخر**. فتُرسَم الشاشةُ ويُضغَط الزرُّ ولا شيءَ
 * خلفه — و«قريباً» في سجلٍّ لا يقرؤه المانحُ إعلانٌ بلا أثر.
 *
 * فهذا الحارسُ يُثبت الاتّفاقَ في اتّجاهيه:
 *
 *   ① كلُّ قدرةٍ قطاعيّةٍ في السجلّ يمنحها **قطاعُها وحدَه** في المربّع.
 *   ② وكلُّ ما يمنحه مربّعٌ **معلَنٌ في السجلّ** لذلك القطاع.
 *   ③ وما يبيعه السجلُّ بباقةٍ **لا يمنحه المربّعُ نواةً** — والعكس.
 *
 * **والانفراقُ الوحيدُ المأذون: `comingSoon()`** — وهو انفراقٌ مقصود:
 * القدرةُ معروضةٌ في الكتالوج «قريباً» ولا تُمنَح لأحد. وما عداه يسقط.
 */
class CapabilityBoxAgreementGuardTest extends TestCase
{
    /**
     * القطاعاتُ التي يمنحها المربّعُ لكلّ قدرة.
     *
     * @return array{0:array<string,list<string>>,1:array<string,array<string,string>>}
     *         [ النواة: قدرة ⇒ قطاعات ، العمق: قدرة ⇒ (قطاع ⇒ باقة) ]
     */
    private function boxGrants(): array
    {
        $own = [];
        $depth = [];

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            $vertical = VerticalRegistry::find($biz);

            $this->assertNotNull($vertical, "قطاعٌ بلا مربّع: {$biz}");

            foreach ($vertical->own() as $feature) {
                $own[$feature][] = $biz;
            }

            foreach ($vertical->paidDepth() as $plan => $features) {
                foreach ($features as $feature) {
                    $depth[$feature][$biz] = $plan;
                }
            }
        }

        return [$own, $depth];
    }

    /**
     * @test
     *
     * **① و② معاً: كلُّ قدرةٍ قطاعيّةٍ لقطاعها بالضبط — لا أقلَّ ولا أكثر.**
     *
     * والنقصُ يُقفل بابَ القطاع على صاحبه وهو يراه في الكتالوج. والزيادةُ
     * تمنح ميزةَ صيدليّةٍ لبائع السمك: يُرسَم زرُّها في شاشته ويُردّ عند
     * الضغط بـ٤٠٢.
     */
    public function every_sectoral_capability_is_granted_by_its_own_vertical_only(): void
    {
        [$own, $depth] = $this->boxGrants();

        $sectoral = 0;
        $mismatched = [];

        foreach (CapabilityRegistry::all() as $code => $cap) {
            if ($cap->appliesToAllBusinessTypes()) {
                continue;   // قدرةٌ عامّة — ليست محلَّ هذا الحارس
            }

            $sectoral++;

            $declared = array_values(array_filter(
                A::ALL_BUSINESS_TYPES,
                static fn (string $biz): bool => $cap->appliesTo($biz)));

            $granted = array_values(array_unique(array_merge(
                $own[$code] ?? [], array_keys($depth[$code] ?? []))));

            // **والانفراقُ المأذونُ واحد.** «قريباً» تُعلَن في الكتالوج
            // ولا تُمنَح لأحد — وهي الحالةُ التي كُتب هذا الحارسُ لها.
            if ($cap->isComingSoon()) {
                $this->assertSame([], $granted, sprintf(
                    '«%s» أُعلنت «قريباً» **وتُمنَح فعلاً** في [%s] — '
                    . 'فتُرسَم شاشتُها ويُضغَط زرُّها ولا شيءَ خلفه.',
                    $code, implode('، ', $granted)));

                continue;
            }

            sort($declared);
            sort($granted);

            if ($declared !== $granted) {
                $mismatched[] = sprintf('  %s → السجلّ:[%s] · المربّع:[%s]',
                    $code, implode('،', $declared) ?: '—', implode('،', $granted) ?: '—');
            }
        }

        // **ولا يُفحص فراغٌ.** لو تغيّرت صياغةُ السجلّ فلم تُقرأ قدرةٌ
        // قطاعيّةٌ واحدة، لخرج هذا أخضرَ على صفرٍ وصمت عن كلّ انفراق.
        $this->assertGreaterThanOrEqual(15, $sectoral,
            "لم تُقرأ إلّا {$sectoral} قدرةً قطاعيّة — تغيّرت صياغةُ السجلّ، "
            . 'والحارسُ يفحص فراغاً.');

        $this->assertSame([], $mismatched,
            "**السجلُّ والمربّعُ يفترقان في مَن يملك ماذا:**\n"
            . implode("\n", $mismatched) . "\n\n"
            . 'والنقصُ يُقفل بابَ القطاع على صاحبه وهو يراه في الكتالوج، '
            . 'والزيادةُ ترسم زرّاً يُردّ بـ٤٠٢ عند الضغط.');
    }

    /**
     * @test
     *
     * **③ وما يُباع لا يُمنَح مجّاناً — وما لا يُباع لا يُحجَب.**
     *
     * وهو أخطرُ الاتّجاهين: `minPlan` يقول للتاجر «ترقَّ لتفتحها»،
     * والمربّعُ يمنحها في النواة. فيدفع ثمنَ ما يملكه — أو يُحجَب عنه ما
     * لم يُعلَن ثمنُه فيرى «ترقية» لا تفتح شيئاً.
     */
    public function what_a_plan_sells_is_never_granted_as_core(): void
    {
        [$own, $depth] = $this->boxGrants();

        $contradictions = [];

        foreach (CapabilityRegistry::all() as $code => $cap) {
            if ($cap->appliesToAllBusinessTypes() || $cap->isComingSoon()) {
                continue;
            }

            $min = $cap->minimumPlan();
            $sold = $min !== null && $min !== A::PLAN_FREE;

            foreach ($own[$code] ?? [] as $biz) {
                if ($sold) {
                    $contradictions[] = sprintf(
                        '  %s → السجلّ يبيعها بـ«%s» والمربّعُ يمنحها نواةً في «%s»',
                        $code, $min, $biz);
                }
            }

            foreach ($depth[$code] ?? [] as $biz => $atPlan) {
                if (! $sold) {
                    $contradictions[] = sprintf(
                        '  %s → السجلُّ مجّانيّةٌ والمربّعُ يبيعها بـ«%s» في «%s»',
                        $code, $atPlan, $biz);
                } elseif ($atPlan !== $min) {
                    $contradictions[] = sprintf(
                        '  %s → السجلّ يبيعها بـ«%s» والمربّعُ بـ«%s» في «%s»',
                        $code, $min, $atPlan, $biz);
                }
            }
        }

        $this->assertSame([], $contradictions,
            "**ثمنُ القدرة يُقال في موضعين ويفترقان:**\n"
            . implode("\n", $contradictions) . "\n\n"
            . 'فيدفع التاجرُ ثمنَ ما يملكه، أو يرى «ترقّ باقتك» على ترقيةٍ '
            . 'لن تفتح شيئاً.');
    }

    /**
     * @test
     *
     * **وكلُّ ما يمنحه مربّعٌ له مدخلٌ في الكتالوج.**
     *
     * فقدرةٌ تُمنَح ولا تُعلَن لا اسمَ لها ولا أيقونةَ ولا شاشة — تُفتح
     * في الخادم ولا تظهر في «خدماتي» إطلاقاً. وهو **مبنيٌّ ولا يُوصَل
     * إليه** من بابه الآخر.
     */
    public function nothing_a_box_grants_is_missing_from_the_catalogue(): void
    {
        [$own, $depth] = $this->boxGrants();

        $orphans = [];

        foreach (array_keys($own + $depth) as $code) {
            if (CapabilityRegistry::find($code) === null) {
                $orphans[] = $code;
            }
        }

        sort($orphans);

        $this->assertSame([], $orphans,
            "**قدراتٌ تمنحها المربعاتُ ولا مدخلَ لها في الكتالوج:**\n  "
            . implode("\n  ", $orphans) . "\n\n"
            . 'فتُفتح في الخادم ولا تظهر في «خدماتي» — بلا اسمٍ ولا شاشة.');
    }

    /**
     * @test
     *
     * **واسمُ القطاع يُكتب في موضعٍ واحد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان يُكتب في أربعة: `BUSINESS_TYPE_LABELS` (المصدر)، ولوحةُ ٣٦٠،
     * وقائمةُ إنشاء الحساب، ومربّعُ القطاع نفسُه. **وبثلاث هجاءات**:
     * «محطة وقود» · «محطّة وقود» · «محطّةُ وقود».
     *
     * فيُنشئ المديرُ التاجرَ باسمٍ ويراه في لوحته بآخر — **واختلافُ
     * الهجاء في لوحةٍ ماليّةٍ يُقرأ نظامين لا نظاماً**.
     *
     * فصار الاسمُ يُشتقّ من الثابت، وهذا يمنع عودةَ هجاءٍ خامس: كلُّ
     * اسمٍ قطاعيٍّ مكتوبٍ نصّاً خارج الثابت يسقط هنا.
     * ══════════════════════════════════════════════════════════════════
     */
    public function the_vertical_name_is_written_in_exactly_one_place(): void
    {
        $offenders = [];

        // الملفّاتُ التي تعرض اسمَ القطاع — والمصدرُ نفسُه مستثنىً.
        $files = [
            app_path('Services/Admin/MerchantThreeSixtyService.php'),
            app_path('Domain/Verticals/VerticalRegistry.php'),
            resource_path('views/admin-views/amial/hub/users.blade.php'),
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);

            // يُنزع التعليقُ أوّلاً: اسمٌ في شرحٍ ليس اسماً معروضاً —
            // وهذا الفخُّ أوقع المشروعَ من قبل.
            $src = (string) preg_replace(
                '~//[^\n]*|/\*.*?\*/|\{\{--.*?--\}\}~s', '',
                (string) file_get_contents($file));

            foreach (A::ALL_BUSINESS_TYPES as $biz) {
                $name = VerticalRegistry::find($biz)?->nameAr();

                if ($name === null || $name === '') {
                    continue;
                }

                if (str_contains($src, "'{$name}'") || str_contains($src, ">{$name}<")) {
                    $offenders[] = sprintf('  %s → «%s» مكتوبٌ نصّاً',
                        basename($file), $name);
                }
            }
        }

        $this->assertSame([], $offenders,
            "**اسمُ قطاعٍ مكتوبٌ نصّاً خارج مصدره:**\n"
            . implode("\n", $offenders) . "\n\n"
            . 'ويُقرأ من `AccessConstants::BUSINESS_TYPE_LABELS` أو '
            . '`VerticalRegistry::find($biz)->nameAr()` — فهجاءان لشيءٍ '
            . 'واحدٍ يجعلان التاجرَ يُنشَأ باسمٍ ويُعرَض بآخر.');
    }

}
