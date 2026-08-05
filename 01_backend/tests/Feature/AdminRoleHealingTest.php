<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-OPERATOR-RBAC-004 — شبكةُ أمان الأدوار تعمل في المسارين.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **حارسٌ يقول عن نفسه إنّه يعمل «في كلّ إقلاع» وهو داخل فرعٍ واحد.**
 *
 * `healAdminsWithoutRoles()` كانت داخل `if ($admin)` في `EnsureDemoStaff`
 * — أي مسارِ «الأدمن التجريبيّ موجودٌ سلفاً» وحده. فعلى الإقلاع الذي
 * يُنشأ فيه الأدمن التجريبيّ لأوّل مرّة — وهو أوّلُ إقلاعٍ على قاعدةٍ
 * جديدة، وأكثرُ الأوقات إنتاجاً لحساباتٍ بلا دور — لا تعمل إطلاقاً.
 *
 * والقياس الذي كشفه: بعد تشغيل الأمر كاملاً على قاعدةٍ مبذورة، بقي
 * `admin@amyal.pay` **بلا دور**. يدخل لوحة الإدارة، ويُردّ ٤٠٣ على واحدٍ
 * وأربعين مساراً، ولا يقرأ رقماً ماليّاً واحداً — بلا رسالةٍ تقول لماذا.
 *
 * (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها. والأمرُ هنا له
 * مساران — «الحساب موجود» و«يُنشأ الآن» — فيُجرَّب من كليهما.)
 */
class AdminRoleHealingTest extends TestCase
{
    use RefreshDatabase;

    /** حساب إدارةٍ يتيمٌ: قائمٌ، نشط، وبلا دورٍ واحد. */
    private function orphanAdmin(string $phone): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'يتيم', 'phone' => $phone,
            'email' => $phone . '@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        $this->assertSame([], app(PlatformRoleService::class)->codesOf($u),
            'الحساب وُلد بدورٍ — فالقياس لاغٍ، إذ لا يتيمَ نشفيه');

        return $u->fresh();
    }

    private function rolesOf(User $u): array
    {
        return app(PlatformRoleService::class)->codesOf($u->fresh());
    }

    /**
     * @test
     *
     * **المسار الأوّل: الأدمن التجريبيّ يُنشأ الآن.**
     *
     * وهو المسار الذي كانت الشبكة معطَّلةً فيه تماماً. ولا وجود لـ
     * `admin@amyalpay.com` هنا، فيؤخذ فرعُ الإنشاء.
     */
    public function an_orphan_admin_is_healed_even_when_the_demo_admin_is_created_fresh(): void
    {
        $orphan = $this->orphanAdmin('967770004001');

        $this->assertNull(User::where('email', 'admin@amyalpay.com')->first(),
            'الأدمن التجريبيّ موجودٌ سلفاً — فسيُؤخذ الفرعُ الآخر ولا يُختبر ما نريد');

        $this->artisan('amial:ensure-demo-staff')->assertSuccessful();

        $this->assertNotEmpty($this->rolesOf($orphan),
            'حسابُ إدارةٍ بقي بلا دور — والشبكةُ لا تعمل في مسار الإنشاء');
    }

    /**
     * @test
     *
     * **والمسار الثاني: الأدمن التجريبيّ موجودٌ سلفاً.**
     *
     * وهو المسار الذي كان يعمل. ويُثبَّت كي لا يسقط مع نقل الشبكة.
     */
    public function an_orphan_admin_is_healed_when_the_demo_admin_already_exists(): void
    {
        // إقلاعٌ أوّل: يُنشئ الأدمن التجريبيّ.
        $this->artisan('amial:ensure-demo-staff')->assertSuccessful();
        $this->assertNotNull(User::where('email', 'admin@amyalpay.com')->first());

        // ثمّ يظهر يتيمٌ بعده — من اللوحة أو بيدٍ على القاعدة.
        $orphan = $this->orphanAdmin('967770004002');

        // وإقلاعٌ ثانٍ يشفيه.
        $this->artisan('amial:ensure-demo-staff')->assertSuccessful();

        $this->assertNotEmpty($this->rolesOf($orphan),
            'حسابُ إدارةٍ بقي بلا دور في مسار «موجودٌ سلفاً»');
    }

    /**
     * @test
     *
     * **والشفاءُ لا يسرق دوراً مضبوطاً.**
     *
     * فمن أُسند له `platform_support` عمداً لا يُرقّى إلى مدير المنصّة —
     * وشبكةُ أمانٍ تمنح صلاحيّاتٍ لم تُطلب أخطرُ من الثقب الذي تسدّه.
     */
    public function healing_does_not_upgrade_an_admin_who_already_has_a_narrower_role(): void
    {
        $support = $this->orphanAdmin('967770004003');
        app(PlatformRoleService::class)->assign($support, PlatformRoleService::SUPPORT);

        $this->artisan('amial:ensure-demo-staff')->assertSuccessful();

        $this->assertSame([PlatformRoleService::SUPPORT], $this->rolesOf($support),
            'رُقّي موظّفُ دعمٍ إلى مدير المنصّة — والشبكةُ تمنح ما لم يُطلب');
    }
}
