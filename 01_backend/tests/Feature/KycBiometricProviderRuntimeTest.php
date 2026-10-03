<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Kyc\Biometric\BiometricProviderDriver;
use App\Services\Kyc\Biometric\BiometricProviderManager;
use App\Services\Kyc\Biometric\BiometricStartResult;
use App\Services\Kyc\Biometric\BiometricVerificationService;
use App\Services\Kyc\Biometric\BiometricWebhookEvent;
use App\Services\Kyc\KycPrivacyService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Driver اختبار فقط؛ لا يُسجَّل في config الإنتاج ولا ينتج نتيجة من تلقاء نفسه. */
final class TestSignedBiometricDriver implements BiometricProviderDriver
{
    public function name(): string
    {
        return 'test_bio';
    }

    public function available(): bool
    {
        return true;
    }

    public function start(User $user, string $attemptUlid): BiometricStartResult
    {
        return new BiometricStartResult(
            providerReference: 'ref-'.$attemptUlid,
            redirectUrl: 'https://provider.example/session/'.$attemptUlid,
            sdkToken: 'one-time-sdk-'.$attemptUlid,
            expiresAt: now()->addMinutes(15)->toIso8601String(),
        );
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $value = $headers['x-test-signature'][0] ?? $headers['X-Test-Signature'][0] ?? null;
        return is_string($value) && hash_equals('signed-test-event', $value);
    }

    public function parseWebhook(string $rawBody, array $headers): BiometricWebhookEvent
    {
        $data = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

        return new BiometricWebhookEvent(
            eventId: (string) ($data['event_id'] ?? ''),
            providerReference: (string) ($data['provider_reference'] ?? ''),
            eventType: (string) ($data['event_type'] ?? 'verification.updated'),
            livenessStatus: (string) ($data['liveness_status'] ?? 'pending'),
            livenessScore: isset($data['liveness_score']) ? (string) $data['liveness_score'] : null,
            faceMatchStatus: (string) ($data['face_match_status'] ?? 'pending'),
            faceMatchScore: isset($data['face_match_score']) ? (string) $data['face_match_score'] : null,
            resultCode: isset($data['result_code']) ? (string) $data['result_code'] : null,
            occurredAt: isset($data['occurred_at']) ? (string) $data['occurred_at'] : null,
        );
    }
}

class KycBiometricProviderRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('amial_kyc.biometric.enabled', true);
        config()->set('amial_kyc.biometric.provider', 'test_bio');
        config()->set('amial_kyc.biometric.drivers', [
            'test_bio' => TestSignedBiometricDriver::class,
        ]);
        config()->set('amial_kyc.biometric.restart_cooldown_seconds', 30);
        config()->set('amial_kyc.biometric.attempt_ttl_minutes', 30);
    }

    private function user(): User
    {
        return User::factory()->create(['is_kyc_verified' => 0]);
    }

    private function chooseAutomated(User $user): void
    {
        app(KycPrivacyService::class)->choose($user, KycPrivacyService::MODE_AUTOMATED);
    }

    private function signedHeaders(): array
    {
        return ['x-test-signature' => ['signed-test-event']];
    }

    private function providerCallbackPayload(string $reference, string $eventId = 'evt-1'): string
    {
        return json_encode([
            'event_id' => $eventId,
            'provider_reference' => $reference,
            'event_type' => 'verification.completed',
            'liveness_status' => 'passed',
            'liveness_score' => '0.9821',
            'face_match_status' => 'matched',
            'face_match_score' => '0.9432',
            'result_code' => 'OK',
            'occurred_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
    }

    /** @test */
    public function enabled_unknown_alias_is_not_treated_as_a_real_provider(): void
    {
        config()->set('amial_kyc.biometric.provider', 'ghost_provider');

        $this->assertFalse(app(BiometricProviderManager::class)->configured());
        $this->assertFalse(app(KycPrivacyService::class)->biometricConfigured());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('KYC_BIOMETRIC_PROVIDER_NOT_CONFIGURED');
        app(KycPrivacyService::class)->choose($this->user(), KycPrivacyService::MODE_AUTOMATED);
    }

    /** @test */
    public function starting_a_session_creates_pending_evidence_but_never_verifies_the_account(): void
    {
        $user = $this->user();
        $this->chooseAutomated($user);

        $result = app(BiometricVerificationService::class)->start($user);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('test_bio', $result['provider']);
        $this->assertNotEmpty($result['launch']['sdk_token']);
        $this->assertSame(0, (int) $user->fresh()->is_kyc_verified,
            'بدء جلسة بيومترية وثّق الحساب قبل وصول نتيجة وقبل قرار اللجنة.');

        $attempt = DB::table('kyc_biometric_attempts')->where('attempt_ulid', $result['attempt_ulid'])->first();
        $this->assertSame('pending', $attempt->status);
        $this->assertStringStartsWith('ref-', (string) $attempt->provider_reference);
        $this->assertFalse(SchemaProbe::columnExists('kyc_biometric_attempts', 'sdk_token'),
            'رمز SDK المؤقت صار يُخزن في قاعدة البيانات.');
    }

    /** @test */
    public function invalid_signature_cannot_create_an_event_or_change_the_case(): void
    {
        $user = $this->user();
        $this->chooseAutomated($user);
        $start = app(BiometricVerificationService::class)->start($user);
        $reference = (string) DB::table('kyc_biometric_attempts')
            ->where('attempt_ulid', $start['attempt_ulid'])->value('provider_reference');

        try {
            app(BiometricVerificationService::class)->applyWebhook(
                'test_bio', $this->providerCallbackPayload($reference), ['x-test-signature' => ['wrong']],
            );
            $this->fail('قُبل callback بتوقيع غير صالح.');
        } catch (DomainException $e) {
            $this->assertSame('KYC_BIOMETRIC_WEBHOOK_SIGNATURE_INVALID', $e->getMessage());
        }

        $this->assertSame(0, DB::table('kyc_biometric_events')->count());
        $this->assertSame('pending', DB::table('kyc_verification_cases')
            ->where('user_id', $user->id)->value('liveness_status'));
    }

    /** @test */
    public function signed_success_marks_evidence_ready_but_account_stays_unverified(): void
    {
        $user = $this->user();
        $this->chooseAutomated($user);
        $start = app(BiometricVerificationService::class)->start($user);
        $reference = (string) DB::table('kyc_biometric_attempts')
            ->where('attempt_ulid', $start['attempt_ulid'])->value('provider_reference');

        $outcome = app(BiometricVerificationService::class)->applyWebhook(
            'test_bio', $this->providerCallbackPayload($reference), $this->signedHeaders(),
        );

        $this->assertSame('completed', $outcome['status']);
        $case = DB::table('kyc_verification_cases')->where('user_id', $user->id)->first();
        $this->assertSame('evidence_ready', $case->status);
        $this->assertSame('passed', $case->liveness_status);
        $this->assertSame('matched', $case->face_match_status);
        $this->assertSame(0, (int) $user->fresh()->is_kyc_verified,
            'Callback المزود تجاوز قرار KYC النهائي وكتب التوثيق مباشرة.');
    }

    /** @test */
    public function duplicate_provider_event_is_idempotent(): void
    {
        $user = $this->user();
        $this->chooseAutomated($user);
        $start = app(BiometricVerificationService::class)->start($user);
        $reference = (string) DB::table('kyc_biometric_attempts')
            ->where('attempt_ulid', $start['attempt_ulid'])->value('provider_reference');
        $body = $this->providerCallbackPayload($reference, 'evt-idempotent');

        $service = app(BiometricVerificationService::class);
        $first = $service->applyWebhook('test_bio', $body, $this->signedHeaders());
        $second = $service->applyWebhook('test_bio', $body, $this->signedHeaders());

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, DB::table('kyc_biometric_events')->count());
    }

    /** @test */
    public function late_callback_from_superseded_attempt_cannot_overwrite_the_current_case(): void
    {
        $user = $this->user();
        $this->chooseAutomated($user);
        $service = app(BiometricVerificationService::class);

        $first = $service->start($user);
        $firstRef = (string) DB::table('kyc_biometric_attempts')
            ->where('attempt_ulid', $first['attempt_ulid'])->value('provider_reference');
        DB::table('kyc_biometric_attempts')->where('attempt_ulid', $first['attempt_ulid'])
            ->update(['started_at' => now()->subMinute()]);

        $second = $service->start($user);
        $secondRef = (string) DB::table('kyc_biometric_attempts')
            ->where('attempt_ulid', $second['attempt_ulid'])->value('provider_reference');
        $this->assertNotSame($firstRef, $secondRef);

        $outcome = $service->applyWebhook(
            'test_bio', $this->providerCallbackPayload($firstRef, 'evt-late'), $this->signedHeaders(),
        );

        $this->assertFalse($outcome['case_updated'],
            'Callback قديم كتب فوق القضية الحالية.');
        $case = DB::table('kyc_verification_cases')->where('user_id', $user->id)->first();
        $this->assertSame($secondRef, $case->provider_reference);
        $this->assertSame('pending', $case->liveness_status);
        $this->assertSame('pending', $case->face_match_status);
    }
}

/** فصل صغير كي لا يعتمد الاختبار على تفاصيل Doctrine/نسخة DB. */
final class SchemaProbe
{
    public static function columnExists(string $table, string $column): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
    }
}
