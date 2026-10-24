<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ENTERPRISE-AUDIT-001 — **باقةُ المؤسّسة: مبنيّةٌ · موصولةٌ · تعمل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * تبيع المؤسّسةُ (٩٩ ر.س/شهر) **أربعَ عشرةَ ميزةً** فوق «الأعمال». وسؤالُ
 * صاحب المشروع ثلاثيّ، ولكلّ شقٍّ منه حارسٌ هنا:
 *
 *   **مبنيّة** → للقدرة شاشةٌ مصرَّحةٌ في السجلّ، ولها مسارٌ في الخادم.
 *   **موصولة** → التاجرُ المؤسَّسيُّ **يملكها فعلاً** في ملفّ صلاحيّاته،
 *                فلا تُعرَض «بالترقية» لمن رقّى بالفعل.
 *   **تعمل**   → البابُ يُطرَق فيجيب، ولا يردّ ٤٠٢ (مقفلةٌ بالباقة)
 *                ولا ٤٠٣ (ممنوع) ولا ٥٠٠ (منهار).
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ يلزم هذا:** نمطُ العطل الأكثر تكراراً في المشروع «مبنيٌّ ولا
 * يُوصَل إليه». وقد وقع في هذه الباقة بعينها مرّتين من قبل:
 * `retail.locations` و`retail.transfers` كانتا معلنتين بـ`minPlan
 * (enterprise)` **ولا يمنحهما مانحٌ واحد** — تُعرَض «بالترقية»، ويرقّى
 * التاجرُ فلا تُفتحان. **وحارسٌ يفحص الوجودَ ولا يفحص المنحَ يمرّ عليها.**
 */
class EnterprisePlanScreensWorkTest extends TestCase
{
    use RefreshDatabase;

    /** كلُّ أبواب المؤسّسة التي تُقرأ بـGET، وشاشتُها في التطبيق. */
    private const DOORS = [
        'الفروع' => 'merchant/branches',
        'المواقع (تجزئة)' => 'merchant/retail/locations',
        'التحويلات بين المواقع' => 'merchant/retail/transfers',
        'الأدوار والصلاحيات' => 'merchant/retail/roles',
        'العملات المتعددة' => 'merchant/currencies',
        'الأقساط' => 'merchant/installments/contracts',
        'سجلّ التدقيق' => 'merchant/audit-log',
        'النسخ الاحتياطي' => 'merchant/backup',
        'مفاتيح API' => 'merchant/api-keys',
        'الحسابات المؤسسية' => 'merchant/corporate/accounts',
        'لوحة المؤسسات' => 'merchant/corporate/dashboard',
    ];

    private function enterpriseMerchant(): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id,
            'tier' => 'large',
            'verification_status' => 'verified',
            'business_type' => 'retail',
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'single_receive_limit' => '5000000',
            'daily_receive_limit' => '50000000',
        ]);

        return $u;
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① موصولة — التاجرُ المؤسَّسيُّ يملك ما دفع ثمنَه.**
     *
     * وهذا الشقُّ هو الذي سقط مرّتين من قبل: قدرةٌ معلَنةٌ في الباقة ولا
     * مانحَ لها. فيُقاس **ملفُّ الصلاحيّات الفعليّ** لا قائمةُ التسعير.
     */
    /** @test */
    public function every_enterprise_feature_actually_reaches_the_merchant(): void
    {
        $merchant = $this->enterpriseMerchant();

        $access = app(\App\Services\FeatureAccessService::class)->accessFor($merchant);
        $granted = $access['features'] ?? [];

        // ══════════════════════════════════════════════════════════════
        // **والموعودُ يُقرأ من السجلّ لا من قائمة المنح.**
        //
        // أوّلُ صياغةٍ قارنت `planFeatures(enterprise)` بالممنوح — وكلاهما
        // من المصدر نفسِه، **فالحارسُ يُثبت أنّ القائمةَ تساوي نفسَها**
        // ويعمى عن العطل الحقيقيّ بعينه: قدرةٌ تُعلن `minPlan(enterprise)`
        // في السجلّ — فتظهر في صفحة التسعير و«قدراتي» — **ولا تُذكَر في
        // قائمة المنح**. وهو ما وقع لـ`retail.locations` و`retail.transfers`.
        //
        // فالمصدرُ هنا **ما يَعِد به السجلُّ التاجرَ**، والمقياسُ ما يصله.
        // ══════════════════════════════════════════════════════════════
        $promised = [];
        foreach (\App\Support\Access\CapabilityRegistry::all() as $cap) {
            $a = $cap->toArray();
            // **و`coming_soon` تُستثنى بحقّ**: مُعلَنةٌ ولم تُبنَ، تُعرَض
            // «قريباً» ولا تُباع ولا تُمنح — فمطالبتُها بالمنح تُلزم
            // النظامَ بما لم يَعِد به أحد. (وقد أمسكها هذا الحارسُ أوّلَ
            // تشغيلٍ فبدت عطلاً: `retail.discount_limit` — والتصريحُ فوقها
            // يقول بنصّه إنّها «اسمٌ بلا رقم»، لا عمودَ سقفٍ ولا فحصَ بيع.)
            if (($a['min_plan'] ?? null) === A::PLAN_ENTERPRISE
                && ($a['status'] ?? '') !== 'coming_soon') {
                $promised[] = $a['code'];
            }
        }
        // ومعها ما تعلنه قائمةُ الباقة نفسُها فوق «الأعمال».
        $promised = array_values(array_unique(array_merge($promised, array_diff(
            P::planFeatures(A::PLAN_ENTERPRISE),
            P::planFeatures(A::PLAN_BUSINESS),
        ))));

        $this->assertNotSame([], $promised, 'لم تُقرأ قدراتُ المؤسّسة أصلاً');

        $missing = array_values(array_diff($promised, $granted));

        $this->assertSame([], $missing, sprintf(
            "**ميزاتٌ تَعِد بها باقةُ المؤسّسة ولا تصل صاحبَها:**\n  %s\n\n"
            ."فيدفع التاجرُ ٩٩ ريالاً، وتفتح الشاشةُ، **فيردّه الحارسُ نفسُه**. "
            .'وهذا مقلوبُ «تُباع ولا وجودَ لها»: موجودةٌ ومبيعةٌ ومقفلة.',
            implode("\n  ", $missing)));
    }

    /**
     * **② وتعمل — كلُّ بابٍ يُطرَق فيجيب.**
     *
     * **ولا يُقاس ٢٠٠ وحدَه**: ٤٠٢ تعني «مقفلةٌ بالباقة» وهي كذبٌ على من
     * اشترى الباقة، و٤٠٣ «ممنوع»، و٥٠٠ انهيار. والثلاثةُ تصل صاحبَ المتجر
     * شاشةً فارغةً أو رسالةَ خطأ.
     */
    /** @test */
    public function every_enterprise_door_answers_for_a_paying_merchant(): void
    {
        $merchant = $this->enterpriseMerchant();

        $broken = [];
        foreach (self::DOORS as $label => $path) {
            $r = $this->actingAs($merchant, 'api')->getJson('/api/v1/amial/'.$path);

            if (in_array($r->status(), [402, 403, 500], true)) {
                $broken[] = sprintf('%s (%s) → %d %s',
                    $label, $path, $r->status(),
                    (string) ($r->json('message') ?? ''));
            }
        }

        $this->assertSame([], $broken, sprintf(
            "**أبوابٌ في باقة المؤسّسة لا تفتح لمن دفع:**\n  %s",
            implode("\n  ", $broken)));
    }

    /**
     * **③ ومبنيّة — لكلّ قدرةٍ تُباع شاشةٌ أو مسارٌ يخدمها.**
     *
     * وقدرةٌ بلا شاشةٍ ولا مسارٍ **ليست ميزةً بل سطرٌ في فاتورة**.
     * (الاستثناءُ المكتوب: قدراتُ الأدوار — `operations_manager`
     * و`financial_manager` — تُقيَّد بها صلاحيّاتُ موظّفٍ داخل شاشة
     * الأدوار القائمة، فلا شاشةَ مستقلّةً لها وهذا مقصود.)
     */
    /** @test */
    public function every_sold_capability_has_a_screen_or_a_route(): void
    {
        // قدراتُ الأدوار: تُستهلَك داخل شاشة الأدوار ولا تُفرَد بشاشة.
        $roleScoped = ['operations_manager', 'financial_manager'];

        $promised = array_values(array_diff(
            P::planFeatures(A::PLAN_ENTERPRISE),
            P::planFeatures(A::PLAN_BUSINESS),
        ));

        $orphans = [];
        foreach ($promised as $code) {
            if (in_array($code, $roleScoped, true)) {
                continue;
            }
            $cap = \App\Support\Access\CapabilityRegistry::find($code);
            if (!$cap) {
                $orphans[] = $code.' (لا تصريحَ في السجلّ)';
                continue;
            }
            // **الحقولُ خاصّة** — تُقرأ من `toArray()` وهي الواجهةُ التي
            // يُبنى منها ملفُّ التاجر نفسُه، فأقيسُ ما يقيسه المنتج.
            $a = $cap->toArray();
            if (($a['screen'] ?? null) === null && ($a['routes'] ?? []) === []) {
                $orphans[] = $code.' (بلا شاشةٍ وبلا مسار)';
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "**قدراتٌ تُباع في المؤسّسة بلا شاشةٍ ولا مسار:**\n  %s\n\n"
            .'وقدرةٌ لا يفتحها شيءٌ ليست ميزةً بل سطرٌ في فاتورة.',
            implode("\n  ", $orphans)));
    }

    /**
     * **④ ومحروسة — من لم يدفع لا يدخل.**
     *
     * الشقُّ المقابل للثلاثة: ميزةٌ «تعمل» للجميع ليست ميزةَ باقة، بل
     * إيرادٌ ضائعٌ ووعدٌ كاذبٌ لمن دفع. فيُقاس القفلُ من الجهة الأخرى.
     *
     * **وتنبيهٌ معماريّ قِيس ولم يُفترَض:** البوّابةُ الخادميّة تحرس
     * بـ`minPlan` من السجلّ، وملفُّ التطبيق يُبنى من `planFeatures` —
     * **مصدران متوازيان**. وهما متّفقان اليوم (يحرسه الاختبارُ ①)، لكنّ
     * نزعَ منحٍ من `planFeatures` وحدَه يُخفي الميزةَ في الواجهة ويُبقي
     * البابَ مفتوحاً. فالحارسان معاً لا أحدُهما.
     */
    /** @test */
    public function a_free_merchant_is_locked_out_of_every_enterprise_door(): void
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $u->id, 'tier' => 'micro',
            'verification_status' => 'verified', 'business_type' => 'retail',
            'subscription_plan' => A::PLAN_FREE,
            'single_receive_limit' => '100000', 'daily_receive_limit' => '100000',
        ]);

        $gate = app(\App\Services\Access\EntitlementService::class);

        $leaked = [];
        foreach (['branches', 'multi_currency', 'installments', 'api_access',
                  'corporate_accounts', 'audit_log', 'advanced_backup', 'rbac'] as $code) {
            if ($gate->gate($u, $code) === null) {
                $leaked[] = $code;
            }
        }

        $this->assertSame([], $leaked, sprintf(
            "**أبوابُ المؤسّسة مفتوحةٌ لتاجرٍ في الباقة المجّانيّة:**\n  %s\n\n"
            .'وميزةٌ تعمل للجميع ليست ميزةَ باقة.',
            implode("\n  ", $leaked)));
    }
}
