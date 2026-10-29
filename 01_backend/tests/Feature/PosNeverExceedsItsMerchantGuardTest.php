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
 * AMIAL-POS-CEILING-001 — **الوارثُ لا يفتح ما لا يفتحه مورِّثُه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * هذه **القاعدةُ الحاكمةُ للمربّع الثالث** (الورثة)، وهي مكتوبةٌ في رأس
 * `VerticalRegistry` منذ بُني:
 *
 *     الوارثُ يُضيف ولا يستبدل — ويُقتطع منه ولا يُوسَّع.
 *
 * فنقطةُ البيع ترث قطاعَ تاجرها وباقتَه، **ثمّ يُقتطع منها** بما مُنح
 * لها في `pos_users.permissions`. والمنحُ **تقاطعٌ لا اتّحاد**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ هي أخطرُ قاعدةٍ في المربّع:** لو صارت اتّحاداً — بإعادة صياغةٍ
 * حسنةِ النيّة — لَصار الالتفافُ على الباقات كلِّها **سطراً واحداً**:
 *
 *     نزِّل اشتراكَك إلى المجّانيّة
 *     امنح كاشيرَك كلَّ القدرات
 *     اعمل من حسابه
 *
 * **ولا شيءَ يظهر**: لا خطأ، ولا سجلّ، ولا رقمٌ ينقص. تُباع الباقاتُ
 * ويعمل الجميعُ مجّاناً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وستّةُ حرّاسٍ كانت تغطّي الوراثةَ ولا واحدَ منها يغطّي السقف**
 * (`PosActorAccessGuardTest`: يرث الصنف · الفارغُ بيعٌ فقط · الممنوحُ
 * يُحترَم · المالكُ يرى الكلّ · القطاعاتُ الأخرى لا تتأثّر · المعطَّلُ لا
 * يرث). كلُّها تسأل «أيصل ما يجب؟» **ولا واحدةَ تسأل «أيصل ما لا يجب؟»**.
 *
 * وكانت القاعدةُ تعمل بالبناء وحدَه — أي أنّها **مرجوّةٌ لا محروسة**.
 */
class PosNeverExceedsItsMerchantGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * تاجرٌ بقطاعٍ وباقة، ومعه كاشيرٌ مُنح **كلَّ قدرةٍ في الكتالوج**.
     *
     * **والمنحُ الأقصى مقصود**: هو أسوأُ حالةٍ ممكنة، وبها وحدَها يُختبر
     * السقف. فمنحٌ متواضعٌ يمرّ ولو كان الجمعُ اتّحاداً.
     *
     * @return array{0:User,1:User}
     */
    private function pair(string $biz, string $plan): array
    {
        $this->seq++;

        // **والدورُ يُضبط صراحةً.** `accessFor` تقرأ عمودَ `role` لا
        // `type`، وخطّافُ النموذج لا يشتقّه إلّا حين يكون فارغاً أو
        // `user`. والمصنعُ يضبط `'customer'` — **فتاجرٌ بالنوع ٣ يُقرأ
        // عميلاً** ويخرج بأربع قدرات. (أوّلُ صياغةٍ لهذا الحارس سقطت
        // عليه، وهو نفسُه ما تحرسه القاعدةُ السابعة: صفرٌ لا يعني «فُحص».)
        $merchant = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'zone_code' => 'SOUTH', 'is_active' => 1,
            'phone' => '9677700' . str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => $biz,
            'verification_status' => 'verified',
            'subscription_plan' => $plan,
        ]);

        $cashier = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'zone_code' => 'SOUTH', 'is_active' => 1,
            'phone' => '9677701' . str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
        ]);

        PosUser::create([
            'user_id' => $cashier->id,
            'merchant_user_id' => $merchant->id,
            'pos_number' => 'E' . $this->seq,
            'display_name' => 'كاشير',
            'is_active' => true,
            'permissions' => array_keys(CapabilityRegistry::all()),
        ]);

        return [$merchant->fresh(), $cashier->fresh()];
    }

    /**
     * @test
     *
     * **السقفُ في كلّ تركيبة: ثمانيَ عشرةَ (قطاع × باقة).**
     *
     * ولا يُفحص قطاعٌ واحدٌ ولا باقةٌ واحدة: العطلُ في هذا الصنف يظهر
     * غالباً في تركيبةٍ بعينها — قطاعٌ عمقُه مدفوعٌ على باقةٍ لا تشتريه.
     */
    public function a_cashier_never_receives_what_its_merchant_does_not_have(): void
    {
        $access = app(FeatureAccessService::class);

        $checked = 0;
        $leaks = [];

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            foreach (A::ALL_PLANS as $plan) {
                [$merchant, $cashier] = $this->pair($biz, $plan);

                $checked++;

                $his = $access->accessFor($merchant)['features'];
                $hers = $access->accessFor($cashier)['features'];

                // **ولا يُفحص فراغ.** تاجرٌ بلا قدراتٍ يجعل كلَّ مقارنةٍ
                // تمرّ — وهو الصمتُ بثوب نجاح.
                $this->assertGreaterThan(5, count($his), sprintf(
                    'التاجرُ «%s/%s» بلا قدرات — الحارسُ يفحص فراغاً', $biz, $plan));

                $beyond = array_values(array_diff($hers, $his));

                if ($beyond !== []) {
                    $leaks[] = sprintf('  %s × %s → %s',
                        $biz, $plan, implode('، ', $beyond));
                }
            }
        }

        $this->assertSame(
            count(A::ALL_BUSINESS_TYPES) * count(A::ALL_PLANS), $checked,
            'لم تُفحص كلُّ التركيبات — وحارسٌ يفحص بعضَها يقول «سليم» ولم ينظر');

        $this->assertSame([], $leaks,
            "**كاشيرٌ يفتح ما لا يفتحه تاجرُه:**\n" . implode("\n", $leaks) . "\n\n"
            . 'والمنحُ يقتطع من قدرات التاجر ولا يضيف إليها. ولو صار '
            . "اتّحاداً لصار الالتفافُ على الباقات سطراً واحداً:\n"
            . '  نزِّل اشتراكَك · امنح كاشيرَك كلَّ شيء · اعمل من حسابه.');
    }

    /**
     * @test
     *
     * **وعمقُ الباقة لا يُنال بالمنح.**
     *
     * وهو الوجهُ الماليُّ للقاعدة: قدرةٌ **تُباع** بباقةٍ لا يبلغها
     * التاجر، تُمنَح للكاشير صراحةً — ويجب ألّا تصل.
     */
    public function paid_depth_is_not_reachable_through_a_cashier_grant(): void
    {
        $access = app(FeatureAccessService::class);

        // قدرةٌ قطاعيّةٌ يبيعها الكتالوجُ بباقةِ الأعمال.
        $sold = A::F_PHARMACY_PRESCRIPTIONS;

        $cap = CapabilityRegistry::find($sold);
        $this->assertNotNull($cap, "قدرةٌ اختفت من الكتالوج: {$sold}");
        $this->assertSame(A::PLAN_BUSINESS, $cap->minimumPlan(),
            'لم تعد تُباع بباقة الأعمال — يُختار غيرُها، وإلّا فحص الحارسُ مجّانيّة');

        [$freeMerchant, $freeCashier] = $this->pair(A::BIZ_PHARMACY, A::PLAN_FREE);

        $this->assertNotContains($sold, $access->accessFor($freeMerchant)['features'],
            'التاجرُ المجّانيُّ يملكها أصلاً — فالاختبار يفحص فراغاً');

        $this->assertNotContains($sold, $access->accessFor($freeCashier)['features'],
            '**كاشيرُ تاجرٍ مجّانيٍّ نال قدرةً مدفوعة** — فالباقاتُ تُباع '
            . 'ويُلتفّ عليها من حساب الموظّف.');

        // **والضبطُ المقابل**: على الباقة التي تبيعها، تصل — وإلّا كان
        // الحارسُ قفلاً يمنع ما دُفع ثمنُه.
        [, $paidCashier] = $this->pair(A::BIZ_PHARMACY, A::PLAN_BUSINESS);

        $this->assertContains($sold, $access->accessFor($paidCashier)['features'],
            'كاشيرُ تاجرٍ دفع ثمنَ القدرة لا يراها — الحارسُ صار قفلاً');
    }

    /**
     * @test
     *
     * **ولا يرث قطاعاً غيرَ قطاعه.**
     *
     * فكاشيرُ الصيدليّة لا يفتح مضخّةَ وقود ولو مُنحها صراحةً — والقدرةُ
     * القطاعيّةُ تخصّ قطاعَها وحدَه.
     */
    public function a_cashier_never_crosses_into_another_vertical(): void
    {
        $access = app(FeatureAccessService::class);

        [, $cashier] = $this->pair(A::BIZ_PHARMACY, A::PLAN_ENTERPRISE);

        $hers = $access->accessFor($cashier)['features'];

        foreach ([A::F_FUEL_POS, A::F_FUEL_PUMPS, A::F_WHOLESALE_INVOICES,
                  A::F_RESTAURANT_ORDERS] as $foreign) {
            $this->assertNotContains($foreign, $hers, sprintf(
                'كاشيرُ صيدليّةٍ نال «%s» — وهي قدرةُ قطاعٍ آخر، '
                . 'فيُرسَم زرُّها ويُردّ بـ٤٠٢ عند الضغط.', $foreign));
        }

        // وقدرةُ قطاعه تصل — وإلّا فحصنا حساباً مقفلاً.
        $this->assertContains(A::F_PHARMACY_POS, $hers,
            'كاشيرُ الصيدليّة لا يرى نقطةَ بيعها — الحارسُ يفحص حساباً معطَّلاً');
    }
}
