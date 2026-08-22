<?php

namespace Tests\Feature;

use App\Http\Middleware\PlatformPermissionMiddleware;
use App\Mail\PlatformLoginPinMail;
use App\Models\User;
use App\Services\PlatformLoginPinService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * AMIAL-PLATFORM-LOGIN-PIN-001
 *
 * حارس عقد دخول موظفي المنصّة:
 * هاتف + كلمة مرور + PIN مستقل من 4 أرقام، بلا CAPTCHA.
 */
class PlatformAdminLoginPinGuardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role = PlatformRoleService::ADMIN, array $overrides = []): User
    {
        $u = User::factory()->create(array_merge([
            'type' => ADMIN_TYPE,
            'role' => 'admin',
            'is_active' => 1,
            'phone' => '9677000' . random_int(1000, 9999),
            'email' => 'operator-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => Hash::make('StrongPass123!'),
        ], $overrides));

        app(PlatformRoleService::class)->assign($u, $role);

        return $u->refresh();
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('test');
        parent::tearDown();
    }

    /** @test */
    public function login_page_uses_employee_pin_and_no_longer_requires_captcha(): void
    {
        $r = $this->get(route('admin.auth.login'));

        $r->assertOk()
            ->assertSee('name="login_pin"', false)
            ->assertSee('رمز PIN الوظيفي', false)
            ->assertDontSee('name="default_captcha_value"', false)
            ->assertDontSee('data-testid="captcha-img"', false);
    }

    /** @test */
    public function correct_phone_password_and_pin_open_the_admin_session(): void
    {
        $admin = $this->operator();
        app(PlatformLoginPinService::class)->issue(
            $admin, '4821', null, 'test', false, 'not_required');

        $r = $this->post(route('admin.auth.login'), [
            'phone' => $admin->phone,
            'password' => 'StrongPass123!',
            'login_pin' => '4821',
        ]);

        $r->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'user');
    }

    /** @test */
    public function password_alone_cannot_open_the_admin_session(): void
    {
        $admin = $this->operator();
        app(PlatformLoginPinService::class)->issue(
            $admin, '4821', null, 'test', false, 'not_required');

        $this->post(route('admin.auth.login'), [
            'phone' => $admin->phone,
            'password' => 'StrongPass123!',
        ])->assertSessionHasErrors('login_pin');

        $this->assertGuest('user');
    }

    /** @test */
    public function five_wrong_pins_lock_the_employee_pin_temporarily(): void
    {
        $admin = $this->operator();
        $service = app(PlatformLoginPinService::class);
        $service->issue($admin, '4821', null, 'test', false, 'not_required');

        for ($i = 0; $i < PlatformLoginPinService::MAX_ATTEMPTS; $i++) {
            $service->verify($admin, '0000');
        }

        $row = DB::table('platform_login_pins')->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame(PlatformLoginPinService::MAX_ATTEMPTS, (int) $row->failed_attempts);
        $this->assertNotNull($row->locked_until);

        $result = $service->verify($admin, '4821');
        $this->assertFalse($result['ok']);
        $this->assertSame('locked', $result['reason']);
    }

    /** @test */
    public function bootstrap_platform_admin_gets_default_1234_but_other_staff_do_not(): void
    {
        $admin = $this->operator(PlatformRoleService::ADMIN);
        $support = $this->operator(PlatformRoleService::SUPPORT);
        $service = app(PlatformLoginPinService::class);

        $this->assertTrue($service->verify($admin, '1234')['ok']);
        $this->assertTrue((bool) DB::table('platform_login_pins')
            ->where('user_id', $admin->id)->value('must_change'));

        $this->assertSame('not_configured', $service->verify($support, '1234')['reason']);
        $this->assertDatabaseMissing('platform_login_pins', ['user_id' => $support->id]);
    }

    /** @test */
    public function creating_an_employee_requires_email_generates_random_pin_and_sends_it_without_storing_plaintext(): void
    {
        Mail::fake();
        $admin = $this->operator();
        $supportRoleId = app(PlatformRoleService::class)->roleId(PlatformRoleService::SUPPORT);
        $this->assertNotNull($supportRoleId);

        $email = 'new.support@example.test';
        $phone = '967777333444';

        $this->actingAs($admin, 'user')->post(route('admin.amial.ops.operators.store'), [
            'f_name' => 'دعم',
            'l_name' => 'جديد',
            'phone' => $phone,
            'email' => $email,
            'password' => 'AnotherStrong123!',
            'role_ids' => [$supportRoleId],
        ])->assertRedirect();

        $employee = User::where('email', $email)->firstOrFail();
        $row = DB::table('platform_login_pins')->where('user_id', $employee->id)->firstOrFail();

        Mail::assertSent(PlatformLoginPinMail::class, function (PlatformLoginPinMail $mail) use ($email, $row) {
            return $mail->hasTo($email)
                && preg_match('/^\d{4}$/', $mail->pin) === 1
                && $row->pin_hash !== $mail->pin
                && Hash::check($mail->pin, $row->pin_hash);
        });

        $this->assertSame('sent', DB::table('platform_login_pins')
            ->where('user_id', $employee->id)->value('delivery_status'));
    }

    /** @test */
    public function only_a_platform_admin_can_reset_an_employee_pin(): void
    {
        Mail::fake();
        $supportActor = $this->operator(PlatformRoleService::SUPPORT);
        $target = $this->operator(PlatformRoleService::SUPPORT);
        app(PlatformLoginPinService::class)->issue($target, '1111', null, 'test', false, 'sent');

        // نعزل هنا الحارس الداخلي الأدق عن صلاحية الوصول العامة للصفحة.
        $this->withoutMiddleware(PlatformPermissionMiddleware::class);

        $this->actingAs($supportActor, 'user')
            ->post(route('admin.amial.ops.roles.update', $target->id), [
                'operator_action' => 'reset_login_pin',
            ])->assertForbidden();

        Mail::assertNothingSent();
        $hash = DB::table('platform_login_pins')->where('user_id', $target->id)->value('pin_hash');
        $this->assertTrue(Hash::check('1111', $hash));
    }

    /** @test */
    public function platform_admin_reset_emails_the_new_pin_and_never_exposes_it_in_the_response(): void
    {
        Mail::fake();
        $admin = $this->operator();
        $target = $this->operator(PlatformRoleService::SUPPORT);
        app(PlatformLoginPinService::class)->issue($target, '1111', null, 'test', false, 'sent');

        $response = $this->actingAs($admin, 'user')
            ->post(route('admin.amial.ops.roles.update', $target->id), [
                'operator_action' => 'reset_login_pin',
            ])->assertRedirect();

        $row = DB::table('platform_login_pins')->where('user_id', $target->id)->firstOrFail();
        $mailedPin = null;

        Mail::assertSent(PlatformLoginPinMail::class, function (PlatformLoginPinMail $mail) use ($target, $row, &$mailedPin) {
            $mailedPin = $mail->pin;
            return $mail->hasTo($target->email)
                && $mail->reason === 'reset'
                && Hash::check($mail->pin, $row->pin_hash);
        });

        $this->assertNotNull($mailedPin);
        $this->assertStringNotContainsString($mailedPin, $response->getContent());
    }

    /** @test */
    public function admin_can_change_the_default_pin_only_after_proving_the_current_pin(): void
    {
        $admin = $this->operator();
        $service = app(PlatformLoginPinService::class);
        $this->assertTrue($service->verify($admin, '1234')['ok']);

        $this->actingAs($admin, 'user')
            ->post(route('admin.amial.ops.roles.update', $admin->id), [
                'operator_action' => 'change_own_login_pin',
                'current_login_pin' => '1234',
                'new_login_pin' => '8642',
                'new_login_pin_confirmation' => '8642',
            ])->assertRedirect();

        $row = DB::table('platform_login_pins')->where('user_id', $admin->id)->firstOrFail();
        $this->assertFalse(Hash::check('1234', $row->pin_hash));
        $this->assertTrue(Hash::check('8642', $row->pin_hash));
        $this->assertFalse((bool) $row->must_change);
    }
}
