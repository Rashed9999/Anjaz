<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Models\UserLogHistory;
use App\Services\Muwathaq\MuwathaqProvisioningService;
use App\Services\Muwathaq\MuwathaqWelcomeEmailService;
use App\Services\Otp\EmailOtpService;
use App\Services\TransactionPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-JOURNEY-PHASE1-001 — رحلة العميل ١ من التثبيت والفتح الأول إلى التفعيل والدخول.
 *
 * يفحص السلسلة الحقيقية نهايةً لنهاية:
 *   ١. تفعيل حساب معتمَد من موثّق برمز PIN من 6 أرقام ← يعيد هاتف العميل في meta.user.phone
 *      لتتذكّره شاشة الدخول والاستلام السريع، ويقبل رمز الـ6 أرقام في بوابة الدخول (/amial/verify-pin)
 *      ومسارات تغيير الرمز والتحويل دون حبس العميل عند 4 أرقام.
 *   ٢. بوابة /amial/verify-pin تفرّق بين «رمز خاطئ» (403) و«حساب مقفول مؤقتاً» (429 مع الدقائق المتبقية).
 *   ٣. استعادة كلمة المرور أو رمز PIN عبر البريد ترفض توحيد السرّين دون إتلاف إثبات التحقق (OTP proof).
 *   ٤. حرّاس إقلاع التطبيق وتوجيه المسارات وتطبيع الأرقام العربية الشرقية.
 */
class CustomerFirstLaunchJourneyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('passport:install', ['--no-interaction' => true]);
    }

    /** @test */
    public function activated_customer_with_six_digit_pin_gets_phone_in_payload_and_passes_pin_gate_and_change_pin(): void
    {
        config(['amial_otp.resend.api_key' => 'rk_test']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'msg_journey_1'], 200)]);
        Queue::fake();

        $ulid = (string) Str::ulid();
        $phone = '+967771445566';
        $email = 'customer1-journey@example.test';

        $provisioned = app(MuwathaqProvisioningService::class)->provisionApproved([
            'event_version' => 1,
            'event_type' => 'account.approved',
            'application_ulid' => $ulid,
            'decision_reference' => 'MWT-' . strtoupper(substr(md5($ulid), 0, 12)),
            'subject_kind' => 'customer',
            'verification_path' => 'standard_customer',
            'business_type' => null,
            'requested_tier' => null,
            'applicant' => [
                'f_name' => 'سالم',
                'l_name' => 'العولقي',
                'phone' => $phone,
                'dial_country_code' => '+967',
                'email' => $email,
                'gender' => 'male',
            ],
            'subject' => [],
            'decision_context' => [
                'policy_version' => 'standard-customer-v1',
                'verified_phone' => true,
                'verified_residence_governorate' => 'YE-AD',
                'verified_identity' => true,
            ],
        ]);

        $user = User::findOrFail((int) $provisioned['user_id']);
        app(MuwathaqWelcomeEmailService::class)->send($user, 'customer', $ulid);

        $code = null;
        Http::assertSent(function (ClientRequest $r) use (&$code): bool {
            preg_match('/(?<!\d)\d{6}(?!\d)/u', (string) ($r->data()['text'] ?? ''), $m);
            $code = $m[0] ?? null;
            return true;
        });
        $this->assertNotNull($code);

        // التفعيل مع ترويسة جهاز التثبيت ورمز PIN من 6 أرقام
        $activated = $this->withHeader('device-id', 'amial-dev-first-launch-01')
            ->postJson('/api/v1/auth/activate', [
                'email' => $email,
                'otp' => $code,
                'password' => 'Amial#2026Pass',
                'password_confirmation' => 'Amial#2026Pass',
                'pin' => '482913',
                'pin_confirmation' => '482913',
            ]);

        $activated->assertOk()->assertJsonPath('code', 'LOGIN_OK');
        $this->assertNotEmpty($activated->json('meta.user.phone'), 'يجب أن تعيد استجابة التفعيل/الدخول هاتف العميل لتذكّره في شاشة الدخول');
        $token = (string) $activated->json('meta.token');
        $this->assertNotEmpty($token);

        // بوابة رمز الدخول للتطبيق تقبل الرمز ذا الـ6 أرقام مباشرةً بعد التفعيل
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('device-id', 'amial-dev-first-launch-01')
            ->postJson('/api/v1/amial/verify-pin', ['pin' => '482913'])
            ->assertOk()
            ->assertJsonPath('code', 'PIN_OK');

        // ومسار تغيير الرمز للعميل يقبل الرموز من 4 إلى 6 أرقام
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('device-id', 'amial-dev-first-launch-01')
            ->postJson('/api/v1/customer/change-pin', [
                'old_pin' => '482913',
                'new_pin' => '593718',
                'confirm_pin' => '593718',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('593718', (string) $user->fresh()->transaction_pin));
    }

    /** @test */
    public function verify_pin_gate_reports_lockout_with_remaining_minutes_instead_of_generic_wrong_pin(): void
    {
        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);
        app(TransactionPinService::class)->setPin($user, '4829');

        // 4 محاولات خاطئة تعيد 403 PIN_INVALID، والخامسة تقفل الحساب وتعيد 429 PIN_LOCKED
        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($user->fresh(), 'api')
                ->postJson('/api/v1/amial/verify-pin', ['pin' => '9173'])
                ->assertStatus(403)
                ->assertJsonPath('code', 'PIN_INVALID');
        }

        $fifth = $this->actingAs($user->fresh(), 'api')
            ->postJson('/api/v1/amial/verify-pin', ['pin' => '9173']);

        $fifth->assertStatus(429)->assertJsonPath('code', 'PIN_LOCKED');
        $this->assertStringContainsString('دقيقة', (string) $fifth->json('message'));

        // المحاولة التالية حتى بالرمز الصحيح تعيد 429 PIN_LOCKED مع الدقائق المتبقية
        $whileLocked = $this->actingAs($user->fresh(), 'api')
            ->postJson('/api/v1/amial/verify-pin', ['pin' => '4829']);

        $whileLocked->assertStatus(429)->assertJsonPath('code', 'PIN_LOCKED');
        $this->assertGreaterThanOrEqual(1, (int) $whileLocked->json('meta.retry_after_minutes'));
    }

    /** @test */
    public function email_recovery_enforces_secret_separation_without_burning_verification_proof(): void
    {
        config([
            'amial_otp.resend.api_key' => 'rk_test',
            'amial_otp.password_reset_channel' => 'email',
            'amial_otp.pin_recovery_channel' => 'email',
        ]);
        $sentTexts = [];
        Http::fake(['api.resend.com/*' => function ($req) use (&$sentTexts) {
            $sentTexts[] = (string) ($req['text'] ?? '');
            return Http::response(['id' => 'msg_sep_' . count($sentTexts)], 200);
        }]);

        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'is_active' => 1,
            'email' => 'sep-guard@example.test',
            'is_email_verified' => 1,
            'password' => Hash::make('482913'),
        ]);
        app(TransactionPinService::class)->setPin($user, '739582');

        // ١) محاولة استعادة رمز PIN ليكون مطابقاً لكلمة المرور الحالية تُرفض دون استهلاك التحدي
        $issuedPin = app(EmailOtpService::class)->issue($user->email, 'pin_recovery', $user->id);
        preg_match('/(?<!\d)\d{6}(?!\d)/u', (string) end($sentTexts), $m1);
        $tokenPin = app(EmailOtpService::class)->verify($issuedPin['challenge_id'], $user->email, 'pin_recovery', $m1[0]);

        $sameAsPass = $this->postJson('/api/v1/auth/pin-recovery/email', [
            'challenge_id' => $issuedPin['challenge_id'],
            'email' => $user->email,
            'verification_token' => $tokenPin,
            'new_pin' => '482913',
            'new_pin_confirmation' => '482913',
        ]);
        $sameAsPass->assertStatus(422)->assertJsonPath('code', 'SECRET_SEPARATION_REQUIRED');
        $this->assertNull(DB::table('otp_challenges')->where('challenge_id', $issuedPin['challenge_id'])->value('consumed_at'));

        // إعادة المحاولة برمز مستقل بنفس جلسة التحقق تنجح فوراً
        $this->postJson('/api/v1/auth/pin-recovery/email', [
            'challenge_id' => $issuedPin['challenge_id'],
            'email' => $user->email,
            'verification_token' => $tokenPin,
            'new_pin' => '618429',
            'new_pin_confirmation' => '618429',
        ])->assertOk()->assertJsonPath('code', 'PIN_RESET');
    }

    /** @test */
    public function flutter_first_launch_and_auth_screens_keep_resilience_and_navigation_contracts(): void
    {
        $main = $this->stripDartComments(base_path('../02_flutter_app/lib/main.dart'));
        $this->assertStringNotContainsString('late List<CameraDescription> cameras', $main);
        $this->assertMatchesRegularExpression('/try\s*\{\s*cameras\s*=\s*await\s+availableCameras\(\)/s', $main);

        $di = $this->stripDartComments(base_path('../02_flutter_app/lib/helper/get_di.dart'));
        $this->assertStringContainsString('getOrCreateInstallationDeviceId', $di);

        $routes = $this->stripDartComments(base_path('../02_flutter_app/lib/helper/route_helper.dart'));
        $this->assertStringContainsString('GetPage(name: createAccountScreen,', $routes);
        $this->assertStringContainsString('GetPage(name: loginScreen,', $routes);

        $pinGate = $this->stripDartComments(base_path('../02_flutter_app/lib/features/shared/widgets/amial_pin_gate.dart'));
        $this->assertStringContainsString('maxLength: 6', $pinGate);
        $this->assertStringNotContainsString('maxLength: 4', $pinGate);

        $activate = $this->stripDartComments(base_path('../02_flutter_app/lib/features/auth/screens/activate_account_screen.dart'));
        $this->assertStringContainsString('requestActivationCode(', $activate);
        $this->assertStringContainsString('ArabicDigitsNormalizingFormatter', $activate);

        $login = $this->stripDartComments(base_path('../02_flutter_app/lib/features/auth/screens/unified_login_screen.dart'));
        $this->assertStringContainsString('forgetLastUser()', $login);
        $this->assertStringContainsString('readLastLogin()', $login);
        $this->assertStringContainsString('ArabicDigitsNormalizingFormatter', $login);
    }

    private function stripDartComments(string $path): string
    {
        $src = (string) file_get_contents($path);
        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? '';
        return preg_replace('~//[^\n]*~', '', $src) ?? '';
    }
}
