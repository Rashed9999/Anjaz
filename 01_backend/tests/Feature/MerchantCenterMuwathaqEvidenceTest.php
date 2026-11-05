<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\MuwathaqOpeningEvidence;
use App\Models\User;
use App\Services\AdminCenter\MerchantCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MerchantCenterMuwathaqEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        $user = User::factory()->create(['type' => MERCHANT_TYPE, 'is_active' => 1]);
        MerchantProfile::create([
            'user_id' => $user->id, 'verification_status' => 'verified',
            'business_type' => 'quick_sale',
        ]);

        return $user;
    }

    private function opening(User $user, string $origin): MuwathaqAccountOpeningApplication
    {
        return MuwathaqAccountOpeningApplication::create([
            'user_id' => $user->id,
            'application_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'origin' => $origin,
            'subject_kind' => 'merchant',
            'verification_path' => 'merchant_sector',
            'business_type' => 'quick_sale',
            'status' => 'approved',
            'submitted_at' => now(),
            'decided_at' => now(),
        ]);
    }

    /** @test */
    public function approved_muwathaq_owned_merchant_sees_real_redacted_evidence_metadata(): void
    {
        config([
            'muwathaq.base_url' => 'https://muwathaq.example.test',
            'muwathaq.bridge_secret' => 'integration-testing-signed-secret',
        ]);
        $owner = $this->merchant();
        $case = $this->opening($owner, 'muwathaq');

        Http::fake(['muwathaq.example.test/*' => Http::response([
            'success' => true,
            'data' => [
                'application_ulid' => $case->application_ulid,
                'origin' => 'muwathaq',
                'items' => [
                    ['kind' => 'owner_identity', 'state' => 'submitted',
                        'document_received' => true, 'submitted_at' => '2026-10-22 10:00:00'],
                    ['kind' => 'business_registration', 'state' => 'submitted',
                        'document_received' => true, 'submitted_at' => '2026-10-22 10:00:00'],
                    ['kind' => 'operating_address', 'state' => 'submitted',
                        'document_received' => true, 'submitted_at' => '2026-10-22 10:00:00'],
                    ['kind' => 'private_unrelated_data', 'state' => 'verified',
                        'document_received' => true, 'secret' => 'never-expose'],
                ],
            ],
        ], 200)]);

        $data = app(MerchantCenterService::class)->compliance($owner);

        $this->assertSame('verified', $data['kyc_status']);
        $this->assertSame('approved', $data['muwathaq_opening']['status']);
        $this->assertSame('muwathaq', $data['muwathaq_evidence_availability']);
        $this->assertCount(3, $data['muwathaq_evidence']);
        $this->assertSame('هوية مالك المنشأة', $data['muwathaq_evidence'][0]['label']);
        $this->assertTrue($data['muwathaq_evidence'][0]['received']);
        $this->assertSame('submitted', $data['muwathaq_evidence'][0]['state'],
            'Approval of account must not manufacture per-document verification');
        $this->assertSame([], $data['documents'], 'Historical KYC table remains separate');
        $this->assertSame([], $data['registration_dossiers']);
        $this->assertStringNotContainsString('never-expose', json_encode($data));
        $this->assertStringNotContainsString('file_encrypted_path', json_encode($data));

        Http::assertSent(function ($request) use ($case): bool {
            $timestamp = $request->header('X-Amial-Timestamp')[0] ?? '';
            $signature = $request->header('X-Amial-Signature')[0] ?? '';
            $body = $request->body();
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/internal/evidence-summary')
                && $request['application_ulid'] === $case->application_ulid
                && hash_equals(
                    hash_hmac('sha256', $timestamp.'.'.$body, 'integration-testing-signed-secret'),
                    $signature,
                );
        });
    }

    /** @test */
    public function unavailable_muwathaq_is_not_misreported_as_no_documents(): void
    {
        config([
            'muwathaq.base_url' => 'https://muwathaq.example.test',
            'muwathaq.bridge_secret' => 'integration-testing-signed-secret',
        ]);
        $owner = $this->merchant();
        $this->opening($owner, 'muwathaq');
        Http::fake(['muwathaq.example.test/*' => Http::response(['error' => 'temporary'], 503)]);

        $data = app(MerchantCenterService::class)->compliance($owner);
        $this->assertSame('unavailable', $data['muwathaq_evidence_availability']);
        $this->assertSame([], $data['muwathaq_evidence']);
        $this->assertSame('verified', $data['kyc_status']);
    }

    /** @test */
    public function legacy_origin_reads_only_its_own_local_encrypted_evidence_metadata(): void
    {
        Http::fake();
        $owner = $this->merchant();
        $other = $this->merchant();
        $case = $this->opening($owner, 'amial');
        $unrelatedCase = $this->opening($other, 'amial');

        MuwathaqOpeningEvidence::create([
            'application_id' => $case->id, 'kind' => 'owner_identity',
            'state' => 'submitted',
            'file_encrypted_path' => 'private/NEVER_LEAK_DOCUMENT.enc',
            'submitted_at' => now(),
            'muwathaq_delivered_at' => now(),
        ]);
        MuwathaqOpeningEvidence::create([
            'application_id' => $unrelatedCase->id, 'kind' => 'business_registration',
            'state' => 'submitted', 'file_encrypted_path' => 'private/OTHER.enc',
        ]);

        $data = app(MerchantCenterService::class)->compliance($owner);
        $this->assertSame('local', $data['muwathaq_evidence_availability']);
        $this->assertCount(1, $data['muwathaq_evidence']);
        $this->assertSame('owner_identity', $data['muwathaq_evidence'][0]['kind']);
        $this->assertTrue($data['muwathaq_evidence'][0]['received']);
        $this->assertStringNotContainsString('NEVER_LEAK_DOCUMENT', json_encode($data));
        $this->assertStringNotContainsString('OTHER.enc', json_encode($data));
        Http::assertNothingSent();
    }
}
