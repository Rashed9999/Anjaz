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
     * ══════════════════════════════════════════════════════════════════
     * **وقد تغيّرت البنيةُ مرّةً بالفعل، وهذا الحارسُ قالها ولم يسقط
     * صامتاً.** كانت `_verticalGroups()` تُعيد قائمةً واحدةً لكلّ قطاع،
     * فأُعيد بناءُ الدرج إلى **أقسامٍ لكلّ قطاع** (`_MerchantDrawerSection`)
     * ولكلّ قسمٍ `groups:` خاصّةٌ به.
     *
     * **وهي بنيةٌ أفضل — وأكثرُ عرضةً للشيخوخة**: أسماءُ المجموعات صارت
     * مكتوبةً في مواضعَ أكثرَ لا أقلّ. فالحارسُ يبقى، ويقرأ من الموضع
     * الجديد.
     * ══════════════════════════════════════════════════════════════════
     *
     * @return array<string,list<string>>
     */
    private function declaredInDrawer(): array
    {
        $this->assertFileExists(self::SHELL, 'درجُ التاجر غير موجود — الحارسُ يفحص فراغاً');

        $src = (string) file_get_contents(self::SHELL);

        $start = mb_strpos($src, 'List<_MerchantDrawerSection> _sectionsForBusiness()');

        $this->assertNotFalse($start,
            '`_sectionsForBusiness` لم تعد في الدرج — راجع هذا الحارس قبل حذفه: '
            . 'إمّا اشتُقّت الأقسامُ من الملفّ (فيُستبدل بحارسٍ على الاشتقاق) '
            . 'وإمّا سقط تخصيصُ الدرج بالنشاط.');

        // إلى نهاية الدالّة: أوّلُ سطرٍ مستقلٍّ بإغلاقٍ على مستوى الصنف.
        $end = mb_strpos($src, "\n  }\n", $start);
        $body = mb_substr($src, $start, $end - $start);

        // **الأقسامُ المشتركةُ تسبق `switch`** (البيع · الناس · التقارير)
        // وتنطبق على كلّ قطاع — فتُقرأ مرّةً وتُضاف للجميع.
        $switchAt = mb_strpos($body, 'switch (access.businessType.value)');

        $this->assertNotFalse($switchAt, 'لم يعد الدرجُ يتفرّع بالنشاط — راجع هذا الحارس');

        $shared = $this->groupsIn(mb_substr($body, 0, $switchAt));
        $tail = mb_substr($body, $switchAt);

        preg_match_all("~case '([a-z_]+)':~u", $tail, $cases, PREG_OFFSET_CAPTURE);

        $out = [];

        foreach ($cases[1] as $i => [$biz, $_]) {
            $from = $cases[0][$i][1];
            $to = $cases[0][$i + 1][1] ?? mb_strlen($tail);

            $out[$biz] = array_values(array_unique(array_merge(
                $shared, $this->groupsIn(substr($tail, $from, $to - $from)))));
        }

        return $out;
    }

    /** أسماءُ المجموعات المكتوبةُ في `groups:` داخل مقطعٍ من الشيفرة. */
    private function groupsIn(string $chunk): array
    {
        preg_match_all("~groups:\s*(?:const\s*)?\[([^\]]*)\]~u", $chunk, $m);

        $names = [];

        foreach ($m[1] as $list) {
            preg_match_all("~'([^']+)'~u", $list, $q);
            $names = array_merge($names, $q[1]);
        }

        return array_values(array_unique($names));
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

        $direct = $this->verticalsWithTheirOwnScreens();

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            // ══════════════════════════════════════════════════════════
            // **وبابٌ مباشرٌ بابٌ كذلك.**
            //
            // محطّةُ الوقود لا تمرّ بمجموعات القدرات إطلاقاً: لها في
            // الدرج **مداخلُ مباشرةٌ إلى شاشاتها** (بيعُ الوقود ·
            // المضخّاتُ · الخزّاناتُ · الورديّات)، وذاك قرارٌ مكتوبٌ في
            // الدرج نفسِه — «المحطّةُ ليست متجراً عامّاً».
            //
            // وكان هذا الحارسُ يشترط `groups:` فاتّهم درجَ الوقود بأنّه
            // بلا باب **وهو أغنى الأدراج باباً**. فالمحروسُ **الوصولُ
            // لا شكلُه**: إمّا مجموعةٌ وإمّا مداخلُ مباشرةٌ غيرُ فارغة.
            // ══════════════════════════════════════════════════════════
            if (in_array($biz, $direct, true)) {
                continue;
            }

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

    /**
     * القطاعاتُ التي يفتح لها الدرجُ **شاشاتِها مباشرةً** لا مجموعاتِ قدرات.
     *
     * ويُشترط أن يكون فرعُها غيرَ فارغ: فرعٌ بلا مدخلٍ واحدٍ ليس باباً
     * مباشراً، بل قطاعٌ بلا درج.
     *
     * @return list<string>
     */
    private function verticalsWithTheirOwnScreens(): array
    {
        $src = (string) file_get_contents(self::SHELL);

        $start = mb_strpos($src, 'List<Widget> _activityItems(');
        $this->assertNotFalse($start, '`_activityItems` غائبة — راجع هذا الحارس');

        $body = mb_substr($src, $start, mb_strpos($src, "\n  }\n", $start) - $start);

        preg_match_all(
            "~businessType\.value == '([a-z_]+)'~u", $body, $m, PREG_OFFSET_CAPTURE);

        $out = [];

        foreach ($m[1] as $i => [$biz, $_]) {
            $from = $m[0][$i][1];

            // ══════════════════════════════════════════════════════════
            // **ويُحَدُّ المقطعُ بفرعه لا بآخر الدالّة.**
            //
            // جُرّب هذا بالعكس فمرّ: أُفرغ فرعُ الوقود من مداخله كلِّها
            // (`return [];`) **ولم يسقط** — لأنّ المقطعَ كان يمتدّ إلى
            // نهاية الدالّة فيبتلع `_item(` من الفرع العامّ تحته.
            //
            // فكان يعدّ مداخلَ غيرِه ويقول «للوقود باب». وحارسٌ يقيس
            // خارجَ ما يحرسه يمرّ دائماً.
            // ══════════════════════════════════════════════════════════
            $close = mb_strpos($body, "\n    }", $from);
            $to = min(
                $m[0][$i + 1][1] ?? mb_strlen($body),
                $close === false ? mb_strlen($body) : $close);

            $chunk = substr($body, $from, $to - $from);

            // مدخلٌ واحدٌ على الأقلّ — وإلّا فالفرعُ إعلانٌ بلا باب.
            $items = preg_match_all("~_item\(~u", $chunk);

            $this->assertGreaterThan(0, $items,
                "«{$biz}» له فرعٌ خاصٌّ في الدرج **بلا مدخلٍ واحد** — "
                . 'فيُستثنى من فحص المجموعات ولا يُعوَّض عنه بشيء.');

            $out[] = $biz;
        }

        return $out;
    }

}
