<?php

namespace Tests\Feature;

use App\Domain\Verticals\VerticalRegistry;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-CAP-GRANTER-001 — **قدرةٌ لا يمنحها مصدرٌ مقفلةٌ إلى الأبد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **كيف كُشفت:** لم تُطلَب. ظهرت وأنا أحدّ قدراتِ التجزئة عن محطّة
 * الوقود (`AMIAL-VERTICAL-SCOPE-001`): وسمتُ سبعَ قدراتٍ بنطاقها، فسقط
 * `CapabilityBoxAgreementGuardTest` عليها — **لا لأنّ نطاقَها خطأ، بل
 * لأنّ لا مصدرَ يمنحها أصلاً**.
 *
 * وقِيس على تاجر **تجزئةٍ** في باقة **المؤسّسة** — أعلى ما يُشترى:
 *
 *     retail.catalog · retail.variants · retail.price_versions
 *     retail.locations · retail.transfers · retail.waste
 *     retail.returns.by_line
 *
 *     يمنحها المربّع : —
 *     تبيعها الباقة  : لا
 *     تصل التاجرَ    : **لا**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهي معلَنةٌ ومسعَّرةٌ وموصوفةٌ ولها شاشات**، فتظهر في «قدراتي»
 * **«بالترقية»** — ويرقّى التاجرُ إلى أعلى باقةٍ فلا تُفتح واحدةٌ منها.
 * ولا خطأَ في أيّ سجلّ: كلُّ طبقةٍ سليمةٌ وحدَها، والوصلُ مفقود.
 *
 * (وهذا نمطُ العطل الأكثر تكراراً في المشروع: **مبنيٌّ ولا يُوصَل
 * إليه** — وهنا صورتُه المعكوسة: **يُعرَض ولا يُمنَح**.)
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لا يُصلَح هنا:** فتحُها قرارُ **تسعير** — أيُّ باقةٍ تبيع
 * «التصنيفات والعلامات والوحدات»؟ — وهو لصاحب المشروع لا لكاتب الشيفرة.
 * ووضعُها في باقةٍ بالظنّ يُسرّب ميزةً مدفوعةً أو يقفل أساسيّة، وكلاهما
 * في جدول أولويّات التدقيق **«عالية»**.
 *
 * **فيُجمَّد الدَّينُ ولا يُنسى**: القائمةُ أدناه سقفٌ لا أرضيّة —
 * **لا يُقبل زائدٌ عليها**، ومن أُغلق منها يُشطَب من القائمة. فحارسٌ
 * يمنع النموَّ خيرٌ من ملاحظةٍ في تقريرٍ يُقرأ مرّةً ويُنسى.
 */
class CapabilityHasAGranterGuardTest extends TestCase
{
    /**
     * **الدَّينُ المعروف — سبعُ قدراتٍ قِيست يومَ كُتب هذا الحارس.**
     *
     * ولا يُضاف إليها إلّا بقرارٍ مكتوب. ومن فتح واحدةً فليحذفها من هنا،
     * وإلّا صار الحارسُ يشترط بقاءَ العطل.
     *
     * @var array<int,string>
     */
    private const KNOWN_ORPHANS = [
        'retail.catalog',
        'retail.price_versions',
        'retail.returns.by_line',
        'retail.variants',
        'retail.waste',
    ];

    /**
     * **وسبعةٌ صارت خمساً — بثمنٍ من المستند لا باجتهاد.**
     *
     * `retail.locations` و`retail.transfers` أُغلقتا في
     * `AMIAL-WHOLESALE-GUIDE-001`: كانتا معلَنتين
     * `minPlan(enterprise)` ولا يمنحهما مصدر. ودليلا التشغيل يسعّرانهما
     * هناك صراحةً — «فروع ومستودعات ومواقع» · «تحويلات بين المواقع» —
     * فأُدرجتا في `planFeatures(PLAN_ENTERPRISE)` بنطاق `GOODS`.
     *
     * **والخمسُ الباقيةُ بلا ثمنٍ مكتوبٍ في أيّ مستند**، فتبقى دَيناً
     * حتّى يقول صاحبُ المشروع بأيّ باقةٍ تُباع — أو تُعلَن `comingSoon()`.
     */
    private const CLOSED_BY_THE_OPERATING_GUIDES = [
        'retail.locations',
        'retail.transfers',
    ];

    /** كلُّ ما يمنحه مصدرٌ: مربّعُ قطاعٍ أو باقة. */
    private function granted(): array
    {
        $out = [];

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            $v = VerticalRegistry::find($biz);
            if ($v === null) continue;

            $out = array_merge($out, $v->own());

            foreach ($v->paidDepth() as $features) {
                $out = array_merge($out, $features);
            }
        }

        foreach (A::ALL_PLANS as $plan) {
            $out = array_merge($out, AccessPresets::planFeatures($plan));
        }

        foreach (A::ALL_ROLES as $role) {
            $out = array_merge($out, AccessPresets::roleBase($role));
        }

        return array_values(array_unique($out));
    }

    /** القدراتُ المعلَنةُ التي لا يمنحها شيء. */
    private function orphans(): array
    {
        $granted = $this->granted();
        $out = [];

        foreach (CapabilityRegistry::all() as $code => $cap) {
            // **الأساسيّةُ لا تُمنَح بقائمة** — هي مفتوحةٌ بحكم كونها أساساً.
            if ($cap->isCore()) continue;

            // **و«قريباً» مُعلَنٌ أنّها لا تُمنَح** — وذاك انفراقٌ مقصود.
            if ($cap->isComingSoon()) continue;

            if (! in_array($code, $granted, true)) {
                $out[] = $code;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * @test
     *
     * **لا يزيد الدَّين.** قدرةٌ جديدةٌ تُعلَن ولا تُمنَح تسقط هنا يومَ تُكتب.
     */
    public function no_new_capability_is_declared_without_a_granter(): void
    {
        $new = array_values(array_diff($this->orphans(), self::KNOWN_ORPHANS));

        $this->assertSame([], $new,
            "**قدراتٌ معلَنةٌ ولا يمنحها مصدر:**\n  " . implode("\n  ", $new)
            . "\n\nفتُعرَض في «قدراتي» **«بالترقية»**، ويرقّى التاجرُ إلى "
            . "أعلى باقةٍ فلا تُفتح. ولا خطأَ في أيّ سجلّ.\n\n"
            . 'وتُفتح بأن تُدرَج في `planFeatures` بباقتها، أو في '
            . '`own()`/`paidDepth()` لقطاعها — **أو تُعلَن `comingSoon()`** '
            . 'إن لم يُحسم ثمنُها بعد، فيقرأ التاجرُ «قريباً» لا «ادفع».');
    }

    /**
     * @test
     *
     * **ولا يُشترط بقاءُ العطل.** من أُغلق منها يُشطَب من القائمة، وإلّا
     * صار الحارسُ يحرس الدَّينَ بدل أن يمنعه.
     */
    public function the_known_debt_list_holds_nothing_already_fixed(): void
    {
        $stale = array_values(array_diff(self::KNOWN_ORPHANS, $this->orphans()));

        $this->assertSame([], $stale,
            "**قدراتٌ في قائمة الدَّين وقد صار لها مانحٌ فعلاً:**\n  "
            . implode("\n  ", $stale) . "\n\n"
            . 'فتُحذف من `KNOWN_ORPHANS` — وقائمةٌ لا تُنقَص تُجمّد العطلَ '
            . 'بدل أن تعدّه.');
    }

    /**
     * @test
     *
     * **وما أُغلق يبقى مغلقاً.** فالدَّينُ يُنقَص ولا يعود.
     */
    public function what_the_operating_guides_priced_stays_granted(): void
    {
        $orphans = $this->orphans();

        foreach (self::CLOSED_BY_THE_OPERATING_GUIDES as $code) {
            $this->assertNotContains($code, $orphans,
                "**«{$code}» عادت يتيمةً بلا مانح.** وقد أُغلقت بثمنٍ "
                . 'مكتوبٍ في دليلَي التشغيل: «فروع ومستودعات ومواقع» · '
                . '«تحويلات بين المواقع» في باقة المؤسّسة. فمن نزعها من '
                . '`planFeatures` أعاد عرضَها «بالترقية» على ترقيةٍ لا تفتحها.');
        }
    }

    /**
     * @test
     *
     * **ولا يُفحص فراغ.** لو تغيّرت صياغةُ السجلّ فلم تُقرأ قدرةٌ واحدة،
     * لخرج ما سبق أخضرَ على صفرٍ وصمت عن كلّ يتيم.
     */
    public function the_registry_is_actually_read(): void
    {
        $this->assertGreaterThan(50, count(CapabilityRegistry::all()),
            'لم يُقرأ سجلُّ القدرات — والحارسُ يفحص مجموعةً فارغة.');

        $this->assertGreaterThan(20, count($this->granted()),
            'لم تُقرأ مصادرُ المنح — فكلُّ قدرةٍ ستبدو يتيمة.');
    }
}
