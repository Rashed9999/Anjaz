<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-PROFILE-NULL-001 — **حسابُ تاجرٍ بلا ملفٍّ يُسقط صفحتين.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس بمسبار اللوحة:**
 *
 *     500  /admin/amial/merchant-center/{id}/overview
 *     500  /admin/amial/entitlements/merchants/{id}
 *     Attempt to read property "business_type" on null
 *
 * مستخدمٌ نوعُه ٣ ولا صفَّ له في `merchant_profiles` — ويقع عند إنشاءٍ
 * يدويٍّ من اللوحة أو ترقيةِ حسابٍ لم تكتمل. فيقرأ الكودُ `$p->…` على
 * `null`، وتردّ صفحتان **٥٠٠**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما فيه أنّه بقي مستوراً حتّى اليوم**، والسببُ في **كيفيّة**
 * الفحص لا في غيابه:
 *
 * `scripts/sweep-admin.php` يفتح ١٧٢ مساراً في كلّ بوّابة — **وقاعدةُ
 * التطوير تُفرَّغ قبله** بـ`RefreshDatabase`. فلا مستخدمَ نوعُه ٣ إطلاقاً،
 * فيُخطَّى المسارُ بعذرٍ سليم: «لا بيانات لِـid في القاعدة».
 *
 * **فمسحٌ على قاعدةٍ فارغةٍ يُطمئن ولا يفحص.** وظهر العطلُ في هذه الجلسة
 * بالمصادفة وحدَها: بُذرت بياناتُ العرض لسببٍ آخر، فوجد المسبارُ تاجراً
 * فوجد العطل.
 *
 * **فالحارسُ هنا يصنع الحالةَ بنفسِه** ولا ينتظر أن تصادفها قاعدةٌ.
 */
class MerchantWithoutProfileGuardTest extends TestCase
{
    use RefreshDatabase;

    /** تاجرٌ **بلا** `merchant_profiles` — الحالةُ التي أسقطت الصفحتين. */
    private function merchantWithoutProfile(): User
    {
        // **المصنعُ لا `create()`**: `type` خارج `fillable` عمداً.
        return User::factory()->create(['type' => 3, 'role' => 'merchant']);
    }

    /**
     * مديرٌ **بدورٍ منصّيّ**.
     *
     * `type = 0` وحدَه لا يكفي: حارسُ الصلاحيّات يردّ ٤٠٣، فيمرّ الحارسُ
     * على «ليست ٥٠٠» **دون أن يدخل الصفحةَ أصلاً**. وهو تمامُ ما وقع في
     * أوّل تشغيلٍ هنا: اختباران خضراوان وثالثٌ مُخطّى، **والصفحةُ لم
     * تُفتَح مرّة**.
     */
    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 0, 'role' => 'super_admin']);

        app(\App\Services\PlatformRoleService::class)
            ->assign($admin, \App\Services\PlatformRoleService::ADMIN);

        return $admin->refresh();
    }

    /**
     * @test
     *
     * **مركزُ التاجر يُعرض ولا يسقط.**
     */
    public function the_merchant_centre_opens_for_a_merchant_without_a_profile(): void
    {
        $merchant = $this->merchantWithoutProfile();

        $response = $this->actingAs($this->admin(), 'user')
            ->get("/admin/amial/merchant-center/{$merchant->id}/overview");

        $this->assertNotSame(500, $response->getStatusCode(),
            'مركزُ التاجر يسقط بـ٥٠٠ على حسابٍ بلا ملفّ — '
            . 'وهي حالةٌ تقع عند إنشاءٍ يدويٍّ من اللوحة');
    }

    /**
     * @test
     *
     * **ومركزُ الاستحقاقات كذلك.**
     */
    public function the_entitlement_centre_opens_for_a_merchant_without_a_profile(): void
    {
        $merchant = $this->merchantWithoutProfile();

        $response = $this->actingAs($this->admin(), 'user')
            ->get("/admin/amial/entitlements/merchants/{$merchant->id}");

        $this->assertNotSame(500, $response->getStatusCode(),
            'مركزُ الاستحقاقات يسقط بـ٥٠٠ على حسابٍ بلا ملفّ');
    }

    /**
     * @test
     *
     * **والغيابُ يُقال، لا يُملأ افتراضاً صامتاً** (القاعدة السابعة).
     *
     * صفحةٌ تعرض «تجزئة» لتاجرٍ بلا ملفٍّ أسوأُ من صفحةٍ تسقط: الأولى
     * تُقرأ حقيقةً، والثانيةُ تُقرأ عطلاً فتُصلَح.
     */
    public function the_missing_profile_is_stated_not_silently_defaulted(): void
    {
        $merchant = $this->merchantWithoutProfile();

        $response = $this->actingAs($this->admin(), 'user')
            ->get("/admin/amial/merchant-center/{$merchant->id}/overview");

        if ($response->getStatusCode() !== 200) {
            $this->markTestSkipped(
                'الصفحةُ ردّت ' . $response->getStatusCode()
                . ' (صلاحيّةٌ أو تحويل) — ومحتواها يُفحص حين تُعرض');
        }

        $profile = $response->json('data.profile');

        $this->assertIsArray($profile, 'الردُّ بلا قسم «profile»');

        $this->assertArrayHasKey('has_profile', $profile,
            'الردُّ لا يقول إن كان للحساب ملفُّ تاجرٍ أصلاً — '
            . 'فتُقرأ «مجاني/pending» حقيقةً وهي غيابٌ');

        $this->assertFalse($profile['has_profile'],
            '«has_profile» تقول نعم لحسابٍ بلا ملفّ');
    }
}
