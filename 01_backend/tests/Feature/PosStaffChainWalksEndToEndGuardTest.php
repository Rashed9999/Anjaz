<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePosDevice;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-POS-CHAIN-001 — **أربعُ حلقاتٍ خضراءُ وسلسلةٌ لم تُمشَ قطّ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس:** سأل صاحبُ المشروع «كيف صارت الطريقةُ من البداية
 * إلى أوّل عمليّة بيع، وهل مسارُها جاهزٌ ويعمل؟». فقِيست الحرّاسُ فإذا
 * **تسعةٌ وتسعون اختباراً تمرّ على حلقاتها**:
 *
 *   `MerchantStaffTest`            إضافةُ الموظّف
 *   `PosDeviceSeatGuardTest`       رمزُ التفعيل والمقاعد
 *   `PosDeviceLoginBindingTest`    الدخولُ مربوطٌ بجهاز
 *   `CashierTest` وأخواتُه         البيع
 *
 * **ولا واحدٌ منها يمشي من أوّلها إلى آخرها.** ومُسح مجلّدُ الاختبارات
 * كلُّه فلم يوجد ملفٌّ يمسّ ثلاثَ حلقاتٍ معاً، فضلاً عن الأربع.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ هذا فرقٌ حقيقيّ لا زينة:** كلُّ حارسٍ من الأربعة **يبني حالتَه
 * بيده** — يُنشئ `PosUser` بـ`create()`، ويُنشئ `PosDevice` بالخدمة
 * مباشرةً، ويدخل بـ`UnifiedAuthService` لا بنقطة نهاية. **فما بين
 * الحلقات لا يفحصه أحد**: أن يكون الرمزُ الذي أخرجته الشاشةُ الأولى هو
 * الذي تقبله الثانية، وأن يكون الاسمُ الذي حفظته الأولى هو الذي يُدخِل
 * في الثالثة.
 *
 * **وهذا بعينه ما وقع في السلسلة من قبل:** `merchant_number` في
 * `merchants` لا في `merchant_profiles` — والحلقتان سليمتان والوصلةُ
 * مقطوعة، والرسالةُ «بيانات الدخول غير صحيحة» **صادقةٌ عن سببٍ غيرِ
 * الذي يُظنّ**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **فالحارسُ لا يبني شيئاً بيده بعد الحالة الأولى.** كلُّ حلقةٍ تأخذ
 * مُخرَجَ سابقتها كما يأخذه الإنسان: الرمزُ من ردّ التاجر، والجهازُ من
 * ردّ التفعيل، والرمزُ الشخصيُّ من ردّ الدخول.
 */
class PosStaffChainWalksEndToEndGuardTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_PHONE = '967700990011';

    private const MERCHANT_NUMBER = 'M-CHAIN-01';

    private const STAFF_CODE = 'EMP-07';

    private const STAFF_PASSWORD = 'Kashier@2026';

    private User $merchant;

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

        // **متجرٌ على باقة الأعمال** — وهي أدنى باقةٍ تُتيح `employees`
        // و`multi_pos` معاً، وقد قِيس ذلك من `resolveFeatures` لا فُرض.
        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT,
            'phone' => self::MERCHANT_PHONE,
            'password' => Hash::make('Owner@2026'),
            'is_active' => 1,
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        // **`merchant_number` في `merchants`** — والخلطُ بينه وبين
        // `merchant_profiles` أسقط الدخولَ صامتاً في حارسٍ سابق.
        $row = new Merchant();
        $row->user_id = $this->merchant->id;
        $row->merchant_number = self::MERCHANT_NUMBER;
        $row->store_name = 'متجرُ السلسلة';
        $row->save();
    }

    /**
     * **① الحلقةُ الأولى: التاجرُ يضيف موظّفاً من شاشته.**
     *
     * ولا يُنشأ `PosUser` بيدٍ هنا — تُضغط نقطةُ النهاية التي تضغطها
     * الشاشة، **فالتحقّقُ والصلاحيّةُ وحدُّ الباقة كلُّها في الطريق**.
     */
    private function merchantAddsStaff(): array
    {
        Passport::actingAs($this->merchant);

        $res = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => self::STAFF_CODE,
            'display_name' => 'سالمٌ الكاشير',
            'password' => self::STAFF_PASSWORD,
            'permissions' => ['sell'],
        ]);

        $res->assertStatus(201);

        return (array) $res->json('meta');
    }

    /**
     * **② والحلقةُ الثانية: التاجرُ يُنشئ رمزَ تفعيلٍ للجهاز.**
     *
     * والرمزُ يُقرأ **من الردّ** لا يُختلَق — فرمزٌ مختلَقٌ يفحص أنّ
     * التفعيل يقبل ما نكتبه، لا أنّه يقبل ما تُخرجه الشاشة.
     */
    private function merchantCreatesActivationCode(): string
    {
        Passport::actingAs($this->merchant);

        $res = $this->postJson('/api/v1/amial/merchant/pos-devices/activation-codes', [
            'display_name' => 'كاشير الصالة',
        ]);

        $res->assertOk();

        // **ومفتاحُ الحمولة `data` هنا و`meta` في شاشة الموظّفين.**
        //
        // متحكّمان في المشروع نفسِه بغلافين مختلفين — وقد سقط هذا
        // الحارسُ عليه، كما سقط قبله حارسُ تعطيل الموظّف على العكس.
        // فيُقرأ الاثنان ولا يُفترَض واحد.
        $meta = (array) ($res->json('data') ?: $res->json('meta'));

        $code = null;
        array_walk_recursive($meta, function ($v, $k) use (&$code) {
            if ($code === null && is_string($v) && preg_match('/^\d{8}$/', $v)) {
                $code = $v;
            }
        });

        $this->assertNotNull($code, sprintf(
            "**لم يخرج رمزٌ من ثمانية أرقامٍ في ردّ إنشاء الرمز.**\n"
            ."والشاشةُ تقول للتاجر «سيظهر رمز تفعيل صالح لمدة 15 دقيقة» — "
            ."فإن لم يكن في الردّ فلا شيءَ يُملى على الجهاز.\n\nالردّ: %s",
            json_encode($meta, JSON_UNESCAPED_UNICODE)));

        return $code;
    }

    /**
     * **③ والحلقةُ الثالثة: الجهازُ يُفعَّل بالرمز — بلا حسابٍ ولا رمزِ
     * دخول.**
     *
     * وهذا شرطٌ من الوثيقة ومن الشاشة معاً: «لا تستخدم حساب الموظف أو
     * كلمة مروره هنا».
     */
    private function deviceActivates(string $code): string
    {
        $uuid = 'chain-device-'.\Illuminate\Support\Str::random(12);

        $res = $this->postJson('/api/v1/amial/pos-devices/activate', [
            'activation_code' => $code,
            'device_uuid' => $uuid,
            'platform' => 'android',
        ]);

        $res->assertOk();

        return $uuid;
    }

    /**
     * **④ والحلقةُ الرابعة: الموظّفُ يدخل من الجهاز المفعَّل.**
     */
    private function staffLogsIn(string $deviceUuid): string
    {
        $res = $this->postJson('/api/v1/auth/login', [
            // **`role` لا `kind`** — والشاشةُ تسمّيه `AccountKind`،
            // فالتسميتان مختلفتان على طرفَي السلك. وهذا بعينه ما يفوت
            // حرّاسَ الحلقة الواحدة: `PosDeviceLoginBindingTest` يستدعي
            // `UnifiedAuthService` مباشرةً **فلا يمرّ بهذا المتحكّم أصلاً**.
            'role' => 'merchant',
            'merchant_number' => self::MERCHANT_NUMBER,
            'phone' => self::MERCHANT_PHONE,
            'password' => self::STAFF_PASSWORD,
            'employee_code' => self::STAFF_CODE,
        ], [EnsurePosDevice::HEADER => $deviceUuid]);

        $res->assertOk();

        $token = null;
        $body = (array) $res->json();
        array_walk_recursive($body, function ($v, $k) use (&$token) {
            if ($token === null && in_array($k, ['access_token', 'token'], true)
                && is_string($v) && strlen($v) > 40) {
                $token = $v;
            }
        });

        $this->assertNotNull($token, sprintf(
            "**دخولٌ ناجحٌ بلا رمزِ عمل.**\nفالموظّفُ «دخل» ولا يستطيع "
            ."طلباً واحداً بعدها.\n\nالردّ: %s",
            json_encode($res->json(), JSON_UNESCAPED_UNICODE)));

        return $token;
    }

    /**
     * **السلسلةُ كلُّها في مشيةٍ واحدة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وكلُّ حلقةٍ تأخذ مُخرَجَ سابقتها: الرمزُ من ردّ التاجر، والجهازُ
     * من ردّ التفعيل، والرمزُ الشخصيُّ من ردّ الدخول. **فوصلةٌ مقطوعةٌ
     * بين اثنتين تُسقط هذا الاختبارَ وحدَه** — ولا تُسقط أيّاً من
     * التسعة والتسعين.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_merchant_can_take_a_new_employee_all_the_way_to_a_first_sale(): void
    {
        $this->merchantAddsStaff();

        $code = $this->merchantCreatesActivationCode();
        $deviceUuid = $this->deviceActivates($code);
        $token = $this->staffLogsIn($deviceUuid);

        // **وأوّلُ فعلٍ للموظّف: يفتح ورديّتَه.**
        //
        // وهي البابُ الذي لا بيعَ قبله — و`EnsurePosDevice` على المسار،
        // فترويسةُ الجهاز تُرسَل كما يرسلها التطبيق.
        $shift = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            EnsurePosDevice::HEADER => $deviceUuid,
        ])->postJson('/api/v1/amial/cashier/shift/open', ['opening_float' => 0]);

        $this->assertContains($shift->status(), [200, 201], sprintf(
            "**الموظّفُ دخل ولا يستطيع فتحَ ورديّة — والسلسلةُ تنقطع عند "
            ."آخر حلقة.**\n\nالحالة: %d\nالردّ: %s",
            $shift->status(),
            json_encode($shift->json(), JSON_UNESCAPED_UNICODE)));
    }

    /**
     * ══════════════════════════════════════════════════════════════════
     * **وحالةٌ ثانيةٌ كُتبت هنا ثمّ نُزعت — والسببُ يُقرأ ولا يُخمَّن.**
     *
     * كتبتُ «جهازٌ غريبٌ لا يفتح ورديّة» فمرّت. **وجُرّبت بالعكس فمرّت
     * أيضاً** — أي أنّها كانت تمرّ لسببٍ آخر: أوّلُ نداءٍ فتح الورديّة،
     * فصار كلُّ ما بعده ٤٢٢ «ورديّةٌ مفتوحة» **لا ٤٠٣ «جهازٌ آخر»**.
     * فقُرئ منعُ الحارس وهو منعُ حالةٍ.
     *
     * وأُعيد القياسُ على مسارٍ لا يُغيّر حالة (`GET /cashier/shift`):
     *
     *   enforce=false  الجهازُ نفسُه → 200   ·  جهازٌ غريب → **200**
     *   enforce=true   الجهازُ نفسُه → 200   ·  جهازٌ غريب → **200**
     *
     * **والوسيطُ فيه المطابقةُ صراحةً** (`POS_DEVICE_MISMATCH` · ٤٠٣)،
     * وجلسةٌ مربوطةٌ موجودةٌ بعد الدخول، و`isPosActor` يقول «نعم».
     * فلمَ لا تُطلَق؟ **لم يُحسم بعد** — و`tokenId()` تبتلع خطأَها في
     * `catch` وتُرجع `null`، وهو أوّلُ ما يُشتبَه به.
     *
     * **فلا يُشحن حارسٌ يمرّ لسببٍ لا يُعرَف** — يُطمئن ولا يحرس، وهو
     * أسوأُ من غيابه. والمفتوحُ يُقال لصاحب المشروع بقياسه.
     * ══════════════════════════════════════════════════════════════════
     */
}
