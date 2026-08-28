<?php

namespace Tests\Feature;

use App\Domain\Verticals\MerchantVertical;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-OOP-001 — **الصندوقُ المشترك يطابق ما يُمنح فعلاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * أُنشئ صندوقٌ واحدٌ لِما يناله كلُّ تاجرٍ مهما كان نشاطُه. **وصندوقٌ
 * يفترق عمّا يُمنح فعلاً أسوأ من غيابه**: يقرأ منه القارئُ حقيقةً لا
 * وجودَ لها في التشغيل، ويبني عليها.
 *
 * فالحارسُ يُثبت أنّه **نسخةٌ صادقةٌ عن `roleBase(ROLE_MERCHANT)`** لا
 * قائمةٌ ثانيةٌ تشيخ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ صندوقٌ أصلاً:** قِيس أنّ `receipts` و`daily_reports` تُمنحان
 * مرّتين — من الدور، ثمّ من قائمة كلّ قطاع. وتكرارُ المنح لا يُغيّر
 * سلوكاً اليوم، **لكنّه يجعل نزعَ إحداهما من الدور بلا أثر**: يظنّ من
 * نزعها أنّه أغلقها وهي مفتوحةٌ من ستّة أبوابٍ أخرى.
 */
class MerchantSharedBoxGuardTest extends TestCase
{
    /** @test */
    public function the_shared_box_is_exactly_what_the_role_grants_every_merchant(): void
    {
        $box = MerchantVertical::shared();
        $role = AccessPresets::roleBase(A::ROLE_MERCHANT);

        sort($box);
        sort($role);

        $this->assertSame($role, $box,
            "**الصندوقُ المشترك يفترق عمّا يمنحه الدورُ فعلاً.**\n"
            . '  الدور : ' . implode('، ', $role) . "\n"
            . '  الصندوق: ' . implode('، ', $box) . "\n\n"
            . 'وصندوقٌ يفترق عن التشغيل يُقرأ حقيقةً لا وجودَ لها. '
            . 'فإمّا يُحدَّث، وإمّا يُنقل المنحُ إليه.');
    }

    /**
     * **ولا قطاعَ يُعيد ما في الصندوق.**
     *
     * من أعادها ظنّ أنّه يفتحها وهي مفتوحة. والأخطرُ عكسُه: من نزعها من
     * الصندوق ظنّ أنّه أغلقها، وهي مفتوحةٌ من قائمة القطاع.
     *
     * **وهذا يُقاس على القائمة القائمة اليوم** (`businessTypeFeatures`)
     * فيُظهر التكرارَ الموجود قبل النقل — ولا يمنعه، بل يعدّه ويسمّيه.
     *
     * @test
     */
    public function the_duplication_between_role_and_verticals_is_named_not_hidden(): void
    {
        $box = MerchantVertical::shared();

        $repeated = [];

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            foreach (array_intersect(AccessPresets::businessTypeFeatures($biz), $box) as $f) {
                $repeated[$f][] = $biz;
            }
        }

        // **ولا يُطلب صفرٌ اليوم** — النقلُ لم يقع بعد. المطلوبُ أن يبقى
        // العددُ معروفاً فلا يزيد صامتاً، وأن يُقرأ بأسمائه لا رقماً.
        $this->assertLessThanOrEqual(2, count($repeated), sprintf(
            "**تكرارٌ جديدٌ بين الدور والقطاعات.** المكرَّرُ الآن:\n  %s\n\n"
            . 'وكان اثنين (`receipts` و`daily_reports`) — فما زاد عليهما '
            . 'يعني ميزةً تُمنح من بابين، ونزعُها من أحدهما بلا أثر.',
            implode("\n  ", array_map(
                fn ($f, $types) => $f . ' ← ' . implode('، ', $types),
                array_keys($repeated), $repeated))));
    }

    /**
     * **والمشتركُ مشتركٌ فعلاً** — لا ميزةَ قطاعٍ دُسّت فيه.
     *
     * فميزةُ صيدليّةٍ في الصندوق تُمنح لبائع السمك، ويُرسَم زرُّها في
     * شاشته، ويُضغَط ولا شيءَ خلفه.
     *
     * @test
     */
    public function no_vertical_specific_feature_leaked_into_the_shared_box(): void
    {
        foreach (MerchantVertical::shared() as $feature) {
            foreach (['pharmacy_', 'fuel_', 'wholesale_', 'restaurant_'] as $prefix) {
                $this->assertStringStartsNotWith($prefix, $feature,
                    "**«{$feature}» ميزةُ قطاعٍ بعينه وهي في الصندوق المشترك** — "
                    . 'فتُمنح لكلّ تاجرٍ مهما كان نشاطُه، ويُرسَم زرُّها في '
                    . 'شاشةٍ لا تخصّه.');
            }
        }
    }
}
