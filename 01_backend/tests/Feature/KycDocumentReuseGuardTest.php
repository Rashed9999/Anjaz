<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Admin\KycEvidenceService;
use App\Services\Kyc\DocumentReuseService;
use App\Services\KycDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-KYC-REUSE-001 — **ورقةٌ واحدةٌ تفتح حسابين.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، ولم يُفترَض:**
 *
 *     KycDocumentService::store → 'content_sha256' => $sha   ← تُكتَب
 *     grep 'content_sha256' app/ → موضعُ الكتابة وحدَه        ← ولا قارئ
 *
 * فبصمةُ كلّ وثيقةٍ محسوبةٌ منذ اليوم الأوّل **ولا سطرَ يقارنها بشيء**.
 * أي أنّ صورةَ هويّةٍ واحدةً تُرفَع لعشرين حساباً، وكلُّ حسابٍ يُراجَع
 * وحدَه فيبدو ملفُّه سليماً — ولا خطأ في أيّ سجلّ. وهذا أرخصُ طرق فتح
 * الحسابات الوهميّة.
 *
 * وستُّ حالات، **وأخطرُها ⑤**: المنعُ في القرار لا في الشاشة — فتحذيرٌ
 * يُمكن تخطّيه يُتخطّى.
 */
class KycDocumentReuseGuardTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        return User::factory()->create([
            'type' => CUSTOMER_TYPE, 'is_active' => 1, 'is_kyc_verified' => 0,
            'zone_code' => 'SOUTH', 'residence_governorate' => 'عدن',
        ]);
    }

    private function doc(User $u, string $type, ?string $sha = null,
        string $status = KycDocument::STATUS_APPROVED): KycDocument
    {
        return KycDocument::create([
            'user_id' => $u->id, 'doc_type' => $type, 'status' => $status,
            'encrypted_path' => 'kyc/'.Str::random(10).'.enc',
            'size_bytes' => 2048, 'ocr_status' => 'not_run',
            'content_sha256' => $sha ?? hash('sha256', Str::random(20)),
        ]);
    }

    /** ملفٌّ مكتملٌ بثلاث وثائقَ مختلفة. */
    private function completeFile(User $u): void
    {
        foreach ([KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE] as $type) {
            $this->doc($u, $type);
        }
    }

    private function reuse(User $u): array
    {
        return app(DocumentReuseService::class)->findingsFor($u);
    }

    /** @test */
    public function the_same_identity_photo_in_two_accounts_closes_the_road(): void
    {
        $sha = hash('sha256', 'نفس-صورة-الهويّة');

        $first = $this->account();
        $second = $this->account();

        $this->doc($first, KycDocument::TYPE_ID_FRONT, $sha);
        $this->doc($second, KycDocument::TYPE_ID_FRONT, $sha);

        $r = $this->reuse($second);

        $this->assertNotEmpty($r['blockers'],
            'صورةُ هويّةٍ واحدةٌ في حسابين ولم يُلاحَظ — وهي أرخصُ طرق '
            .'فتح الحسابات الوهميّة، والبصمةُ محسوبةٌ منذ اليوم الأوّل');

        $this->assertStringContainsString('حسابٍ آخر', implode(' ', $r['blockers']));
        $this->assertSame('high', $r['matches'][0]['severity']);
    }

    /** @test */
    public function an_address_proof_shared_by_a_household_warns_and_never_blocks(): void
    {
        $sha = hash('sha256', 'فاتورة-كهرباء-البيت');

        $father = $this->account();
        $son = $this->account();

        $this->doc($father, KycDocument::TYPE_ADDRESS_PROOF, $sha);
        $this->doc($son, KycDocument::TYPE_ADDRESS_PROOF, $sha);

        $r = $this->reuse($son);

        // **الحتميُّ يُغلق، والاحتماليُّ يُنبّه** — وبيتٌ واحدٌ لأسرةٍ
        // واحدةٍ مشروعٌ تماماً، ومنعُه يشلّ عملاً سليماً.
        $this->assertSame([], $r['blockers'],
            'مُنع أبٌ وابنُه لأنّهما يسكنان بيتاً واحداً — وحاجزٌ يشلّ '
            .'عملاً سليماً أسوأ من ثغرة');
        $this->assertNotEmpty($r['warnings'], 'ولا يُقال للمراجع شيءٌ أصلاً');
        $this->assertSame('medium', $r['matches'][0]['severity']);
    }

    /** @test */
    public function the_same_image_as_front_and_back_means_a_side_was_never_given(): void
    {
        $u = $this->account();
        $sha = hash('sha256', 'صورةٌ-واحدةٌ-للوجهين');

        $this->doc($u, KycDocument::TYPE_ID_FRONT, $sha);
        $this->doc($u, KycDocument::TYPE_ID_BACK, $sha);
        $this->doc($u, KycDocument::TYPE_SELFIE);

        $r = $this->reuse($u);

        $this->assertNotEmpty($r['blockers'],
            'رُفعت الصورةُ نفسُها للوجه والظهر — فالملفُّ مكتملٌ شكلاً '
            .'وناقصٌ حقيقةً، والعدّادُ يقول «٣ من ٣» فيعتمد المراجع');
        $this->assertStringContainsString('لم يُقدَّم', implode(' ', $r['blockers']));
    }

    /** @test */
    public function re_uploading_the_same_document_type_is_not_a_reuse(): void
    {
        $u = $this->account();
        $sha = hash('sha256', 'نفس-الوثيقة-مرفوعة-مرّتين');

        // **وهذا ما تطلبه المنصّةُ نفسُها** حين تقول «أعِد الرفع».
        $this->doc($u, KycDocument::TYPE_ID_FRONT, $sha, KycDocument::STATUS_SUPERSEDED);
        $this->doc($u, KycDocument::TYPE_ID_FRONT, $sha);

        $this->assertSame([], $this->reuse($u)['blockers'],
            'مُنع من أعاد رفعَ وثيقته بطلبٍ منّا — فيُرفض ما طلبناه');
        $this->assertSame([], $this->reuse($u)['warnings']);
    }

    /** @test */
    public function a_clean_file_produces_no_finding_at_all(): void
    {
        $u = $this->account();
        $this->completeFile($u);

        $r = $this->reuse($u);

        // **وتحذيرٌ بلا سببٍ يُعوّد القارئَ تجاهلَه يومَ يصدق.**
        $this->assertSame([], $r['blockers']);
        $this->assertSame([], $r['warnings']);
        $this->assertSame([], $r['matches']);
    }

    /** @test */
    public function the_decision_itself_refuses_not_only_the_screen(): void
    {
        $sha = hash('sha256', 'هويّةٌ-منتحَلة');

        $victim = $this->account();
        $impostor = $this->account();
        $reviewer = User::factory()->create(['type' => ADMIN_TYPE]);

        $this->doc($victim, KycDocument::TYPE_ID_FRONT, $sha);

        // ملفُّ المنتحِل «مكتمل» شكلاً: ثلاثُ وثائقَ معتمَدة.
        $this->doc($impostor, KycDocument::TYPE_ID_FRONT, $sha);
        $this->doc($impostor, KycDocument::TYPE_ID_BACK);
        $this->doc($impostor, KycDocument::TYPE_SELFIE);

        $ev = app(KycEvidenceService::class)->for($impostor, 2, $reviewer);
        $this->assertTrue($ev['complete'], 'الملفُّ مكتملٌ شكلاً — وهذا هو بيتُ القصيد');
        $this->assertNotEmpty($ev['blockers'], 'الشاشةُ لا تقول شيئاً');

        // ⑤ **والمنعُ في القرار لا في الشاشة** — تحذيرٌ يُمكن تخطّيه
        // يُتخطّى، ومسارُ القرار له بابان.
        $this->expectException(\DomainException::class);

        app(KycDocumentService::class)->decideAccountVerification(
            user: $impostor, reviewer: $reviewer, approve: true);
    }
}
