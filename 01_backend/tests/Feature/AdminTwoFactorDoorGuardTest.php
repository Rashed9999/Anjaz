<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformLoginPinService;
use App\Services\PlatformRoleService;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-2FA-DOOR-001 · AMIAL-2FA-INLINE-001
 *
 * 2FA ليس مجرد QR يُربط ثم يُنسى: عند كل دخول لحساب فعّله يجب أن يُتحقق
 * من رمز TOTP المتغيّر. يمكن إدخاله في شاشة الدخول نفسها، وإن تُرك فارغاً
 * تبقى شاشة التحدي الثانية مساراً احتياطياً.
 */
class AdminTwoFactorDoorGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ngAdminPass!2026';
    private const LOGIN_PIN = '4821';

    private function admin(bool $withTwoFactor): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'admin',
            'phone' => '+967711900001',
            'password' => Hash::make(self::PASSWORD),
        ]);

        // عقد الدخول الحالي يتطلب PIN وظيفياً حقيقياً، فلا يجوز أن تختبر
        // 2FA عبر مسار قديم يتجاوز credential أضيف بعد بناء هذا الاختبار.
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);
        app(PlatformLoginPinService::class)->issue(
            $u,
            self::LOGIN_PIN,
            null,
            'two_factor_guard_test',
            false,
            'not_required',
        );

        if ($withTwoFactor) {
            $svc = app(TwoFactorAuthService::class);
            $secret = $svc->generateSecret();
            $enc = app(\App\Services\EncryptionService::class);

            $u->forceFill([
                'two_factor_secret' => $enc->encrypt($secret),
                'two_factor_enabled' => true,
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        return $u->fresh();
    }

    /**
     * **وعقدُ الباب تغيّر مرّتين، فيُختبَر بعقده الأخير.**
     *
     * كان المساعِدُ يُرسل حقلَي الكابتشا، فحلّ محلَّهما رمزُ PIN، ثمّ صار
     * النموذجُ يقبل رمزَ المصادقة الثنائيّة معهما. **وكلُّ تغييرٍ منها
     * أسقط هذا الحارس** — وأوّلُ قراءةٍ توحي بعطلٍ في المصادقة الثنائيّة،
     * **والعطلُ في بابٍ سابق**: الدخولُ يفشل صامتاً فلا تُكتب حالةُ
     * الانتظار.
     *
     * **ومقياسٌ يُشخّص خطأً أسوأ من مقياسٍ يسقط** — يُرسل من يصدّقه إلى
     * الموضع الخطأ.
     */
    private function login(?string $twoFactorCode = null): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'phone' => '+967711900001',
            'password' => self::PASSWORD,
            'login_pin' => self::LOGIN_PIN,
        ];

        if ($twoFactorCode !== null) {
            $payload['two_factor_code'] = $twoFactorCode;
        }

        return $this->post(route('admin.auth.login'), $payload);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الفرض عند الدخول
    // ══════════════════════════════════════════════════════════════════

    public function test_an_admin_without_two_factor_still_gets_in(): void
    {
        $admin = $this->admin(false);

        $this->login()->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'user');
    }

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

    public function test_password_and_pin_do_not_open_the_panel_when_two_factor_is_on_and_code_is_missing(): void
    {
        $this->admin(true);

        $this->login()->assertRedirect(route('admin.auth.two-factor'));

        $this->assertFalse(auth('user')->check(),
            'الجلسة فُتحت قبل رمز المصادقة الثنائية');
    }

    public function test_the_pending_admin_cannot_reach_the_dashboard(): void
    {
        $this->admin(true);
        $this->login();

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.auth.login'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الإدخال الصريح في شاشة الدخول
    // ══════════════════════════════════════════════════════════════════

    public function test_login_page_has_a_google_authenticator_input(): void
    {
        $this->get(route('admin.auth.login'))
            ->assertOk()
            ->assertSee('name="two_factor_code"', false)
            ->assertSee('Google Authenticator');
    }

    public function test_a_correct_google_authenticator_code_can_complete_login_inline(): void
    {
        $admin = $this->admin(true);
        $secret = app(\App\Services\EncryptionService::class)
            ->decrypt($admin->two_factor_secret);
        $code = app(TwoFactorAuthService::class)->generateTotp($secret);

        $this->login($code)->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'user');
    }

    public function test_a_wrong_inline_authenticator_code_keeps_the_door_shut(): void
    {
        $this->admin(true);

        $this->login('000000')
            ->assertSessionHasErrors('two_factor_code');

        $this->assertFalse(auth('user')->check());
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ شاشة التحدي الاحتياطية
    // ══════════════════════════════════════════════════════════════════

    public function test_the_challenge_screen_is_not_a_door_of_its_own(): void
    {
        $this->get(route('admin.auth.two-factor'))
            ->assertRedirect(route('admin.auth.login'));
    }

    public function test_a_correct_code_on_the_second_step_completes_the_login(): void
    {
        $admin = $this->admin(true);
        $this->login();

        $svc = app(TwoFactorAuthService::class);
        $code = $svc->generateTotp(app(\App\Services\EncryptionService::class)
            ->decrypt($admin->two_factor_secret));

        $this->post(route('admin.auth.two-factor.verify'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'user');
    }

    public function test_a_wrong_code_on_the_second_step_keeps_the_door_shut(): void
    {
        $this->admin(true);
        $this->login();

        $this->post(route('admin.auth.two-factor.verify'), ['code' => '000000'])
            ->assertRedirect();

        $this->assertFalse(auth('user')->check());
    }

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
            'محاولات 2FA المتكررة بلا حد');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ يُوصَل إلى الإعداد من اللوحة
    // ══════════════════════════════════════════════════════════════════

    public function test_the_setup_screen_is_reachable_from_the_sidebar(): void
    {
        $sidebar = file_get_contents(resource_path(
            'views/admin-views/amial/partials/_sidebar.blade.php'));

        $this->assertStringContainsString("admin.amial.2fa.page", $sidebar,
            'شاشة المصادقة الثنائية بلا رابط في الشريط');

        $this->assertStringContainsString('admin.withdraw.index', $sidebar,
            'شاشة طلبات السحب بلا رابط في الشريط');
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
