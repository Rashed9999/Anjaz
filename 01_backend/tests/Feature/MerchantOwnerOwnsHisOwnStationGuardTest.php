<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Merchant\MerchantPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-OWNER-001 — **المالكُ يُطلَب منه أن يستأذن نفسَه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع، بنصّ صاحب المشروع:**
 *
 *     «هذا حساب مالك المحطة، لماذا لا يستطيع الدخول إلى حسابه؟
 *      أليس من المفترض مالك المحطة لديه كل الصلاحيات؟»
 *
 * وشاشةُ «لوحة المحطة» تقول له: **«لم تُمنح أي صلاحية بعد — اطلب من
 * مالك المحطة إسناد دورٍ لحسابك»**. وهو مالكُها. طريقٌ مسدودٌ تامّ:
 * الجهةُ التي تُحيله إليها الرسالةُ هي هو.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والسببُ المقيس:** `merchantIdFor()` تسأل ثلاثةَ أسئلةٍ ولا تسأل
 * أبسطَها — **أهذا الحسابُ حسابُ تاجرٍ أصلاً؟**
 *
 *   ① أله `MerchantProfile`؟           → مالك
 *   ② أله `merchant_user_roles` نشط؟   → تابعٌ لذلك التاجر
 *   ③ أله `pos_users`؟                 → تابعٌ لذلك التاجر
 *   ④ وإلّا                            → مالك
 *
 * فمن سقط ملفُّه (وهو واقعٌ مسجَّلٌ في `CLAUDE.md`: «صفحتان تردّان ٥٠٠
 * على حسابِ تاجرٍ بلا ملفّ») **وبقي له صفٌّ تابعٌ من تجربةٍ أو استيراد**،
 * صار الصفُّ التابعُ هويّتَه.
 *
 * **والحرسُ كان موجوداً وكان نصفَ حرس.** التعليقُ فوق ① يقول حرفيّاً إنّ
 * الربطَ التابع «يجعله موظفاً في منشأته بلا دور، فتُبنى لوحة الوقود
 * فارغة رغم أنّه المالك الحقيقي» — **وحُرس بشرطٍ واحدٍ هو وجودُ الملفّ**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والنوعان لا يلتقيان بحكم البناء، وهذا ما يجعل الإصلاحَ آمناً:**
 * `MerchantStaffController` ينشئ الموظّفَ `type = 4`، والتاجرُ `type = 3`.
 * فحسابُ تاجرٍ لا يمكن أن يكون موظّفاً عند تاجرٍ آخر.
 */
class MerchantOwnerOwnsHisOwnStationGuardTest extends TestCase
{
    use RefreshDatabase;

    private MerchantPermissionService $perm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->perm = app(MerchantPermissionService::class);
    }

    private function merchant(): User
    {
        return User::factory()->create(['type' => MERCHANT_TYPE, 'zone_code' => 'SOUTH']);
    }

    /** محطّةٌ باسمه — **دليلُ الملكيّة الذي يُسأل، لا نوعُ الحساب**. */
    private function giveStation(User $owner): void
    {
        DB::table('fuel_stations')->insert([
            'merchant_user_id' => $owner->id, 'station_name' => 'محطّة الاختبار',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function linkPos(User $u, User $toMerchant, string $code): void
    {
        DB::table('pos_users')->insert([
            'user_id' => $u->id, 'merchant_user_id' => $toMerchant->id,
            'pos_number' => $code, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الحالةُ التي وصلت من شاشة صاحب المشروع.**
     *
     * تاجرٌ بلا ملفٍّ وله صفٌّ تابعٌ لتاجرٍ آخر — **وهي وحدَها من بين
     * أربع حالاتٍ قِيست تُنتج اللوحةَ الفارغة.**
     */
    /** @test */
    public function a_merchant_without_a_profile_is_still_the_owner_of_his_own_account(): void
    {
        $someoneElse = $this->merchant();
        $owner = $this->merchant();
        $this->giveStation($owner);

        $this->linkPos($owner, $someoneElse, 'LEFTOVER-1');

        $this->assertTrue($this->perm->isOwner($owner),
            "**المالكُ ليس مالكاً في حسابه.**\n"
            .'فصفٌّ تابعٌ بقي من تجربةٍ أو استيرادٍ صار هويّتَه، وتقول له '
            ."لوحةُ المحطّة: «اطلب من مالك المحطة إسناد دورٍ لحسابك» — وهو هو.\n"
            .'**وحسابُ تاجرٍ (‏type=3) لا يكون موظّفاً عند تاجرٍ آخر: الموظّفُ '
            .'يُنشأ type=4.**');

        $this->assertNotEmpty($this->perm->effective($owner),
            '**المالكُ بصفر صلاحيّة** — فاللوحةُ تُرسَم فارغةً وكلُّ زرٍّ مخفيّ.');

        $this->assertSame($owner->id, $this->perm->merchantIdFor($owner),
            'منشأةُ المالك ليست منشأتَه — فكلُّ شاشةٍ تقرأ بيانات غيره.');
    }

    /**
     * **② وصفُّ دورٍ تابعٌ لا يسلبه ملكيّتَه كذلك.**
     *
     * وهو الطريقُ الثاني إلى العطل نفسِه، ولا يكفي إغلاق أحدهما.
     */
    /** @test */
    public function a_stray_role_row_does_not_demote_a_merchant_either(): void
    {
        $someoneElse = $this->merchant();
        $owner = $this->merchant();
        $this->giveStation($owner);

        $roleId = DB::table('merchant_roles')->insertGetId([
            'merchant_user_id' => $someoneElse->id, 'code' => 'cashier',
            'name_ar' => 'كاشير', 'is_system' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('merchant_user_roles')->insert([
            'user_id' => $owner->id, 'merchant_user_id' => $someoneElse->id,
            'merchant_role_id' => $roleId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($this->perm->isOwner($owner),
            '**إسنادُ دورٍ قديمٍ سلب التاجرَ منشأتَه** — والبابُ الثاني '
            .'إلى اللوحة الفارغة.');
    }

    /**
     * **③ والملفُّ ما زال أوّلَ ما يُسأل — فلا يُستبدَل حرسٌ بحرس.**
     */
    /** @test */
    public function a_merchant_with_a_profile_is_unchanged(): void
    {
        $owner = $this->merchant();
        MerchantProfile::create(['user_id' => $owner->id, 'business_type' => 'fuel']);

        $this->assertTrue($this->perm->isOwner($owner));
        $this->assertNotEmpty($this->perm->effective($owner));
    }

    /**
     * **④ وموظّفُ نقطة البيع يبقى موظّفاً — وهذا شرطُ صحّة الإصلاح.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فإصلاحٌ يمنح الملكيّةَ للجميع يفتح كلَّ شاشةٍ لكلّ داخل — وهو أسوأ
     * ألفَ مرّةٍ من الشاشة الفارغة. **والفرقُ كلُّه في `type`.**
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function pos_staff_stay_scoped_to_the_merchant_they_work_for(): void
    {
        $boss = $this->merchant();

        $staff = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'zone_code' => 'SOUTH',
        ]);

        $this->linkPos($staff, $boss, 'EMP-9');

        $this->assertFalse($this->perm->isOwner($staff),
            '**موظّفُ نقطة البيع صار مالكاً** — فكلُّ صلاحيّات المنشأة '
            .'بيد كاشير. وهذا أخطرُ من العطل الذي عولج.');

        $this->assertSame($boss->id, $this->perm->merchantIdFor($staff),
            'الموظّفُ لم يعد تابعاً لمنشأته — فيقرأ بياناتِ حسابه لا بيانات المتجر.');

        $this->assertEmpty($this->perm->effective($staff),
            'موظّفٌ بلا دورٍ مُسنَدٍ يملك صلاحيّات — والإسنادُ صار زينة.');
    }

    /**
     * **⑤ والعميلُ العاديُّ لا يصير تاجراً.**
     *
     * فالشرطُ على `MERCHANT_TYPE` بعينه، لا على «ليس موظّفاً».
     */
    /** @test */
    public function a_plain_customer_gets_no_merchant_powers(): void
    {
        $boss = $this->merchant();

        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH']);
        $this->linkPos($customer, $boss, 'EMP-7');

        $this->assertFalse($this->perm->isOwner($customer),
            '**عميلٌ صار مالكَ منشأة** — والشرطُ صار «ليس موظّفاً» لا «تاجر».');

        $this->assertEmpty($this->perm->effective($customer));
    }

    /**
     * **⑥ وموظّفٌ قديمٌ يحمل نوعَ التاجر يبقى موظّفاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذه الحالةُ هي التي ردَّت أوّلَ إصلاحٍ كتبتُه.** كان الشرطُ
     * `type === MERCHANT_TYPE`، فأسقط عشرةَ اختباراتِ صلاحيّاتٍ قائمة —
     * وتجهيزاتُها تُنشئ الموظّفَ `type = 3`.
     *
     * ومسارُ الإنشاء اليوم يضع `type = 4`، **لكنّ حساباً مستورَداً أو
     * قديماً قد يحمل ٣**. وقاعدةٌ على النوع كانت تمنح ذلك الكاشيرَ كلَّ
     * صلاحيّات منشأة صاحبِه — **وهو أخطرُ ألفَ مرّةٍ من الشاشة الفارغة**.
     *
     * فصار المسؤولُ **دليلَ الملكيّة**: لا محطّةَ له ولا فرعَ ولا أدوارَ
     * باسمه ⇒ ليس مالكاً، مهما كان نوعُ حسابه.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_legacy_staff_account_typed_as_merchant_stays_staff(): void
    {
        $boss = $this->merchant();
        $this->giveStation($boss);

        // موظّفٌ بنوع التاجر — **ولا سجلَّ منشأةٍ باسمه**.
        $legacyStaff = $this->merchant();
        $this->linkPos($legacyStaff, $boss, 'LEGACY-3');

        $this->assertFalse($this->perm->isOwner($legacyStaff),
            '**موظّفٌ قديمٌ صار مالكَ منشأةٍ ليست له** — وهو أخطرُ من '
            .'العطل الذي عولج: كاشيرٌ يملك كلَّ صلاحيّات صاحب العمل.');

        $this->assertSame($boss->id, $this->perm->merchantIdFor($legacyStaff),
            'الموظّفُ لم يعد تابعاً لمنشأة صاحبِه.');
    }

    /**
     * **⑦ والشاشةُ نفسُها تفتح للمالك — لا الخدمةُ وحدَها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فخدمةٌ تُصلَح ونقطةُ نهايةٍ تُرجع القديمَ إصلاحٌ لا يراه أحد. وهذا
     * الفحصُ يمرّ من **نقطة النهاية التي يناديها التطبيق فعلاً**، بغلافها
     * الذي تقرؤه الشاشة (`data.is_owner`) — فتغييرُ الغلاف يُسقطه.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_station_console_endpoint_answers_the_owner_as_owner(): void
    {
        $someoneElse = $this->merchant();
        $owner = $this->merchant();
        MerchantProfile::create(['user_id' => $someoneElse->id, 'business_type' => 'fuel']);
        $this->giveStation($owner);
        $this->linkPos($owner, $someoneElse, 'LEFTOVER-2');

        $r = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/amial/merchant/fuel/me/permissions');

        $r->assertOk();

        $this->assertTrue($r->json('data.is_owner') === true,
            "**نقطةُ النهاية ما زالت تقول للمالك إنّه ليس مالكاً.**\n"
            .'وهي التي تقرؤها «لوحة المحطة»، فتُرسم الشاشةُ فارغةً برسالة '
            .'«اطلب من مالك المحطة إسناد دورٍ لحسابك».');

        $this->assertNotEmpty($r->json('data.permissions'),
            'الصلاحيّاتُ فارغةٌ في الردّ — فكلُّ بطاقةٍ في اللوحة تُخفى.');
    }
}
