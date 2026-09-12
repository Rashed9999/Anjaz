<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\Merchant\MerchantRole;
use App\Models\Merchant\MerchantUserRole;
use App\Models\PosUser;
use App\Models\User;
use App\Services\PlatformRoleService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-USABLE-001 — **حسابٌ يُنشأ من اللوحة يعمل في التطبيق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قالها صاحبُ المشروع مرّتين: أنشأ حساب «محطّة وقود» من اللوحة، فتح
 * التطبيق، فقرأ: «تعذَّر إتمام العملية · لا توجد محطة مرتبطة بهذا الحساب».
 *
 * وأُصلح مرّةً — **ولم يصل الإصلاح**. فهذا الحارسُ لا يفحص سطراً بعينه:
 * **يمشي الطريقَ كلَّه** من زرّ اللوحة إلى شاشة التطبيق، لكلّ قطاع.
 *
 * وثلاثةُ أشياءَ يجب أن تُولَد مع الحساب، وإلّا فُتحت شاشةٌ ورفضت:
 *
 *   ① **الباقة** — بلا اشتراكٍ تُقفل الميزات خلف بوّابة الاستحقاق.
 *   ② **مِلكيّةُ الحساب** — صاحبُه يملك كلَّ صلاحيّات قطاعه بلا صفّ دور،
 *      وهو من يُسند الأدوارَ لموظّفيه بعد ذلك.
 *   ③ **سجلُّ القطاع** — محطّةٌ للوقود، وصيدليّةٌ للدواء، ومستودعٌ للجملة.
 *
 * **والفحصُ من مدخلَي الحساب**: لوحةُ الإدارة والتسجيلُ الذاتيّ — فأُصلح
 * مدخلٌ وتُرك الآخر مرّةً من قبل. (القاعدة الرابعة.)
 */
class MerchantAccountIsUsableGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function operator(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u;
    }

    /** يُنشئ تاجراً من **زرّ اللوحة نفسِه** — لا من المصنع. */
    private function createFromAdminPanel(string $businessType, string $phone): User
    {
        $this->actingAs($this->operator(), 'user')
            ->postJson('/admin/amial/hub/merchants/users', [
                'f_name' => 'صاحب',
                'l_name' => 'الحساب',
                // **ورمزُ الدولة صار إلزاميّاً** — أضافه التزامُ صاحب
                // المشروع في لوحة الإنشاء، فردَّت التجهيزةُ 422 على حقلٍ
                // لم تكن تعرفه. والتجهيزةُ تتبع العقدَ لا تسبقه.
                // ══════════════════════════════════════════════════════
                // **وملفُّ فتح الحساب صار عقداً كاملاً.**
                //
                // شدّد صاحبُ المشروع إنشاءَ الحسابات من اللوحة: ثمانيةُ
                // حقولِ «اعرف عميلك» + إفصاحُ المنصب السياسيّ + إقرارُ
                // صحّة البيانات + ثلاثُ صور. وهو تشديدٌ سليمٌ في منتجٍ
                // ماليّ — **والتجهيزةُ تتبع العقدَ لا تسبقه**.
                //
                // والحقولُ تُقرأ من المتحكّم (`AdminHubController`) لا
                // تُخمَّن، فحقلٌ يُضاف غداً يُسقط هذا الاختبار برسالته
                // لا بصمت.
                // ══════════════════════════════════════════════════════
                'dial_country_code' => '+967',
                'phone' => $phone,
                'store_name' => 'منشأة الاختبار',
                'business_type' => $businessType,
                'password' => 'Passw0rd!2026',

                'gender' => 'male',
                'date_of_birth' => '1990-01-01',
                'identification_type' => 'nid',
                'identification_number' => '0100' . substr($phone, -6),
                'address' => 'صنعاء — شارع الاختبار',
                'residence_district' => 'الصافية',
                'income_source' => 'business',
                'account_purpose' => 'business',
                'is_pep' => 0,
                "declaration_accepted" => 1,

                // وثلاثةٌ تخصّ التاجرَ وحدَه — سجلٌّ ومفوَّضٌ بالتوقيع.
                'business_registration_number' => 'REG-' . substr($phone, -6),
                'authorized_signatory_name' => 'صاحبُ المنشأة',
                'authorized_signatory_id' => '0100' . substr($phone, -6),

                'identity_front' => \Illuminate\Http\UploadedFile::fake()->image('front.jpg'),
                'identity_back' => \Illuminate\Http\UploadedFile::fake()->image('back.jpg'),
                'selfie' => \Illuminate\Http\UploadedFile::fake()->image('selfie.jpg'),
            ])->assertCreated();

        $user = User::where('phone', 'like', '%' . ltrim($phone, '0') . '%')->latest('id')->first();

        $this->assertNotNull($user, 'اللوحةُ ردّت بنجاح ولا حسابَ في القاعدة');

        return $user;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الباقة — إلزاميّة عند الإنشاء
    // ══════════════════════════════════════════════════════════════════

    public function test_every_new_merchant_gets_the_free_plan(): void
    {
        foreach ([A::BIZ_FUEL => '967771000101',
                  A::BIZ_PHARMACY => '967771000102',
                  A::BIZ_RETAIL => '967771000103'] as $type => $phone) {
            $merchant = $this->createFromAdminPanel($type, $phone);

            $profile = MerchantProfile::where('user_id', $merchant->id)->first();

            $this->assertNotNull($profile, "«{$type}»: لا ملفَّ تاجرٍ أصلاً");

            $this->assertNotEmpty($profile->subscription_plan,
                "«{$type}»: حسابٌ بلا باقة — والميزاتُ خلف بوّابة الاستحقاق "
                . 'تُقفل بلا أن يعرف صاحبُها لماذا');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② المِلكيّة — صاحبُ الحساب يملك قطاعه
    // ══════════════════════════════════════════════════════════════════

    /**
     * **من أنشأته اللوحةُ تاجراً هو مالكٌ في التطبيق.**
     *
     * ولو رُدَّ `is_owner = false` لعُرضت «لم تُمنح أي صلاحية بعد» —
     * ومالكُ المحطّة لا يجد من يُسند له دوراً.
     */
    public function test_the_owner_owns_his_vertical_without_any_role_row(): void
    {
        $merchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000201');

        $data = $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/fuel/me/permissions')
            ->assertOk()->json('data');

        $this->assertTrue($data['is_owner'],
            'صاحبُ الحساب ليس مالكاً — فلا صلاحيّةَ له ولا من يمنحه إيّاها');

        $this->assertNotEmpty($data['permissions'],
            'المالكُ بلا صلاحيّات — الشاشةُ تُفتح فارغةً');
    }

    /**
     * الربط القديم كموظف أو POS لا يسبق ملف التاجر. بدونه يُرى مالك المحطة
     * «موظفاً بلا دور»، فتظهر له رسالة الصورة نفسها رغم أن حسابه صحيح.
     */
    public function test_a_merchant_profile_wins_over_a_stale_staff_assignment(): void
    {
        $merchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000211');
        $otherMerchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000212');

        $role = MerchantRole::where('merchant_user_id', $otherMerchant->id)->firstOrFail();
        MerchantUserRole::create([
            'merchant_user_id' => $otherMerchant->id,
            'user_id' => $merchant->id,
            'merchant_role_id' => $role->id,
            'is_active' => true,
        ]);

        $data = $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/fuel/me/permissions')
            ->assertOk()->json('data');

        $this->assertTrue($data['is_owner'],
            'ربط موظف قديم سلب مالك المحطة ملكيته وأفرغ لوحته');
        $this->assertNotEmpty($data['permissions']);
    }

    /** ونفس الحارس لربط نقطة بيع قديم — المساران كانا يسلبان الملكية. */
    public function test_a_merchant_profile_wins_over_a_stale_pos_assignment(): void
    {
        $merchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000221');
        $otherMerchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000222');

        PosUser::create([
            'user_id' => $merchant->id,
            'merchant_user_id' => $otherMerchant->id,
            'pos_number' => 'STALE-OWNER-LINK',
            'display_name' => 'ربط قديم',
            'is_active' => true,
        ]);

        $data = $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/fuel/me/permissions')
            ->assertOk()->json('data');

        $this->assertTrue($data['is_owner'],
            'ربط POS قديم سلب مالك المحطة ملكيته وأفرغ لوحته');
        $this->assertNotEmpty($data['permissions']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ سجلُّ القطاع — الشكوى نفسُها
    // ══════════════════════════════════════════════════════════════════

    /**
     * **الشكوى حرفيّاً**: يُنشأ من اللوحة، ويُفتح في التطبيق.
     */
    public function test_a_fuel_account_opens_its_station_console(): void
    {
        $merchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000301');

        // سجلُّ المحطّة يُبنى مع الحساب — لا عند أوّل فتح.
        $this->assertDatabaseHas('fuel_stations', ['merchant_user_id' => $merchant->id]);

        // والشاشةُ تفتح فعلاً: هذا هو النداءُ الذي كان يردّ بالرفض.
        $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/fuel/ops')
            ->assertOk();
    }

    /**
     * **وحسابٌ قديمٌ بلا محطّة يُشفى عند أوّل فتح** — لا يبقى مسدوداً.
     *
     * فالحساباتُ التي أُنشئت قبل هذا الإصلاح موجودةٌ على الخادم، ولا
     * يُطلب من صاحبها أن يُنشئ حساباً جديداً.
     */
    public function test_a_legacy_fuel_account_heals_itself_on_first_open(): void
    {
        $merchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000401');

        // نُعيد العطلَ عمداً: نحذف سجلَّ المحطّة كما كانت الحساباتُ القديمة.
        \DB::table('fuel_stations')->where('merchant_user_id', $merchant->id)->delete();
        $this->assertDatabaseMissing('fuel_stations', ['merchant_user_id' => $merchant->id]);

        $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/fuel/ops')
            ->assertOk();

        $this->assertDatabaseHas('fuel_stations', ['merchant_user_id' => $merchant->id]);
    }

    public function test_a_pharmacy_account_opens_its_console(): void
    {
        $merchant = $this->createFromAdminPanel(A::BIZ_PHARMACY, '967771000501');

        $this->assertDatabaseHas('pharmacies', ['merchant_user_id' => $merchant->id]);
    }


    /**
     * **والمالكُ يجد أدوارَ موظّفيه جاهزةً** — لا شاشةً فارغةً وزرّاً
     * عليه أن يعرف مكانه.
     *
     * وهذا نصُّ ما طلبه صاحبُ المشروع: يُنشأ الحسابُ فيكون صاحبُه مالكاً،
     * **ثمّ المالكُ هو من يُسند لموظّفيه**. والإسنادُ يحتاج أدواراً
     * موجودة.
     */
    public function test_the_owner_finds_staff_roles_ready_to_assign(): void
    {
        $merchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000601');

        $roles = $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/fuel/roles')
            ->assertOk()->json('data.roles');

        $this->assertNotEmpty($roles,
            'شاشةُ الأدوار فارغة — والمالكُ لا يملك ما يُسنده لكاشيره');

        $codes = array_column($roles, 'code');

        $this->assertContains('cashier', $codes,
            'لا دورَ كاشير — وهو أوّلُ من يُوظَّف في محطّة');
    }

    /** **ولا تُكتب البذرةُ فوق تعديلات المالك** عند أوّل نداءٍ ثانٍ. */
    public function test_seeding_twice_does_not_duplicate_or_overwrite(): void
    {
        $merchant = $this->createFromAdminPanel(A::BIZ_FUEL, '967771000701');

        $before = \App\Models\Merchant\MerchantRole::where('merchant_user_id', $merchant->id)->count();

        \App\Models\Merchant\MerchantRole::where('merchant_user_id', $merchant->id)
            ->where('code', 'cashier')->update(['name_ar' => 'أمين الصندوق']);

        app(\App\Services\Vertical\VerticalBootstrapService::class)->ensureFor($merchant);

        $this->assertSame($before,
            \App\Models\Merchant\MerchantRole::where('merchant_user_id', $merchant->id)->count(),
            'نداءٌ ثانٍ ضاعف الأدوار');

        $this->assertSame('أمين الصندوق',
            \App\Models\Merchant\MerchantRole::where('merchant_user_id', $merchant->id)
                ->where('code', 'cashier')->value('name_ar'),
            'البذرةُ كتبت فوق تسميةِ المالك');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ البابُ الثاني — التسجيلُ الذاتيّ
    // ══════════════════════════════════════════════════════════════════

    /**
     * **ولا يُصلَح مدخلٌ ويُترك الآخر.** من سجّل نفسَه محطّةً من التطبيق
     * يجد محطّته كمن أنشأته اللوحة.
     */
    public function test_self_registration_builds_the_vertical_too(): void
    {
        $merchant = User::factory()->create(['type' => MERCHANT_TYPE, 'is_active' => 1]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_name' => 'محطّة التسجيل الذاتيّ',
            'business_type' => A::BIZ_FUEL,
        ]);

        app(\App\Services\Vertical\VerticalBootstrapService::class)->ensureFor($merchant);

        $this->assertDatabaseHas('fuel_stations', ['merchant_user_id' => $merchant->id]);
    }
}
