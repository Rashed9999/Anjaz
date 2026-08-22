<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-2FA-DOOR-001 — **حمايةٌ تُخزَّن ولا تُقرأ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `TwoFactorAuthService` و`Admin2FAController` مكتملان منذ v1.8: سرٌّ
 * ورمزُ QR ورموزُ استرداد وتأكيدٌ وتعطيلٌ وتحقّق. والأعمدةُ الخمسةُ في
 * `users`.
 *
 * **ولم يكن لها شاشةٌ واحدة، ولا سطرٌ واحدٌ يفحصها عند الدخول.**
 *
 * أي أنّ من فعّلها — لو استطاع بأداةٍ خارجيّة — يدخل بكلمة المرور وحدها
 * كأنّه لم يفعّلها. وهي أسوأ من غيابها: **تُطمئن صاحبَها إلى بابٍ
 * مفتوح**، فيستعمل كلمةَ مرورٍ أضعف واثقاً بحاجزٍ لا وجود له.
 */
class AdminTwoFactorDoorGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ngAdminPass!2026';

    private function admin(bool $withTwoFactor): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'admin',
            'phone' => '+967711900001',
            'password' => Hash::make(self::PASSWORD),
        ]);

        app(\App\Services\PlatformLoginPinService::class)
            ->issue($u, self::LOGIN_PIN, null, 'تهيئةُ اختبار');

        if ($withTwoFactor) {
            $svc = app(TwoFactorAuthService::class);
            $secret = $svc->generateSecret();

            // **تُشفَّر بالخدمة نفسِها لا بـ`encrypt()`**: `TwoFactorAuthService`
            // يستعمل `EncryptionService` بمفتاحٍ منفصلٍ للبيانات الحسّاسة.
            // وتهيئةٌ تُشفّر بطريقةٍ أخرى تُنتج `Unsupported ciphertext
            // version` — وهو فشلُ تهيئةٍ يُقرأ عطلاً في الشيفرة.
            $enc = app(\App\Services\EncryptionService::class);

            $u->forceFill([
                'two_factor_secret' => $enc->encrypt($secret),
                'two_factor_enabled' => true,
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        return $u->fresh();
    }

    /** رمزُ دخولِ الموظّف — حلّ محلَّ الكابتشا في `AMIAL-AUTH-PIN`. */
    private const LOGIN_PIN = '4271';

    private function login(): \Illuminate\Testing\TestResponse
    {
        // **وعقدُ الباب تغيّر، فيُختبَر بعقده الجديد.**
        //
        // كان المساعِدُ يُرسل حقلَي الكابتشا، وقد استُبدلا برمز PIN
        // يُصدَره مديرُ المنصّة. **فالدخولُ صار يفشل صامتاً**، ولا تُكتب
        // حالةُ انتظار المصادقة الثنائيّة — فيُقرأ ذلك «الرمزُ الصحيح لا
        // يُكمل الدخول» وهو تشخيصٌ خاطئ لعطلٍ في بابٍ سابق.
        //
        // **ومقياسٌ يُشخّص خطأً أسوأ من مقياسٍ يسقط**: يُرسل من يصدّقه
        // إلى المصادقة الثنائيّة والعطلُ في كلمة المرور.
        return $this->post(route('admin.auth.login'), [
            'phone' => '+967711900001',
            'password' => self::PASSWORD,
            'login_pin' => self::LOGIN_PIN,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الفرض عند الدخول
    // ══════════════════════════════════════════════════════════════════

    public function test_an_admin_without_two_factor_still_gets_in(): void
    {
        // **ولا يُقفل الباب على أحدٍ بتغييرٍ صامت** — من لم يفعّلها يدخل
        // كما كان.
        $this->admin(false);

        $this->login()->assertRedirect(route('admin.dashboard'));
        $this->assertTrue(auth('user')->check());
    }

    /** حساب أوقفه مدير المنصّة لا يبقى نافذاً بكلمة المرور القديمة. */
    public function test_a_disabled_admin_cannot_open_a_session(): void
    {
        $this->admin(false)->forceFill(['is_active' => 0])->save();

        // **والثابتُ أن لا جلسةَ تُفتح، لا أن تكون الوجهةُ عنواناً بعينه.**
        //
        // ردُّ الرفض صار `back()` — يعود إلى صفحة الدخول في متصفّحٍ
        // حقيقيّ، وإلى الجذر في اختبارٍ بلا مُحيل. **ومقياسٌ يحرس عنوانَ
        // إعادةِ توجيهٍ يسقط على تغييرٍ لا يمسّ الأمان**، ويُخفي حين
        // يُصادف العنوانُ الصحيحَ أنّ الجلسةَ فُتحت.
        $this->login();

        $this->assertFalse(auth('user')->check(),
            'حسابٌ معطَّلٌ فُتحت له جلسة');

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.auth.login'));
    }

    public function test_the_password_alone_does_not_open_the_panel_when_two_factor_is_on(): void
    {
        $this->admin(true);

        $this->login()->assertRedirect(route('admin.auth.two-factor'));

        // **والجلسةُ لم تُفتح** — لا مجرّد إعادةِ توجيه. فالطريقةُ الشائعة
        // أن تُفتح الجلسةُ ثمّ يُطلب الرمز، ومن يعرف عنوانَ أيّ صفحةٍ
        // يتخطّى الشاشة.
        $this->assertFalse(auth('user')->check(),
            'الجلسةُ فُتحت قبل الرمز — فمن يفتح /admin مباشرةً يتخطّى البوّابة');
    }

    public function test_the_pending_admin_cannot_reach_the_dashboard(): void
    {
        $this->admin(true);
        $this->login();

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.auth.login'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② البوّابة نفسُها
    // ══════════════════════════════════════════════════════════════════

    public function test_the_challenge_screen_is_not_a_door_of_its_own(): void
    {
        // من يفتح عنوانَ البوّابة بلا مرورٍ بكلمة المرور يُعاد.
        $this->get(route('admin.auth.two-factor'))
            ->assertRedirect(route('admin.auth.login'));
    }

    public function test_a_correct_code_completes_the_login(): void
    {
        $admin = $this->admin(true);
        $this->login();

        $svc = app(TwoFactorAuthService::class);
        $code = $svc->generateTotp(app(\App\Services\EncryptionService::class)
            ->decrypt($admin->two_factor_secret));

        $this->post(route('admin.auth.two-factor.verify'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(auth('user')->check());
        $this->assertSame($admin->id, auth('user')->id());
    }

    public function test_a_wrong_code_keeps_the_door_shut(): void
    {
        $this->admin(true);
        $this->login();

        $this->post(route('admin.auth.two-factor.verify'), ['code' => '000000'])
            ->assertRedirect();

        $this->assertFalse(auth('user')->check());
    }

    /** التعطيل بعد كلمة المرور وقبل TOTP لا يمرّ من الجلسة المعلّقة. */
    public function test_a_disabled_pending_admin_cannot_finish_two_factor(): void
    {
        $admin = $this->admin(true);
        $this->login();
        $admin->forceFill(['is_active' => 0])->save();

        $this->post(route('admin.auth.two-factor.verify'), ['code' => '000000'])
            ->assertRedirect(route('admin.auth.login'));

        $this->assertFalse(auth('user')->check());
    }

    public function test_cancelling_clears_the_pending_identity(): void
    {
        // معرّفٌ معلّقٌ يبقى في الجلسة يعني أنّ من يفتح البوّابة لاحقاً
        // يجد حساباً جاهزاً ينتظر رمزاً.
        $this->admin(true);
        $this->login();

        $this->get(route('admin.auth.two-factor.cancel'))
            ->assertRedirect(route('admin.auth.login'));

        $this->get(route('admin.auth.two-factor'))
            ->assertRedirect(route('admin.auth.login'));
    }

    public function test_attempts_are_limited(): void
    {
        $this->admin(true);
        $this->login();

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('admin.auth.two-factor.verify'), ['code' => '00000' . $i]);
        }

        $r = $this->post(route('admin.auth.two-factor.verify'), ['code' => '111111']);

        $this->assertStringContainsString('تجاوزت',
            (string) session('errors')?->first(),
            'ستُّ محاولاتٍ ولا حدّ — ومساحةُ ستّة أرقامٍ مليون، '
            . 'تُكسر في أسبوعٍ بمئة محاولةٍ في الدقيقة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ يُوصَل إليها — القاعدة ١٢
    // ══════════════════════════════════════════════════════════════════

    /**
     * **والشاشةُ تُفتح من الشريط الجانبيّ.**
     *
     * فالميزةُ كانت مبنيّةً بلا مدخل، وإصلاحُها بمسارٍ بلا رابطٍ يُعيد
     * العطلَ نفسه بصورةٍ أحدث.
     */
    public function test_the_setup_screen_is_reachable_from_the_sidebar(): void
    {
        $sidebar = file_get_contents(resource_path(
            'views/admin-views/amial/partials/_sidebar.blade.php'));

        $this->assertStringContainsString("admin.amial.2fa.page", $sidebar,
            'شاشةُ المصادقة الثنائية بلا رابطٍ في الشريط — مبنيّةٌ ولا يُوصل إليها');

        // وطلباتُ السحب كذلك: المسارُ سُجّل في الجولة السابقة والرابطُ أُجّل.
        $this->assertStringContainsString('admin.withdraw.index', $sidebar,
            'شاشةُ طلبات السحب بلا رابطٍ في الشريط');
    }

    public function test_the_setup_screen_opens(): void
    {
        $admin = $this->admin(false);

        $this->actingAs($admin, 'user')
            ->get(route('admin.amial.2fa.page'))
            ->assertOk()
            ->assertSee('المصادقة الثنائية');
    }
}
