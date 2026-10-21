<?php

namespace Tests\Feature;

use App\Models\MuwathaqOpeningEvidence;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** @test */
    public function merchant_uploads_each_required_document_before_wallet_opening(): void
    {
        config(['amial.encryption.pii_key' => base64_encode(str_repeat('k', 32))]);
        Storage::fake('local');

        $owner = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 0,
        ]);
        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_QUICK_SALE,
            'verification_status' => 'unverified',
        ]);
        $opening = app(MuwathaqAccountOpeningService::class)->submitFor($owner, A::BIZ_QUICK_SALE);
        $token = app(MuwathaqOpeningProgressService::class)->issueAccessToken($opening);

        foreach (['owner_identity', 'business_registration', 'operating_address'] as $kind) {
            $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
                ->post('/api/v1/auth/account-opening/'.$opening->application_ulid.'/evidence/'.$kind, [
                    'evidence' => UploadedFile::fake()->image($kind.'.png'),
                ])->assertOk();

            $this->assertDatabaseHas('muwathaq_opening_evidences', [
                'application_id' => $opening->id,
                'kind' => $kind,
                'state' => MuwathaqOpeningEvidence::STATE_SUBMITTED,
            ]);
            $this->assertFalse(EMoney::where('user_id', $owner->id)->exists(),
                'رفع المستندات لا يجب أن ينشئ محفظة قبل قرار موثّق والإدارة');
        }

        $this->assertSame('under_review', $opening->fresh()->status);
        $this->getJson(
            '/api/v1/auth/account-opening/'.$opening->application_ulid.'/status',
            ['Authorization' => 'Bearer '.$token],
        )->assertOk()->assertJsonPath('data.next_steps.0', 'await_muwathaq_review');
        $this->assertSame(3, $opening->evidences()->count());
        $this->assertSame(0, (int) $owner->fresh()->is_kyc_verified);
    }

}
