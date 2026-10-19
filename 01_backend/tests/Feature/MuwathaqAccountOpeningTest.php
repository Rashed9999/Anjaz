<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyMuwathaqSignature;
use App\Models\EMoney;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
}
