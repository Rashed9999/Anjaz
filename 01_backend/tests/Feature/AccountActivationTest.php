<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Muwathaq\MuwathaqProvisioningService;
use App\Services\Muwathaq\MuwathaqWelcomeEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تفعيل حسابٍ اعتمده موثّق من داخل التطبيق: بريد + رمز الاعتماد + كلمة مرور + PIN ← الدخول مباشرة.
 * (بديلٌ عن «نسيت كلمة المرور» الذي كان بابَ الدخول الأول.)
 */
class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $applicant = [], string $kind = 'customer'): array
    {
        $ulid = (string) Str::ulid();

        return [
            'event_version' => 1, 'event_type' => 'account.approved', 'application_ulid' => $ulid,
            'decision_reference' => 'MWT-'.strtoupper(substr(md5($ulid), 0, 12)),
            'subject_kind' => $kind, 'verification_path' => 'standard_customer', 'business_type' => null, 'requested_tier' => null,
            'applicant' => $applicant + ['f_name' => 'محمد', 'l_name' => 'القحطاني', 'phone' => '+9677'.random_int(10000000, 99999999),
                'dial_country_code' => '+967', 'email' => 'welcome-'.strtolower(Str::random(8)).'@example.test', 'gender' => 'male'],
            'subject' => [],
            'decision_context' => ['policy_version' => 'standard-customer-v1', 'verified_phone' => true, 'verified_residence_governorate' => 'YE-AD', 'verified_identity' => true],
        ];
    }

    private function provision(array $event): User
    {
        $r = app(MuwathaqProvisioningService::class)->provisionApproved($event);

        return User::query()->findOrFail((int) $r['user_id']);
    }

    private function provisionedWithCode(): array
    {
        config(['amial_otp.resend.api_key' => 'rk_test']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'msg_1'], 200)]);
        Queue::fake();
        $event = $this->event();
        $u = $this->provision($event);
        app(MuwathaqWelcomeEmailService::class)->send($u, 'customer', $event['application_ulid']);
        $code = null;
        Http::assertSent(function (ClientRequest $r) use (&$code): bool {
            preg_match('/(?<!\d)\d{6}(?!\d)/u', (string) ($r->data()['text'] ?? ''), $m);
            $code = $m[0] ?? null;

            return true;
        });
        $this->assertNotNull($code);

        return [$u, (string) $code];
    }

    private function activate(string $email, string $code, array $over = [])
    {
        return $this->postJson('/api/v1/auth/activate', $over + ['email' => $email, 'otp' => $code, 'password' => 'Str0ng-Pass-9', 'password_confirmation' => 'Str0ng-Pass-9', 'pin' => '482913', 'pin_confirmation' => '482913']);
    }

    /** @test */
    public function the_emailed_code_activates_the_account_sets_password_and_pin_and_signs_the_customer_in(): void
    {
        [$u, $code] = $this->provisionedWithCode();
        $r = $this->activate($u->email, $code);
        $r->assertOk()->assertJsonPath('code', 'LOGIN_OK');
        $this->assertNotEmpty($r->json('meta.token'), 'حمولة الدخول نفسها: رمز الجلسة يعود مع التفعيل');
        $this->assertSame('customer', $r->json('meta.user.role'));
        $fresh = $u->fresh();
        $this->assertSame(1, (int) $fresh->is_email_verified, 'نجاح الرمز = ملكية البريد');
        $this->assertTrue(Hash::check('Str0ng-Pass-9', $fresh->password));
        $this->assertTrue(Hash::check('482913', $fresh->transaction_pin));
        $this->assertFalse((bool) $fresh->requires_pin_setup);
        $this->assertSame(1, DB::table('audit_decisions')->where('action', 'ACCOUNT_ACTIVATED_BY_EMAIL_CODE')->where('subject_id', (string) $u->id)->count());
    }

    /** @test */
    public function a_wrong_code_is_refused_counts_attempts_and_changes_nothing(): void
    {
        [$u, $code] = $this->provisionedWithCode();
        $wrong = $code === '111111' ? '222222' : '111111';
        $this->activate($u->email, $wrong)->assertStatus(422)->assertJsonPath('code', 'ACTIVATION_INVALID');
        $this->assertSame(0, (int) $u->fresh()->is_email_verified);
        $this->assertSame(1, (int) DB::table('otp_challenges')->where('purpose', 'account_activation')->where('identifier', mb_strtolower($u->email))->value('attempts'));
        $this->activate($u->email, $code)->assertOk();
    }

    /** @test */
    public function the_code_works_once_and_an_activated_account_cannot_be_activated_again(): void
    {
        [$u, $code] = $this->provisionedWithCode();
        $this->activate($u->email, $code)->assertOk();
        $this->activate($u->email, $code, ['password' => 'Other-Pass-77', 'password_confirmation' => 'Other-Pass-77'])->assertStatus(422)->assertJsonPath('code', 'ACTIVATION_INVALID');
        $this->assertTrue(Hash::check('Str0ng-Pass-9', $u->fresh()->password), 'كلمة المرور لم تُستبدل بالرمز المستعمل');
    }

    /** @test */
    public function an_unknown_email_gets_the_same_refusal_so_the_route_cannot_probe_accounts(): void
    {
        [$u, $code] = $this->provisionedWithCode();
        $known = $this->activate($u->email, '000000');
        $unknown = $this->activate('nobody-'.Str::random(6).'@example.test', '000000');
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('code'), $unknown->json('code'));
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    /** @test */
    public function a_weak_pin_is_refused_without_burning_the_code(): void
    {
        [$u, $code] = $this->provisionedWithCode();
        $this->activate($u->email, $code, ['pin' => '1111', 'pin_confirmation' => '1111'])->assertStatus(422)->assertJsonPath('code', 'WEAK_PIN');
        $this->assertSame(0, (int) $u->fresh()->is_email_verified);
        $this->activate($u->email, $code)->assertOk();
    }

    /** @test */
    public function the_activation_code_can_be_requested_again_only_for_a_pending_muwathaq_account(): void
    {
        config(['amial_otp.resend.api_key' => 'rk_test']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'msg_2'], 200)]);
        Queue::fake();
        $u = $this->provision($this->event());
        $known = $this->postJson('/api/v1/auth/email-otp/request', ['email' => $u->email, 'purpose' => 'account_activation']);
        $unknown = $this->postJson('/api/v1/auth/email-otp/request', ['email' => 'nobody-'.Str::random(6).'@example.test', 'purpose' => 'account_activation']);
        $known->assertOk()->assertJsonPath('code', 'OTP_ACCEPTED');
        $unknown->assertOk()->assertJsonPath('code', 'OTP_ACCEPTED');
        $this->assertSame(1, DB::table('otp_challenges')->where('purpose', 'account_activation')->count(), 'لا رمز يخرج لبريدٍ مجهول');
    }
}
