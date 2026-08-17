<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePosDevice;
use App\Models\Merchant\PosDevice;
use App\Models\Merchant\PosDeviceSession;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\PosDeviceRegistrar;
use App\Services\UnifiedAuthService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-POS-DEVICES-007 — **مسارُ الدخول: الالتفافاتُ ①②③.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القيدُ الثاني حرفاً: «الجهازُ يجب أن يدخل مسارَ المصادقة نفسَه. نقطةُ
 * تسجيلٍ لا تكفي.»**
 *
 * ونقطةُ التسجيل وحدَها **لا تحرس شيئاً**: تُسجَّل الأجهزةُ بالحدّ، ثمّ
 * يدخل من شاء بلا جهازٍ إطلاقاً — فالحدُّ حدُّ صفوفٍ في جدولٍ لا حدُّ
 * أجهزةٍ تعمل.
 *
 *   ① دخولٌ بلا جهاز        → يُسجَّل ويُقاس (‏صامتٌ مؤقّتاً)، ويُمنع عند الإنفاذ
 *   ② دخولٌ بمعرِّفٍ مخترَع  → **يُرفض الآن، صامتاً كان أو منفَّذاً**
 *   ③ متابعةٌ بعد الإلغاء   → تُمنع
 */
class PosDeviceLoginBindingTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Cashier@2026';

    private const MERCHANT_NUMBER = 'M-100200';

    private const PHONE = '967700123456';

    /**
     * **عميلُ الرموز الشخصيّة يُنشأ للمجموعة.**
     *
     * فبدونه يسقط `createToken` بـ«Personal access client not found»،
     * **وهو عطلُ أداةٍ يُقرأ عطلَ منتج**: الدخولُ سليمٌ والرمزُ وحدَه
     * تعذّر. (‏وقد سقط هذا الملفُّ عليه أوّلَ تشغيل.)
     */
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
    }

    private function seedShop(string $plan = A::PLAN_BUSINESS): array
    {
        $merchant = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'phone' => self::PHONE,
            'password' => Hash::make(self::PASSWORD),
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => $plan,
        ]);

        // **`merchant_number` في `merchants` لا في `merchant_profiles`.**
        //
        // وأوّلُ صياغةٍ وضعته في الملفّ الخطأ، فأسقطه `$fillable` صامتاً
        // — فسقط الدخولُ بـ«بيانات الدخول غير صحيحة»، **وهي رسالةٌ صادقةٌ
        // عن سببٍ غيرِ الذي يُظنّ**. (‏ولو عُدِّل الاختبارُ ليقبلها لمرّ
        // وهو لا يفحص الدخولَ إطلاقاً.)
        $row = new \App\Models\Merchant();
        $row->user_id = $merchant->id;
        $row->merchant_number = self::MERCHANT_NUMBER;
        $row->store_name = 'متجرُ القياس';
        $row->save();

        $staff = User::factory()->create([
            'type' => 4, 'role' => 'pos',
            'password' => Hash::make(self::PASSWORD),
        ]);

        PosUser::create([
            'user_id' => $staff->id,
            'merchant_user_id' => $merchant->id,
            'pos_number' => 'POS-001',
            'display_name' => 'كاشير',
            'is_active' => true,
        ]);

        return [$merchant->refresh(), $staff->refresh()];
    }

    /** يستدعي الدخولَ كما يستدعيه المتحكّم، بترويسةِ جهازٍ أو بدونها. */
    private function login(?string $deviceUuid): array|string
    {
        $request = Request::create('/api/v1/auth/login', 'POST');

        if ($deviceUuid !== null) {
            $request->headers->set(EnsurePosDevice::HEADER, $deviceUuid);
        }

        try {
            return app(UnifiedAuthService::class)->loginMerchant(
                self::MERCHANT_NUMBER, self::PHONE, self::PASSWORD, 'POS-001', $request);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @test
     *
     * **② دخولٌ بمعرِّفٍ مخترَع يُرفض — الآن، لا بعد رفع الصمت.**
     *
     * ══════════════════════════════════════════════════════════════
     * **والتفريقُ مقصودٌ ومقيس:** غيابُ الترويسة صامتٌ مؤقّتاً لأنّ منعَه
     * اليومَ يُخرج كلَّ موظّفٍ يعمل (لا تطبيقَ يُرسلها بعد). أمّا **ترويسةٌ
     * حاضرةٌ بقيمةٍ غيرِ مسجَّلة فادّعاءٌ**، ورفضُه لا يمسّ أحداً: لا عميلَ
     * يُرسلها اليوم أصلاً.
     *
     * فالصمتُ يُقصَر على ما يُؤذي رفعُه، ولا يُوسَّع ليشمل ما لا يؤذي.
     */
    public function a_login_with_an_invented_device_identifier_is_refused(): void
    {
        [$merchant] = $this->seedShop();

        config(['amial.pos_devices.enforce_session_binding' => false]);

        $result = $this->login('never-registered-device-xyz');

        $this->assertIsString($result,
            '**دخولٌ بمعرِّفٍ مخترَعٍ نجح** — فالترويسةُ تُقبل بلا تحقّق');

        $this->assertStringContainsString('غير مسجَّل', $result);

        $this->assertSame(0, PosDeviceSession::count(),
            'رُبطت جلسةٌ بجهازٍ لا وجودَ له');
    }

    /**
     * @test
     *
     * **والدخولُ بجهازٍ مسجَّلٍ يربط الجلسةَ بمقعده.**
     *
     * وهذا الضابطُ الذي بدونه يكون الرفضُ أعلاه رفضاً للجميع.
     */
    public function a_login_with_a_registered_device_binds_the_session_to_its_seat(): void
    {
        [$merchant, $staff] = $this->seedShop();

        $device = app(PosDeviceRegistrar::class)
            ->register($merchant, 'registered-device-001')['device'];

        $result = $this->login('registered-device-001');

        $this->assertIsArray($result, is_string($result) ? $result : '');

        $session = PosDeviceSession::first();

        $this->assertNotNull($session, '**لم تُربط الجلسةُ بمقعد** — فالرمزُ طليق');
        $this->assertSame($device->id, (int) $session->pos_device_id);
        $this->assertSame($staff->id, (int) $session->actor_user_id);
        $this->assertSame($merchant->id, (int) $session->merchant_user_id);
        $this->assertNull($session->ended_at);
    }

    /**
     * @test
     *
     * **① ودخولٌ بلا جهاز: صامتٌ اليوم، ممنوعٌ عند الإنفاذ.**
     *
     * ويُجرَّب الطرفان في اختبارٍ واحد — فوضعٌ صامتٌ لا يُثبَت أنّ رفعَه
     * يُنتج المنعَ ليس «مؤقّتاً»، بل حارسٌ لا وجودَ له.
     */
    public function a_login_without_any_device_is_shadowed_now_and_refused_when_enforced(): void
    {
        $this->seedShop();

        config(['amial.pos_devices.enforce_session_binding' => false]);

        $this->assertIsArray($this->login(null),
            'الوضعُ الصامتُ منع الدخولَ — **فسيُخرج كلَّ موظّفٍ يعمل الآن**');

        $this->assertSame(0, PosDeviceSession::count(),
            'رُبطت جلسةٌ بلا جهاز');

        config(['amial.pos_devices.enforce_session_binding' => true]);

        $denied = $this->login(null);

        $this->assertIsString($denied,
            '**رفعُ الصمت لم يُنتج منعاً** — فالمفتاحُ لا يفعل شيئاً، '
            . 'والخطّةُ التي تنتظره تنتظر ما لا يقع');

        $this->assertStringContainsString('لا جهازَ مسجَّل', $denied);
    }

    /**
     * @test
     *
     * **③ ومتابعةُ الجلسة بعد إلغاء الجهاز تُمنع.**
     *
     * وهو «تحديثُ الرمز بعد الإلغاء» في قائمة الالتفافات: المشروعُ لا
     * يستعمل مِنحةَ تحديثٍ منفصلة (‏رموزُ Passport شخصيّة)، **فالمكافئُ
     * هو استعمالُ الرمز نفسِه في الطلب التالي** — وهو ما يُمنع هنا.
     */
    public function a_session_cannot_continue_after_its_device_is_revoked(): void
    {
        [$merchant, $staff] = $this->seedShop();

        $device = app(PosDeviceRegistrar::class)
            ->register($merchant, 'device-to-be-revoked')['device'];

        $this->login('device-to-be-revoked');

        $session = PosDeviceSession::firstOrFail();

        $this->assertSame(200, $this->probe($staff, (string) $session->access_token_id),
            'الجلسةُ لم تعمل أصلاً — فالمنعُ التالي لا يُثبت شيئاً');

        app(PosDeviceRegistrar::class)->revoke($device, $merchant->id);

        $this->assertSame(401, $this->probe($staff, (string) $session->access_token_id),
            '**رمزٌ استمرّ بعد إلغاء جهازه** — فالإلغاءُ يُخلي المقعدَ ولا يوقف الجلسة');
    }

    /**
     * @test
     *
     * **وإعادةُ الدخول بعد الإلغاء تُرفض** — لا يكفي قتلُ الجلسة القائمة.
     *
     * فلو مُنعت الجلسةُ القديمةُ وقُبل دخولٌ جديدٌ بالجهاز نفسِه لكان
     * الإلغاءُ إزعاجاً لا منعاً: يُعاد الدخولُ فيعود العمل.
     */
    public function a_revoked_device_cannot_log_in_again(): void
    {
        [$merchant] = $this->seedShop();

        $device = app(PosDeviceRegistrar::class)
            ->register($merchant, 'device-revoked-relogin')['device'];

        app(PosDeviceRegistrar::class)->revoke($device, $merchant->id);

        $result = $this->login('device-revoked-relogin');

        $this->assertIsString($result,
            '**جهازٌ ملغىً دخل من جديد** — فالإلغاءُ إزعاجٌ لا منع');

        $this->assertStringContainsString('أُلغي', $result);
    }

    /** يطرق البوّابةَ برمزٍ بعينه ويُعيد رمزَ الاستجابة. */
    private function probe(User $actor, string $tokenId): int
    {
        $token = new \Laravel\Passport\Token();
        $token->id = $tokenId;

        $request = Request::create('/api/v1/amial/probe', 'GET');
        $request->setUserResolver(fn () => $actor->withAccessToken($token));

        return app(EnsurePosDevice::class)
            ->handle($request, fn () => response()->json(['ok' => true]))
            ->getStatusCode();
    }
}
