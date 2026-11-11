<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\MuwathaqReviewRequest as Req;
use App\Models\User;
use App\Services\Kyc\LegalNameService;
use App\Services\Muwathaq\MuwathaqBridgeReviewer;
use App\Services\Muwathaq\MuwathaqResidenceReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** CAP-02: مراجعة إثبات السكن يملكها موثّق، والتطبيق في أميال باي عبر ResidenceDecisionApplier نفسه. */
class MuwathaqResidenceReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['muwathaq.decision_secret' => 'dec-secret', 'muwathaq.transport_secret' => null, 'muwathaq.bridge_secret' => 'bridge-secret',
            'muwathaq.base_url' => 'https://muwathaq.test', 'muwathaq.residence_review_mode' => 'on']);
        Queue::fake();
    }

    /** @return array{0:User,1:int,2:KycDocument} */
    private function pendingResidence(string $strength = 'strong'): array
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE, 'f_name' => 'محمد', 'l_name' => 'القحطاني', 'kyc_tier' => 0, 'is_kyc_verified' => 0,
            'is_phone_verified' => 1, 'residence_governorate' => 'YE-AD', 'verified_residence_governorate' => null, 'residence_verified_at' => null]);
        app(LegalNameService::class)->registerDeclaredName($u, 'محمد', 'أحمد', 'علي', 'القحطاني');
        $doc = KycDocument::query()->create(['user_id' => $u->id, 'doc_type' => KycDocument::TYPE_ADDRESS_PROOF, 'encrypted_path' => 'x/y',
            'original_mime' => 'image/png', 'size_bytes' => 10, 'content_sha256' => str_repeat('c', 64), 'status' => KycDocument::STATUS_PENDING]);
        $id = DB::table('residence_verifications')->insertGetId([
            'user_id' => $u->id, 'kyc_document_id' => $doc->id, 'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document', 'evidence_strength' => $strength, 'status' => 'pending',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$u->fresh(), $id, $doc];
    }

    private function decide(Req $req, array $over = [])
    {
        $body = json_encode(array_replace_recursive([
            'decision' => 'approved', 'decision_reference' => 'MWT-'.strtoupper(bin2hex(random_bytes(6))), 'decision_seq' => 1,
            'context' => ['reviewer_reference' => 'rev-b', 'proposer_reference' => 'rev-a', 'verified_residence' => true, 'document_name' => 'محمد أحمد علي القحطاني'],
        ], $over), JSON_THROW_ON_ERROR);
        $ts = (string) time();

        return $this->call('POST', '/api/v1/internal/muwathaq/applications/'.$req->request_ulid.'/decision', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_MUWATHAQ_TIMESTAMP' => $ts,
            'HTTP_X_MUWATHAQ_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, 'dec-secret'),
        ], $body);
    }

    /** @test */
    public function off_by_default_keeps_the_admin_queue_as_owner(): void
    {
        config(['muwathaq.residence_review_mode' => 'off']);
        [$u, $id] = $this->pendingResidence();
        $this->assertNull(app(MuwathaqResidenceReviewService::class)->openFor($u, $id));
        $this->assertFalse(Req::ownsResidenceVerification($id));
    }

    /** @test */
    public function open_builds_a_residence_payload_with_the_address_proof_only(): void
    {
        [$u, $id, $doc] = $this->pendingResidence();
        $req = app(MuwathaqResidenceReviewService::class)->openFor($u, $id);
        $this->assertSame(Req::KIND_RESIDENCE, $req->kind);
        $this->assertSame($id, $req->subject_id);
        $this->assertSame('residence_review', $req->payload['application']['verification_path']);
        $this->assertSame('residence_review.submitted', $req->payload['event_type']);
        $this->assertSame('YE-AD', $req->payload['residence']['declared_governorate']);
        $this->assertSame([['id' => $doc->id, 'kind' => 'address_proof', 'mime' => 'image/png', 'sha256' => str_repeat('c', 64), 'size' => 10]], $req->payload['documents']);
        $this->assertTrue(Req::ownsResidenceVerification($id));
        $this->assertFalse(Req::ownsOpenCaseFor((int) $u->id), 'طلب السكن لا يحجب قرار الترقية');
    }

    /** @test */
    public function an_independent_approval_verifies_residence_through_the_shared_applier(): void
    {
        [$u, $id, $doc] = $this->pendingResidence();
        $req = app(MuwathaqResidenceReviewService::class)->openFor($u, $id);
        $this->decide($req)->assertOk()->assertJsonPath('meta.applied', true);
        $row = DB::table('residence_verifications')->find($id);
        $bridge = app(MuwathaqBridgeReviewer::class)->find();
        $this->assertSame('verified', $row->status);
        $this->assertSame((int) $bridge->id, (int) $row->reviewed_by);
        $this->assertSame('exact', $row->name_match_status);
        $this->assertSame(KycDocument::STATUS_APPROVED, $doc->fresh()->status);
        $this->assertSame('YE-AD', $u->fresh()->verified_residence_governorate);
        $this->assertSame(1, (int) $u->fresh()->kyc_tier);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertFalse(Req::ownsResidenceVerification($id));
    }

    /** @test */
    public function approval_needs_independent_reviewers_affirmation_and_the_document_name(): void
    {
        [$u, $id] = $this->pendingResidence();
        $req = app(MuwathaqResidenceReviewService::class)->openFor($u, $id);
        $this->decide($req, ['context' => ['proposer_reference' => 'rev-b']])->assertStatus(409)->assertJsonPath('code', 'INDEPENDENT_REVIEW_REQUIRED');
        $this->decide($req, ['context' => ['verified_residence' => false]])->assertStatus(409)->assertJsonPath('code', 'RESIDENCE_NOT_AFFIRMED');
        $this->decide($req, ['decision_seq' => 2, 'context' => ['document_name' => 'سالم صالح ناصر اليافعي']])
            ->assertOk()->assertJsonPath('code', 'MUWATHAQ_DECISION_BLOCKED')->assertJsonPath('meta.applied', false);
        $this->assertSame('pending', DB::table('residence_verifications')->find($id)->status, 'اسم مختلف لا يعتمد السكن');
        $this->assertNull($u->fresh()->verified_residence_governorate);
    }

    /** @test */
    public function supporting_evidence_cannot_be_approved_and_is_reported_as_a_block(): void
    {
        [$u, $id] = $this->pendingResidence('supporting');
        $req = app(MuwathaqResidenceReviewService::class)->openFor($u, $id);
        $this->decide($req)->assertOk()->assertJsonPath('meta.apply_code', 'RESIDENCE_STRONGER_EVIDENCE_REQUIRED');
        $this->assertSame('pending', DB::table('residence_verifications')->find($id)->status);
    }

    /** @test */
    public function needs_more_info_maps_to_needs_more_evidence_and_a_resubmission_supersedes(): void
    {
        [$u, $id, $doc] = $this->pendingResidence();
        $req = app(MuwathaqResidenceReviewService::class)->openFor($u, $id);
        $this->decide($req, ['decision' => 'needs_more_info', 'reason' => 'x'])->assertStatus(422);
        $this->decide($req, ['decision' => 'needs_more_info', 'reason' => 'الفاتورة غير مقروءة'])->assertOk()->assertJsonPath('meta.applied', true);
        $this->assertSame('needs_more_evidence', DB::table('residence_verifications')->find($id)->status);
        $this->assertSame(KycDocument::STATUS_REJECTED, $doc->fresh()->status);
        $this->decide($req, ['decision' => 'approved', 'decision_seq' => 9])->assertStatus(409);

        [, $id2] = [null, DB::table('residence_verifications')->insertGetId([
            'user_id' => $u->id, 'kyc_document_id' => KycDocument::query()->create(['user_id' => $u->id, 'doc_type' => KycDocument::TYPE_ADDRESS_PROOF,
                'encrypted_path' => 'x/z', 'original_mime' => 'image/png', 'size_bytes' => 11, 'content_sha256' => str_repeat('d', 64), 'status' => 'pending'])->id,
            'declared_governorate' => 'YE-AD', 'evidence_type' => 'government_residence_document', 'evidence_strength' => 'strong',
            'status' => 'pending', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ])];
        $new = app(MuwathaqResidenceReviewService::class)->openFor($u->fresh(), $id2);
        $this->assertSame('superseded', $req->fresh()->status);
        $this->assertSame($req->request_ulid, $new->payload['application']['supersedes_application_ulid']);
    }

    /** @test */
    public function the_admin_screen_cannot_decide_a_muwathaq_owned_residence(): void
    {
        [$u, $id] = $this->pendingResidence();
        app(MuwathaqResidenceReviewService::class)->openFor($u, $id);
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin']);
        $this->assertTrue(Req::ownsResidenceVerification($id));
        $response = app(\App\Http\Controllers\Admin\KycResidenceAdminController::class)->decide(
            \Illuminate\Http\Request::create('/', 'POST', ['status' => 'verified', 'document_name' => 'محمد أحمد علي القحطاني'])->setUserResolver(fn () => $admin),
            $id,
            app(\App\Services\Kyc\ResidenceDecisionApplier::class),
        );
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('pending', DB::table('residence_verifications')->find($id)->status);
    }
}
