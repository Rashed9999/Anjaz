<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Auth\EmailOtpController;
use App\Http\Controllers\Api\V1\Auth\EmailRegistrationController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Models\User;
use App\Services\EmailIdentityService;
use App\Services\Otp\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** All provider requests are faked. No test sends a real email. */
class EmailOtpLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private array $messages = [];
    private int $providerStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'amial_otp.resend.api_key' => 'test-provider-key',
            'amial_otp.registration_channel' => 'email',
            'amial_otp.password_reset_channel' => 'email',
            'amial_otp.pin_recovery_channel' => 'email',
            'amial_otp.max_attempts' => 5,
            'amial_otp.resend_seconds' => 60,
            'amial_otp.ttl_minutes' => 5,
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.resend.com/emails' => function ($request) {
            $this->messages[] = $request;
            return Http::response($this->providerStatus === 200
                ? ['id' => 'email-' . count($this->messages)]
                : ['message' => 'sensitive echoed request'], $this->providerStatus);
        }]);
    }

    private function issue(string $purpose = 'registration', ?User $user = null, string $email = 'recipient@example.com'): array
    {
        return app(EmailOtpService::class)->issue($user?->email ?? $email, $purpose, $user?->id);
    }

    private function code(): string
    {
        $message = $this->messages[array_key_last($this->messages)];
        $this->assertSame(1, preg_match('/(?<!\d)\d{6}(?!\d)/u', $message['text'], $match));
        return $match[0];
    }

    private function row(string $id): object
    {
        return DB::table('otp_challenges')->where('challenge_id', $id)->first();
    }

    private function rejected(string $error, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection: ' . $error);
        } catch (RuntimeException $e) {
            $this->assertSame($error, $e->getMessage());
        }
    }

    private function otpRequest(string $method, array $data, ?User $user = null): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/v1/auth/test', 'POST', $data);
        $request->setUserResolver(fn () => $user);
        return app(EmailOtpController::class)->{$method}($request);
    }

    private function proof(User $user, string $purpose): array
    {
        $issued = $this->issue($purpose, $user);
        $token = app(EmailOtpService::class)->verify($issued['challenge_id'], $user->email, $purpose, $this->code());
        return ['challenge_id' => $issued['challenge_id'], 'email' => $user->email, 'verification_token' => $token];
    }

    #[Test]
    public function issuance_hashes_the_code_and_uses_one_provider_idempotency_key(): void
    {
        $issued = $this->issue();
        $code = $this->code();
        $row = $this->row($issued['challenge_id']);
        $this->assertTrue(Hash::check($code, $row->token_hash));
        $this->assertStringNotContainsString($code, json_encode($issued));
        $this->assertSame('sent', $row->delivery_status);
        $this->assertSame(['email-otp/' . $issued['challenge_id']], $this->messages[0]->header('Idempotency-Key'));
        $this->assertStringNotContainsString($code, $this->messages[0]['subject']);
        $this->assertNotEmpty($this->messages[0]['html']);
    }

    #[Test]
    public function wrong_attempts_commit_and_exhaustion_rejects_even_the_correct_code(): void
    {
        $id = $this->issue()['challenge_id'];
        $correct = $this->code();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->rejected($attempt === 5 ? 'OTP_LOCKED' : 'OTP_INVALID',
                fn () => app(EmailOtpService::class)->verify($id, 'recipient@example.com', 'registration', '000000'));
            $this->assertSame($attempt, (int) $this->row($id)->attempts);
        }
        $this->assertNotNull($this->row($id)->consumed_at);
        $this->rejected('OTP_LOCKED', fn () => app(EmailOtpService::class)->verify($id, 'recipient@example.com', 'registration', $correct));
    }

    #[Test]
    public function successful_verification_cannot_mint_another_token(): void
    {
        $id = $this->issue()['challenge_id'];
        $otp = app(EmailOtpService::class);
        $token = $otp->verify($id, 'recipient@example.com', 'registration', $this->code());
        $this->rejected('OTP_ALREADY_USED', fn () => $otp->verify($id, 'recipient@example.com', 'registration', $this->code()));
        $otp->consumeVerification($id, 'recipient@example.com', 'registration', $token);
        $this->rejected('OTP_ALREADY_USED', fn () => $otp->consumeVerification($id, 'recipient@example.com', 'registration', $token));
    }

    #[Test]
    public function expired_code_is_persistently_invalidated_at_the_boundary(): void
    {
        $id = $this->issue()['challenge_id'];
        DB::table('otp_challenges')->where('challenge_id', $id)->update(['expires_at' => now()]);
        $this->rejected('OTP_EXPIRED', fn () => app(EmailOtpService::class)->verify($id, 'recipient@example.com', 'registration', $this->code()));
        $this->assertSame('expired', $this->row($id)->delivery_status);
        $this->assertNotNull($this->row($id)->consumed_at);
    }

    #[Test]
    public function cooldown_and_resend_supersede_the_old_code(): void
    {
        $id = $this->issue()['challenge_id'];
        $oldCode = $this->code();
        $response = $this->otpRequest('requestCode', ['email' => 'recipient@example.com', 'purpose' => 'registration']);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertCount(1, $this->messages);
        $this->travel(61)->seconds();
        $this->issue();
        $this->assertSame('superseded', $this->row($id)->delivery_status);
        $this->assertSame(1, DB::table('otp_issuance_locks')->count());
        $this->rejected('OTP_ALREADY_USED', fn () => app(EmailOtpService::class)->verify($id, 'recipient@example.com', 'registration', $oldCode));
    }

    #[Test]
    public function provider_failure_is_not_reported_as_sent_and_never_stores_the_response_body(): void
    {
        // Keep one fake callback: adding another matching fake would preserve
        // the earlier success callback and never exercise provider failure.
        $this->providerStatus = 503;
        $response = $this->otpRequest('requestCode', ['email' => 'recipient@example.com', 'purpose' => 'registration']);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $row = DB::table('otp_challenges')->first();
        $this->assertSame('failed', $row->delivery_status);
        $this->assertSame('RESEND_HTTP_503', $row->last_error);
        $again = $this->otpRequest('requestCode', ['email' => 'recipient@example.com', 'purpose' => 'registration']);
        $this->assertSame(429, $again->getStatusCode());
    }

    #[Test]
    public function proof_is_bound_to_email_purpose_and_expiry(): void
    {
        $id = $this->issue()['challenge_id'];
        $otp = app(EmailOtpService::class);
        $code = $this->code();
        $this->rejected('OTP_NOT_FOUND', fn () => $otp->verify($id, 'other@example.com', 'registration', $code));
        $this->rejected('OTP_NOT_FOUND', fn () => $otp->verify($id, 'recipient@example.com', 'pin_recovery', $code));
        $token = $otp->verify($id, 'recipient@example.com', 'registration', $code);
        $this->rejected('VERIFICATION_INVALID', fn () => $otp->consumeVerification($id, 'recipient@example.com', 'registration', str_repeat('x', 64)));
        DB::table('otp_challenges')->where('challenge_id', $id)->update(['verification_expires_at' => now()]);
        $this->rejected('VERIFICATION_EXPIRED', fn () => $otp->consumeVerification($id, 'recipient@example.com', 'registration', $token));
        $this->assertNull($this->row($id)->consumed_at);
    }

    #[Test]
    public function recovery_responses_do_not_disclose_whether_the_account_is_verified(): void
    {
        $user = User::factory()->create();
        $unknown = $this->otpRequest('requestCode', ['email' => 'unknown@example.com', 'purpose' => 'password_reset']);
        $known = $this->otpRequest('requestCode', ['email' => $user->email, 'purpose' => 'password_reset']);
        $a = $unknown->getData(true);
        $b = $known->getData(true);
        unset($a['meta']['challenge_id'], $a['meta']['masked_email'], $b['meta']['challenge_id'], $b['meta']['masked_email']);
        $this->assertSame($a, $b);
        $this->assertSame('OTP_ACCEPTED', $b['code']);
        $this->assertCount(1, $this->messages);
    }

    #[Test]
    public function disabled_and_unverified_accounts_receive_no_recovery_email(): void
    {
        foreach ([['is_active' => 0], ['is_email_verified' => 0]] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->assertNull(app(EmailIdentityService::class)->findVerifiedOwner($user->email));
            $this->otpRequest('requestCode', ['email' => $user->email, 'purpose' => 'pin_recovery']);
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function a_weak_pin_does_not_burn_valid_proof_and_retry_changes_only_the_pin(): void
    {
        $user = User::factory()->create();
        $oldPassword = $user->password;
        $proof = $this->proof($user, 'pin_recovery');
        $bad = $this->otpRequest('resetPin', $proof + ['new_pin' => '1234', 'new_pin_confirmation' => '1234']);
        $this->assertSame('WEAK_PIN', $bad->getData(true)['code']);
        $this->assertNull($this->row($proof['challenge_id'])->consumed_at);
        $ok = $this->otpRequest('resetPin', $proof + ['new_pin' => '739582', 'new_pin_confirmation' => '739582']);
        $this->assertSame('PIN_RESET', $ok->getData(true)['code']);
        $this->assertTrue(Hash::check('739582', $user->fresh()->transaction_pin));
        $this->assertSame($oldPassword, $user->fresh()->password);
        $this->assertNotNull($this->row($proof['challenge_id'])->consumed_at);
    }

    #[Test]
    public function password_recovery_revokes_access_refresh_and_remembered_sessions_for_only_its_owner(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember']);
        $other = User::factory()->create();
        foreach ([$user, $other] as $owner) {
            DB::table('oauth_access_tokens')->insert([
                'id' => 'access-' . $owner->id, 'user_id' => $owner->id, 'client_id' => 1,
                'revoked' => $owner->id === $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('oauth_refresh_tokens')->insert([
                'id' => 'refresh-' . $owner->id, 'access_token_id' => 'access-' . $owner->id,
                'revoked' => false, 'expires_at' => now()->addDay(),
            ]);
            DB::table('sessions')->insert([
                'id' => 'session-' . $owner->id, 'user_id' => $owner->id,
                'payload' => '', 'last_activity' => time(),
            ]);
        }
        $proof = $this->proof($user, 'password_reset');
        $result = $this->otpRequest('resetPassword', $proof + ['password' => 'Changed#723', 'password_confirmation' => 'Changed#723']);
        $this->assertSame('PASSWORD_RESET', $result->getData(true)['code']);
        $this->assertTrue(Hash::check('Changed#723', $user->fresh()->password));
        $this->assertNotSame('old-remember', $user->fresh()->remember_token);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'refresh-' . $user->id, 'revoked' => true]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'refresh-' . $other->id, 'revoked' => false]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $replay = $this->otpRequest('resetPassword', $proof + ['password' => 'Changed#999', 'password_confirmation' => 'Changed#999']);
        $this->assertSame(409, $replay->getStatusCode());
    }

    #[Test]
    public function recovery_rechecks_identity_before_writing_and_rolls_back_consumption(): void
    {
        $user = User::factory()->create();
        $proof = $this->proof($user, 'password_reset');
        $oldPassword = $user->password;
        app(EmailIdentityService::class)->replaceVerifiedEmail($user, 'replacement@example.com');
        $response = $this->otpRequest('resetPassword', $proof + ['password' => 'change-me', 'password_confirmation' => 'change-me']);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame($oldPassword, $user->fresh()->password);
        $this->assertNull($this->row($proof['challenge_id'])->consumed_at);
    }

    #[Test]
    public function legacy_owner_can_verify_the_current_email_and_retry_a_wrong_code(): void
    {
        $user = User::factory()->create(['email' => 'legacy@example.com', 'is_email_verified' => 0, 'password' => Hash::make('6392')]);
        $requested = $this->otpRequest('requestEmailChange', ['new_email' => $user->email, 'current_password' => '6392'], $user);
        $this->assertSame(200, $requested->getStatusCode());
        $id = $requested->getData(true)['meta']['challenge_id'];
        $data = ['challenge_id' => $id, 'new_email' => $user->email];
        $bad = $this->otpRequest('confirmEmailChange', $data + ['otp' => '000000'], $user);
        $this->assertSame(422, $bad->getStatusCode());
        $this->assertSame(1, (int) $this->row($id)->attempts);
        $good = $this->otpRequest('confirmEmailChange', $data + ['otp' => $this->code()], $user);
        $this->assertSame('EMAIL_CHANGED', $good->getData(true)['code']);
        $this->assertSame(1, (int) $user->fresh()->is_email_verified);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[Test]
    public function email_change_is_owner_bound_and_a_failed_write_keeps_the_code_retryable(): void
    {
        $user = User::factory()->create(['password' => Hash::make('6392')]);
        $other = User::factory()->create();
        $requested = $this->otpRequest('requestEmailChange', ['new_email' => 'new@example.com', 'current_password' => '6392'], $user);
        $id = $requested->getData(true)['meta']['challenge_id'];
        $data = ['challenge_id' => $id, 'new_email' => 'new@example.com', 'otp' => $this->code()];
        $this->assertSame(404, $this->otpRequest('confirmEmailChange', $data, $other)->getStatusCode());
        User::factory()->create(['email' => 'new@example.com']);
        $this->assertSame(409, $this->otpRequest('confirmEmailChange', $data, $user)->getStatusCode());
        $this->assertNull($this->row($id)->consumed_at);
        $this->assertNull($this->row($id)->verified_at);
        $this->assertSame($user->email, $user->fresh()->email);
    }

    #[Test]
    public function failed_registration_keeps_proof_and_email_registration_never_manufactures_phone_otp(): void
    {
        $issued = $this->issue();
        $token = app(EmailOtpService::class)->verify($issued['challenge_id'], 'recipient@example.com', 'registration', $this->code());
        $data = [
            'email' => 'recipient@example.com', 'email_challenge_id' => $issued['challenge_id'],
            'email_verification_token' => $token, 'dial_country_code' => '+967', 'phone' => '779123987',
            'password' => '6392', 'gender' => 'Male', 'account_type' => 'customer',
        ];
        DB::table('business_settings')->updateOrInsert(['key' => 'phone_verification'], ['value' => 1]);
        $controller = app(EmailRegistrationController::class);
        $failed = $controller->register(Request::create('/api/v1/auth/register/email', 'POST', $data));
        $this->assertSame(403, $failed->getStatusCode());
        $this->assertNull($this->row($issued['challenge_id'])->consumed_at);
        $this->assertDatabaseMissing('users', ['email_canonical' => 'recipient@example.com']);
        $ok = $controller->register(Request::create('/api/v1/auth/register/email', 'POST', $data + ['f_name' => 'Email', 'l_name' => 'Owner']));
        $this->assertSame(200, $ok->getStatusCode());
        $user = User::where('email_canonical', 'recipient@example.com')->firstOrFail();
        $this->assertSame(1, (int) $user->is_email_verified);
        $this->assertNotNull($this->row($issued['challenge_id'])->consumed_at);
        $this->assertSame((int) $user->id, (int) $this->row($issued['challenge_id'])->user_id);
        $this->assertSame(0, DB::table('phone_verifications')->count());
    }

    #[Test]
    public function channel_switches_disable_requests_and_consumption(): void
    {
        $user = User::factory()->create();
        $proof = $this->proof($user, 'pin_recovery');
        config(['amial_otp.pin_recovery_channel' => 'sms']);
        $this->assertSame(409, $this->otpRequest('requestCode', ['email' => $user->email, 'purpose' => 'pin_recovery'])->getStatusCode());
        $this->assertSame(409, $this->otpRequest('resetPin', $proof + ['new_pin' => '739582', 'new_pin_confirmation' => '739582'])->getStatusCode());
        $this->assertNull($this->row($proof['challenge_id'])->consumed_at);
    }

    #[Test]
    public function request_fields_cannot_bypass_the_legacy_phone_verification_gate(): void
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'phone_verification'], ['value' => 1]);
        $response = app(RegisterController::class)->customerRegistration(Request::create('/api/v1/customer/auth/register', 'POST', [
            'f_name' => 'Phone', 'l_name' => 'Owner', 'gender' => 'Male',
            'dial_country_code' => '+967', 'phone' => '779123988', 'password' => '6392',
            'email' => 'phone-owner@example.com', 'verifiedEmail' => 'phone-owner@example.com',
            'email_authorized' => true,
        ]));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertDatabaseMissing('users', ['email_canonical' => 'phone-owner@example.com']);
    }

    #[Test]
    public function authenticated_email_routes_require_authentication_and_the_pos_device_gate(): void
    {
        foreach (['request', 'confirm'] as $action) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('amial.auth.email-change.' . $action);
            $this->assertNotNull($route);
            $this->assertContains('auth:api', $route->gatherMiddleware());
            $this->assertContains('amial.pos-device', $route->gatherMiddleware());
            $this->postJson('/api/v1/auth/email-change/' . $action, [])->assertUnauthorized();
        }
    }

    #[Test]
    public function signed_webhook_changes_delivery_only_and_late_events_cannot_regress_it(): void
    {
        $issued = $this->issue();
        $key = random_bytes(32);
        config(['amial_otp.resend.webhook_secret' => 'whsec_' . base64_encode($key)]);
        $payload = json_encode(['type' => 'email.delivered', 'data' => ['email_id' => 'email-1']]);
        $id = 'msg-test';
        $stamp = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $id . '.' . $stamp . '.' . $payload, $key, true));
        $response = $this->callWebhook($payload, $id, $stamp, 'v1,' . $sig);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('delivered', $this->row($issued['challenge_id'])->delivery_status);
        $this->assertNull($this->row($issued['challenge_id'])->verified_at);
        app(EmailOtpService::class)->applyResendEvent(['type' => 'email.sent', 'data' => ['email_id' => 'email-1']]);
        $this->assertSame('delivered', $this->row($issued['challenge_id'])->delivery_status);
        $this->assertSame(401, $this->callWebhook($payload . ' ', $id, $stamp, 'v1,' . $sig)->getStatusCode());
        $this->assertSame(401, $this->callWebhook($payload, $id, (string) (time() - 301), 'v1,' . $sig)->getStatusCode());
    }

    #[Test]
    public function a_rolled_back_support_approval_cannot_send_a_code_for_a_nonexistent_challenge(): void
    {
        DB::beginTransaction();
        try {
            $issued = app(EmailOtpService::class)->issue('recipient@example.com', 'pin_recovery', afterCommit: true);
            $this->assertSame('pending', $issued['delivery_status']);
            Http::assertNothingSent();
        } finally {
            DB::rollBack();
        }
        Http::assertNothingSent();
        $this->assertDatabaseMissing('otp_challenges', ['challenge_id' => $issued['challenge_id']]);
    }

    private function callWebhook(string $payload, string $id, string $stamp, string $signature): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/v1/auth/email-otp/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => $stamp, 'HTTP_SVIX_SIGNATURE' => $signature,
        ], $payload);
        return app(EmailOtpController::class)->webhook($request);
    }
}
