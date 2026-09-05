<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\DocumentReuseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-IDENTITY-UNIQUE-001 — **البصمةُ لا تكفي، والرقمُ هو الثابت.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * سأل صاحبُ المشروع: «هل هناك نظامٌ يكشف تكرارَ الهويّة في أكثر من حساب،
 * وتكرارَ رقم الهاتف؟». وقِيس، فكانت ثلاثةَ أشياءَ لا شيئاً واحداً:
 *
 *   · صورةُ الهويّة  → مبنيّةٌ (`AMIAL-KYC-REUSE-001`)
 *   · رقمُ الهاتف    → `unique:users` في التطبيق، **والفهرسُ غيرُ فريد**
 *   · رقمُ الهويّة   → **لا شيءَ إطلاقاً**
 *
 * **وأخطرُها الثالث، وهو ما يُبطل الأوّل:** من صوّر بطاقتَه ثانيةً
 * بزاويةٍ أخرى أنتج بصمةً مختلفةً تماماً **فمرّ من حارس البصمة**. ورقمُ
 * الهويّة لا تُغيّره إعادةُ التصوير — فعشرُ صورٍ لبطاقةٍ واحدةٍ تفتح عشرةَ
 * حساباتٍ اليومَ، والرقمُ نفسُه في كلِّها ولا أحدَ ينظر إليه.
 *
 * **والثاني ثقبُه السباق:** طلبان في اللحظة نفسِها يقرآن «غير موجود» ثمّ
 * يُدرجان معاً — وهو بعينه ما أسقط `USER_WALLET_{id}` سلفاً. **والقيدُ في
 * القاعدة هو الشيءُ الوحيد الذرّيّ.**
 */
class IdentityUniquenessGuardTest extends TestCase
{
    use RefreshDatabase;

    private function account(?string $idNumber = null, ?string $phone = null): User
    {
        return User::factory()->create([
            'type' => CUSTOMER_TYPE, 'is_active' => 1, 'is_kyc_verified' => 0,
            'zone_code' => 'SOUTH', 'residence_governorate' => 'عدن',
            'identification_number' => $idNumber,
            'phone' => $phone ?? ('9677'.random_int(10000000, 99999999)),
        ]);
    }

    private function reuse(User $u): array
    {
        return app(DocumentReuseService::class)->findingsFor($u);
    }

    /** @test */
    public function a_second_photo_of_the_same_card_no_longer_slips_through(): void
    {
        // **وهذا هو بيتُ القصيد:** بصمتان مختلفتان — فحارسُ البصمة أعمى.
        $first = $this->account('01020304050');
        $second = $this->account('01020304050');

        KycDocument::create([
            'user_id' => $first->id, 'doc_type' => KycDocument::TYPE_ID_FRONT,
            'status' => KycDocument::STATUS_APPROVED,
            'encrypted_path' => 'kyc/'.Str::random(10).'.enc', 'size_bytes' => 2048,
            'ocr_status' => 'not_run', 'content_sha256' => hash('sha256', 'صورة-أولى'),
        ]);
        KycDocument::create([
            'user_id' => $second->id, 'doc_type' => KycDocument::TYPE_ID_FRONT,
            'status' => KycDocument::STATUS_APPROVED,
            'encrypted_path' => 'kyc/'.Str::random(10).'.enc', 'size_bytes' => 2048,
            'ocr_status' => 'not_run', 'content_sha256' => hash('sha256', 'صورة-ثانية-بزاوية-أخرى'),
        ]);

        $r = $this->reuse($second);

        $this->assertNotEmpty($r['blockers'],
            'صُوِّرت البطاقةُ ثانيةً بزاويةٍ أخرى فمرّت — والبصمةُ مختلفةٌ '
            .'والرقمُ واحد، وهو الثابتُ الذي لا تُغيّره إعادةُ التصوير');

        $this->assertStringContainsString('رقمُ الهويّة', implode(' ', $r['blockers']));
        $this->assertSame('high', $r['matches'][0]['severity']);
    }

    /** @test */
    public function the_arabic_indic_digits_are_normalised_before_comparing(): void
    {
        // **بدونه يُقرأ «١٢٣٤» و«1234» رقمين مختلفين** — والهويّاتُ اليمنيّةُ
        // تُطبع بالشكلين، فيمرّ الانتحالُ من أوسع أبوابه.
        $this->account('01020304050');
        $impostor = $this->account('٠١٠٢٠٣٠٤٠٥٠');

        $this->assertNotEmpty($this->reuse($impostor)['blockers'],
            'الأرقامُ العربيّةُ-الهنديّةُ لم تُوحَّد قبل المقارنة — '
            .'فـ«١٢٣٤» و«1234» رقمان مختلفان، ويمرّ الانتحال');
    }

    /** @test */
    public function what_the_reviewer_confirmed_is_compared_too(): void
    {
        // **وإقرارُ المراجع أوثقُ من الحقل** — فهو ما قُرئ من الوثيقة.
        $first = $this->account('77889900112');
        $second = $this->account();   // لا رقمَ في حقله

        KycDocument::create([
            'user_id' => $second->id, 'doc_type' => KycDocument::TYPE_ID_FRONT,
            'status' => KycDocument::STATUS_APPROVED,
            'encrypted_path' => 'kyc/'.Str::random(10).'.enc', 'size_bytes' => 2048,
            'ocr_status' => 'not_run',
            'content_sha256' => hash('sha256', Str::random(20)),
            // **كما تكتبه الشيفرةُ الحيّة** — مشفَّراً، لا مصفوفةً خاماً.
            'verified_fields' => \Illuminate\Support\Facades\Crypt::encryptString(
                json_encode(['national_id' => '77889900112'], JSON_UNESCAPED_UNICODE)),
        ]);

        $this->assertNotEmpty($this->reuse($second)['blockers'],
            'ما أقرّه المراجعُ بعد قراءة الوثيقة لا يُقارَن — وهو أوثقُ '
            .'ممّا كتبه المستعملُ في الحقل');
    }

    /** @test */
    public function a_short_or_absent_number_never_blocks_anyone(): void
    {
        // **«1» و«12» تصادفاتٌ لا انتحال** — ومنعُ حسابٍ عليها حاجزٌ يشلّ
        // عملاً سليماً، وهو أسوأ من ثغرة.
        $this->account('12');
        $short = $this->account('12');

        $this->assertSame([], $this->reuse($short)['blockers'],
            'مُنع حسابٌ على رقمٍ من محرفين — تصادفٌ لا انتحال');

        $blank = $this->account(null);
        $this->assertSame([], $this->reuse($blank)['blockers'],
            'مُنع حسابٌ بلا رقمِ هويّةٍ إطلاقاً');
    }

    /** @test */
    public function two_different_people_are_never_blocked(): void
    {
        $this->account('11111111111');
        $other = $this->account('22222222222');

        $r = $this->reuse($other);

        // **وتحذيرٌ بلا سببٍ يُعوّد القارئَ تجاهلَه يومَ يصدق.**
        $this->assertSame([], $r['blockers']);
        $this->assertSame([], $r['warnings']);
    }

    /** @test */
    public function the_database_itself_refuses_a_duplicate_phone(): void
    {
        // **والقيدُ في القاعدة هو الشيءُ الوحيد الذرّيّ** — `unique:users`
        // فحصٌ في التطبيق، وطلبان متزامنان يقرآن «غير موجود» ثمّ يُدرجان
        // معاً. وهو بعينه ما أسقط `USER_WALLET_{id}` سلفاً.
        $phone = '967779998881';
        $this->account(null, $phone);

        $index = collect(DB::select('SHOW INDEX FROM users'))
            ->first(fn ($i) => $i->Key_name === 'users_phone_unique_idx');

        $this->assertNotNull($index, 'لا قيدَ تفرُّدٍ على الهاتف في القاعدة');
        $this->assertSame(0, (int) $index->Non_unique,
            'الفهرسُ موجودٌ وغيرُ فريد — فهو للبحث لا للمنع');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        // إدراجٌ مباشرٌ يتخطّى تحقّقَ التطبيق — كما تفعل أوامرُ البذر.
        DB::table('users')->insert([
            'f_name' => 'منتحِل', 'l_name' => 'ثانٍ', 'phone' => $phone,
            'password' => bcrypt('x'), 'type' => CUSTOMER_TYPE, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
