<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePosDevice;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-POS-LOGIN-SIMPLE-001 — **حقلان له، لا أربعةٌ نصفُها لمديره.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس:** أرسل صاحبُ المشروع صورةَ شاشةِ «إضافة موظف» في
 * نظامٍ منافسٍ وقال: «انظر إلى السهولة». وقِيس ما يكتبه موظّفُنا ليدخل
 * فإذا هو أربعةُ حقول، **اثنان منها ليسا له**: رقمُ التاجر وجوّالُ
 * التاجر. أي أنّ الكاشيرَ يحفظ رقمَ هاتفِ مديره ليبدأ يومَه.
 *
 * **والجهازُ يحملهما منذ لحظة التفعيل** —
 * `merchant_pos_devices.merchant_user_id`. فيُقرآن منه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ شروطٍ تُحرَس، لا واحد:**
 *
 * ① **يدخل بحقلين** — وهو المطلوب.
 * ② **ومن أرسل الأربعةَ يمرّ كما كان** — تطبيقٌ قديمٌ لا يُكسَر، وهو
 *    شرطُ التنفيذ على تجربةٍ حيّة.
 * ③ **وبلا جهازٍ مفعَّلٍ لا اشتقاق** — فلو اشتُقّت الهويّةُ من ترويسةٍ
 *    غيرِ مسجَّلة لصار كلُّ من يعرف رمزَ موظّفٍ وكلمتَه يدخل من أيّ
 *    هاتف، **والتبسيطُ يفتح باباً بدل أن يُقصّر طريقاً**.
 */
class PosLoginNeedsOnlyTheStaffsOwnTwoFieldsGuardTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_PHONE = '967700550011';

    private const MERCHANT_NUMBER = 'M-SIMPLE-9';

    private const STAFF_CODE = 'EMP-02';

    private const STAFF_PASSWORD = 'Kashier@2026';

    private string $deviceUuid = 'simple-login-device-0001';

    protected function setUp(): void
    {
        parent::setUp();

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

        $owner = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'is_active' => 1,
            'phone' => self::OWNER_PHONE,
            'password' => Hash::make('Owner@2026'),
        ]);

        MerchantProfile::create([
            'user_id' => $owner->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        $row = new Merchant();
        $row->user_id = $owner->id;
        $row->merchant_number = self::MERCHANT_NUMBER;
        $row->store_name = 'متجرُ التبسيط';
        $row->save();

        $staff = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'is_active' => 1,
            'password' => Hash::make(self::STAFF_PASSWORD),
        ]);

        PosUser::create([
            'user_id' => $staff->id,
            'merchant_user_id' => $owner->id,
            'pos_number' => self::STAFF_CODE,
            'display_name' => 'كاشير التبسيط',
            'is_active' => true,
        ]);

        // **الجهازُ يُفعَّل كما يُفعَّل في المنتج** — بصمةٌ لا نصٌّ خام.
        \App\Models\Merchant\PosDevice::create([
            'merchant_user_id' => $owner->id,
            'device_uuid_hash' => \App\Models\Merchant\PosDevice::hashUuid($this->deviceUuid),
            'display_name' => 'لوحُ الصالة',
            'is_active' => true,
        ]);
    }

    private function login(array $payload, ?string $device): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login',
            ['role' => 'merchant'] + $payload,
            $device === null ? [] : [EnsurePosDevice::HEADER => $device]);
    }

    /**
     * **① الموظّفُ يدخل برمزه وكلمتِه — لا شيءَ لمديره.**
     */
    /** @test */
    public function the_staff_signs_in_with_only_their_own_code_and_password(): void
    {
        $res = $this->login([
            'employee_code' => self::STAFF_CODE,
            'password' => self::STAFF_PASSWORD,
        ], $this->deviceUuid);

        $res->assertOk();
    }

    /**
     * **② ومن أرسل الأربعةَ يمرّ كما كان.**
     *
     * فتطبيقٌ قديمٌ على جهازِ موظّفٍ يعمل الآن لا يُكسَر — وهو شرطُ
     * التنفيذ على تجربةٍ حيّة، لا تحسينٌ فوقه.
     */
    /** @test */
    public function the_old_four_field_form_still_works_unchanged(): void
    {
        $res = $this->login([
            'merchant_number' => self::MERCHANT_NUMBER,
            'phone' => self::OWNER_PHONE,
            'employee_code' => self::STAFF_CODE,
            'password' => self::STAFF_PASSWORD,
        ], $this->deviceUuid);

        $res->assertOk();
    }

    /**
     * **③ وبلا جهازٍ مفعَّلٍ لا اشتقاق.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا شرطُ صحّة التبسيط لا زينةٌ عليه.** لو اشتُقّت الهويّةُ من
     * أيّ ترويسة، لصار **كلُّ من يعرف رمزَ موظّفٍ وكلمتَه يدخل من أيّ
     * هاتف** — فيكون التبسيطُ قد فتح باباً لا قصّر طريقاً.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function two_fields_alone_are_refused_from_a_device_that_was_never_activated(): void
    {
        $res = $this->login([
            'employee_code' => self::STAFF_CODE,
            'password' => self::STAFF_PASSWORD,
        ], 'a-phone-that-was-never-activated');

        $this->assertSame(422, $res->status(),
            '**دخولٌ بحقلين من جهازٍ لم يُفعَّل قطّ.** فمن يعرف رمزَ موظّفٍ '
            .'وكلمتَه يبيع من أيّ هاتف — والتبسيطُ فتح باباً.');
    }

    /**
     * **④ ولا ترويسةَ إطلاقاً = لا اشتقاق.**
     */
    /** @test */
    public function two_fields_alone_are_refused_with_no_device_header_at_all(): void
    {
        $res = $this->login([
            'employee_code' => self::STAFF_CODE,
            'password' => self::STAFF_PASSWORD,
        ], null);

        $this->assertSame(422, $res->status(),
            'دخولٌ بحقلين بلا ترويسةِ جهازٍ أصلاً — فالاشتقاقُ يعمل على الهواء.');
    }

    /**
     * **⑤ والجهازُ الملغى لا يُشتقّ منه.**
     *
     * فالتاجرُ يلغي جهازاً مسروقاً، **ولا يُغني إلغاؤه إن بقي يُعرّف
     * متجرَه** — يكفي السارقَ رمزُ موظّفٍ واحد.
     */
    /** @test */
    public function a_revoked_device_no_longer_identifies_its_shop(): void
    {
        \App\Models\Merchant\PosDevice::query()->update(['revoked_at' => now()]);

        $res = $this->login([
            'employee_code' => self::STAFF_CODE,
            'password' => self::STAFF_PASSWORD,
        ], $this->deviceUuid);

        $this->assertSame(422, $res->status(),
            '**جهازٌ ألغاه التاجرُ ما زال يُعرّف متجرَه** — فالإلغاءُ نصفُ إلغاء.');
    }

    /**
     * **⑥ وما كُتب يُستعمَل — لا يُستبدَل صامتاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذه الحالةُ وُلدت من تجربةٍ عكسيّةٍ مرّت.** نُزع شرطُ «لا يُستبدَل
     * مكتوب» فبقيت الحالاتُ الخمسُ خضراء — لأنّ حالةَ «الأربعة» ترسل
     * **نفسَ** القيم التي يشتقّها الجهاز، فلا فرقَ بين استعمالها
     * واستبدالها.
     *
     * فتُرسَل هنا قيمةٌ **خاطئة**: لو صحّح الاشتقاقُ خطأَ المُرسِل صامتاً،
     * لَما عرف أحدٌ أنّ تطبيقاً يرسل رقمَ متجرٍ خطأً — **ويظلّ يعمل حتّى
     * يُنزع الجهاز، فينكشف كلُّ شيءٍ دفعةً واحدة**.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_wrong_merchant_number_is_still_refused_and_not_silently_corrected(): void
    {
        $res = $this->login([
            'merchant_number' => 'M-NOT-THIS-SHOP',
            'phone' => self::OWNER_PHONE,
            'employee_code' => self::STAFF_CODE,
            'password' => self::STAFF_PASSWORD,
        ], $this->deviceUuid);

        $this->assertNotSame(200, $res->status(),
            '**رقمُ متجرٍ خاطئٌ صُحّح من الجهاز صامتاً.** فتطبيقٌ يرسل '
            .'الخطأَ يبقى يعمل، ولا يُكشف إلّا يومَ يُنزع الجهاز.');
    }
}
