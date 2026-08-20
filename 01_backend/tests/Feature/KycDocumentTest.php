<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycDocumentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * AMIAL-KYC-DOCS-001 — الدائرة المقطوعة تُغلق.
 *
 * **ما كان:** زرٌّ في لوحة الدعم اسمه «طلب تحديث الهوية» يضع علامةً على
 * المستخدم، ثم **لا مكان يرفع إليه مستنده**. الزرّ يعمل، والموظّف يطمئنّ،
 * والعميل ينتظر شيئاً لن يأتي. وكل التخزين عمودٌ واحد على `users` بلا نوعٍ
 * ولا مراجعةٍ ولا تاريخ.
 *
 * وهذا — لا الميزة السابعة عشرة — هو العائق التنظيميّ الأوّل لأي منصّة مالية
 * في اليمن.
 */
class KycDocumentTest extends TestCase
{
    use RefreshDatabase;

    private KycDocumentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(KycDocumentService::class);
    }

    private function image(string $name = 'id.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 400);
    }

    private function customer(): User
    {
        return User::factory()->create(['zone_code' => 'SOUTH']);
    }

    private function reviewer(): User
    {
        return User::factory()->create(['type' => 0, 'role' => 'super_admin']);
    }

    // ── الرفع ──────────────────────────────────────────────────────────

    public function test_a_customer_can_upload_an_identity_document(): void
    {
        $u = $this->customer();

        $doc = $this->svc->upload($u, KycDocument::TYPE_ID_FRONT, $this->image());

        $this->assertSame(KycDocument::STATUS_PENDING, $doc->status);
        $this->assertSame($u->id, (int) $doc->user_id);
        $this->assertNotEmpty($doc->content_sha256, 'لم تُحسب بصمة المحتوى');
    }

    public function test_the_file_itself_is_stored_encrypted_not_readable(): void
    {
        // صورةُ بطاقةٍ في تخزينٍ عاديّ تُقرأ بأي وصولٍ للقرص. والفحص على
        // المحتوى لا على وجود العمود: عمودٌ اسمه encrypted_path لا يعني أن
        // ما فيه مشفَّر.
        $u = $this->customer();
        $doc = $this->svc->upload($u, KycDocument::TYPE_ID_FRONT, $this->image());

        $raw = \Illuminate\Support\Facades\Storage::disk('local')->get($doc->encrypted_path);

        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString('JFIF', $raw,
            'الملفّ مخزَّن كما هو — ترويسة JPEG ظاهرة، فلا تشفير');

        // ويُفكّ صحيحاً: تشفيرٌ لا يُفكّ يساوي فقدان المستند.
        $plain = $this->svc->decrypt($doc);
        $this->assertSame(hash('sha256', $plain), $doc->content_sha256,
            'المفكوك يخالف ما رُفع');
    }

    public function test_the_encrypted_path_is_never_exposed_in_json(): void
    {
        // من يملك المسار يملك الملفّ إن تسرّب مفتاح، والمسار لا يفيد الواجهة.
        $doc = $this->svc->upload($this->customer(), KycDocument::TYPE_SELFIE, $this->image());

        $this->assertArrayNotHasKey('encrypted_path', $doc->toArray(),
            'مسار الملفّ المشفَّر يخرج في الاستجابة');
    }

    public function test_re_uploading_supersedes_the_previous_pending_document(): void
    {
        // لو بقي القديم قيد المراجعة لظهر للمراجع مستندان لنوعٍ واحد فلا يدري
        // أيّهما المقصود، ولو اعتمد القديم لوثّق الحساب بصورةٍ تخلّى عنها
        // صاحبها.
        $u = $this->customer();
        $old = $this->svc->upload($u, KycDocument::TYPE_ID_FRONT, $this->image('a.jpg'));
        $new = $this->svc->upload($u, KycDocument::TYPE_ID_FRONT, $this->image('b.jpg'));

        $this->assertSame(KycDocument::STATUS_REJECTED, $old->fresh()->status,
            'بقي المستند القديم قيد المراجعة مع وجود أحدث منه');
        $this->assertSame(KycDocument::STATUS_PENDING, $new->status);

        $pendingCount = KycDocument::where('user_id', $u->id)
            ->where('doc_type', KycDocument::TYPE_ID_FRONT)
            ->where('status', KycDocument::STATUS_PENDING)->count();

        $this->assertSame(1, $pendingCount, 'مستندان من نوعٍ واحد ينتظران');
    }

    public function test_an_oversized_or_unsupported_file_is_refused(): void
    {
        $u = $this->customer();

        $this->expectException(DomainException::class);
        $this->svc->upload($u, KycDocument::TYPE_ID_FRONT,
            UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'));
    }

    public function test_an_unknown_document_type_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->svc->upload($this->customer(), 'birth_certificate', $this->image());
    }

    // ── المراجعة ───────────────────────────────────────────────────────

    public function test_a_reviewer_can_approve_and_reject_with_a_reason(): void
    {
        $u = $this->customer();
        $admin = $this->reviewer();

        $ok = $this->svc->approve(
            $this->svc->upload($u, KycDocument::TYPE_ID_FRONT, $this->image()), $admin,
        );
        $this->assertSame(KycDocument::STATUS_APPROVED, $ok->status);
        $this->assertSame($admin->id, (int) $ok->reviewed_by);

        $bad = $this->svc->reject(
            $this->svc->upload($u, KycDocument::TYPE_ID_BACK, $this->image()),
            $admin, 'الصورة غير واضحة',
        );
        $this->assertSame(KycDocument::STATUS_REJECTED, $bad->status);
        $this->assertSame('الصورة غير واضحة', $bad->rejection_reason);
    }

    public function test_rejecting_without_a_reason_is_refused(): void
    {
        // رفضٌ بلا سبب يجعل العميل يرفع الصورة نفسها مرّةً بعد مرّة.
        $doc = $this->svc->upload($this->customer(), KycDocument::TYPE_SELFIE, $this->image());

        $this->expectException(DomainException::class);
        $this->svc->reject($doc, $this->reviewer(), '   ');
    }

    public function test_nobody_reviews_their_own_document(): void
    {
        // موظّفو المنصّة عملاء فيها أيضاً، ولهم محافظ وحدود. ومن يعتمد
        // هويّته بيده يرفع حدوده بيده.
        $staff = $this->reviewer();
        $doc = $this->svc->upload($staff, KycDocument::TYPE_ID_FRONT, $this->image());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FOUR_EYES_VIOLATION');

        $this->svc->approve($doc, $staff);
    }

    public function test_a_document_cannot_be_reviewed_twice(): void
    {
        $doc = $this->svc->upload($this->customer(), KycDocument::TYPE_ID_FRONT, $this->image());
        $this->svc->approve($doc, $this->reviewer());

        $this->expectException(DomainException::class);
        $this->svc->reject($doc->fresh(), $this->reviewer(), 'تراجعتُ');
    }

    // ── الاكتمالية ─────────────────────────────────────────────────────

    public function test_completeness_reports_exactly_what_is_missing(): void
    {
        $u = $this->customer();
        $admin = $this->reviewer();

        $this->svc->approve(
            $this->svc->upload($u, KycDocument::TYPE_ID_FRONT, $this->image()), $admin,
        );

        $c = $this->svc->completenessFor($u, 2);

        $this->assertFalse($c['complete']);
        $this->assertContains(KycDocument::TYPE_ID_BACK, $c['missing']);
        $this->assertContains(KycDocument::TYPE_SELFIE, $c['missing']);
        $this->assertNotContains(KycDocument::TYPE_ID_FRONT, $c['missing']);
    }

    public function test_completeness_is_reached_when_all_required_are_approved(): void
    {
        $u = $this->customer();
        $admin = $this->reviewer();

        foreach ([KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK, KycDocument::TYPE_SELFIE] as $t) {
            $this->svc->approve($this->svc->upload($u, $t, $this->image()), $admin);
        }

        $this->assertTrue($this->svc->completenessFor($u, 2)['complete'],
            'اكتملت المستندات ولم تُحتسب مكتملة');
    }

    public function test_account_approval_requires_a_complete_approved_document_set(): void
    {
        $u = $this->customer();
        $admin = $this->reviewer();

        try {
            $this->svc->decideAccountVerification($u, $admin, true);
            $this->fail('اعتمد الحساب بلا وثائق مكتملة');
        } catch (DomainException $e) {
            $this->assertStringContainsString('KYC_DOCUMENTS_INCOMPLETE', $e->getMessage());
        }
        $this->assertNotSame(1, (int) $u->fresh()->is_kyc_verified);

        foreach ([KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK, KycDocument::TYPE_SELFIE] as $type) {
            $this->svc->approve($this->svc->upload($u, $type, $this->image()), $admin);
        }

        $verified = $this->svc->decideAccountVerification($u, $admin, true);
        $this->assertSame(1, (int) $verified->is_kyc_verified);
        $this->assertGreaterThanOrEqual(2, (int) $verified->kyc_tier);
    }

    public function test_account_verification_cannot_be_decided_by_its_owner(): void
    {
        $staff = $this->reviewer();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FOUR_EYES_VIOLATION');
        $this->svc->decideAccountVerification($staff, $staff, false, 2, 'رفض مستقل');
    }

    public function test_an_expired_document_does_not_count_as_complete(): void
    {
        // بطاقةٌ انتهت صلاحيتها لا توثّق، والحساب الموثَّق بها غير موثَّق.
        // وهذا ما يُنسى دائماً: يُفحص القرار ولا تُفحص الوثيقة.
        $u = $this->customer();
        $admin = $this->reviewer();

        foreach ([KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK, KycDocument::TYPE_SELFIE] as $t) {
            $this->svc->approve($this->svc->upload($u, $t, $this->image()), $admin);
        }
        $this->assertTrue($this->svc->completenessFor($u, 2)['complete']);

        KycDocument::where('user_id', $u->id)
            ->where('doc_type', KycDocument::TYPE_ID_FRONT)
            ->update(['document_expires_at' => now()->subDay()->toDateString()]);

        $c = $this->svc->completenessFor($u, 2);

        $this->assertFalse($c['complete'],
            'حسابٌ موثَّق ببطاقةٍ منتهية ما زال يُحتسب مكتملاً');
        $this->assertContains(KycDocument::TYPE_ID_FRONT, $c['missing']);
    }

    // ── الطابور ────────────────────────────────────────────────────────

    public function test_the_pending_queue_shows_the_oldest_first_with_waiting_time(): void
    {
        // الانتظار هو ما يُشتكى منه، فالأقدم أوّلاً لا الأحدث.
        $u = $this->customer();
        $first = $this->svc->upload($u, KycDocument::TYPE_ID_FRONT, $this->image());
        KycDocument::where('id', $first->id)->update(['created_at' => now()->subDays(3)]);
        $this->svc->upload($u, KycDocument::TYPE_SELFIE, $this->image());

        $queue = $this->svc->pendingQueue();

        $this->assertNotEmpty($queue);
        $this->assertSame($first->id, $queue[0]['id'], 'الأقدم ليس أوّل الطابور');
        $this->assertGreaterThanOrEqual(70, $queue[0]['waiting_hours'],
            'زمن الانتظار غير محسوب');
    }
}
