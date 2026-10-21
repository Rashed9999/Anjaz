<?php

namespace Tests\Feature;

use App\Models\MuwathaqOpeningEvidence;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use App\Services\Muwathaq\MuwathaqOpeningProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MuwathaqOpeningProgressTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function opening_access_token_is_scoped_and_cannot_be_guessed_or_used_as_a_login_token(): void
    {
        config(['amial.otp.pilot_customer_phone_enabled' => true]);

        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'female']);
        $application = app(MuwathaqAccountOpeningService::class)->submitFor($user);
        $token = app(MuwathaqOpeningProgressService::class)->issueAccessToken($application);

        $this->getJson('/api/v1/auth/account-opening/'.$application->application_ulid.'/status')
            ->assertStatus(403)
            ->assertJsonPath('code', 'OPENING_ACCESS_DENIED');

        $this->postJson(
            '/api/v1/auth/account-opening/'.$application->application_ulid.'/phone/confirm',
            ['code' => '123456'],
            ['Authorization' => 'Bearer '.$token],
        )->assertOk()
            ->assertJsonPath('data.next_steps.0', 'submit_residence');

        $this->assertDatabaseHas('muwathaq_opening_evidences', [
            'application_id' => $application->id,
            'kind' => MuwathaqOpeningEvidence::PHONE,
            'state' => MuwathaqOpeningEvidence::STATE_SUBMITTED,
        ]);
        $this->assertSame(0, (int) $user->fresh()->is_phone_verified, 'قرار موثّق فقط هو الذي يفعّل الهاتف في الحساب.');
    }

    /** @test */
    public function evidence_collection_closes_after_a_final_decision(): void
    {
        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'female']);
        $application = app(MuwathaqAccountOpeningService::class)->submitFor($user);
        $progress = app(MuwathaqOpeningProgressService::class);
        $progress->issueAccessToken($application);

        foreach ([MuwathaqOpeningEvidence::PHONE, MuwathaqOpeningEvidence::RESIDENCE] as $kind) {
            MuwathaqOpeningEvidence::query()->create([
                'application_id' => $application->id,
                'kind' => $kind,
                'state' => MuwathaqOpeningEvidence::STATE_SUBMITTED,
                'submitted_at' => now(),
            ]);
        }

        app(MuwathaqAccountOpeningService::class)->receiveDecision(
            $application->application_ulid, 'approved', 'muwathaq-final-0001', null,
            ['verified_phone' => true, 'verified_residence_governorate' => 'YE-AD'],
        );

        $this->expectException(\DomainException::class);
        $progress->confirmPilotPhone($application->fresh(), '123456');
    }

    /** @test */
    public function expired_opening_access_token_is_rejected(): void
    {
        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'female']);
        $application = app(MuwathaqAccountOpeningService::class)->submitFor($user);
        $token = app(MuwathaqOpeningProgressService::class)->issueAccessToken($application);
        $application->forceFill(['opening_access_expires_at' => now()->subSecond()])->save();

        $this->getJson(
            '/api/v1/auth/account-opening/'.$application->application_ulid.'/status',
            ['Authorization' => 'Bearer '.$token],
        )->assertStatus(403);
    }
}
