<?php

namespace Tests\Feature;

use App\Support\Access\Capability;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-ENTITLEMENTS-002 — **بوّابةٌ صحيحةٌ لا تحرس إلّا ثُمنَ ما بُنيت له.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في تدقيق الميزات والباقات:**
 *
 *     قدراتٌ مسجَّلة في CapabilityRegistry : 59
 *     منها تُعلن مساراً يحرسه EnsureCapability : 13
 *     مساراتُ routes/api/amial.php : 467
 *     منها يحمل حارسَ باقةٍ في سطره : 22
 *
 * و`EnsureCapability` **سليمةُ المنطق**: تقارن رتبةَ الباقة برتبة الحدّ
 * الأدنى، ولا تقرأ صنفَ النشاط إلّا للانطباق. أي أنّ التصميمَ الذي طلبه
 * التدقيق **مبنيٌّ**.
 *
 * **لكنّه لا يُوصَل إليه.** ستٌّ وأربعون قدرةً لا تُعلن مساراً واحداً،
 * فالوسيطُ لا يُستدعى لها قطّ، ويبقى الفحصُ في **خمسةَ عشرَ**
 * `if (! hasFeature(...))` منثوراً في المتحكّمات — وهي تقرأ
 * `FeatureAccessService` الذي **يوحّد** صنفَ النشاط بالباقة.
 *
 * وهذا نصُّ نمطِ العطل الأكثر تكراراً في المشروع — «مبنيٌّ ولا يُوصَل
 * إليه» — واقعاً هذه المرّةَ على الأداة التي بُنيت لتمنعه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ يُثبّت الرقمَ بدل أن يُصلحه:**
 *
 * ربطُ ٤٦ قدرةً بمساراتها تغييرٌ في مسارِ مالٍ حيّ: بادئةٌ خاطئةٌ على
 * `cashier` **تُقفل نقطةَ البيع في وجه كلّ تاجرٍ يدفع**. ولا تُكتب
 * كتلةٌ كهذه بلا مجموعةِ اختباراتٍ تجري.
 *
 * فيُثبَّت الرقمُ أوّلاً: **لا يزيد المكشوفُ**، ولا تدخل قدرةٌ جديدةٌ بلا
 * مسار، ولا يُعلَن مسارٌ لا وجودَ له. ثمّ يُنقَص العددُ دفعةً دفعةً وكلُّ
 * دفعةٍ محروسة.
 *
 * (القاعدة السابعة: «غير معروف» ليس صفراً — والمكشوفُ يُعدّ ويُكتب،
 * لا يُسكت عنه حتّى يُصلَح.)
 */
class EntitlementGateReachabilityGuardTest extends TestCase
{
    /**
     * **العددُ المقيس اليومَ للقدرات التي لا تُعلن مساراً.**
     *
     * وهو سقفٌ لا هدف: يُنقَص ولا يُزاد. ومن أضاف قدرةً بلا مسارٍ يسقط
     * هنا فيعلم قبل الدمج لا بعد ستّة أشهر.
     */
    private const UNROUTED_CEILING = 46;

    /** بادئاتُ المسارات المسجَّلة فعلاً — تُقرأ من الملفّ لا من تخمين. */
    private function declaredPrefixes(): array
    {
        $src = (string) file_get_contents(base_path('routes/api/amial.php'));

        // **يُنزع التعليقُ أوّلاً.** بادئةٌ مذكورةٌ في شرحٍ ليست بادئةً
        // مسجَّلة — وهذا الفخُّ أوقع هذا المشروعَ أربعَ مرّاتٍ من قبل.
        $src = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $src);

        preg_match_all("~Route::prefix\('([^']+)'\)~", $src, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return array<int,Capability> */
    private function routed(): array
    {
        return array_values(array_filter(
            CapabilityRegistry::all(),
            static fn (Capability $c) => $c->toArray()['routes'] !== [],
        ));
    }

    /** @return array<int,Capability> */
    private function unrouted(): array
    {
        return array_values(array_filter(
            CapabilityRegistry::all(),
            static fn (Capability $c) => $c->toArray()['routes'] === [],
        ));
    }

    /**
     * @test
     *
     * **كلُّ مسارٍ تُعلنه قدرةٌ موجودٌ فعلاً.**
     *
     * قدرةٌ تحرس `retail/prices` بعد إعادة تسمية البادئة إلى
     * `retail/pricing` **تحرس لا شيء** — والوسيطُ لا يُستدعى، والردُّ ٢٠٠،
     * ولا خطأَ في أيّ سجلّ. وهو حارسٌ يكذب.
     */
    public function every_prefix_a_capability_claims_to_guard_actually_exists(): void
    {
        $prefixes = $this->declaredPrefixes();

        $this->assertNotEmpty($prefixes, 'لم تُقرأ بادئةٌ واحدة — تغيّرت صيغةُ ملفّ المسارات');

        $orphans = [];

        foreach ($this->routed() as $cap) {
            foreach ($cap->toArray()['routes'] as $claimed) {
                $claimed = trim($claimed, '/');

                $exists = in_array($claimed, $prefixes, true)
                    // بادئةٌ متداخلة: `retail/products` تحت `retail`
                    || array_filter($prefixes, static fn (string $p) => str_starts_with($claimed, trim($p, '/') . '/'));

                if (! $exists) {
                    $orphans[] = "{$cap->code} → {$claimed}";
                }
            }
        }

        $this->assertSame([], $orphans,
            "قدرةٌ تحرس مساراً لا وجودَ له — فهي تحرس لا شيء:\n  "
            . implode("\n  ", $orphans));
    }

    /**
     * @test
     *
     * **والمكشوفُ لا يزيد.**
     *
     * الرقمُ سقفٌ يُنقَص. ومن أضاف قدرةً جديدةً بلا مسارٍ يسقط هنا —
     * فلا تُبنى قدرةٌ خامسةٌ وستّون تُعرض في شاشة الخدمات ولا يحرسها شيء.
     */
    public function the_number_of_capabilities_without_a_route_never_grows(): void
    {
        $unrouted = $this->unrouted();
        $codes = array_map(static fn (Capability $c) => $c->code, $unrouted);

        sort($codes);

        $this->assertLessThanOrEqual(
            self::UNROUTED_CEILING,
            count($unrouted),
            sprintf(
                "قدراتٌ بلا مسارٍ صارت %d وكانت %d — وقدرةٌ بلا مسارٍ لا يحرسها "
                . "EnsureCapability إطلاقاً.\n  %s\n\n"
                . 'وإن كان النقصُ مقصوداً فأنزِل UNROUTED_CEILING معه.',
                count($unrouted), self::UNROUTED_CEILING, implode("\n  ", $codes),
            ),
        );
    }

    /**
     * @test
     *
     * **والقدراتُ الأساسيّةُ مستثناةٌ بنصٍّ لا بسكوت.**
     *
     * `core()` لا تُباع ولا تُقفَل بباقة — فغيابُ حارسِ باقةٍ عنها **صواب**
     * لا نقص. وتُسمَّى هنا لئلّا تُعدّ يوماً ديناً على المشروع فتُغلَق
     * بحسن نيّةٍ فتُنتج أرقاماً كاذبة.
     */
    public function the_core_capabilities_are_exempt_by_design_not_by_omission(): void
    {
        $core = array_values(array_filter(
            CapabilityRegistry::all(),
            static fn (Capability $c) => $c->isCore(),
        ));

        $this->assertNotEmpty($core, 'لا قدرةَ أساسيّةً واحدة — وهذا يعني أنّ كلَّ شيءٍ يُباع');

        foreach ($core as $cap) {
            $this->assertNull($cap->toArray()['min_plan'],
                "«{$cap->code}» أساسيّةٌ ولها حدُّ باقةٍ — وبيعُ الأساسيّ بيعُ أرقامٍ "
                . 'خاطئةٍ لمن دفع أقلّ');
        }
    }

    /**
     * ما تفحصه المتحكّماتُ بـ`hasFeature` — **يُقرأ من الشيفرة لا من
     * قائمةٍ مكتوبةٍ بيد**، فقائمةٌ تشيخ تجعل الحارسَ يكذب.
     *
     * @return array<string,true>
     */
    private function gatedInsideControllers(): array
    {
        $out = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers')));

        foreach ($files as $f) {
            if ($f->isDir() || $f->getExtension() !== 'php') {
                continue;
            }

            $src = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '',
                (string) file_get_contents($f->getPathname()));

            if (! preg_match_all(
                '~hasFeature\([^,]+,\s*(?:A|AccessConstants)::(F_[A-Z_]+)~', $src, $m)) {
                continue;
            }

            foreach ($m[1] as $const) {
                $name = \App\Support\Access\AccessConstants::class . '::' . $const;

                if (defined($name)) {
                    $out[constant($name)] = true;
                }
            }
        }

        return $out;
    }

    /**
     * @test
     *
     * **الرقمُ الذي يهمّ تجاريّاً: قدرةٌ مدفوعةٌ لها شاشةٌ ولا حارسَ لها
     * من أيّ نوع.**
     *
     * ولا يكفي عدُّ ما لا يُعلن مساراً: أحدَ عشرَ منها محروسٌ **داخل
     * المتحكّم** بـ`hasFeature`. فعدُّها مكشوفةً يُغرق التقريرَ بضجيجٍ
     * يُخفي المكشوفَ حقّاً — **وحارسٌ يصرخ كلَّ مرّةٍ يُتجاهَل**.
     *
     * فتُطرح الطبقاتُ الثلاث: وسيطُ القدرة · مسارٌ معلَنٌ في السجلّ ·
     * فحصٌ في متحكّم. والباقي هو المكشوف — **وهو سبعةٌ اليوم**، وكلُّها
     * في باقة الأعمال فما فوق.
     */
    public function paid_capabilities_with_a_screen_and_no_gate_at_all_never_grow(): void
    {
        $inControllers = $this->gatedInsideControllers();

        $open = [];

        foreach ($this->unrouted() as $cap) {
            $a = $cap->toArray();

            // الأساسيّةُ والمجّانيّةُ لا تُباع — فغيابُ حارسِ باقةٍ عنها صواب.
            if ($a['is_core'] || $a['min_plan'] === null || $a['min_plan'] === 'free') {
                continue;
            }

            // بلا شاشةٍ لا بابَ يُفتح من التطبيق.
            if (empty($a['screen'])) {
                continue;
            }

            if (isset($inControllers[$a['code']])) {
                continue;
            }

            $open[] = "{$a['code']} (باقة {$a['min_plan']} · شاشة {$a['screen']})";
        }

        sort($open);

        $this->assertLessThanOrEqual(7, count($open),
            sprintf(
                "قدراتٌ مدفوعةٌ لها شاشةٌ ولا يحرسها الخادمُ بشيءٍ صارت %d وكانت 7:\n  %s\n\n"
                . 'وكلُّ واحدةٍ منها تُفتح بنداءٍ مباشرٍ من باقةٍ لا تشتريها.',
                count($open), implode("\n  ", $open)));
    }
}
