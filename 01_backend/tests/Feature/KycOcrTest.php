<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycDocumentService;
use App\Services\KycOcrService;
use App\Services\Ocr\IdFieldExtractor;
use App\Services\Ocr\OcrDriverInterface;
use App\Services\Ocr\OcrResult;
use App\Services\Ocr\TesseractOcrDriver;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * AMIAL-KYC-OCR-001 — الفصل ٢٣: استخراج بيانات وثيقة الهوية.
 *
 * **الحدّ الذي تختبره أكثر هذه الحالات:** OCR يقترح ولا يقرّر. اعتمادُ
 * هويّةٍ يفتح حدوداً مالية أعلى، ومحرّكٌ يقرأ صورةً بهاتفٍ في إضاءةٍ رديئة
 * ليس ما يُتّخذ عنده هذا القرار.
 *
 * وأكثر ما يُختبَر هنا هو **الامتناع**: متى يترك المحرّك الحقل فارغاً بدل
 * أن يملأه بأقرب احتمال. لأنّ حقلاً مملوءاً خطأً أخطر من حقلٍ فارغ — من
 * يرى مربّعاً فارغاً يقرأ الصورة ويكتب، ومن يرى رقماً مكتوباً يمرّ عليه.
 */
class KycOcrTest extends TestCase
{
    use RefreshDatabase;

    private IdFieldExtractor $ex;
    private User $customer;
    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ex = new IdFieldExtractor();
        $this->customer = User::factory()->create([
            'phone' => '770006601', 'f_name' => 'أحمد', 'l_name' => 'صالح',
        ]);
        $this->reviewer = User::factory()->create([
            'type' => 0, 'role' => 'super_admin', 'phone' => '967770006602',
        ]);
    }

    // ── الاستخراج: متى يمتنع ────────────────────────────────────────────

    /** @test */
    public function a_labelled_national_id_is_certain_an_unlabelled_one_is_not(): void
    {
        $labelled = $this->ex->extract('الجمهورية اليمنية الرقم الوطني: 01234567890');
        $this->assertSame('01234567890', $labelled['national_id']['value']);
        $this->assertTrue($labelled['national_id']['certain']);

        $bare = $this->ex->extract('بطاقة 01234567890 صادرة');
        $this->assertSame('01234567890', $bare['national_id']['value']);
        $this->assertFalse($bare['national_id']['certain'],
            'رقمٌ بلا عنوان يُقدَّم كأنّه مؤكَّد');
    }

    /** @test */
    public function two_long_numbers_with_no_label_produce_no_national_id_at_all(): void
    {
        // اختيارُ أحدهما تخمين. والامتناع هنا هو الصواب: مربّعٌ فارغ يجعل
        // المراجع يقرأ الصورة، ورقمٌ مخمَّن يجعله يمرّ.
        $out = $this->ex->extract('123456789012 و 987654321098 على الوثيقة');

        $this->assertArrayNotHasKey('national_id', $out,
            'خُمِّن رقم هوية من رقمين متساويي الاحتمال');
    }

    /** @test */
    public function arabic_indic_digits_are_read_as_numbers(): void
    {
        // الهويّات اليمنية تطبع الأرقام بالشكلين. وبلا التوحيد يفشل كلّ نمطٍ
        // رقميّ على نصف الوثائق — ويبدو ذلك كأنّ المحرّك ضعيف وهو يقرأ جيّداً.
        $out = $this->ex->extract('الرقم الوطني: ٠١٢٣٤٥٦٧٨٩٠');

        $this->assertSame('01234567890', $out['national_id']['value']);
    }

    /** @test */
    public function an_ambiguous_date_is_refused_not_guessed(): void
    {
        // 03/04/1990 قد تكون الثالث من أبريل أو الرابع من مارس. والخلط يُنتج
        // تاريخاً **صالحاً** لكنّه خطأ — فلا يكشفه فحصُ صحّة، ويمرّ إلى
        // الاعتماد بلا أن ينتبه أحد.
        $out = $this->ex->extract('03/04/1990');

        $this->assertArrayNotHasKey('dates', $out, 'خُمِّن ترتيب يوم/شهر ملتبس');

        // ويومٌ فوق ١٢ يحسم الترتيب قطعاً، فيُقبل.
        $clear = $this->ex->extract('25/04/1990');
        $this->assertSame('1990-04-25', $clear['dates']['birth']['value']);
    }

    /** @test */
    public function labelled_birth_and_expiry_are_kept_apart(): void
    {
        $out = $this->ex->extract(
            'تاريخ الميلاد: 1990-05-14 تاريخ الانتهاء: 2030-05-14',
        );

        $this->assertSame('1990-05-14', $out['dates']['birth']['value']);
        $this->assertSame('2030-05-14', $out['dates']['expiry']['value']);
        $this->assertTrue($out['dates']['birth']['certain']);
    }

    /** @test */
    public function an_unlabelled_name_is_never_extracted(): void
    {
        // استخراجُ الاسم بلا عنوان — بأخذ أطول سطرٍ عربيّ — يلتقط اسم الجهة
        // المُصدِرة أو عنوان البطاقة بثقةٍ عالية.
        $out = $this->ex->extract('الجمهورية اليمنية وزارة الداخلية مصلحة الأحوال المدنية');

        $this->assertArrayNotHasKey('full_name', $out,
            'اختُرع اسمٌ من ترويسة الوثيقة');

        $labelled = $this->ex->extract('الاسم: أحمد محمد صالح');
        $this->assertSame('أحمد محمد صالح', $labelled['full_name']['value']);
    }

    // ── الملاحظات الحتميّة ──────────────────────────────────────────────

    /** @test */
    public function an_expired_document_is_flagged_critical_by_the_calendar_not_by_opinion(): void
    {
        $doc = $this->makeDoc();

        $this->fakeDriverReturning(
            'الاسم: أحمد صالح الرقم الوطني: 01234567890 تاريخ الانتهاء: 2020-01-15',
        );

        $doc = app(KycOcrService::class)->process($doc);

        $codes = array_column($doc->ocr_findings ?? [], 'code');
        $this->assertContains('DOCUMENT_EXPIRED', $codes,
            'وثيقة منتهية مرّت بلا ملاحظة');
    }

    /** @test */
    public function a_name_mismatch_warns_but_never_rejects(): void
    {
        // الأسماء تُكتب بصيغٍ مختلفة وتُنقل حرفيّاً بطرقٍ شتّى — ورفضٌ آليّ
        // هنا يحجب عملاء صادقين.
        $doc = $this->makeDoc();

        $this->fakeDriverReturning('الاسم: خالد عبدالله الرقم الوطني: 01234567890');

        $doc = app(KycOcrService::class)->process($doc);

        $findings = $doc->ocr_findings ?? [];
        $mismatch = collect($findings)->firstWhere('code', 'NAME_MISMATCH');

        $this->assertNotNull($mismatch, 'اختلاف الاسم مرّ بلا تنبيه');
        $this->assertSame('warning', $mismatch['severity'],
            'اختلاف الاسم عُومل كخطأ قاطع — والأسماء تختلف بصيغها');
        $this->assertSame(KycDocument::STATUS_PENDING, $doc->status,
            'رُفض المستند آلياً لاختلافٍ في صيغة الاسم');
    }

    // ── الثقة ───────────────────────────────────────────────────────────

    /** @test */
    public function below_the_confidence_floor_no_field_is_filled_at_all(): void
    {
        // مقلوبٌ عن الحدس: الأسهل أن تُعرَض الحقول مع تحذير. لكنّ الحقل
        // المملوء يسرق انتباه المراجع بدل أن يستدعيه.
        $doc = $this->makeDoc();

        $this->app->bind(OcrDriverInterface::class, fn () => new class implements OcrDriverInterface {
            public function available(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function read(string $p): OcrResult
            {
                return new OcrResult(
                    status: OcrResult::STATUS_SUCCESS,
                    rawText: 'الرقم الوطني: 01234567890',
                    confidence: 35.0,
                    engine: 'fake',
                );
            }
        });

        $doc = app(KycOcrService::class)->process($doc);
        $view = app(KycOcrService::class)->forReviewer($doc);

        $this->assertSame('low_confidence', $doc->ocr_status);
        $this->assertSame([], $view['fields'],
            'مُلئت حقول من قراءةٍ ثقتها دون الحدّ');
        $this->assertNotEmpty($view['raw_text'],
            'حُجب النصّ الخام أيضاً — والمراجع يحتاجه ليقرأ بنفسه');
    }

    /** @test */
    public function a_missing_engine_is_reported_as_a_server_fault_not_a_bad_document(): void
    {
        // الحالتان تبدوان واحدة للمراجع — لا بيانات — وهما مختلفتان تماماً:
        // الأولى تُصلَح بصورةٍ أوضح، والثانية بتثبيت حزمة على الخادم.
        $doc = $this->makeDoc();

        $this->app->bind(OcrDriverInterface::class, fn () => new class implements OcrDriverInterface {
            public function available(): bool { return false; }
            public function name(): string { return 'absent'; }
            public function read(string $p): OcrResult
            {
                return OcrResult::unavailable('المحرّك غير مثبَّت');
            }
        });

        $doc = app(KycOcrService::class)->process($doc);

        $this->assertSame('unavailable', $doc->ocr_status,
            'غيابُ المحرّك سُجّل كفشلٍ في الوثيقة — فتُطلب من العميل صورة أخرى بلا فائدة');
    }

    /** @test */
    public function ocr_failure_never_fails_the_upload(): void
    {
        // العميل رفع وثيقته، والوثيقة محفوظة. وعطلُ محرّكٍ مساعد شأنُنا لا
        // شأنه.
        $this->app->bind(OcrDriverInterface::class, fn () => new class implements OcrDriverInterface {
            public function available(): bool { return true; }
            public function name(): string { return 'boom'; }
            public function read(string $p): OcrResult { throw new \RuntimeException('انفجر'); }
        });

        $doc = app(KycDocumentService::class)->uploadAndRead(
            $this->customer,
            KycDocument::TYPE_ID_FRONT,
            UploadedFile::fake()->image('id.jpg', 600, 400),
        );

        $this->assertSame(KycDocument::STATUS_PENDING, $doc->status);
        $this->assertSame('failed', $doc->ocr_status);
    }

    // ── التخزين والخصوصية ───────────────────────────────────────────────

    /** @test */
    public function extracted_personal_data_is_encrypted_at_rest(): void
    {
        // حفظُ الاسم ورقم الهوية نصّاً صريحاً بجانب ملفٍّ مشفَّر يُبطل تشفير
        // الملفّ: من يقرأ الجدول يحصل على المضمون بلا أن يفكّ شيئاً.
        $doc = $this->makeDoc();
        $this->fakeDriverReturning('الرقم الوطني: 01234567890');

        $doc = app(KycOcrService::class)->process($doc);

        $raw = \Illuminate\Support\Facades\DB::table('kyc_documents')
            ->where('id', $doc->id)->value('ocr_extracted');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('01234567890', $raw,
            'رقم الهوية مخزَّن صريحاً في قاعدة البيانات');

        // ويُقرأ سليماً عبر الخدمة.
        $this->assertSame('01234567890',
            app(KycOcrService::class)->forReviewer($doc)['fields']['national_id']['value']);
    }

    // ── إقرار المراجع ───────────────────────────────────────────────────

    /** @test */
    public function the_reviewer_confirmation_is_stored_apart_from_what_the_engine_said(): void
    {
        // خلطُهما يجعل قراءةَ آلةٍ تصير إقراراً موثّقاً بلا أن يقرّ أحد.
        $doc = $this->makeDoc();
        $this->fakeDriverReturning('الرقم الوطني: 01234567890');
        $doc = app(KycOcrService::class)->process($doc);

        $svc = app(KycOcrService::class);
        $doc = $svc->confirmFields($doc, $this->reviewer, [
            'national_id' => '09999999999',   // المراجع صحّح الرقم
            'full_name' => 'أحمد صالح',
            'expiry_date' => '2032-01-01',
        ]);

        $view = $svc->forReviewer($doc);

        $this->assertSame('01234567890', $view['fields']['national_id']['value'],
            'ضاع ما قاله المحرّك بعد التصحيح — ولا يُعرف ما صُحِّح');
        $this->assertSame('09999999999', $view['verified']['national_id'],
            'لم يُحفظ تصحيح المراجع');
        $this->assertSame($this->reviewer->id, $view['verified']['_confirmed_by']);

        // وتاريخ الانتهاء المُقَرّ يصير تاريخ انتهاء المستند.
        $this->assertSame('2032-01-01', $doc->document_expires_at?->format('Y-m-d'));
    }

    /** @test */
    public function confirming_without_a_national_id_is_refused(): void
    {
        // إقرارُ هويّةٍ بلا رقمها إقرارٌ بصورةٍ لا بشخص.
        $doc = $this->makeDoc();

        $this->expectException(DomainException::class);
        app(KycOcrService::class)->confirmFields($doc, $this->reviewer, ['full_name' => 'أحمد صالح']);
    }

    /** @test */
    public function an_expired_document_cannot_be_confirmed_however_clear_it_is(): void
    {
        $doc = $this->makeDoc();

        $this->expectException(DomainException::class);
        app(KycOcrService::class)->confirmFields($doc, $this->reviewer, [
            'national_id' => '01234567890',
            'expiry_date' => '2020-01-01',
        ]);
    }

    // ── المحرّك الحقيقيّ ────────────────────────────────────────────────

    /** @test */
    public function the_real_tesseract_driver_reads_a_generated_image(): void
    {
        // اختبارٌ على المحرّك الحقيقيّ لا على بديلٍ مزيّف: بدونه تُختبَر
        // أنابيبُ البيانات وحدها ويبقى السؤال «هل يقرأ فعلاً؟» بلا جواب.
        $driver = new TesseractOcrDriver('tesseract', 'eng', 20);

        if (!$driver->available()) {
            $this->markTestSkipped('tesseract غير مثبَّت في هذه البيئة');
        }

        $path = sys_get_temp_dir() . '/amial_ocr_' . uniqid() . '.png';
        $img = imagecreatetruecolor(700, 180);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 5, 20, 40, 'ID Number: 01234567890', imagecolorallocate($img, 0, 0, 0));
        imagestring($img, 5, 20, 90, 'Date of Birth: 25/04/1990', imagecolorallocate($img, 0, 0, 0));
        imagepng($img, $path);
        imagedestroy($img);

        try {
            $result = $driver->read($path);

            $this->assertTrue($result->usable(),
                'لم يقرأ المحرّك صورةً نظيفة: ' . ($result->error ?? ''));
            $this->assertStringContainsString('01234567890', $result->rawText);
            $this->assertGreaterThan(0, $result->confidence,
                'الثقة صفر — لم تُقرأ من ناتج المحرّك');

            // وحقولٌ تُستخرَج من نصٍّ قرأه محرّكٌ حقيقيّ لا من نصٍّ مكتوب باليد.
            $fields = $this->ex->extract($result->rawText);
            $this->assertSame('01234567890', $fields['national_id']['value'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    // ── مساعدات ─────────────────────────────────────────────────────────

    private function makeDoc(): KycDocument
    {
        return app(KycDocumentService::class)->upload(
            $this->customer,
            KycDocument::TYPE_ID_FRONT,
            UploadedFile::fake()->image('id.jpg', 600, 400),
        );
    }

    private function fakeDriverReturning(string $text, float $confidence = 92.0): void
    {
        $this->app->bind(OcrDriverInterface::class, fn () => new class ($text, $confidence) implements OcrDriverInterface {
            public function __construct(private string $t, private float $c) {}
            public function available(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function read(string $p): OcrResult
            {
                return new OcrResult(OcrResult::STATUS_SUCCESS, $this->t, $this->c, 'fake');
            }
        });
    }
}
