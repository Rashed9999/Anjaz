<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ENTITLEMENTS-010 — **لا تُباع قدرةٌ لم تُبنَ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي أدخل هذا الملفّ:**
 *
 * أربعُ قدراتٍ كانت في جدول الباقات ولا شيءَ خلفها — تظهر في صفحة
 * التسعير وفي «قدراتي» كأنّها جاهزة. اثنتان منها **كانتا مبنيّتين ولم
 * تُوصَلا** (‏`low_stock_alerts` و`pharmacy_prescriptions`)، واثنتان
 * **لم تُبنَيا أصلاً** (‏`offline_pos` و`retail.discount_limit`).
 *
 * **وأخذُ الثمن بلا عطاءٍ أسوأُ من إعطاءٍ بلا ثمن**: الثغرةُ الأمنيّةُ
 * تُكلّف المنصّةَ مالاً، وهذا يُكلّف التاجرَ ثقتَه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقاعدةُ التي يفرضها هذا الملفّ:**
 *
 *   قدرةٌ مدفوعة  ⇒  إمّا **موصولةٌ بنقطة نهاية**
 *                  ⇒  وإمّا **مُعلَنةٌ `coming_soon` ولا تمنحها باقة**
 *
 * ولا حالةَ ثالثة.
 */
class SoldButUnbuiltGuardTest extends TestCase
{
    use RefreshDatabase;

    private function rank(string $plan): int
    {
        return (int) (array_flip(A::ALL_PLANS)[$plan] ?? 0);
    }

    /** كلُّ قدرةٍ مدفوعةٍ مع بياناتها. */
    private function paid(): array
    {
        $out = [];

        foreach (CapabilityRegistry::all() as $cap) {
            $a = $cap->toArray();
            $min = $a['min_plan'] ?? null;

            if (($a['is_core'] ?? false) || $min === null || $this->rank($min) === 0) {
                continue;
            }

            $out[$a['code']] = $a;
        }

        return $out;
    }

    /** بوادئُ المسارات التي يحرسها `capability:` فعلاً في جدول المسارات. */
    private function gatedCodes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'capability:')) {
                    $out[substr($m, strlen('capability:'))] = true;
                }
            }
        }

        return $out;
    }

    /** ورموزٌ تُحرَس داخل المتحكّمات بـ`denyUnless` أو `hasFeature`. */
    private function gatedInControllers(): array
    {
        $out = [];

        foreach ((new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers')))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            if (preg_match_all('~denyUnless\([^,]+,\s*[\'"]([a-z0-9_.]+)[\'"]~', $src, $m)) {
                foreach ($m[1] as $c) {
                    $out[$c] = true;
                }
            }

            if (preg_match_all('~(?:hasFeature|gate)\([^,]+,\s*(?:A|AccessConstants)::(F_[A-Z_]+)~', $src, $m2)) {
                foreach ($m2[1] as $const) {
                    $v = constant(A::class.'::'.$const);
                    $out[$v] = true;
                }
            }
        }

        return $out;
    }

    /**
     * @test
     *
     * **① كلُّ قدرةٍ مدفوعةٍ إمّا موصولةٌ وإمّا مُعلَنةٌ «قريباً».**
     *
     * وهذا هو `sold-but-unimplemented = 0` حرفاً.
     */
    public function no_paid_capability_is_sold_without_an_implementation(): void
    {
        $gated = $this->gatedCodes() + $this->gatedInControllers();

        $hollow = [];

        foreach ($this->paid() as $code => $a) {
            if (($a['status'] ?? 'available') === 'coming_soon') {
                continue;   // مُصرَّحٌ بأنّها لم تُبنَ — تُفحص في ② أدناه
            }

            $reachable = $a['routes'] !== [] || isset($gated[$code]);

            if (! $reachable) {
                $hollow[] = sprintf('%-26s %s — تُباع ولا نقطةَ نهايةٍ لها ولا حارس',
                    $code, $a['min_plan']);
            }
        }

        $this->assertSame([], $hollow, sprintf(
            "قدرةٌ **تُباع في الباقات ولا شيءَ خلفها** — تظهر في صفحة التسعير "
            . "وفي «قدراتي» كأنّها جاهزة:\n  %s\n\n"
            . "والعلاجُ أحدُ اثنين: صِلها بنقطة نهايةٍ، أو أعلِنها "
            . '`->comingSoon()` فتخرج من الباقات.',
            implode("\n  ", $hollow)));
    }

    /**
     * @test
     *
     * **② والمُعلَنةُ «قريباً» لا تمنحها أيُّ باقة.**
     *
     * فحقلٌ يقول «قريباً» بينما الاتّحادُ يمنحها **يُنتج زرّاً يعمل على لا
     * شيء** — وهو أسوأُ من الحالتين: وعدٌ في الشاشة وصمتٌ في الخادم.
     */
    public function a_coming_soon_capability_is_granted_by_no_plan(): void
    {
        $svc = app(FeatureAccessService::class);
        $granted = [];

        foreach ($this->paid() as $code => $a) {
            if (($a['status'] ?? 'available') !== 'coming_soon') {
                continue;
            }

            $feature = $a['feature'] ?? $code;

            foreach (A::ALL_PLANS as $plan) {
                foreach (A::ALL_BUSINESS_TYPES as $biz) {
                    $features = $svc->resolveFeatures(A::ROLE_MERCHANT, 'verified', $biz, $plan, []);

                    if (in_array($feature, $features, true)) {
                        $granted[] = "«{$code}» مُعلَنةٌ «قريباً» ومُنحت على {$plan} ({$biz})";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($granted)), sprintf(
            "قدرةٌ «قريباً» تُمنح فعلاً — **فالتطبيقُ يفتح لها باباً على لا شيء**:\n  %s",
            implode("\n  ", array_unique($granted))));
    }

    /**
     * @test
     *
     * **③ والقدرتان الموصولتان حديثاً تُمنعان فعلاً على باقةٍ دونهما.**
     *
     * فالوصلُ يُثبَت بالمنع لا بوجود السطر. (‏والضابطُ: تُقاسان بطلبٍ
     * حقيقيّ في `PaidEndpointBypassMatrixTest` أيضاً.)
     */
    public function the_newly_wired_capabilities_actually_deny(): void
    {
        $pharmacy = $this->merchant(A::BIZ_PHARMACY, A::PLAN_FREE);

        // تنبيهُ النفاد — يُباع من «البداية».
        $this->actingAs($pharmacy, 'api')
            ->getJson('/api/v1/amial/merchant/pharmacy/alerts')
            ->assertStatus(402);

        // ══════════════════════════════════════════════════════════════
        // **والباقةُ تُقرأ من سجلّ القدرات لا تُكتَب اسماً.**
        //
        // كان مكتوباً «من تاجر محترف»، ثمّ نزل `minPlan` للوصفات إلى
        // «الأعمال» في توحيد الكتالوج — **قرارُ تسعيرٍ لا ثغرةُ جدار**.
        // فسقط الحارسُ على تغييرٍ مقصود، وأوهم أنّ جداراً مدفوعاً انكسر.
        //
        // فيُجرَّب على **الباقة التي دون الحدّ المُعلَن** أيّاً كانت:
        // يبقى الجدارُ محروساً، ويتبع الكتالوجَ حيثما ذهب.
        // ══════════════════════════════════════════════════════════════
        $min = \App\Support\Access\CapabilityRegistry::find(A::F_PHARMACY_PRESCRIPTIONS)?->minimumPlan();

        $this->assertNotNull($min, 'الوصفاتُ بلا حدِّ باقةٍ مُعلَن');

        $below = $min === A::PLAN_ENTERPRISE ? A::PLAN_BUSINESS : A::PLAN_FREE;

        $under = $this->merchant(A::BIZ_PHARMACY, $below);

        $this->actingAs($under, 'api')
            ->postJson('/api/v1/amial/merchant/pharmacy/products', [
                'trade_name' => 'دواء', 'sale_price' => 10,
                'requires_prescription' => true,
            ])->assertStatus(402);
    }

    /**
     * @test
     *
     * **وضابطُها: الباقةُ المستحقّةُ لا تُمنَع.**
     *
     * فبوّابةٌ تردّ ٤٠٢ على الجميع تجتاز الفحصَ أعلاه كاملاً وتُعطّل المنتج.
     */
    public function the_entitled_plan_reaches_the_newly_wired_capabilities(): void
    {
        $pro = $this->merchant(A::BIZ_PHARMACY, A::PLAN_MERCHANT_PRO);

        $this->assertNotSame(402,
            $this->actingAs($pro, 'api')
                ->getJson('/api/v1/amial/merchant/pharmacy/alerts')->status(),
            'تنبيهُ النفاد مُنع على باقةٍ تستحقّه');

        $this->assertNotSame(402,
            $this->actingAs($pro, 'api')
                ->postJson('/api/v1/amial/merchant/pharmacy/products', [
                    'trade_name' => 'دواء', 'sale_price' => 10,
                    'requires_prescription' => true,
                ])->status(),
            'وسمُ الوصفة مُنع على باقةٍ تستحقّه');
    }

    private function merchant(string $biz, string $plan): User
    {
        $u = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'verification_status' => 'verified',
            'business_type' => $biz, 'subscription_plan' => $plan,
        ]);

        return $u->refresh();
    }
}
