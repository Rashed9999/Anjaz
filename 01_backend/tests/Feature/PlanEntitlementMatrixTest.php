<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ENTITLEMENTS-007 — **مصفوفةُ الاستحقاق: كلُّ قدرةٍ × كلُّ باقة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا مصفوفةٌ لا اختباراتٌ مفردة:**
 *
 * الحرّاسُ المفردةُ تسأل «أتُمنَع هذه القدرةُ على المجّانيّة؟» فتمرّ —
 * **والثغرةُ تكون في قدرةٍ لم يسألها أحد**. وقد وقع: ستُّ قدراتٍ كانت
 * تُمنح مجّاناً عبر `business_type` بينما السجلُّ يبيعها، ولم يكشفها
 * اختبارٌ واحدٌ من ١٩٦٢ لأنّ لا أحدَ سأل عنها بالذات.
 *
 * **فالسؤالُ يُطرح آليّاً على كلّ صفٍّ في السجلّ** — فقدرةٌ تُضاف غداً
 * تدخل المصفوفةَ بلا أن يتذكّرها أحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما تسأله هذه المصفوفة:**
 *
 *   ① الاتّحاد     — أتُمنح قدرةٌ مدفوعةٌ لباقةٍ دونها بأيّ طريق؟
 *   ② نوعُ النشاط  — أيُسرّب `business_type` قدرةً مدفوعة؟
 *   ③ الانتهاء     — أتسقط الباقةُ المنتهيةُ إلى المجّانيّ فعلاً؟
 *   ④ الإضافيّ     — أيَمنح `extra_features` ما لم تبعه الباقة؟ (نعم — عمداً)
 *   ⑤ الترتيب      — أباقةٌ أعلى تُعطي كلَّ ما تُعطيه الأدنى؟
 */
class PlanEntitlementMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): FeatureAccessService
    {
        return app(FeatureAccessService::class);
    }

    /** رتبةُ الباقة — والأعلى رقماً أعلى منزلة. */
    private function rank(string $plan): int
    {
        return (int) array_flip(A::ALL_PLANS)[$plan];
    }

    /** كلُّ قدرةٍ مدفوعةٍ مع أدنى باقةٍ تفتحها. */
    private function paidCapabilities(): array
    {
        $out = [];

        foreach (CapabilityRegistry::all() as $cap) {
            $a = $cap->toArray();
            $min = $a['min_plan'] ?? null;

            if ($min === null || ! in_array($min, A::ALL_PLANS, true) || $this->rank($min) === 0) {
                continue;   // مجّانيّةٌ أو بلا باقةِ حدّ
            }

            $out[$a['code']] = ['min' => $min, 'feature' => $a['feature'] ?? $a['code']];
        }

        return $out;
    }

    /**
     * @test
     *
     * **① ولا قدرةٌ مدفوعةٌ تُمنح لباقةٍ دونها — بأيّ نوع نشاطٍ أو توثيق.**
     *
     * وهذه هي المصفوفةُ الكاملة: قدرةٌ × باقةٌ أدنى × ستّةُ أنواع نشاطٍ
     * × حالتَي توثيق. وكلُّ منحٍ هنا **مالٌ يُسلَّم بلا ثمن**.
     */
    public function no_paid_capability_leaks_to_a_lower_plan(): void
    {
        $leaks = [];

        foreach ($this->paidCapabilities() as $code => $c) {
            foreach (A::ALL_PLANS as $plan) {
                if ($this->rank($plan) >= $this->rank($c['min'])) {
                    continue;
                }

                foreach (A::ALL_BUSINESS_TYPES as $biz) {
                    foreach (['verified', 'unverified'] as $ver) {
                        $features = $this->svc()->resolveFeatures(
                            A::ROLE_MERCHANT, $ver, $biz, $plan, []);

                        if (in_array($c['feature'], $features, true)) {
                            $leaks[] = sprintf('%-26s يحتاج %-12s فمُنح على %-12s (%s · %s)',
                                $code, $c['min'], $plan, $biz, $ver);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $leaks, sprintf(
            "قدرةٌ مدفوعةٌ تُمنح لباقةٍ دونها — **والاتّحادُ يُرجّح الأسخى**، "
            . "فالسجلُّ يقول «محروسة» والواقعُ يُسلّمها بلا ثمن:\n  %s",
            implode("\n  ", $leaks)));
    }

    /**
     * @test
     *
     * **② والباقةُ الأعلى لا تنقص عمّا دونها.**
     *
     * سلّمٌ ينكسر في درجةٍ يُنتج تاجراً يدفع أكثر فيحصل على أقلّ — ولا
     * يظهر في أيّ اختبارٍ يسأل عن باقةٍ واحدة.
     */
    public function a_higher_plan_never_gives_less_than_a_lower_one(): void
    {
        $breaks = [];

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            $previous = null;
            $previousPlan = null;

            foreach (A::ALL_PLANS as $plan) {
                $now = $this->svc()->resolveFeatures(A::ROLE_MERCHANT, 'verified', $biz, $plan, []);

                if ($previous !== null) {
                    $lost = array_diff($previous, $now);

                    foreach ($lost as $f) {
                        $breaks[] = "{$biz}: «{$f}» كانت في {$previousPlan} وغابت عن {$plan}";
                    }
                }

                $previous = $now;
                $previousPlan = $plan;
            }
        }

        $this->assertSame([], $breaks, sprintf(
            "الترقيةُ أنقصت ميزة — فالتاجرُ يدفع أكثرَ فيحصل على أقلّ:\n  %s",
            implode("\n  ", $breaks)));
    }

    /**
     * @test
     *
     * **③ والاشتراكُ المنتهي يسقط إلى المجّانيّ.**
     *
     * ويُقاس **بالمقارنة لا بالادّعاء**: ما يراه المنتهي يجب أن يساوي
     * ما يراه المجّانيُّ تماماً — لا أكثرَ ولا أقلّ.
     */
    public function an_expired_subscription_falls_back_to_free(): void
    {
        foreach ([A::PLAN_STARTER, A::PLAN_BUSINESS, A::PLAN_MERCHANT_PRO, A::PLAN_ENTERPRISE] as $plan) {
            $expired = $this->merchant(A::BIZ_RETAIL, $plan, expiredDays: 3);
            $free = $this->merchant(A::BIZ_RETAIL, A::PLAN_FREE);

            $a = $this->svc()->accessFor($expired->refresh());
            $b = $this->svc()->accessFor($free->refresh());

            sort($a['features']);
            sort($b['features']);

            $this->assertSame($b['features'], $a['features'], sprintf(
                'اشتراكُ «%s» منتهٍ ولم يسقط إلى المجّانيّ — **فالتاجرُ '
                . 'يعمل بباقةٍ لم يعد يدفع ثمنَها**. الفرق: %s',
                $plan, implode(' · ', array_diff($a['features'], $b['features']))));
        }
    }

    /**
     * @test
     *
     * **وضابطُ الانتهاء: اشتراكٌ سارٍ لا يسقط.**
     *
     * فاختبارٌ يمرّ لأنّ **كلَّ** الباقات تُقرأ مجّانيّةً يُثبت العكسَ
     * ولا يعلم. (وقد وقع نظيرُه في هذا المشروع: بذرٌ ساقطٌ جعل عدّادين
     * يتّفقان على الصفر فقُرئ «لا تعارض».)
     */
    public function a_live_subscription_does_not_fall_back(): void
    {
        $live = $this->merchant(A::BIZ_RETAIL, A::PLAN_BUSINESS, expiredDays: -30);
        $free = $this->merchant(A::BIZ_RETAIL, A::PLAN_FREE);

        $a = $this->svc()->accessFor($live->refresh())['features'];
        $b = $this->svc()->accessFor($free->refresh())['features'];

        $this->assertNotEmpty(array_diff($a, $b),
            'باقةُ الأعمال السارية لا تُعطي شيئاً فوق المجّانيّة — '
            . '**فاختبارُ الانتهاء أعلاه يقارن الصفرَ بالصفر**');
    }

    /**
     * @test
     *
     * **④ والميزةُ الإضافيّةُ تُمنح فوق الباقة — وهذا مقصود.**
     *
     * `extra_features` بابُ الأدمن لِمَن اتُّفق معه خارج الباقات. وهو
     * **ليس ثغرة** ما دام لا يُفتح إلّا من الأدمن. ويُثبَّت هنا لئلّا
     * يُغلَق يوماً بحسن نيّةٍ فيسقط عملاءُ اتُّفق معهم.
     */
    public function an_admin_granted_extra_feature_is_honoured(): void
    {
        $free = $this->svc()->resolveFeatures(A::ROLE_MERCHANT, 'verified', A::BIZ_RETAIL, A::PLAN_FREE, []);
        $granted = $this->svc()->resolveFeatures(A::ROLE_MERCHANT, 'verified', A::BIZ_RETAIL, A::PLAN_FREE, [A::F_SUPPLIERS]);

        $this->assertNotContains(A::F_SUPPLIERS, $free,
            'المورّدون مُنحوا مجّاناً بلا إضافةٍ من الأدمن');

        $this->assertContains(A::F_SUPPLIERS, $granted,
            '**الميزةُ الإضافيّةُ لم تُحترَم** — فمن اتُّفق معه خارج الباقات لا يحصل عليها');
    }

    /**
     * @test
     *
     * **⑤ ولا يرث العميلُ ولا الوكيلُ قدرةَ تاجرٍ مدفوعة.**
     *
     * فأساسُ الدور يُتّحد مع غيره، وميزةٌ تسرّبت إلى `roleBase` تُمنح
     * **لكلّ مستخدمي المنصّة** لا لتاجرٍ واحد.
     */
    public function non_merchant_roles_never_inherit_paid_merchant_capabilities(): void
    {
        $leaks = [];

        foreach ([A::ROLE_USER, A::ROLE_AGENT, A::ROLE_DISTRIBUTOR] as $role) {
            $features = $this->svc()->resolveFeatures($role, 'verified', null, A::PLAN_FREE, []);

            foreach ($this->paidCapabilities() as $code => $c) {
                if (in_array($c['feature'], $features, true)) {
                    $leaks[] = "{$role} ورث «{$code}» (تحتاج {$c['min']})";
                }
            }
        }

        $this->assertSame([], $leaks, sprintf(
            "دورٌ غيرُ تاجرٍ ورث قدرةً مدفوعة — **فهي تُمنح لكلّ مستخدمي "
            . "المنصّة**:\n  %s", implode("\n  ", $leaks)));
    }

    // ══════════════════════════════════════════════════════════════════

    private function merchant(string $biz, string $plan, ?int $expiredDays = null): User
    {
        $u = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id,
            'verification_status' => 'verified',
            'business_type' => $biz,
            'subscription_plan' => $plan,
            'subscription_expires_at' => $expiredDays === null
                ? null : now()->subDays($expiredDays),
        ]);

        return $u->refresh();
    }

    /** موظّفُ نقطةِ بيعٍ تحت تاجر — يُستعمل في مصفوفة نقاط النهاية. */
    private function cashier(User $merchant): User
    {
        $staff = User::factory()->create(['type' => 4, 'role' => 'pos']);

        PosUser::create([
            'user_id' => $staff->id,
            'merchant_user_id' => $merchant->id,
            'pos_number' => 'POS-'.$staff->id,
            'display_name' => 'كاشير',
            'is_active' => true,
        ]);

        return $staff->refresh();
    }
}
