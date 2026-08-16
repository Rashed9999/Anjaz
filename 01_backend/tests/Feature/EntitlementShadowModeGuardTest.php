<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ENTITLEMENTS-002 — **جدارُ دفعٍ يُشعَل على تجربةٍ حيّة يُطفئ متجراً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس قبل أن يقع:**
 *
 * أربعُ قدراتٍ مدفوعةٍ (‏`suppliers` · `purchases` · `profit_reports` ·
 * `branches`) عاشت **بلا حارسٍ** حتّى اليوم. وتجّارُ التجربة يستعملونها
 * الآن — ولا سبيلَ من هنا لمعرفة **من** يستعملها: لا قاعدةَ إنتاجٍ ولا
 * قياسَ استعمال.
 *
 * فربطُها بالوسيط وإشعالُ الإنفاذ في اللحظة نفسِها يعني أنّ تاجراً على
 * باقة الأعمال يفتح شاشةَ الموردين صباحَ الغد **فيجدها مقفلة**، بلا
 * إنذارٍ ولا سببٍ يفهمه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **فالإنفاذُ يبدأ مطفأً، وهذا ما يحرسه هذا الملفّ:**
 *
 * | ما يُفحص | لأنّ |
 * |---|---|
 * | المربوطُ حديثاً **في الظلّ** | وإلّا اشتعل جدارُ دفعٍ على تجربةٍ حيّة |
 * | الظلُّ **لا يمسّ ما كان محروساً** | وهذا ما أسقط الصيغةَ الأولى — أدناه |
 * | الظلُّ **يُمرّر** الطلب | وعدُ الظلّ ألّا يُلمَس السلوك — وحارسٌ يَعِد بذلك ولا يفي أسوأُ من إنفاذٍ صريح |
 * | الظلُّ **يكتب** الحادثة | ظلٌّ صامتٌ = لا شيء. والقرارُ يُبنى على القائمة |
 * | الإنفاذُ حين يُشعَل **يمنع فعلاً** | فلا يبقى مطفأً بحسن نيّةٍ إلى الأبد |
 * | `NOT_APPLICABLE` **لا تدخل الظلّ** | ليست منعاً بل إخفاءُ قدرةِ قطاعٍ آخر — وإمرارُها تفتح مضخّاتِ الوقود لصيدليّة |
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والصيغةُ الأولى كانت مفتاحاً عامّاً — وأسقطتها البوّابة.**
 *
 * `enforce => false` أطفأت الوسيطَ كلَّه، **فسقطت خمسةُ اختباراتٍ تُثبت
 * المنع**: `products` و`retail.*` و`rbac` محروسةٌ منذ زمن، فجاء «وضعُ
 * الأمان» فسلّمها مجّاناً.
 *
 * **حاجزٌ بُني ليمنع انقطاعاً كاد يفتح باباً مغلقاً** — وهو أخطرُ من
 * غيابه لأنّه يُطمئن. فصار الظلُّ **قائمةً مقصورةً على ما رُبط في هذه
 * الدفعة**، وإشعالُ واحدةٍ حذفُ رمزها منها.
 */
class EntitlementShadowModeGuardTest extends TestCase
{
    use RefreshDatabase;

    /** قدرةٌ مدفوعةٌ رُبطت في الدفعة الأولى، ولها مسارٌ حقيقيّ. */
    private const CAPABILITY = A::F_SUPPLIERS;

    private const URL = '/api/v1/amial/merchant/suppliers';

    /**
     * تاجرٌ على الباقة المجّانيّة — أي دون الحدّ الأدنى للقدرة.
     *
     * **و`role` صريحاً**: المصنعُ يثبّت `'customer'`، فيبقى التاجرُ عميلاً
     * في عين محرّك الصلاحيات ويُقرأ الردُّ خطأً. (نفسُ فخّ
     * `EntitlementsGuardTest`، ويُكرَّر هنا لأنّه فخٌّ لا اصطلاح.)
     */
    private function freeMerchant(): User
    {
        $merchant = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_FREE,
        ]);

        return $merchant->refresh();
    }

    /**
     * @test
     *
     * **القدرةُ المفحوصةُ مدفوعةٌ فعلاً** — وإلّا كان الملفُّ كلُّه يفحص لا شيء.
     *
     * ولو نُقلت `suppliers` يوماً إلى الباقة المجّانيّة لمرّ كلُّ ما تحته
     * **لأنّ لا منعَ أصلاً**، فيُقرأ ذلك «الظلُّ يعمل». وهو حارسٌ يكذب.
     */
    public function the_capability_under_test_is_actually_a_paid_one(): void
    {
        $cap = CapabilityRegistry::find(self::CAPABILITY);

        $this->assertNotNull($cap, 'القدرةُ المفحوصةُ غيرُ مسجَّلة');

        $this->assertNotNull($cap->toArray()['min_plan'],
            'القدرةُ المفحوصةُ بلا حدّ باقةٍ — فلا منعَ يُفحص ظلُّه');

        $this->assertNotSame(A::PLAN_FREE, $cap->toArray()['min_plan'],
            'القدرةُ المفحوصةُ صارت مجّانيّة — فاختر غيرَها وإلّا فحص هذا الملفُّ لا شيء');

        $this->assertNotSame([], $cap->toArray()['routes'],
            'القدرةُ المفحوصةُ لا تُعلن مساراً — فالوسيطُ لا يُستدعى لها');
    }

    /**
     * @test
     *
     * **المربوطُ حديثاً في الظلّ افتراضاً.**
     *
     * وهذا هو الحدُّ الذي يمنع أن تتحوّل هذه الدفعةُ إلى انقطاعٍ لتاجر.
     */
    public function the_newly_wired_capabilities_start_in_shadow(): void
    {
        $shadow = (array) config('amial.entitlements.shadow');

        foreach ([A::F_SUPPLIERS, A::F_PURCHASES, A::F_PROFIT_REPORTS, A::F_BRANCHES] as $code) {
            $this->assertContains($code, $shadow,
                "«{$code}» رُبطت بالوسيط وليست في الظلّ — فجدارُ دفعٍ اشتعل على "
                . 'تجربةٍ حيّة بلا أن يعرف أحدٌ من يتأثّر');
        }
    }

    /**
     * @test
     *
     * **والظلُّ لا يمسّ ما كان محروساً — وهذا ما أمسكته البوّابة.**
     *
     * الصيغةُ الأولى كانت مفتاحاً عامّاً يُطفئ الوسيطَ كلَّه، **فأسقطت
     * خمسةَ اختباراتٍ**: `products` و`retail.*` و`rbac` محروسةٌ منذ زمن،
     * فجاء «وضعُ الأمان» فسلّمها مجّاناً.
     *
     * **حاجزٌ بُني ليمنع انقطاعاً كاد يفتح باباً مغلقاً** — وهو أخطرُ من
     * غيابه، لأنّه يُطمئن.
     */
    public function shadow_never_covers_a_capability_that_was_already_enforced(): void
    {
        $shadow = (array) config('amial.entitlements.shadow');

        foreach ([A::F_PRODUCTS, 'rbac', 'retail.catalog', 'inventory_audit'] as $code) {
            $this->assertNotContains($code, $shadow,
                "«{$code}» كانت محروسةً ودخلت الظلَّ — فبابٌ مغلقٌ فُتح باسم الأمان");
        }
    }

    /**
     * @test
     *
     * **وفي الظلّ يمرّ الطلب.**
     */
    public function in_shadow_mode_the_request_passes(): void
    {
        config(['amial.entitlements.shadow' => [self::CAPABILITY]]);

        $response = $this->actingAs($this->freeMerchant(), 'api')->getJson(self::URL);

        $this->assertNotSame(402, $response->getStatusCode(),
            'الظلُّ منع الطلبَ — ووعدُه ألّا يُلمَس السلوك');
    }

    /**
     * @test
     *
     * **ويُكتب ما كان سيُمنَع.**
     *
     * ظلٌّ لا يكتب لا يُفيد شيئاً: القرارُ بإشعال الإنفاذ يُبنى على قائمةِ
     * من كان سيُمنَع، وبلا قائمةٍ يبقى الإنفاذُ مؤجّلاً إلى الأبد.
     */
    public function the_shadow_denial_is_written_where_it_is_read(): void
    {
        config(['amial.entitlements.shadow' => [self::CAPABILITY]]);

        $this->actingAs($this->freeMerchant(), 'api')->getJson(self::URL);

        $this->assertDatabaseHas('system_errors', [
            'exception' => 'entitlement.shadow.' . self::CAPABILITY . '.locked_by_plan',
        ]);
    }

    /**
     * @test
     *
     * **وحين يُشعَل الإنفاذُ يمنع فعلاً.**
     *
     * وهذه هي التجربةُ بالعكس (القاعدة الثانية): حارسٌ لم يمنع مرّةً ليس
     * حارساً — ولو بقي الوسيطُ يُمرّر في الحالتين لكان ربطُ القدرات كلُّه
     * زينةً في ملفّ.
     *
     * **و٤٠٢ لا ٤٠٣**: نقصُ الباقة يذهب لصاحب المتجر ليرقّي، ونقصُ الدور
     * لمديره ليمنحه. وخلطُهما يُرسل كليهما في الطريق الخطأ.
     */
    public function when_enforcement_is_lit_the_gate_actually_denies(): void
    {
        // إشعالُ الإنفاذ = حذفُ الرمز من قائمة الظلّ.
        config(['amial.entitlements.shadow' => []]);

        $response = $this->actingAs($this->freeMerchant(), 'api')->getJson(self::URL);

        $this->assertSame(402, $response->getStatusCode(),
            'الإنفاذُ مشتعلٌ والبابُ مفتوح — فالوسيطُ غيرُ موصولٍ بالمسار أصلاً');

        $response->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');

        // **ومعه طريقُ الخروج** — رسالةُ منعٍ بلا «كيف أُسمح لي» نصفُ رسالة.
        $this->assertNotNull($response->json('meta.unlock'),
            'المنعُ بلا معلومةِ الترقية — فالتاجرُ يعرف أنّه ممنوعٌ ولا يعرف الحلّ');
    }
}
