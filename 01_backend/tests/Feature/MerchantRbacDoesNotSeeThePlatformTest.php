<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-RBAC-SPLIT-001 — **«نظاميّ» كانت تعني اثنين، والشرطُ يقرأ أحدَهما.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «ثلاثةُ محرّكات RBAC متوازية — أنهِ الازدواج.» وقِيس قبل
 * سطرٍ واحد، فما وُجد أخطرُ من ازدواج:
 *
 * جداولُ `roles` و`permissions` و`role_permissions` **مشتركةٌ بين
 * محرّكين**: أدوارُ المنصّة (`platform_admin` · `platform_security`…)
 * وأدوارُ التاجر القديمة (`cashier` · `branch_manager`…). **وكلاهما
 * `is_system = 1` و`merchant_user_id = null`** — فلا يفترقان بشكلٍ في
 * الجدول، ويفترقان بالرمز وحدَه.
 *
 * وكان `RbacController` يقول `where('is_system', true)`، فيصدق على
 * الصنفين:
 *
 *   ① `GET /merchant/rbac/permissions` **يُخرج كلَّ صفٍّ في `permissions`**
 *      — ومنها `platform.money.move` و`platform.aml.decide` و
 *      `platform.security.act`. **مصفوفةُ صلاحيّات المنصّة كاملةً لكلّ
 *      تاجر**، بأسمائها العربيّة وتصنيفها.
 *   ② `GET /merchant/rbac/roles` يُخرجها أدواراً ومعها صلاحيّاتُها.
 *   ③ و`assign-role` **تقبل معرِّفَ دورِ منصّة** فتكتب في `pos_user_roles`.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والثالثةُ لا تُصعّد اليوم — وهذا لغمٌ لا أمان.**
 *
 * قِيس: الإنفاذُ في `PlatformPermissionMiddleware` يقرأ
 * `admin_user_roles` لا `pos_user_roles`. و`PosPermission` — التي تقرأ
 * الأخير — **مسجَّلةٌ في `bootstrap/app.php` وتحرس صفرَ مسار**.
 *
 * **فالحمايةُ القائمةُ مصادفةُ موتِ محرّك، لا حاجزٌ قُصد.** وأوّلُ من
 * يُلبس تلك الوسيطةَ مساراً يجعل الصفَّ نافذاً، فيصير تاجرٌ يملك
 * `platform_admin`. ولذلك يُقطع البابان معاً: **العرضُ والإسناد**.
 */
class MerchantRbacDoesNotSeeThePlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // **وأوّلُ تشغيلٍ قاس عالماً فارغاً.** أدوارُ التاجر القديمة
        // (`cashier` · `branch_manager`…) تأتي من بذرةٍ لا من هجرة، فبلا
        // بذرِها يخرج المِقياسُ أخضرَ على صفر: «لا تسريبَ» لأنّ الجدولَ
        // خاوٍ لا لأنّ الحاجزَ عمل. وأمسكه توكيدُ الحدّ الأدنى.
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function merchant(): User
    {
        $m = User::factory()->create(['type' => 3]);

        MerchantProfile::create([
            'user_id' => $m->id,
            'business_name' => 'متجر اختبار',
            'business_type' => 'retail',
        ]);

        return $m->refresh();
    }

    private function posUserOf(User $merchant): PosUser
    {
        $u = User::factory()->create(['type' => 3]);

        return PosUser::create([
            'merchant_user_id' => $merchant->id,
            'user_id' => $u->id,
            'pos_number' => 'POS-' . $u->id,
            'is_active' => true,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① لا تُعرَض صلاحيّةُ منصّةٍ لتاجر
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_merchant_never_reads_the_platform_permission_matrix(): void
    {
        $m = $this->merchant();

        $codes = collect($this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/merchant/rbac/permissions')
            ->assertOk()->json('meta.permissions'))->pluck('code')->all();

        $leaked = array_values(array_filter($codes,
            fn ($c) => str_starts_with((string) $c, 'platform.')));

        $this->assertSame([], $leaked,
            "صلاحيّاتُ منصّةٍ مسرَّبةٌ إلى تاجر:\n  " . implode("\n  ", $leaked));

        // **ولا تُفرَّغ القائمةُ بحجّة الأمان.** شاشةُ الأدوار تُبنى منها،
        // وقائمةٌ فارغةٌ تشلّها — والشللُ يُقرأ «الميزةُ معطّلة».
        $this->assertGreaterThan(10, count($codes),
            'قائمةُ صلاحيّات التاجر فرغت — والشاشةُ تُبنى منها');
    }

    /** @test */
    public function a_merchant_never_sees_a_platform_role_in_the_role_list(): void
    {
        $m = $this->merchant();

        $roles = collect($this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/merchant/rbac/roles')
            ->assertOk()->json('meta.roles'));

        $leaked = $roles->pluck('code')
            ->filter(fn ($c) => str_starts_with((string) $c, 'platform_'))
            ->values()->all();

        $this->assertSame([], $leaked,
            "أدوارُ منصّةٍ معروضةٌ لتاجر:\n  " . implode("\n  ", $leaked));

        // وأدوارُ التاجر النظاميّة ما زالت تُعرَض — وإلّا فالشاشةُ فارغة.
        $this->assertContains(Role::CASHIER, $roles->pluck('code')->all(),
            'أدوارُ التاجر النظاميّة اختفت — وحاجزٌ يشلّ عملاً سليماً '
            . 'أسوأ من ثغرةٍ تُكتشَف بتدقيق');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② ولا تُسنَد — والبابان يُقرآن من مصدرٍ واحد
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_merchant_cannot_assign_a_platform_role_to_a_pos_user(): void
    {
        $m = $this->merchant();
        $pos = $this->posUserOf($m);

        $platformRoleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        $this->assertNotNull($platformRoleId,
            'لا وجودَ لدور `platform_admin` — المِقياسُ يفحص فراغاً');

        $this->actingAs($m, 'api')->postJson(
            "/api/v1/amial/merchant/rbac/pos-users/{$pos->id}/assign-role",
            ['role_id' => $platformRoleId],
        )->assertStatus(422)->assertJsonPath('code', 'INVALID_ROLE');

        // **ويُقاس الأثر لا الردّ**: ردٌّ ٤٢٢ مع صفٍّ مكتوبٍ أسوأ من ٢٠٠.
        $this->assertDatabaseMissing('pos_user_roles', [
            'pos_user_id' => $pos->id,
            'role_id' => $platformRoleId,
        ]);
    }

    /** @test */
    public function a_merchant_can_still_assign_their_own_staff_roles(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة.**
        $m = $this->merchant();
        $pos = $this->posUserOf($m);

        $cashierId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', Role::CASHIER)->value('id');

        $this->assertNotNull($cashierId, 'دورُ الكاشير القديم غيرُ مزروع');

        $this->actingAs($m, 'api')->postJson(
            "/api/v1/amial/merchant/rbac/pos-users/{$pos->id}/assign-role",
            ['role_id' => $cashierId],
        )->assertOk();

        $this->assertDatabaseHas('pos_user_roles', [
            'pos_user_id' => $pos->id,
            'role_id' => $cashierId,
        ]);
    }

    /** @test */
    public function the_two_doors_read_the_same_list(): void
    {
        // **بابان لفعلٍ واحد ينحرفان عند أوّل تعديل** (القاعدة الرابعة):
        // يُضيَّق العرضُ ويبقى الإسنادُ واسعاً، أو العكس. فيُثبَت أنّ كلَّ
        // ما يُعرَض يُسنَد، ولا يُسنَد ما لا يُعرَض.
        $m = $this->merchant();
        $pos = $this->posUserOf($m);

        $offered = collect($this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/merchant/rbac/roles')
            ->assertOk()->json('meta.roles'))->pluck('id');

        $this->assertGreaterThan(0, $offered->count());

        foreach ($offered as $id) {
            $this->actingAs($m, 'api')->postJson(
                "/api/v1/amial/merchant/rbac/pos-users/{$pos->id}/assign-role",
                ['role_id' => $id],
            )->assertOk();
        }

        // والعكس: دورٌ غيرُ معروضٍ يُردّ.
        $notOffered = DB::table('roles')->whereNotIn('id', $offered->all())
            ->value('id');

        if ($notOffered !== null) {
            $this->actingAs($m, 'api')->postJson(
                "/api/v1/amial/merchant/rbac/pos-users/{$pos->id}/assign-role",
                ['role_id' => $notOffered],
            )->assertStatus(422);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ واللغمُ يُسمّى — لا يُترَك ليُكتشَف يومَ يُلبَس
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_legacy_engine_is_still_wired_to_nothing_and_it_is_said(): void
    {
        // **`pos_user_roles` يُكتَب ولا يُقرأ عند أيّ إنفاذ.** ووسيطةُ
        // `PosPermission` هي وحدَها من يقرؤه، وهي على صفر مسار.
        //
        // فإن لُبست يوماً مساراً **صار كلُّ صفٍّ في ذلك الجدول نافذاً**.
        // وهذا المِقياسُ يسقط حينها، فيُقرأ نصُّه قبل أن يُشحَن ذلك.
        $guarded = [];

        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && (str_contains($mw, 'PosPermission')
                    || str_starts_with($mw, 'amial.pos-permission'))) {
                    $guarded[] = (string) $route->getName() . ' — ' . $route->uri();
                }
            }
        }

        sort($guarded);

        $this->assertSame([], $guarded,
            "وسيطةُ `PosPermission` صارت تحرس مساراً:\n  "
            . implode("\n  ", $guarded) . "\n\n"
            . "وهي تقرأ `pos_user_roles` — المحرّكَ القديم. فقبل شحن هذا\n"
            . "يجب التأكّد أنّ الإسنادَ فيه مقصورٌ على أدوار التاجر (يحرسه\n"
            . 'هذا الملفّ)، وأنّ الصلاحيّاتِ لا تلتقي مع صلاحيّات المنصّة.');
    }

    /** @test */
    public function the_only_live_merchant_engine_is_the_modern_one(): void
    {
        // **والإنفاذُ يُقاس من الشيفرة لا من نيّةٍ مكتوبة.** كلُّ فحصِ
        // صلاحيّةٍ تاجرٍ يمرّ بـ`MerchantPermissionService` — ومن أضاف
        // محرّكاً ثالثاً يسقط هنا.
        $callers = [];

        foreach ([
            'Http/Controllers/Api/V1/Amial/PharmacyController.php',
            'Http/Controllers/Api/V1/Amial/WholesaleController.php',
            'Http/Controllers/Api/V1/Amial/RestaurantController.php',
            'Http/Controllers/Api/V1/Amial/RetailVerticalController.php',
            'Http/Controllers/Api/V1/Amial/FuelVerticalController.php',
        ] as $rel) {
            $src = file_get_contents(app_path($rel));

            if (str_contains($src, 'MerchantPermissionService')) {
                $callers[] = $rel;
            }

            $this->assertStringNotContainsString('hasPermission(', $src,
                "{$rel} يفحص بالمحرّك القديم — ومحرّكان يفترقان عند أوّل تعديل");
        }

        $this->assertCount(5, $callers,
            'متحكّمٌ قطاعيٌّ لا يستعمل محرّكَ الصلاحيّات الحديث');
    }
}
