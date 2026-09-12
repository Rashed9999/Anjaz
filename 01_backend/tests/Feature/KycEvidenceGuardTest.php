<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Admin\KycEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-KYC-EVIDENCE-001 — **الدليلُ المعروضُ هو الدليلُ المحكومُ به.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، ولم يُفترَض:**
 *
 *     AdminHubController::verificationJson →
 *         'documents' => $u->identification_image_fullpath   ← عمودٌ قديم
 *
 *     KycDocumentService::decideAccountVerification →
 *         completenessFor() يقرأ `kyc_documents`             ← سجلٌّ حديث
 *
 * **فالمراجعُ يرى شيئاً ويقرّر على شيءٍ آخر** — وله وجهان وكلاهما يقع:
 * يعتمد على صورةٍ بائدة، أو يقرأ «لا وثائق مرفوعة» ووثائقُ العميل
 * مرفوعةٌ في السجلّ الحديث لم تُعرَض له.
 *
 * **وسبعُ حالات، والأخيرةُ هي التي تمنع عودةَ العطل:**
 * كتابةُ `is_kyc_verified` خارج مصدر القرار — وكان لها **ثلاثةُ** أبوابٍ
 * قِيست: `MerchantController` عند الإنشاء، و`updateKycStatus` في متحكّمَي
 * العملاء والوكلاء.
 *
 * **والثلاثةُ سواء: عميلٌ ووكيلٌ وتاجر** — الوثيقةُ الشخصيّةُ واحدةٌ لكلّ
 * من يملك محفظة، وكان الدليلُ مكسوراً لثلاثتهم لا للتاجر وحدَه.
 */
class KycEvidenceGuardTest extends TestCase
{
    use RefreshDatabase;

    /** الشيفرةُ بلا تعليقات — فالتعليقُ يصف العطلَ ولا يكونه. */
    private function codeOnly(string $php): string
    {
        $out = '';

        foreach (token_get_all($php) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function account(int $type): User
    {
        return User::factory()->create([
            'type' => $type, 'is_active' => 1, 'is_kyc_verified' => 0,
            'zone_code' => 'SOUTH', 'residence_governorate' => 'عدن',
        ]);
    }

    private function doc(User $u, string $type, string $status): KycDocument
    {
        return KycDocument::create([
            'user_id' => $u->id, 'doc_type' => $type, 'status' => $status,
            'encrypted_path' => 'kyc/'.Str::random(8).'.enc',
            'size_bytes' => 1024, 'ocr_status' => 'not_run',
        ]);
    }

    private function evidence(User $u, ?User $reviewer = null): array
    {
        return app(KycEvidenceService::class)->for($u, 2, $reviewer);
    }

    /** @test */
    public function the_panel_reads_the_modern_record_not_the_legacy_column(): void
    {
        // عميلٌ رفع وثائقَه من التطبيق إلى `kyc_documents`، وعمودُه القديمُ فارغ.
        $u = $this->account(CUSTOMER_TYPE);
        $this->doc($u, KycDocument::TYPE_ID_FRONT, KycDocument::STATUS_PENDING);
        $this->doc($u, KycDocument::TYPE_SELFIE, KycDocument::STATUS_APPROVED);

        $ev = $this->evidence($u);

        $this->assertCount(2, $ev['documents'],
            'الشاشةُ تقرأ العمودَ القديم — فيرى المراجعُ «لا وثائق مرفوعة» '
            .'ووثائقُ العميل في السجلّ الحديث، فيُترك حسابٌ في الطابور بلا سبب');

        $this->assertContains('بطاقة الهوية — الوجه', array_column($ev['documents'], 'type_label'));
    }

    /** @test */
    public function it_names_what_is_missing_instead_of_saying_the_file_is_incomplete(): void
    {
        $u = $this->account(CUSTOMER_TYPE);
        $this->doc($u, KycDocument::TYPE_ID_FRONT, KycDocument::STATUS_APPROVED);

        $ev = $this->evidence($u);

        $this->assertFalse($ev['complete']);
        $this->assertContains('بطاقة الهوية — الظهر', $ev['missing'],
            'الناقصُ لا يُسمّى بعينه — «الملفّ ناقص» تُرسل المراجعَ يبحث '
            .'وتُنتج تذكرةَ دعمٍ لا إجراءً');
        $this->assertContains('صورة شخصية حيّة', $ev['missing']);
    }

    /** @test */
    public function a_pending_or_rejected_document_never_counts_as_approved(): void
    {
        $u = $this->account(CUSTOMER_TYPE);

        foreach ([KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE] as $t) {
            $this->doc($u, $t, KycDocument::STATUS_PENDING);
        }

        $ev = $this->evidence($u);

        $this->assertFalse($ev['complete'],
            'وثيقةٌ تنتظر المراجعةَ حُسبت معتمَدة — فيُعتمد حسابٌ لم تُقرأ '
            .'ورقةٌ من ورقه');
        $this->assertCount(3, $ev['missing']);
    }

    /** @test */
    public function the_legacy_images_are_shown_but_never_counted(): void
    {
        $u = $this->account(CUSTOMER_TYPE);
        $u->identification_image = json_encode(['old-id.jpg']);
        $u->save();

        $ev = $this->evidence($u->fresh());

        $this->assertNotEmpty($ev['legacy_images'],
            'الصورُ القديمةُ طُويت — وقد تنفع المراجع، وطيُّها يُخفي دليلاً قائماً');
        $this->assertFalse($ev['complete'],
            'صورةٌ في الحقل القديم حُسبت وثيقةً معتمَدة — وهي لم تُراجَع قطّ');
    }

    /** @test */
    public function what_blocks_approval_is_said_before_the_press(): void
    {
        $u = $this->account(CUSTOMER_TYPE);
        $u->residence_governorate = null;
        $u->origin_governorate = null;
        $u->save();

        $ev = $this->evidence($u->fresh());

        $this->assertNotEmpty($ev['blockers'],
            'لا يُقال ما يمنع الاعتماد — فيُضغط الزرُّ ويُردّ، ويُعلَّم '
            .'المراجعُ أن يجرّب ويرى بدل أن يقرأ ويُصلح');

        $joined = implode(' ', $ev['blockers']);
        $this->assertStringContainsString('محافظة السكن', $joined);
        $this->assertStringContainsString('مستندات', $joined);

        // **والمبدأُ الرباعيُّ يُقال أيضاً** — وهو أوّلُ ما يرمي به القرار.
        $self = $this->evidence($u->fresh(), $u->fresh());
        $this->assertStringContainsString('المبدأ الرباعيّ', implode(' ', $self['blockers']));
    }

    /** @test */
    public function the_three_account_types_share_one_evidence(): void
    {
        // **عميلٌ ووكيلٌ وتاجر** — الوثيقةُ الشخصيّةُ واحدةٌ لمن يملك محفظة.
        foreach ([CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE] as $type) {
            $u = $this->account($type);

            foreach ([KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK,
                KycDocument::TYPE_SELFIE] as $t) {
                $this->doc($u, $t, KycDocument::STATUS_APPROVED);
            }

            $ev = $this->evidence($u);

            $this->assertTrue($ev['complete'],
                "النوع {$type}: ملفٌّ مكتملٌ قُرئ ناقصاً — فالدليلُ ليس واحداً للثلاثة");
            $this->assertSame([], $ev['blockers'],
                "النوع {$type}: مانعٌ على ملفٍّ مكتملٍ ومحافظتُه محدَّدة");
        }
    }

    /** @test */
    public function no_second_door_writes_the_verification_flag(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ثلاثةُ أبوابٍ قِيست وأُغلقت**، وكلٌّ منها يكتب التوثيقَ بلا
        // مراجعةِ وثيقةٍ ولا محافظةٍ ولا مبدأٍ رباعيٍّ ولا سطرِ تدقيق:
        //   · `MerchantController` عند إنشاء التاجر (`= 1`)
        //   · `CustomerController::updateKycStatus`
        //   · `AgentController::updateKycStatus`
        //
        // **والمسحُ لا يعمل في وقت التشغيل عمداً**: بابٌ يُضاف اليومَ بلا
        // مسارٍ يُوصَل به غداً — واليتيمُ يُتبنّى.
        // ══════════════════════════════════════════════════════════════
        $offenders = [];

        foreach ([app_path('Http/Controllers'), app_path('Services')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                // مصدرُ القرار الوحيد — وهو موضعُ الكتابة المشروع.
                if (str_ends_with($path, 'Services/KycDocumentService.php')) {
                    continue;
                }

                // **والتعليقُ يُنزَع قبل المسح.** أوّلُ تشغيلٍ لهذا
                // الحارس سقط على `AdminHubController` — والمطابقةُ كانت
                // في **تعليقٍ عربيٍّ يشرح العطلَ المُزال**. وهو درسُ
                // `CLAUDE.md` نفسُه مقلوباً: هناك مرّ حارسٌ لأنّ الكلمة
                // في تعليق، وهنا سقط للسبب عينه. **وحارسٌ يكذب في أيّ
                // الاتّجاهين أسوأ من غيابه.**
                $src = $this->codeOnly((string) file_get_contents($path));

                // `= 1` أو `= 2` أو `=> 1` — إسنادُ قيمةٍ ثابتة. والقراءةُ
                // والمقارنةُ (`=== 1`, `->is_kyc_verified ?? 0`) لا تُمسّ.
                if (preg_match('~is_kyc_verified\'?\]?\s*(=|=>)\s*[12]\b~', $src)) {
                    $offenders[] = str_replace(app_path().'/', '', $path);
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "بابٌ ثانٍ يكتب التوثيق خارج `KycDocumentService`:\n  %s\n\n"
            ."والقرارُ واحد: مراجعةُ الوثائق ← اكتمالُ الملفّ ← محافظةُ السكن\n"
            .'← المبدأ الرباعيّ ← الاعتماد. وكلُّ ما عداه يفتح سقفَ مالٍ '
            .'على توثيقٍ لم يقع.',
            implode("\n  ", array_unique($offenders))));
    }
}
