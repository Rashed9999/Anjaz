<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyMuwathaqSignature;
use App\Models\EMoney;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\MuwathaqApplicationSubmission;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqBridgeService;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MuwathaqAccountOpeningTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function gender_selects_the_server_side_opening_path_and_no_wallet_is_created_on_submission(): void
    {
        $female = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'female']);
        $male = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'male']);
        $service = app(MuwathaqAccountOpeningService::class);

        $women = $service->submitFor($female);
        $standard = $service->submitFor($male);

        $this->assertSame('women_progressive', $women->verification_path);
        $this->assertSame(1, $women->requested_tier);
        $this->assertSame('standard_customer', $standard->verification_path);
        $this->assertNull($standard->requested_tier);
        $this->assertDatabaseMissing('e_money', ['user_id' => $female->id]);
        $this->assertDatabaseMissing('e_money', ['user_id' => $male->id]);
        $this->assertDatabaseHas('muwathaq_application_submissions', ['application_id' => $women->id, 'status' => 'pending']);
        $this->assertDatabaseHas('muwathaq_application_submissions', ['application_id' => $standard->id, 'status' => 'pending']);
    }

    /** @test */
    public function account_opening_is_delivered_to_the_subdomain_with_an_idempotent_signed_minimal_payload(): void
    {
        Http::fake(['https://muwathaq.test/*' => Http::response(['accepted' => true], 202)]);
        config([
            'muwathaq.base_url' => 'https://muwathaq.test',
            'muwathaq.bridge_secret' => 'core-to-muwathaq-secret',
        ]);
        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'gender' => 'female',
            'transaction_pin' => '3819',
        ]);
        $application = app(MuwathaqAccountOpeningService::class)->submitFor($user);
        $submission = MuwathaqApplicationSubmission::query()->where('application_id', $application->id)->firstOrFail();

        // قد يكون طابور الاختبار sync قد سلّمه بعد commit؛ استدعاء deliver
        // الثاني آمن ولا يرسل الحدث مرتين.
        app(MuwathaqBridgeService::class)->deliver($submission->id);

        $this->assertSame('delivered', $submission->fresh()->status);
        $this->assertSame(202, $submission->fresh()->response_status);
        Http::assertSent(function (ClientRequest $request) use ($submission): bool {
            $body = $request->body();
            $timestamp = $request->header('X-Amial-Timestamp');
            $expected = hash_hmac('sha256', $timestamp.'.'.$body, 'core-to-muwathaq-secret');

            return $request->url() === 'https://muwathaq.test/api/v1/internal/applications'
                && $request->header('Idempotency-Key') === $submission->event_ulid
                && hash_equals($expected, (string) $request->header('X-Amial-Signature'))
                && str_contains($body, 'account_opening.submitted')
                && !str_contains($body, 'transaction_pin')
                && !str_contains($body, 'password');
        });
    }

    /** @test */
    public function approval_is_idempotent_and_only_then_provisions_the_wallet(): void
    {
        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'female', 'kyc_tier' => 0]);
        $service = app(MuwathaqAccountOpeningService::class);
        $application = $service->submitFor($user);

        $approved = $service->receiveDecision(
            $application->application_ulid,
            MuwathaqAccountOpeningApplication::STATUS_APPROVED,
            'muwathaq-review-0001',
            'اعتماد تجريبي',
            [
                'policy_version' => 'muwathaq-1',
                'verified_phone' => true,
                'verified_residence_governorate' => 'YE-AD',
            ],
        );
        $again = $service->receiveDecision(
            $application->application_ulid,
            MuwathaqAccountOpeningApplication::STATUS_APPROVED,
            'muwathaq-review-0001',
            'اعتماد تجريبي',
            [
                'verified_phone' => true,
                'verified_residence_governorate' => 'YE-AD',
            ],
        );

        $this->assertSame('approved', $approved->status);
        $this->assertSame($approved->id, $again->id);
        $this->assertNotNull(EMoney::query()->where('user_id', $user->id)->first());
        $this->assertSame(1, (int) $user->fresh()->kyc_tier);
    }

    /** @test */
    public function standard_customer_cannot_be_approved_without_identity_confirmation_from_muwathaq(): void
    {
        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'male']);
        $application = app(MuwathaqAccountOpeningService::class)->submitFor($user);

        $this->expectException(\DomainException::class);
        app(MuwathaqAccountOpeningService::class)->receiveDecision(
            $application->application_ulid,
            MuwathaqAccountOpeningApplication::STATUS_APPROVED,
            'muwathaq-standard-review-0001',
            null,
            ['verified_phone' => true, 'verified_residence_governorate' => 'YE-AD'],
        );
    }

    /** @test */
    public function the_decision_channel_rejects_unsigned_or_stale_requests(): void
    {
        config(['muwathaq.decision_secret' => 'test-shared-secret']);
        $body = '{"decision":"approved"}';
        $stamp = (string) time();
        $signature = hash_hmac('sha256', $stamp.'.'.$body, 'test-shared-secret');
        $middleware = app(VerifyMuwathaqSignature::class);

        $valid = Request::create('/api/v1/internal/muwathaq/applications/x/decision', 'POST', [], [], [], [
            'HTTP_X_MUWATHAQ_TIMESTAMP' => $stamp,
            'HTTP_X_MUWATHAQ_SIGNATURE' => $signature,
        ], $body);
        $this->assertSame(204, $middleware->handle($valid, static fn () => response('', 204))->getStatusCode());

        $tampered = Request::create('/api/v1/internal/muwathaq/applications/x/decision', 'POST', [], [], [], [
            'HTTP_X_MUWATHAQ_TIMESTAMP' => $stamp,
            'HTTP_X_MUWATHAQ_SIGNATURE' => $signature,
        ], $body.' ');
        $this->assertSame(401, $middleware->handle($tampered, static fn () => response('', 204))->getStatusCode());

        $stale = Request::create('/api/v1/internal/muwathaq/applications/x/decision', 'POST', [], [], [], [
            'HTTP_X_MUWATHAQ_TIMESTAMP' => (string) (time() - 301),
            'HTTP_X_MUWATHAQ_SIGNATURE' => $signature,
        ], $body);
        $this->assertSame(401, $middleware->handle($stale, static fn () => response('', 204))->getStatusCode());
    }

    /** @test */
    public function signed_decision_route_is_registered_and_applies_a_review_status(): void
    {
        config(['muwathaq.decision_secret' => 'test-shared-secret']);
        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'female']);
        $application = app(MuwathaqAccountOpeningService::class)->submitFor($user);
        $body = json_encode([
            'decision' => 'under_review',
            'decision_reference' => 'muwathaq-route-review-0001',
            'context' => ['policy_version' => 'muwathaq-1'],
        ], JSON_THROW_ON_ERROR);
        $stamp = (string) time();
        $signature = hash_hmac('sha256', $stamp.'.'.$body, 'test-shared-secret');

        $this->call(
            'POST',
            '/api/v1/internal/muwathaq/applications/'.$application->application_ulid.'/decision',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_MUWATHAQ_TIMESTAMP' => $stamp,
                'HTTP_X_MUWATHAQ_SIGNATURE' => $signature,
            ],
            $body,
        )
            ->assertOk()
            ->assertJsonPath('code', 'MUWATHAQ_DECISION_APPLIED');

        $this->assertSame(
            'under_review',
            $application->fresh()->status,
        );
    }


    /** @test */
    public function one_transport_secret_derives_distinct_directional_keys(): void
    {
        config([
            'muwathaq.transport_secret' => 'shared-transport-secret',
            'muwathaq.bridge_secret' => null,
            'muwathaq.decision_secret' => null,
        ]);

        $this->assertSame(
            hash_hmac('sha256', 'amial-to-muwathaq-v1', 'shared-transport-secret'),
            \App\Services\Muwathaq\MuwathaqTransportSecrets::amialToMuwathaq(),
        );
        $this->assertSame(
            hash_hmac('sha256', 'muwathaq-to-amial-v1', 'shared-transport-secret'),
            \App\Services\Muwathaq\MuwathaqTransportSecrets::muwathaqToAmial(),
        );
        $this->assertNotSame(
            \App\Services\Muwathaq\MuwathaqTransportSecrets::amialToMuwathaq(),
            \App\Services\Muwathaq\MuwathaqTransportSecrets::muwathaqToAmial(),
        );
    }

}
