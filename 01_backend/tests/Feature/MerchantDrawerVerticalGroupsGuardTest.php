<?php

namespace Tests\Feature;

use App\Domain\Verticals\VerticalRegistry;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-OOP-003 — **«خدمات نشاطي» تُسمّي مجموعاتٍ يملكها الخادم.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * درجُ التاجر (`merchant_adaptive_shell.dart`) يفتح «خدمات نشاطي» على
 * **مجموعاتٍ بأسمائها العربيّة**:
 *
 *     case 'fuel':      return const ['الوقود'];
 *     case 'pharmacy':  return const ['الصيدلية'];
 *
 * وهذه الأسماءُ ليست نصّاً في التطبيق — هي **حقلُ `group()` في
 * `CapabilityRegistry`**، ويُطابَق بها حرفاً بحرف لتصفية بطاقات الملفّ.
 *
 * **فهي نسخةٌ مكتوبةٌ بيدٍ من حقيقةٍ يملكها الخادم.** ويوم تُعاد تسميةُ
 * مجموعةٍ في PHP — «المطاعم» ← «المطعم» — لا يسقط شيء: يُصرَّف التطبيق،
 * ويُفتح الدرج، ويُضغط «خدمات نشاطي»، **فيظهر فارغاً**. ولا خطأَ في أيّ
 * سجلّ، ولا اختبارَ يقول شيئاً.
 *
 * وهو نمطُ العطل الأكثر تكراراً هنا بوجهه الثالث: لا «مبنيٌّ ولا يُوصَل
 * إليه»، بل **«موصولٌ إلى اسمٍ تغيّر»**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لم تُشتقّ القائمةُ من الملفّ بدل حراستها:**
 *
 * الاشتقاقُ يغيّر ما يراه التاجر. فالدرجُ فيه مداخلُ عامّةٌ سلفاً —
 * «البيع» و«المنتجات والمخزون» و«التقارير» — و«خدمات نشاطي» **مقصورةٌ
 * عمداً على ما يخصّ القطاع**، فتُستثنى «البيع» منها ولو منحها القطاع.
 *
 * فاشتقاقٌ حرفيٌّ يُدخل «البيع» في مدخلين، ويُخرج «الأصناف» و«المخزون»
 * من درج التجزئة. **وذاك قرارُ منتَجٍ لا تنظيفُ شيفرة** — فيُحرَس المرآةُ
 * ولا تُبدَّل بلا قرار.
 */
class MerchantDrawerVerticalGroupsGuardTest extends TestCase
{
    private const SHELL = __DIR__
        . '/../../../02_flutter_app/lib/features/merchant/screens/merchant_adaptive_shell.dart';

    /**
     * ما يُعلنه الدرجُ لكلّ قطاع.
     *
     * @return array<string,list<string>>
     */
    private function declaredInDrawer(): array
    {
        $this->assertFileExists(self::SHELL, 'درجُ التاجر غير موجود — الحارسُ يفحص فراغاً');

        $src = (string) file_get_contents(self::SHELL);

        // تُقتطع دالّةُ `_verticalGroups` وحدَها: الملفُّ فيه `switch`
        // أخرى على النشاط (الأيقونة)، وقراءةُ الملفّ كلِّه تخلطهما.
        $start = mb_strpos($src, 'List<String> _verticalGroups()');

        $this->assertNotFalse($start,
            '`_verticalGroups` لم تعد في الدرج — راجع هذا الحارس قبل حذفه: '
            . 'إمّا اشتُقّت من الملفّ (فيُستبدل بحارسٍ على الاشتقاق) وإمّا سقطت.');

        $end = mb_strpos($src, "\n  }", $start);
        $block = mb_substr($src, $start, $end - $start);

        preg_match_all(
            "~case '([a-z_]+)':\s*return const \[([^\]]*)\];~u",
            $block, $m, PREG_SET_ORDER);

        $out = [];

        foreach ($m as $case) {
            preg_match_all("~'([^']+)'~u", $case[2], $names);
            $out[$case[1]] = $names[1];
        }

        return $out;
    }

    /**
     * المجموعاتُ التي **قد** يملكها تاجرُ هذا القطاع — بأيّ باقة.
     *
     * ══════════════════════════════════════════════════════════════════
     * **وأوّلُ صياغةٍ كتبتُها قاست القطاعَ وحدَه فأخطأت.** أسقطت
     * «الأصناف» و«المخزون» من درج التجزئة واتّهمَته بالشيخوخة — **وهما
     * تمنحهما الباقةُ لا صنفُ النشاط** (قرارُ صاحب المشروع: «صنفُ النشاط
     * يقول ما ينطبق، لا ما اشتُري»).
     *
     * والمقياسُ الصحيح: ما يبلغه تاجرُ هذا القطاع **بأعلى باقة** — فشاشةُ
     * الملفّ تعرض المقفولَ أيضاً بوسمِ «متاح بترقية الباقة»، فمجموعةٌ
     * يفتحها الترقّي ليست مجموعةً فارغة.
     * ══════════════════════════════════════════════════════════════════
     */
    private function serverGroupsFor(string $biz): array
    {
        $vertical = VerticalRegistry::find($biz);

        $this->assertNotNull($vertical, "قطاعٌ بلا مربّع: {$biz}");

        $features = array_merge(
            $vertical->featuresFor(A::PLAN_ENTERPRISE),
            AccessPresets::planFeatures(A::PLAN_ENTERPRISE),
        );

        $groups = [];

        foreach ($features as $feature) {
            $cap = CapabilityRegistry::find($feature);

            if ($cap !== null) {
                $groups[$cap->groupName()] = true;
            }
        }

        return array_keys($groups);
    }

    /** ما يخصّ القطاعَ وحدَه — بلا ما تمنحه الباقةُ لكلّ تاجر. */
    private function sectoralGroupsFor(string $biz): array
    {
        return array_values(array_diff(
            $this->serverGroupsFor($biz),
            $this->planGroups(),
        ));
    }

    /**
     * المجموعاتُ التي تمنحها الباقةُ لكلّ تاجرٍ مهما كان نشاطُه.
     *
     * **وتُحسب ولا تُكتب**: قائمةٌ مكتوبةٌ هنا تشيخ مع أوّل مجموعةٍ
     * تُضاف إلى الباقات، فيتّهم الحارسُ درجاً سليماً.
     */
    private function planGroups(): array
    {
        $groups = [];

        foreach (AccessPresets::planFeatures(A::PLAN_ENTERPRISE) as $feature) {
            $cap = CapabilityRegistry::find($feature);

            if ($cap !== null) {
                $groups[$cap->groupName()] = true;
            }
        }

        return array_keys($groups);
    }

    /**
     * @test
     *
     * **كلُّ مجموعةٍ يسمّيها الدرجُ يملكها قطاعُها فعلاً.**
     *
     * فاسمٌ لا يملكه القطاعُ يُنتج شاشةً فارغةً بلا رسالة — يضغطها التاجر
     * فيظنّ النظامَ معطّلاً، ويعود إلى الدعم بلا معلومة.
     */
    public function every_group_the_drawer_names_is_owned_by_its_vertical(): void
    {
        $declared = $this->declaredInDrawer();

        // **ولا يُفحص فراغ.** لو تغيّرت صياغةُ `switch` فلم تُقرأ حالةٌ
        // واحدة، لخرج هذا أخضرَ وصمت عن كلّ انزلاقٍ بعده.
        $this->assertGreaterThanOrEqual(5, count($declared), sprintf(
            'لم تُقرأ إلّا %d حالة من درج التاجر — تغيّرت الصياغة، والحارسُ يفحص فراغاً.',
            count($declared)));

        $stale = [];

        foreach ($declared as $biz => $groups) {
            $this->assertContains($biz, A::ALL_BUSINESS_TYPES,
                "الدرجُ يسمّي نشاطاً لا يعرفه الخادم: «{$biz}»");

            $owned = $this->serverGroupsFor($biz);

            foreach ($groups as $group) {
                if (! in_array($group, $owned, true)) {
                    $stale[] = sprintf('  %s → «%s» · ويملك القطاعُ: [%s]',
                        $biz, $group, implode('، ', $owned) ?: '—');
                }
            }
        }

        $this->assertSame([], $stale,
            "**الدرجُ يسمّي مجموعاتٍ لا يملكها قطاعُها:**\n"
            . implode("\n", $stale) . "\n\n"
            . 'وتُطابَق الأسماءُ حرفاً بحرف لتصفية بطاقات الملفّ — فاسمٌ '
            . "لا يملكه القطاعُ يفتح «خدمات نشاطي» **فارغةً بلا رسالة**.\n"
            . 'أُعيدت تسميةُ مجموعةٍ في `CapabilityRegistry`؟ فيُحدَّث الدرج.');
    }

    /**
     * @test
     *
     * **ولا قطاعَ ذو خدماتٍ خاصّةٍ يُترك بلا مدخل.**
     *
     * والحالةُ المقابلة: قطاعٌ له مجموعتُه في الخادم ولا يسمّيها الدرج،
     * فتُبنى شاشاتُه كلُّها ولا بابَ إليها من درجه. (القاعدة ١٢.)
     *
     * **ويُستثنى «البيع» صراحةً** — له مدخلُه العامّ في الدرج، وإدخالُه
     * هنا يُظهره في مدخلين.
     */
    public function no_vertical_with_its_own_group_is_left_without_a_door(): void
    {
        $declared = $this->declaredInDrawer();

        $missing = [];

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            // المجموعاتُ التي تمنحها الباقةُ لها مداخلُها المستقلّةُ في
            // الدرج («البيع» · «المنتجات والمخزون» · «التقارير») —
            // فالمطلوبُ هنا ما يخصّ القطاعَ وحدَه.
            foreach ($this->sectoralGroupsFor($biz) as $group) {
                if (! in_array($group, $declared[$biz] ?? [], true)) {
                    $missing[] = sprintf('  %s → «%s» غائبةٌ عن درجه', $biz, $group);
                }
            }
        }

        $this->assertSame([], $missing,
            "**قطاعاتٌ لها مجموعتُها في الخادم ولا بابَ إليها في الدرج:**\n"
            . implode("\n", $missing) . "\n\n"
            . 'فتُبنى شاشاتُ القطاع كلُّها ولا يصل إليها صاحبُه — وهو نمطُ '
            . 'العطل الأكثر تكراراً في هذا المشروع.');
    }
}
