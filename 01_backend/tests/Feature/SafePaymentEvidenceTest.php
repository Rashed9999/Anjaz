<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\SafePayment;
use App\Models\SafePaymentEvidence;
use App\Models\User;
use App\Services\SafePaymentEvidenceService;
use App\Services\SafePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * AMIAL-SAFEPAY-EVIDENCE-001 / CODE-001 / TRUST-001
 *
 * الدفع الآمن كان يحمل عمود `attachments` مصفوفةَ نصوص يرسلها العميل: لا
 * رفع ملفات، ولا تخزين، ولا تحقّق. أي أن «الأدلّة» زينة لا تصلح أساساً
 * لقرار إداري يحوّل مالاً من طرف إلى آخر. والبائع بلا وسيلة إثبات أصلاً.
 */
class SafePaymentEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function party(string $balance = '100000.0000'): User
    {
        $u = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        EMoney::create([
            'user_id' => $u->id, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        return $u;
    }

    /** @return array{SafePayment, User, User} */
    private function deal(): array
    {
        $buyer = $this->party();
        $seller = $this->party('0.0000');

        $payment = app(SafePaymentService::class)->createAndFund(
            buyer: $buyer, seller: $seller,
            title: 'جهاز مستعمل',
            description: 'هاتف بحالة ممتازة مع الشاحن الأصلي',
            amount: '20000',
        );

        return [$payment, $buyer, $seller];
    }

    /**
     * صورة مختلفة المحتوى في كل مرّة.
     *
     * UploadedFile::fake()->image() بنفس الأبعاد يولّد بايتات متطابقة، فيبتلعها
     * منع التكرار بحقّ — ويبدو كأنه عطل في الرفع. نُنوّع الأبعاد لتحاكي
     * صوراً حقيقية، فالصور الفعلية لا تتطابق بايتاً ببايت أبداً.
     */
    private int $imageSeq = 0;

    private function image(string $name = 'proof.jpg'): UploadedFile
    {
        $this->imageSeq++;

        return UploadedFile::fake()->image($name, 400 + $this->imageSeq * 17, 300 + $this->imageSeq * 13);
    }

    private function upload(User $u, SafePayment $p, string $stage, array $files)
    {
        return $this->actingAs($u, 'api')->post(
            "/api/v1/amial/safe-payments/{$p->payment_ulid}/evidence",
            ['stage' => $stage, 'files' => $files]
        );
    }

    // ===================== الأدلّة =====================

    public function test_seller_can_attach_shipping_evidence(): void
    {
        // طلب المستخدم الصريح: البائع كان بلا أي وسيلة إثبات.
        [$p, , $seller] = $this->deal();

        $this->upload($seller, $p, 'in_delivery', [$this->image(), $this->image('b.jpg')])
            ->assertSuccessful();

        $this->assertSame(2, SafePaymentEvidence::where('safe_payment_id', $p->id)
            ->where('role', 'seller')->count());
    }

    public function test_buyer_can_attach_dispute_evidence(): void
    {
        [$p, $buyer] = $this->deal();

        $this->upload($buyer, $p, 'dispute', [$this->image()])->assertSuccessful();

        $this->assertSame(1, SafePaymentEvidence::where('safe_payment_id', $p->id)
            ->where('role', 'buyer')->where('stage', 'dispute')->count());
    }

    public function test_five_files_per_stage_is_the_ceiling(): void
    {
        [$p, , $seller] = $this->deal();

        $this->upload($seller, $p, 'in_delivery', array_map(
            fn ($i) => $this->image("p{$i}.jpg"), range(1, 5)
        ))->assertSuccessful();

        // السادس يُرفض — والحدّ لكل مرحلة لا لكل العملية.
        $this->upload($seller, $p, 'in_delivery', [$this->image('sixth.jpg')])
            ->assertStatus(422);

        $this->upload($seller, $p, 'delivered', [$this->image('d.jpg')])
            ->assertSuccessful();
    }

    public function test_a_stranger_cannot_upload_to_someone_elses_deal(): void
    {
        [$p] = $this->deal();
        $stranger = $this->party();

        $this->upload($stranger, $p, 'dispute', [$this->image()])->assertStatus(403);
    }

    public function test_executables_are_rejected(): void
    {
        [$p, , $seller] = $this->deal();

        $this->upload($seller, $p, 'in_delivery', [
            UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])->assertStatus(422);
    }

    public function test_evidence_records_a_content_fingerprint(): void
    {
        // بلا بصمة، من يملك القرص يملك تبديل الأدلّة بعد رفعها.
        [$p, , $seller] = $this->deal();
        $this->upload($seller, $p, 'delivered', [$this->image()])->assertSuccessful();

        $e = SafePaymentEvidence::where('safe_payment_id', $p->id)->first();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $e->sha256);
        $this->assertTrue(app(SafePaymentEvidenceService::class)->verifyIntegrity($e));
    }

    public function test_tampering_with_a_stored_file_is_detected(): void
    {
        [$p, , $seller] = $this->deal();
        $this->upload($seller, $p, 'delivered', [$this->image()])->assertSuccessful();

        $e = SafePaymentEvidence::where('safe_payment_id', $p->id)->first();
        \Illuminate\Support\Facades\Storage::disk(config('amial.receipts.storage_disk', 'local'))
            ->put($e->path, 'محتوى مبدَّل');

        $this->assertFalse(app(SafePaymentEvidenceService::class)->verifyIntegrity($e));
    }

    public function test_both_parties_see_the_same_evidence(): void
    {
        // الشفافية تُنهي نزاعات قبل وصولها للإدارة: من يرى دليل خصمه
        // يعرف موقفه فيتراجع أو يوضّح.
        [$p, $buyer, $seller] = $this->deal();
        $this->upload($seller, $p, 'delivered', [$this->image()]);
        $this->upload($buyer, $p, 'dispute', [$this->image('c.jpg')]);

        foreach ([$buyer, $seller] as $viewer) {
            $data = $this->actingAs($viewer, 'api')
                ->getJson("/api/v1/amial/safe-payments/{$p->payment_ulid}/evidence")
                ->assertOk()->json('data');

            $this->assertArrayHasKey('delivered', $data);
            $this->assertArrayHasKey('dispute', $data);
        }
    }

    public function test_evidence_file_is_not_readable_by_outsiders(): void
    {
        [$p, , $seller] = $this->deal();
        $this->upload($seller, $p, 'delivered', [$this->image()]);
        $e = SafePaymentEvidence::where('safe_payment_id', $p->id)->first();

        $this->actingAs($this->party(), 'api')
            ->get("/api/v1/amial/safe-payments/evidence/{$e->id}/file")
            ->assertStatus(403);

        $this->actingAs($seller, 'api')
            ->get("/api/v1/amial/safe-payments/evidence/{$e->id}/file")
            ->assertOk();
    }

    // ===================== رمز التسليم =====================

    public function test_delivery_code_is_issued_on_acceptance_and_shown_to_buyer_only(): void
    {
        [$p, $buyer, $seller] = $this->deal();
        app(SafePaymentService::class)->sellerAccept($p->fresh(), $seller);

        $buyerView = $this->actingAs($buyer, 'api')
            ->getJson("/api/v1/amial/safe-payments/{$p->payment_ulid}")->assertOk();
        $code = $buyerView->json('meta.delivery_code');
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', (string) $code);

        // البائع لا يراه — رؤيته له تُبطل الغرض كلّه.
        $this->assertNull($this->actingAs($seller, 'api')
            ->getJson("/api/v1/amial/safe-payments/{$p->payment_ulid}")
            ->json('meta.delivery_code'));
    }

    public function test_correct_code_confirms_delivery(): void
    {
        [$p, $buyer, $seller] = $this->deal();
        $svc = app(SafePaymentService::class);
        $svc->sellerAccept($p->fresh(), $seller);

        $code = $this->actingAs($buyer, 'api')
            ->getJson("/api/v1/amial/safe-payments/{$p->payment_ulid}")
            ->json('meta.delivery_code');

        $this->actingAs($seller, 'api')->postJson(
            "/api/v1/amial/safe-payments/{$p->payment_ulid}/verify-delivery",
            ['code' => $code]
        )->assertSuccessful();

        $fresh = $p->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->delivery_code_verified_at);
    }

    public function test_wrong_code_is_rejected_and_locks_after_three_tries(): void
    {
        // بلا حدّ يستطيع بائع سيّئ النيّة تجريب مليون احتمال في دقائق.
        [$p, , $seller] = $this->deal();
        app(SafePaymentService::class)->sellerAccept($p->fresh(), $seller);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($seller, 'api')->postJson(
                "/api/v1/amial/safe-payments/{$p->payment_ulid}/verify-delivery",
                ['code' => '000000']
            )->assertStatus(422);
        }

        $this->assertSame('funded', $p->fresh()->status);
        $this->assertSame(3, (int) $p->fresh()->delivery_code_attempts);

        // الرابعة تُرفض بسبب القفل لا بسبب الرمز.
        $this->actingAs($seller, 'api')->postJson(
            "/api/v1/amial/safe-payments/{$p->payment_ulid}/verify-delivery",
            ['code' => '000000']
        )->assertStatus(422);
    }

    public function test_delivery_code_hash_never_leaks_in_responses(): void
    {
        [$p, $buyer, $seller] = $this->deal();
        app(SafePaymentService::class)->sellerAccept($p->fresh(), $seller);

        $body = $this->actingAs($buyer, 'api')
            ->getJson("/api/v1/amial/safe-payments/{$p->payment_ulid}")->getContent();

        $this->assertStringNotContainsString('delivery_code_hash', $body);
    }

    // ===================== أسباب النزاع =====================

    public function test_dispute_reasons_are_served_by_the_server(): void
    {
        $data = $this->actingAs($this->party(), 'api')
            ->getJson('/api/v1/amial/safe-payments/dispute-reasons')
            ->assertOk()->json('data');

        $codes = array_column($data, 'code');
        $this->assertContains('not_received', $codes);
        $this->assertContains('not_as_described', $codes);
    }

    public function test_dispute_stores_a_structured_reason(): void
    {
        [$p, $buyer, $seller] = $this->deal();
        $svc = app(SafePaymentService::class);
        $svc->sellerAccept($p->fresh(), $seller);
        $svc->sellerMarkInDelivery($p->fresh(), $seller);
        $svc->sellerMarkDelivered($p->fresh(), $seller);

        $this->actingAs($buyer, 'api')->postJson(
            "/api/v1/amial/safe-payments/{$p->payment_ulid}/buyer-dispute",
            ['reason' => 'الجهاز وصل بشاشة مكسورة وغير مطابق للصور',
             'reason_code' => 'damaged']
        )->assertSuccessful();

        $this->assertSame('damaged', $p->fresh()->dispute_reason_code);
    }

    public function test_unknown_reason_code_is_rejected_not_silently_stored(): void
    {
        [$p, $buyer, $seller] = $this->deal();
        $svc = app(SafePaymentService::class);
        $svc->sellerAccept($p->fresh(), $seller);
        $svc->sellerMarkInDelivery($p->fresh(), $seller);
        $svc->sellerMarkDelivered($p->fresh(), $seller);

        $this->actingAs($buyer, 'api')->postJson(
            "/api/v1/amial/safe-payments/{$p->payment_ulid}/buyer-dispute",
            ['reason' => 'سبب مفصّل كافٍ الطول', 'reason_code' => 'made_up']
        )->assertStatus(422);
    }

    // ===================== سجلّ الثقة =====================

    public function test_counterparty_trust_is_shown_to_the_buyer(): void
    {
        [$p, $buyer] = $this->deal();

        $trust = $this->actingAs($buyer, 'api')
            ->getJson("/api/v1/amial/safe-payments/{$p->payment_ulid}")
            ->assertOk()->json('meta.counterparty_trust');

        $this->assertArrayHasKey('completed_deals', $trust);
        $this->assertArrayHasKey('dispute_rate', $trust);
        $this->assertArrayHasKey('badge', $trust);
        // بائع بصفقة واحدة جارية: ليس «موثوقاً» بعد.
        $this->assertNotSame('موثوق', $trust['badge']);
    }
}
