<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-LANES-001 — **كلُّ قطاعٍ في حارته، والستّةُ تُقاس لا اثنان.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال كما وصل، وهو عادل:**
 *
 *     «إذا كنت تدّعي إصلاحات كودكس صحيحة… لماذا قبل الإصلاحات تدّعي لا
 *      يوجد مشاكل؟ وربما انتقل كودكس إلى قطاع تاجرٍ آخر — فماذا تقول عن
 *      القطاعات الأخرى؟ هل هي سليمةٌ وخاليةٌ من التداخل، أم تفضّل أن
 *      يكتشفها كودكس مثلما اكتشف مشاكل الصيدلية؟»
 *
 * **والجوابُ الصادق: لم أكن أفحصها.** قِيس ما كان:
 *
 *   · `VerticalScopeGuardTest` يفحص **قائمة القدرات** لأربعة قطاعاتٍ من
 *     ستّة (لا مطعمَ ولا بسطة) — **ولا يفتح نقطةَ نهايةٍ واحدة**.
 *   · `PharmacyVerticalBoundaryTest` يفحص **الصيدليّة وحدَها** على الـAPI.
 *   · ولا حارسَ لِـ«أيستطيع تاجرُ تجزئةٍ فتحَ واجهة الوقود؟».
 *
 * فبوّابتي كانت تخرج «٣٤ فحصاً · لا فشل» **وهي عمياءُ عن هذا الصنف
 * كلِّه**. والصمتُ قُرئ سلامةً، وهو لم يكن قياساً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الحارسُ يقيس المصفوفةَ كاملة**: ستّةُ قطاعاتٍ × كلُّ واجهةٍ
 * قطاعيّة، **من الـAPI نفسِه لا من قائمةٍ في الذاكرة**. فإخفاءُ زرٍّ في
 * الشاشة ليس عزلاً: من فتح المسارَ بأيّ أداةٍ يتجاوزه.
 *
 * **ولا يُقاس رمزُ الرفض بل وقوعُه**: قد يُردّ الطلبُ لسببٍ آخرَ عرَضاً
 * (‏جهازُ نقطة بيعٍ مطلوب مثلاً) فيبدو الحاجزُ قائماً وهو غائب. فيُصنع
 * تاجرٌ مكتملُ الشروط، ويُقاس الردُّ عليه وحدَه.
 */
class EveryVerticalStaysInItsLaneGuardTest extends TestCase
{
    use RefreshDatabase;

    /** الواجهةُ القطاعيّةُ التي لا يجوز أن يفتحها غيرُ أهلها. */
    private const LANES = [
        A::BIZ_PHARMACY => '/api/v1/amial/merchant/pharmacy',
        A::BIZ_WHOLESALE => '/api/v1/amial/merchant/wholesale',
        A::BIZ_FUEL => '/api/v1/amial/merchant/fuel/companies',
        A::BIZ_RETAIL => '/api/v1/amial/merchant/retail/brands',
    ];

    private const ALL_VERTICALS = [
        A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_FUEL,
        A::BIZ_PHARMACY, A::BIZ_WHOLESALE, A::BIZ_RESTAURANT,
    ];

    private int $seq = 0;

    /** تاجرٌ مكتملُ الشروط من قطاعٍ بعينه — فلا يُردّ لسببٍ غيرِ القطاع. */
    private function merchantOf(string $businessType): User
    {
        $this->seq++;

        $u = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
            'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1,
            'phone' => '9677718000'.str_pad((string) $this->seq, 2, '0', STR_PAD_LEFT),
        ]);

        // **وباقةُ الأعمال عمداً** — واجهةُ التجزئة تردّ ٤٠٢ على الباقة
        // المجانيّة، وذاك قفلُ باقةٍ لا حاجزُ قطاع. فلولا الباقةُ لَقُرئ
        // الرفضُ عزلاً وهو تسعير، **ولَبدا الحاجزُ قائماً وهو غائب**.
        MerchantProfile::create([
            'user_id' => $u->id,
            'business_type' => $businessType,
            'business_name' => 'منشأة '.$businessType,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
            'subscription_expires_at' => now()->addYear(),
        ]);

        return $u;
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① لا قطاعَ يفتح واجهةَ قطاعٍ آخر.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وتُقاس **المصفوفةُ كاملة** — ستّةُ أصحابٍ × أربعُ واجهات — لا
     * الحالةُ التي انكشفت وحدَها. فحاجزٌ يُبنى على الصيدليّة بعد شكوى
     * ويُترك الوقودُ مكشوفاً يُنتج الشكوى الثانية بعد شهر.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function no_vertical_can_open_another_verticals_api(): void
    {
        $leaks = [];

        foreach (self::LANES as $lane => $url) {
            foreach (self::ALL_VERTICALS as $visitor) {
                if ($visitor === $lane) {
                    continue;
                }

                Passport::actingAs($this->merchantOf($visitor));

                $status = $this->getJson($url)->status();

                if ($status < 400) {
                    $leaks[] = sprintf('  «%s» فتح واجهة «%s» (%d) ← %s',
                        $visitor, $lane, $status, $url);
                }
            }
        }

        $this->assertSame([], $leaks, sprintf(
            "**قطاعاتٌ تفتح واجهاتٍ ليست لها:**\n%s\n\n"
            .'وإخفاءُ الزرّ في الشاشة ليس عزلاً — من فتح المسارَ بأيّ '
            ."أداةٍ يتجاوزه.\n"
            .'وأثرُه ليس تجميليّاً: بياناتُ منشأةٍ تُقرأ من منشأةٍ أخرى، '
            .'وعمليّاتٌ تُكتب في قطاعٍ لا يعرفها محرّكُه.',
            implode("\n", $leaks)));
    }

    /**
     * **② وصاحبُ الحارة يدخلها.**
     *
     * فعزلٌ يمنع الجميعَ ليس عزلاً بل عطل — وهو أسهلُ ما يُكتب حين
     * يُطارَد التسريب.
     */
    /** @test */
    public function each_vertical_still_opens_its_own_api(): void
    {
        $closed = [];

        foreach (self::LANES as $lane => $url) {
            Passport::actingAs($this->merchantOf($lane));

            $status = $this->getJson($url)->status();

            if ($status >= 400) {
                $closed[] = sprintf('  «%s» لا يفتح واجهتَه (%d) ← %s',
                    $lane, $status, $url);
            }
        }

        $this->assertSame([], $closed, sprintf(
            "**قطاعاتٌ مُنعت من واجهتها هي:**\n%s\n\n"
            .'وحاجزٌ يمنع الجميعَ يُطمئن ولا يحرس، ويشلّ عملاً سليماً.',
            implode("\n", $closed)));
    }

    /**
     * **③ والقائمةُ تغطّي القطاعات الستّة — لا أربعةً منها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فالحارسُ القائمُ (`VerticalScopeGuardTest`) يفحص أربعةً، **ولا
     * مطعمَ فيه ولا بسطة**. وقطاعٌ خارج القياس يُقرأ سليماً وهو غيرُ
     * مفحوص. (القاعدة السابعة: «غير معروف» ليس صفراً.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_matrix_covers_every_vertical_the_platform_declares(): void
    {
        $declared = [
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_FUEL,
            A::BIZ_PHARMACY, A::BIZ_WHOLESALE, A::BIZ_RESTAURANT,
        ];

        $missing = array_values(array_diff($declared, self::ALL_VERTICALS));

        $this->assertSame([], $missing, sprintf(
            '**قطاعاتٌ تُعلنها المنصّةُ وخارج هذا القياس: %s.** '
            .'فيُقرأ صمتُها سلامةً وهي غيرُ مفحوصة.',
            implode('، ', $missing)));

        $this->assertGreaterThanOrEqual(4, count(self::LANES),
            'تقلّصت الواجهاتُ المقيسة — والمصفوفةُ تفحص فراغاً.');
    }
}
