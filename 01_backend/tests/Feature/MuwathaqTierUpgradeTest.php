<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\MuwathaqReviewRequest as Req;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqTierUpgradeService;
use App\Services\Verification\AccountVerificationDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EstablishesKycEvidence;
use Tests\TestCase;

/** CAP-01: مراجعة ترقية Tier 2 يملكها موثّق، وتطبيق القرار في أميال باي. */
class MuwathaqTierUpgradeTest extends TestCase
{
    use RefreshDatabase, EstablishesKycEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        config(['muwathaq.decision_secret' => 'dec-secret', 'muwathaq.transport_secret' => null, 'muwathaq.bridge_secret' => 'bridge-secret',
            'muwathaq.base_url' => 'https://muwathaq.test', 'muwathaq.tier_upgrade_mode' => 'on']);
    }

    private function customer(): User
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE, 'gender' => 'female', 'kyc_tier' => 0, 'is_kyc_verified' => 0]);

        return $this->establishTierOnePrerequisite($u);
    }

    /** @return array{0:User,1:Req} */
    private function submitted(): array
    {
        Queue::fake();
        $u = $this->customer();
        $this->establishKycEvidence($u, 2);
        $u->forceFill(['is_kyc_verified' => 0, 'kyc_tier' => 1])->save();
        $docs = KycDocument::query()->where('user_id', $u->id)->whereIn('doc_type', [KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK])->get()->all();
        $req = app(MuwathaqTierUpgradeService::class)->openFor($u->fresh(), $docs);
        $this->assertNotNull($req);

        return [$u->fresh(), $req];
    }

    private function decide(Req $req, array $over = [], ?string $secret = 'dec-secret')
    {
        $body = json_encode(array_replace_recursive([
            'decision' => 'approved', 'decision_reference' => 'MWT-'.strtoupper(bin2hex(random_bytes(6))), 'decision_seq' => 1,
            'context' => ['reviewer_reference' => 'rev-b', 'proposer_reference' => 'rev-a', 'verified_identity' => true],
        ], $over), JSON_THROW_ON_ERROR);
        $ts = (string) time();

        return $this->call('POST', '/api/v1/internal/muwathaq/applications/'.$req->request_ulid.'/decision', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_MUWATHAQ_TIMESTAMP' => $ts,
            'HTTP_X_MUWATHAQ_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, (string) $secret),
        ], $body);
    }

    /** @test */
    public function feature_is_off_by_default_and_keeps_the_classic_owner(): void
    {
        config(['muwathaq.tier_upgrade_mode' => 'off']);
        $u = $this->customer();
        $this->assertNull(app(MuwathaqTierUpgradeService::class)->openFor($u, []));
        $this->assertSame(0, Req::query()->count());
        config(['muwathaq.tier_upgrade_mode' => 'canary', 'muwathaq.tier_upgrade_user_ids' => [999999]]);
        $this->assertNull(app(MuwathaqTierUpgradeService::class)->openFor($u, []));
        config(['muwathaq.tier_upgrade_mode' => 'on', 'muwathaq.base_url' => '']);
        $this->assertNull(app(MuwathaqTierUpgradeService::class)->openFor($u, []));
    }

    /** @test */
    public function delivery_is_signed_carries_the_declared_identity_and_is_idempotent_by_event_key(): void
    {
        [$u, $req] = $this->submitted();
        Http::fake(['https://muwathaq.test/*' => Http::response(['accepted' => true], 202)]);
        $this->assertTrue(app(MuwathaqTierUpgradeService::class)->deliver($req->id));
        $this->assertFalse(app(MuwathaqTierUpgradeService::class)->deliver($req->id), 'لا إعادة إرسال بعد التسليم');
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $r) use ($req, $u): bool {
            $ts = $r->header('X-Amial-Timestamp')[0];
            $body = $r->body();
            $this->assertSame($req->event_ulid, $r->header('Idempotency-Key')[0]);
            $this->assertSame(hash_hmac('sha256', $ts.'.'.$body, 'bridge-secret'), $r->header('X-Amial-Signature')[0]);
            $d = json_decode($body, true);
            $this->assertSame('progressive_tier_upgrade', $d['application']['verification_path']);
            $this->assertSame($req->request_ulid, $d['application']['application_ulid']);
            $this->assertCount(2, $d['documents']);
            // قرار المالك: بيانات الهوية المُصرَّح بها تُرسل ليطابقها المراجع بالوثيقة.
            $this->assertSame($u->identification_number, $d['identity']['identification_number']);
            $this->assertNotEmpty($d['identity']['identification_number']);
            $this->assertArrayHasKey('date_of_birth', $d['identity']);
            $this->assertArrayHasKey('expiry_date', $d['identity']);
            $this->assertStringNotContainsString((string) $u->password, $body);
            $this->assertStringNotContainsString('transaction_pin', $body);
            // فحوص أميال باي تصل إشاراتٍ، و«غير معروف» يُقال لا يُخفى.
            $keys = array_column($d['prechecks']['signals'], 'status', 'key');
            $this->assertSame(['ocr', 'name_match', 'id_number', 'expiry', 'reuse', 'sanctions'], array_keys($keys));
            if (\DB::table('sanction_list_entries')->where('is_active', true)->count() === 0) {
                $this->assertSame('unknown', $keys['sanctions'], 'قائمة عقوبات فارغة = غير معروف لا «نظيف»');
            }
            foreach ($keys as $st) {
                $this->assertContains($st, ['pass', 'warn', 'fail', 'unknown']);
            }
            $this->assertStringNotContainsString('encrypted_path', $body);

            return true;
        });
    }

    /** @test */
    public function delivery_failure_keeps_the_request_pending_for_retry(): void
    {
        [, $req] = $this->submitted();
        Http::fake(['https://muwathaq.test/*' => Http::response('x', 503)]);
        try {
            app(MuwathaqTierUpgradeService::class)->deliver($req->id);
            $this->fail('expected failure');
        } catch (\DomainException) {
        }
        $this->assertSame('pending', $req->fresh()->delivery_status);
        $this->assertSame('HTTP_503', $req->fresh()->failure_code);
    }

    /** @test */
    public function resubmission_supersedes_the_open_request_and_a_late_decision_on_it_is_refused(): void
    {
        [$u, $old] = $this->submitted();
        $docs = KycDocument::query()->where('user_id', $u->id)->get()->all();
        $new = app(MuwathaqTierUpgradeService::class)->openFor($u, $docs);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertNull($old->fresh()->open_key);
        $this->assertSame(1, Req::query()->whereIn('status', Req::OPEN)->count());
        $this->decide($old)->assertStatus(409)->assertJsonPath('code', 'SUPERSEDED');
        $this->assertSame(0, (int) $u->fresh()->is_kyc_verified);
        $this->assertNotSame($old->request_ulid, $new->request_ulid);
    }

    /** @test */
    public function an_independent_signed_approval_is_applied_once_and_replays_are_noops(): void
    {
        [$u, $req] = $this->submitted();
        $ref = 'MWT-REPLAYREF0001';
        $this->decide($req, ['decision_reference' => $ref])->assertOk()->assertJsonPath('meta.applied', true);
        $this->assertSame(1, (int) $u->fresh()->is_kyc_verified);
        $this->assertSame(2, (int) $u->fresh()->kyc_tier);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertNull($req->fresh()->open_key);
        $this->decide($req, ['decision_reference' => $ref])->assertOk();
        $this->assertSame(1, \DB::table('audit_decisions')->where('action', 'MUWATHAQ_TIER_UPGRADE_DECISION')->count());
        // قرار المالك: المراجع الشكلي حساب «جسر» إداري محفوظ لا يدخل، لا كائن عابر بلا معرّف.
        $bridge = app(\App\Services\Muwathaq\MuwathaqBridgeReviewer::class)->find();
        $this->assertNotNull($bridge);
        $this->assertSame(ADMIN_TYPE, (int) $bridge->type);
        $this->assertFalse((bool) $bridge->is_active, 'حساب الجسر لا يدخل لوحة الإدارة');
        $this->assertSame(0, \DB::table('admin_user_permissions')->where('user_id', $bridge->id)->count());
        // قرار الحساب يُسجَّل في التدقيق بمعرّف حساب الجسر (كان null مع الكائن العابر).
        $this->assertSame(
            (int) $bridge->id,
            (int) \DB::table('audit_decisions')->where('action', 'KYC_ACCOUNT_DECISION')->where('subject_id', (string) $u->id)->latest('id')->value('actor_user_id'),
            'actor_user_id في قرار KYC يحمل معرّف حساب الجسر',
        );
        $this->decide($req, ['decision' => 'rejected', 'reason' => 'سبب مختلف جداً'])->assertStatus(409)->assertJsonPath('code', 'REQUEST_FINAL');
    }

    /** @test */
    public function decision_requires_signature_independent_reviewers_and_identity_affirmation(): void
    {
        [$u, $req] = $this->submitted();
        $this->decide($req, [], 'wrong-secret')->assertStatus(401);
        $this->decide($req, ['context' => ['proposer_reference' => 'rev-b']])->assertStatus(409)->assertJsonPath('code', 'INDEPENDENT_REVIEW_REQUIRED');
        $this->decide($req, ['context' => ['proposer_reference' => null]])->assertStatus(409);
        $this->decide($req, ['context' => ['verified_identity' => false]])->assertStatus(409)->assertJsonPath('code', 'IDENTITY_NOT_AFFIRMED');
        $this->decide($req, ['decision' => 'rejected', 'reason' => 'x'])->assertStatus(422);
        $this->assertSame(0, (int) $u->fresh()->is_kyc_verified);
        $this->assertSame('submitted', $req->fresh()->status);
    }

    /** @test */
    public function rejection_marks_the_account_rejected_and_needs_more_info_is_not_turned_into_a_rejection(): void
    {
        [$u, $req] = $this->submitted();
        $this->decide($req, ['decision' => 'needs_more_info', 'reason' => 'x', 'decision_seq' => 1])->assertStatus(422);
        $this->decide($req, ['decision' => 'needs_more_info', 'reason' => 'صورة الوجه غير واضحة', 'decision_seq' => 2])->assertOk()->assertJsonPath('meta.applied', true);
        $this->assertSame('needs_more_info', $req->fresh()->status);
        $this->assertSame(0, (int) $u->fresh()->is_kyc_verified, 'الاستكمال ليس رفضاً');
        $this->assertTrue(Req::ownsOpenCaseFor((int) $u->id), 'الطلب ما زال مملوكاً لموثّق');
        // الرفض المطبَّق يُعاد applied=true؛ false كان يجعل موثّق يعيد فتح قضية محسومة.
        $this->decide($req, ['decision' => 'rejected', 'reason' => 'الصورة لا تطابق الهوية', 'decision_seq' => 3])->assertOk()->assertJsonPath('meta.applied', true);
        $this->assertSame(2, (int) $u->fresh()->is_kyc_verified);
        $this->assertSame('rejected', $req->fresh()->status);
    }

    /** @test */
    public function stale_sequence_is_ignored_and_business_guard_failure_is_reported_not_retried(): void
    {
        [$u, $req] = $this->submitted();
        $this->decide($req, ['decision' => 'under_review', 'decision_seq' => 5])->assertOk();
        $this->decide($req, ['decision_seq' => 4])->assertOk()->assertJsonPath('code', 'STALE_DECISION_IGNORED');
        $this->assertSame(0, (int) $u->fresh()->is_kyc_verified);
        KycDocument::query()->where('user_id', $u->id)->where('doc_type', KycDocument::TYPE_ID_BACK)->delete();
        $this->decide($req, ['decision_seq' => 6])->assertOk()->assertJsonPath('code', 'MUWATHAQ_DECISION_BLOCKED')->assertJsonPath('meta.applied', false);
        $this->assertSame(0, (int) $u->fresh()->is_kyc_verified);
        $this->assertNotNull($req->fresh()->apply_code);
    }

    /** @test */
    public function needs_more_info_is_closed_by_the_customer_resubmitting(): void
    {
        [$u, $req] = $this->submitted();
        $this->decide($req, ['decision' => 'needs_more_info', 'reason' => 'ارفع ظهر البطاقة بوضوح'])->assertOk();
        $docs = KycDocument::query()->where('user_id', $u->id)->get()->all();
        $new = app(MuwathaqTierUpgradeService::class)->openFor($u->fresh(), $docs);
        $this->assertSame('superseded', $req->fresh()->status);
        $this->assertSame($req->request_ulid, $new->payload['application']['supersedes_application_ulid']);
    }

    /** @test */
    public function a_restricted_privacy_review_stays_on_the_classic_path(): void
    {
        Queue::fake();
        $u = $this->customer();
        $this->establishKycEvidence($u, 2);
        $u->forceFill(['is_kyc_verified' => 0, 'kyc_tier' => 1])->save();
        $this->mock(\App\Services\Kyc\KycPrivacyService::class, fn ($m) => $m->shouldReceive('isRestricted')->andReturn(true));
        $this->assertFalse(app(MuwathaqTierUpgradeService::class)->enabledFor($u->fresh()));
        $this->assertNull(app(MuwathaqTierUpgradeService::class)->openFor($u->fresh(), []));
    }

    /** @test */
    public function the_classic_path_cannot_decide_a_muwathaq_owned_request(): void
    {
        [$u, ] = $this->submitted();
        $reviewer = User::factory()->create(['type' => ADMIN_TYPE]);
        $this->expectExceptionMessage('MUWATHAQ_OWNS_CASE');
        app(AccountVerificationDecisionService::class)->decide($u, $reviewer, true, 2);
    }

    /** @test */
    public function the_document_proxy_is_signed_scoped_to_the_request_and_audited(): void
    {
        [$u, $req] = $this->submitted();
        $other = KycDocument::query()->create(['user_id' => $u->id, 'doc_type' => KycDocument::TYPE_SELFIE, 'encrypted_path' => 'x', 'original_mime' => 'image/jpeg', 'size_bytes' => 1, 'content_sha256' => 'a', 'status' => 'pending']);
        $path = fn (int $id) => '/api/v1/internal/muwathaq/tier-upgrades/'.$req->request_ulid.'/documents/'.$id;
        $this->getJson($path((int) $req->document_ids[0]))->assertStatus(401);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.', 'dec-secret');
        $headers = ['X-Muwathaq-Timestamp' => $ts, 'X-Muwathaq-Signature' => $sig];
        $this->withHeaders($headers)->get($path($other->id))->assertStatus(404);
    }
}
