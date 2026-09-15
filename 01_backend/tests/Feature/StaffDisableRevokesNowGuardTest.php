<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-STAFF-REVOKE-001 — **القطعُ يقع، ولم يكن يُقال ولا يُحرَس.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأوّلُ ما يُقرأ هنا تصحيحُ دعوىً لي.** قلتُ إنّ التعطيل يمنع الدخولَ
 * التالي ولا يمسّ الرمزَ القائم، وبنيتُ إبطالاً في `toggle()`.
 * **فقاس هذا الحارسُ فردَّ الدعوى**: `revoked_sessions` خرجت صفراً وقد
 * قُطعت الرموزُ فعلاً — لأنّ `User::boot()` (‏`app/Models/User.php:281`)
 * يُبطل كلَّ الرموز حين يصير `is_active` صفراً. فالقطعُ فوريٌّ منذ زمن،
 * وإبطالي كان مكرَّراً فحُذف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةٌ بقيت، وهي سببُ بقاء هذا الملفّ:**
 *
 * ① **الرسالةُ صامتة.** «تم التعطيل» واحدةٌ لحالتين — «ولا جلسةَ له»
 *    و«وقُطعت ثلاثٌ وهو يبيع الآن». والفرقُ بينهما هو كلُّ ما يحتاجه
 *    التاجرُ ليعرف أنّ موظّفَه كان على الجهاز في تلك اللحظة.
 *    (القاعدة السابعة: صفرٌ صامتٌ ليس «فحصنا فلم نجد».)
 *
 * ② **والعدُّ يُؤخذ قبل الحفظ لا بعده.** فالخُطّاف يكون قد أبطلها،
 *    فيقرأ العدُّ صفراً وتُطبَع «ولا جلسةَ مفتوحةٌ له» **وهي كذب**.
 *
 * ③ **ولا حارسَ على البابِ الذي يضغطه التاجر.** السلوكُ يعتمد على
 *    خُطّافٍ في نموذجٍ بعيدٍ عن هذا المتحكّم: من ينزعه لن يرى شيئاً
 *    يسقط. فالحارسُ يسأل **النتيجةَ المرئيّة** من نقطة النهاية.
 *
 * ══════════════════════════════════════════════════════════════════════
 * والمرجعُ مستندُ صاحب المشروع «نموذج أميال المبسط للموظف وPOS»، صفحة ٦:
 * «إيقاف الوصول — تعطيل الموظف **يزيل عضوية العمل فوراً**. تعطيل الجهاز
 * ينهي كل الجلسات المفتوحة عليه فوراً».
 */
class StaffDisableRevokesNowGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private User $staff;

    private PosUser $membership;

    protected function setUp(): void
    {
        parent::setUp();

        // **عميلُ الرموز الشخصيّة يُنشأ أوّلاً** — وإلّا سقط `createToken`
        // بـ«Personal access client not found»، **وهو عطلُ أداةٍ يُقرأ عطلَ
        // منتج**. والنمطُ هو نفسُه في `PosDeviceLoginBindingTest` ولا
        // يُخترَع ثانٍ.
        \Laravel\Passport\Client::unguarded(function () {
            $client = \Laravel\Passport\Client::create([
                'name' => 'amial-test-personal',
                'secret' => \Illuminate\Support\Str::random(40),
                'redirect' => 'http://localhost',
                'personal_access_client' => true,
                'password_client' => false,
                'revoked' => false,
            ]);

            \Laravel\Passport\PersonalAccessClient::unguarded(
                fn () => \Laravel\Passport\PersonalAccessClient::create(['client_id' => $client->id]));
        });

        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => 'merchant', 'is_active' => 1,
            'phone' => '967770000001',
        ]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => 'retail',
            'subscription_plan' => 'business',
        ]);

        $this->staff = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'is_active' => 1,
            'phone' => '967770000002',
            'password' => Hash::make('secret123'),
        ]);

        $this->membership = PosUser::create([
            'user_id' => $this->staff->id,
            'merchant_user_id' => $this->merchant->id,
            'pos_number' => '01',
            'display_name' => 'كاشير الاختبار',
            'is_active' => true,
            'permissions' => ['sell'],
        ]);
    }

    /** رمزُ عملٍ حيٌّ للموظّف — كما يصدر عند دخوله. */
    private function issueLiveToken(): void
    {
        $this->staff->createToken('pos-test')->accessToken;
    }

    private function liveTokens(): int
    {
        return $this->staff->tokens()->where('revoked', false)->count();
    }

    /**
     * **① الحالةُ قبل الاختبار — وإلّا فحصنا العدم.**
     *
     * (القاعدة السابعة: صفرُ رموزٍ محذوفةٍ لا يعني «قُطعت»، قد يعني
     *  «لم يكن هناك رمزٌ أصلاً».)
     */
    /** @test */
    public function the_staff_member_really_has_a_live_token_first(): void
    {
        $this->issueLiveToken();

        $this->assertSame(1, $this->liveTokens(),
            'لم يصدر رمزٌ حيّ — فالاختبارُ التالي يفحص فراغاً.');
    }

    /**
     * **② والتعطيلُ يقطعه في نفس اللحظة.**
     */
    /** @test */
    public function disabling_a_staff_member_revokes_the_live_token_immediately(): void
    {
        $this->issueLiveToken();
        $this->assertSame(1, $this->liveTokens());

        Passport::actingAs($this->merchant);

        $this->postJson("/api/v1/amial/merchant/staff/{$this->membership->id}/toggle")
            ->assertOk()
            ->assertJsonPath('meta.is_active', false);

        $this->assertSame(0, $this->liveTokens(),
            '**الموظّفُ مُوقَفٌ ورمزُه حيّ.** فيواصل البيعَ من جهازه، '
            . 'والتاجرُ قرأ «تم التعطيل» فظنّ البابَ أُغلق.');
    }

    /**
     * **③ والرسالةُ تقول كم قُطعت — لا «تمّ» مجرّدة.**
     *
     * فرسالةٌ واحدةٌ لحالتين («عُطّل ولا جلسةَ له» و«عُطّل وقُطعت ثلاث»)
     * تُخفي عن التاجر أنّ موظّفَه كان يعمل في تلك اللحظة.
     */
    /** @test */
    public function the_response_says_how_many_sessions_were_cut(): void
    {
        $this->issueLiveToken();
        $this->issueLiveToken();

        Passport::actingAs($this->merchant);

        $this->postJson("/api/v1/amial/merchant/staff/{$this->membership->id}/toggle")
            ->assertOk()
            ->assertJsonPath('meta.revoked_sessions', 2);
    }

    /**
     * **④ وإعادةُ التفعيل لا تقطع شيئاً.**
     *
     * فحارسٌ يقطع في الاتّجاهين يُخرج التاجرَ من حسابه كلَّما فعّل موظّفاً.
     */
    /** @test */
    public function re_enabling_does_not_revoke_anything(): void
    {
        $this->membership->update(['is_active' => false]);
        // **‏`is_active` ليس في `$fillable` على `User`** — فـ`update()`
        // تُسقطه صامتةً وتُرجع `true`. قِيس ذلك بعد أن مرّت حالةٌ هنا
        // **لأنّ شرطَها لم يُصنَع**. فيُكتَب على القاعدة مباشرةً.
        \DB::table('users')->where('id', $this->staff->id)->update(['is_active' => 0]);
        $this->issueLiveToken();

        Passport::actingAs($this->merchant);

        $this->postJson("/api/v1/amial/merchant/staff/{$this->membership->id}/toggle")
            ->assertOk()
            ->assertJsonPath('meta.is_active', true)
            ->assertJsonPath('meta.revoked_sessions', 0);

        $this->assertSame(1, $this->liveTokens(),
            'إعادةُ التفعيل قطعت رمزاً — وهي لا تُقطع.');
    }

    /**
     * **⑤ ولا يقطع رموزَ موظّفٍ آخر.**
     *
     * (الرمزُ يُبطَل على `$pos->user` بعينه — والحارسُ يثبت ذلك بدل
     *  الوثوق بأنّه كذلك.)
     */
    /** @test */
    public function disabling_one_staff_member_leaves_another_alone(): void
    {
        $other = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'is_active' => 1,
            'phone' => '967770000003',
        ]);
        PosUser::create([
            'user_id' => $other->id,
            'merchant_user_id' => $this->merchant->id,
            'pos_number' => '02',
            'is_active' => true,
        ]);

        $this->issueLiveToken();
        $other->createToken('pos-test-other');

        Passport::actingAs($this->merchant);
        $this->postJson("/api/v1/amial/merchant/staff/{$this->membership->id}/toggle")
            ->assertOk();

        $this->assertSame(0, $this->liveTokens());
        $this->assertSame(1, $other->tokens()->where('revoked', false)->count(),
            '**قُطع رمزُ موظّفٍ لم يُعطَّل** — فالورديّةُ كلُّها تسقط بضغطة.');
    }

    /**
     * **⑥ والانحرافُ لا يفتح باباً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الحالةُ الوحيدةُ التي يفوتها الخُطّاف:** عضويّةٌ فعّالةٌ وحسابُها
     * مُعطَّلٌ سلفاً. فحين تُعطَّل العضويّة يُكتب `is_active = 0` على
     * حسابٍ **هو صفرٌ أصلاً**، فلا يتّسخ الحقل، **فلا يُطلَق `updated`**،
     * فتبقى الرموزُ حيّة.
     *
     * وهي ضيّقةٌ لكنّها ليست نظريّة: كلُّ مسارٍ يُعطّل الحساب دون
     * العضويّة يُنتجها.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_drifted_account_still_gets_cut(): void
    {
        // الحسابُ مُعطَّلٌ والعضويّةُ فعّالة — ورمزٌ حيٌّ باقٍ من قبل.
        // كتابةٌ مباشرةٌ على القاعدة: `is_active` ليس في `$fillable`،
        // **ولا يُطلَق الخُطّاف** — وهو عينُ الانحراف المقصود.
        \DB::table('users')->where('id', $this->staff->id)->update(['is_active' => 0]);
        $this->issueLiveToken();
        $this->assertSame(1, $this->liveTokens(),
            'لم يبقَ رمزٌ حيّ — فالحالةُ المقصودةُ لم تُصنَع.');

        Passport::actingAs($this->merchant);

        $this->postJson("/api/v1/amial/merchant/staff/{$this->membership->id}/toggle")
            ->assertOk()
            ->assertJsonPath('meta.is_active', false);

        $this->assertSame(0, $this->liveTokens(),
            '**عضويّةٌ عُطّلت ورمزُها حيّ** — لأنّ حقلَ الحساب لم يتّسخ '
            . 'فلم يُطلَق الخُطّاف. وهو البابُ الذي يُغلقه الدرءُ الصريح.');
    }
}
