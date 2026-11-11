<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MuwathaqReviewRequest as Req;
use App\Models\User;
use App\Services\Kyc\ProfileChangeRequestService;
use App\Services\MerchantVerificationService;
use App\Services\Muwathaq\MuwathaqAccountReviewService as Svc;
use App\Services\Muwathaq\MuwathaqBridgeReviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** CAP-02b/c: طلبات تغيير البيان وتوثيق منشأة التاجر يملكها موثّق، والتطبيق بخدمات أميال باي نفسها. */
class MuwathaqAccountReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config(['muwathaq.decision_secret' => 'dec-secret', 'muwathaq.transport_secret' => null, 'muwathaq.bridge_secret' => 'bridge-secret',
            'muwathaq.base_url' => 'https://muwathaq.test', 'muwathaq.profile_change_mode' => 'on', 'muwathaq.merchant_verification_mode' => 'on']);
    }

    private function decide(Req $req, array $over = [])
    {
        $body = json_encode(array_replace_recursive([
            'decision' => 'approved', 'decision_reference' => 'MWT-'.strtoupper(bin2hex(random_bytes(6))), 'decision_seq' => 1,
            'context' => ['reviewer_reference' => 'rev-b', 'proposer_reference' => 'rev-a', 'verified_change' => true, 'verified_business' => true],
        ], $over), JSON_THROW_ON_ERROR);
        $ts = (string) time();

        return $this->call('POST', '/api/v1/internal/muwathaq/applications/'.$req->request_ulid.'/decision', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_MUWATHAQ_TIMESTAMP' => $ts,
            'HTTP_X_MUWATHAQ_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, 'dec-secret'),
        ], $body);
    }

    private function profileChange(string $field = 'occupation', string $new = 'مهندس'): array
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE, 'occupation' => 'معلم']);
        $svc = app(ProfileChangeRequestService::class);
        $id = $svc->open($u, $field, (int) $u->id, 'customer', 'تغيّرت وظيفتي');
        $svc->submit($id, $u, $new);

        return [$u, $id, Req::query()->where('kind', Svc::PROFILE)->where('subject_id', $id)->first()];
    }

    /** @test */
    public function off_by_default_keeps_the_classic_owner(): void
    {
        config(['muwathaq.profile_change_mode' => 'off', 'muwathaq.merchant_verification_mode' => 'off']);
        [, $id, $req] = $this->profileChange();
        $this->assertNull($req);
        $this->assertFalse(Svc::owns(Svc::PROFILE, $id));
    }

    /** @test */
    public function a_submitted_profile_change_is_sent_with_old_and_new_values(): void
    {
        [$u, $id, $req] = $this->profileChange();
        $this->assertNotNull($req);
        $this->assertSame('profile_change_review', $req->payload['application']['verification_path']);
        $this->assertSame('occupation', $req->payload['change']['field']);
        $this->assertSame('معلم', $req->payload['change']['old_value']);
        $this->assertSame('مهندس', $req->payload['change']['new_value']);
        $this->assertTrue(Svc::owns(Svc::PROFILE, $id));
        $this->assertSame([], array_column(app(ProfileChangeRequestService::class)->pendingQueue(), 'id'), 'الطابور القديم لا يعرض ما يملكه موثّق');
    }

    /** @test */
    public function an_independent_approval_applies_the_change_through_the_same_service(): void
    {
        [$u, $id, $req] = $this->profileChange();
        $this->decide($req, ['context' => ['proposer_reference' => 'rev-b']])->assertStatus(409);
        $this->decide($req, ['decision' => 'needs_more_info', 'reason' => 'أرفق وثيقة'])->assertStatus(409)->assertJsonPath('code', 'NEEDS_MORE_INFO_NOT_SUPPORTED');
        $this->decide($req, ['decision_seq' => 2])->assertOk()->assertJsonPath('meta.applied', true);
        $this->assertSame('مهندس', $u->fresh()->occupation);
        $row = DB::table('profile_change_requests')->find($id);
        $this->assertSame(ProfileChangeRequestService::STATUS_APPROVED, $row->status);
        $this->assertFalse(Svc::owns(Svc::PROFILE, $id));
    }

    /** @test */
    public function a_rejection_needs_a_reason_and_leaves_the_value_unchanged(): void
    {
        [$u, $id, $req] = $this->profileChange();
        $this->decide($req, ['decision' => 'rejected', 'reason' => 'x'])->assertStatus(422);
        $this->decide($req, ['decision' => 'rejected', 'reason' => 'لا تتطابق الوظيفة مع المستند', 'decision_seq' => 2])->assertOk();
        $this->assertSame('معلم', $u->fresh()->occupation);
        $this->assertSame(ProfileChangeRequestService::STATUS_REJECTED, DB::table('profile_change_requests')->find($id)->status);
    }

    /** @test */
    public function the_classic_profile_screen_refuses_a_muwathaq_owned_request(): void
    {
        [, $id] = $this->profileChange();
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin']);
        $response = app(\App\Http\Controllers\Admin\ProfileChangeRequestController::class)->decide(
            \Illuminate\Http\Request::create('/', 'POST', ['decision' => 'approve'])->setUserResolver(fn () => $admin), $id);
        $this->assertSame(ProfileChangeRequestService::STATUS_PENDING_REVIEW, DB::table('profile_change_requests')->find($id)->status);
        $this->assertStringContainsString('موثّق', (string) $response->getSession()?->get('error'));
    }

    private function merchantRequest(): array
    {
        $m = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        $docs = ['id_card_front' => UploadedFile::fake()->image('f.jpg', 800, 600), 'id_card_back' => UploadedFile::fake()->image('b.jpg', 800, 600),
            'commercial_register' => UploadedFile::fake()->create('r.pdf', 100, 'application/pdf'), 'store_photo' => UploadedFile::fake()->image('s.jpg', 800, 600)];
        $mvr = app(MerchantVerificationService::class)->submit($m, ['business_name' => 'بقالة الأمانة', 'bank_account_number' => 'YE12 3456 7890 1234'], $docs);

        return [$m, $mvr, Req::query()->where('kind', Svc::MERCHANT)->where('subject_id', $mvr->id)->first()];
    }

    /** @test */
    public function a_merchant_submission_is_sent_with_its_documents_and_a_masked_bank_account(): void
    {
        [, $mvr, $req] = $this->merchantRequest();
        $this->assertNotNull($req);
        $this->assertSame('بقالة الأمانة', $req->payload['business']['business_name']);
        $this->assertSame('1234', $req->payload['business']['bank_account_last4']);
        $this->assertStringNotContainsString('7890', json_encode($req->payload, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['id_card_front', 'id_card_back', 'commercial_register', 'store_photo'], array_column($req->payload['documents'], 'kind'));
        $this->assertSame([1, 2, 3, 4], array_column($req->payload['documents'], 'id'));

        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.', 'dec-secret');
        $this->withHeaders(['X-Muwathaq-Timestamp' => $ts, 'X-Muwathaq-Signature' => $sig])
            ->get('/api/v1/internal/muwathaq/tier-upgrades/'.$req->request_ulid.'/documents/3')->assertOk();
        $this->withHeaders(['X-Muwathaq-Timestamp' => $ts, 'X-Muwathaq-Signature' => $sig])
            ->get('/api/v1/internal/muwathaq/tier-upgrades/'.$req->request_ulid.'/documents/9')->assertNotFound();
    }

    /** @test */
    public function approval_verifies_the_merchant_and_needs_more_info_asks_for_resubmission(): void
    {
        [$m, $mvr, $req] = $this->merchantRequest();
        $this->decide($req)->assertOk()->assertJsonPath('meta.applied', true);
        $this->assertSame('verified', $mvr->fresh()->status);
        $this->assertSame((int) app(MuwathaqBridgeReviewer::class)->find()?->id, (int) $mvr->fresh()->reviewed_by_admin_id);
        $this->assertSame('verified', MerchantProfile::where('user_id', $m->id)->value('verification_status'));

        [, $mvr2, $req2] = $this->merchantRequest();
        $this->decide($req2, ['decision' => 'needs_more_info', 'reason' => 'صورة السجل غير واضحة'])->assertOk();
        $this->assertSame('resubmission_required', $mvr2->fresh()->status);
    }

    /** @test */
    public function the_classic_merchant_screen_refuses_a_muwathaq_owned_request(): void
    {
        [, $mvr] = $this->merchantRequest();
        $admin = User::factory()->create(['type' => ADMIN_TYPE]);
        $this->actingAs($admin);
        $res = app(\App\Http\Controllers\Admin\MerchantVerificationAdminController::class)->approve(
            \Illuminate\Http\Request::create('/', 'POST')->setUserResolver(fn () => $admin), $mvr->id);
        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame('pending_review', $mvr->fresh()->status);
    }
}
