<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\RegistrationDossier;
use App\Models\User;
use App\Services\Admin\AccountDossierPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-ACCOUNT-PRINT-001 — **الأرشيفُ مبنيٌّ، ولا زرَّ يطبعه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قال صاحبُ المشروع: «لو أرادت الإدارةُ طباعةَ معلومات التسجيل للحساب
 * **مع صور الوثائق** لا يوجد زر — مع أنّه تمّ تأسيسُ الأرشيف سابقاً».
 *
 * وقِيس، فكانتا اثنتين وثالثةٌ ظهرت في الطريق:
 *
 *   ① `registration-dossiers/{reference}/pdf` موجودٌ ويعمل، **وبابُه
 *      الوحيد سجلٌّ آخر**. والجدولُ في نافذة الحساب يطبع المرجعَ نصّاً
 *      لا رابطاً، وتحته سطرٌ يقول «تُفتح الطباعة من ملفات التسجيل» —
 *      **إرشادٌ إلى مكانٍ آخر بدل زرّ**.
 *   ② وليس في المطبوع صورةُ وثيقةٍ واحدة — حقولٌ نصّيّةٌ فقط.
 *   ③ **وحسابٌ بلا لقطةٍ مؤرشفةٍ لا يُطبَع أصلاً** — والطباعةُ بالمرجع
 *      لا تجد شيئاً. فصار المفتاحُ الحسابَ لا المرجع.
 */
class AccountDossierPrintGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function account(int $type = CUSTOMER_TYPE): User
    {
        return User::factory()->create([
            'type' => $type, 'is_active' => 1,
            'f_name' => 'سالم', 'l_name' => 'المقطري',
            'zone_code' => 'SOUTH', 'residence_governorate' => 'عدن',
            'identification_number' => '0102030'.(++$this->seq),
        ]);
    }

    private function doc(User $u, string $type): KycDocument
    {
        return KycDocument::create([
            'user_id' => $u->id, 'doc_type' => $type,
            'status' => KycDocument::STATUS_APPROVED,
            'encrypted_path' => 'kyc/'.Str::random(10).'.enc',
            'size_bytes' => 2048, 'ocr_status' => 'not_run',
            'original_mime' => 'image/jpeg',
            'content_sha256' => hash('sha256', Str::random(20)),
        ]);
    }

    private function service(): AccountDossierPrintService
    {
        return app(AccountDossierPrintService::class);
    }

    /** @test */
    public function the_paper_is_keyed_by_the_account_not_by_an_archive_reference(): void
    {
        // **③ وهذا ما كان يمنع الطباعةَ أصلاً**: حسابٌ فُتح قبل الأرشيف
        // — أو من مسارٍ لا يؤرشف — لا مرجعَ له، فلا شيءَ يُطبَع.
        $u = $this->account();

        $this->assertSame(0, RegistrationDossier::where('subject_user_id', $u->id)->count(),
            'الحسابُ يجب أن يكون بلا لقطةٍ في هذه الحالة');

        $data = $this->service()->data($u);

        $this->assertNull($data['dossier']);
        $this->assertNotEmpty($data['identity'],
            'حسابٌ بلا لقطةٍ أخرج ورقةً فارغة — والهويّةُ في `users` دائماً');

        $printed = implode(' ', array_map(fn ($r) => $r[0].' '.$r[1], $data['identity']));
        $this->assertStringContainsString('سالم', $printed);
    }

    /** @test */
    public function the_absence_of_an_archived_snapshot_is_said_not_left_blank(): void
    {
        // **الغيابُ يُقال ولا يُقرأ نقصَ بيانات** (القاعدة السابعة) —
        // وورقةٌ ينقصها قسمٌ بلا ذكرٍ تُقرأ «العميلُ لم يُدخل شيئاً».
        $u = $this->account();

        $html = view('pdf.account-dossier', $this->service()->data($u))->render();

        $this->assertStringContainsString('لا لقطةَ تسجيلٍ مؤرشفةٌ لهذا الحساب', $html,
            'قسمُ التسجيل غاب صامتاً — فيُقرأ نقصَ بياناتٍ لا غيابَ أرشيف');
    }

    /** @test */
    public function the_archived_snapshot_is_printed_when_it_exists(): void
    {
        $u = $this->account();

        RegistrationDossier::create([
            'reference' => (string) Str::ulid(),
            'subject_type' => 'customer', 'subject_user_id' => $u->id,
            'source' => 'self_registration', 'state' => RegistrationDossier::SUBMITTED,
            'phone_hash' => hash('sha256', (string) $u->phone),
            'payload_encrypted' => ['occupation' => 'صيدلانيّ', 'store_name' => 'محطة النور'],
        ]);

        $html = view('pdf.account-dossier', $this->service()->data($u->fresh()))->render();

        $this->assertStringContainsString('صيدلانيّ', $html,
            'اللقطةُ موجودةٌ ولم تُطبَع حقولُها');
        $this->assertStringContainsString('محطة النور', $html);
        $this->assertStringNotContainsString('لا لقطةَ تسجيلٍ مؤرشفةٌ', $html);
    }

    /** @test */
    public function the_document_images_are_embedded_in_the_page_itself(): void
    {
        // **② الشقُّ الثاني من طلبه: «مع صور الوثائق».** ورابطٌ لا يكفي:
        // PDF يُطبَع اليومَ ويُقرأ في ملفٍّ ورقيٍّ بعد سنةٍ بلا جلسةِ
        // إدارةٍ حيّة — فصورُه مربّعاتٌ فارغة.
        $u = $this->account();
        $doc = $this->doc($u, KycDocument::TYPE_ID_FRONT);

        $this->mock(\App\Services\KycDocumentService::class, function ($m) {
            $m->shouldReceive('decrypt')->andReturn('BINARY-IMAGE-BYTES');
            $m->shouldReceive('completenessFor')->andReturn([
                'complete' => false, 'required' => [], 'approved' => [],
                'missing' => [], 'missing_fields' => [],
            ]);
        });

        $data = $this->service()->data($u);

        $this->assertCount(1, $data['images']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $data['images'][0]['data_uri'],
            'الصورةُ لم تُضمَّن في الورقة — ورابطٌ في PDF يحتاج جلسةً حيّةً ليُفتَح');
        $this->assertSame(
            base64_encode('BINARY-IMAGE-BYTES'),
            substr((string) $data['images'][0]['data_uri'], strlen('data:image/jpeg;base64,')));

        $this->assertNotSame($doc->encrypted_path, $data['images'][0]['data_uri'] ?? null);
    }

    /** @test */
    public function a_document_that_cannot_be_decrypted_is_said_and_never_swallowed(): void
    {
        // **صفحةٌ ينقصها مستندٌ بلا ذكرٍ تُقرأ «لم يُرفَع»** — والفرقُ
        // بينهما تحقيقٌ كامل.
        $u = $this->account();
        $this->doc($u, KycDocument::TYPE_ID_FRONT);

        $this->mock(\App\Services\KycDocumentService::class, function ($m) {
            $m->shouldReceive('decrypt')->andThrow(new \RuntimeException('لا ملفّ'));
            $m->shouldReceive('completenessFor')->andReturn([
                'complete' => false, 'required' => [], 'approved' => [],
                'missing' => [], 'missing_fields' => [],
            ]);
        });

        $data = $this->service()->data($u);

        $this->assertNull($data['images'][0]['data_uri']);
        $this->assertStringContainsString('تعذّر فكُّ تشفير', (string) $data['images'][0]['note'],
            'ابتُلع الفشلُ فظهر مربّعٌ فارغٌ بلا سبب');
    }

    /** @test */
    public function an_account_with_no_document_at_all_says_so(): void
    {
        $u = $this->account();

        $html = view('pdf.account-dossier', $this->service()->data($u))->render();

        $this->assertStringContainsString('لا وثيقةَ واحدةً مرفوعةً لهذا الحساب', $html);
    }

    /** @test */
    public function the_print_route_is_behind_the_same_permission_as_viewing_the_documents(): void
    {
        // **ووضعُها خلف صلاحيّةٍ أدنى بابٌ جانبيٌّ إلى صورِ الهويّة**
        // لمن لا يملك رؤيتَها في لوحة التحقّق.
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->getName() === 'admin.amial.hub.account.print');

        $this->assertNotNull($route, 'لا مسارَ طباعةٍ للحساب إطلاقاً');

        $this->assertContains('platform:platform.customers.kyc.view',
            $route->gatherMiddleware(),
            'الطباعةُ تحمل صورَ الهويّة، وصلاحيّتُها أدنى من صلاحيّة رؤيتها '
            .'في اللوحة — فهي بابٌ جانبيّ');
    }

    /** @test */
    public function the_paper_is_never_cached_to_disk_because_it_carries_id_images(): void
    {
        // **حارسٌ قائمٌ أمسك هذا البناء، وكان محقّاً**: `PdfSurfaceGuardTest`
        // يشترط أن تمرّ كلُّ نقطة PDF على `PdfCacheService`. **والجوابُ هنا
        // ليس الامتثال**: الطبقةُ تكتب الـPDF خاماً في
        // `storage/app/documents`، وهذه الورقةُ تحمل صورَ هويّةٍ مفكوكةَ
        // التشفير — فتمريرُها ينسخ كلَّ بطاقةٍ إلى ملفٍّ غيرِ مشفَّرٍ يبقى،
        // **ويُلغي ما بُني `encrypted_path` كلُّه من أجله**.
        //
        // فالاستثناءُ مكتوبٌ هناك بسببه، **وهذا يمنع أن يُعاد بحسن نيّةٍ**
        // يومَ يقرأ أحدٌ «نقطةٌ بلا تخزين» فيراها نقصاً لا قراراً.
        $src = file_get_contents(app_path(
            'Http/Controllers/Admin/AccountDossierPrintController.php'));

        $this->assertStringNotContainsString('PdfCacheService', (string) $src,
            'مُرّرت ورقةُ الحساب على طبقة التخزين — فصارت صورُ الهويّة '
            .'مفكوكةَ التشفير تُكتَب خاماً على القرص وتبقى');

        $this->assertStringNotContainsString('remember(', (string) $src,
            'خُزّنت الورقةُ بطريقٍ آخر — والعلّةُ أنّ النسخةَ تُكتَب أصلاً، '
            .'لا في اسم الطبقة');
    }

    /** @test */
    public function the_button_exists_where_the_reviewer_actually_stands(): void
    {
        // **① وهذا هو العطل**: مبنيٌّ ولا يُوصَل إليه من الحساب.
        // (القاعدة الثانية عشرة — المسارُ المسجَّل ليس ظهوراً.)
        foreach ([
            'admin-views/amial/hub/verification.blade.php',
            'admin-views/amial/customer/index.blade.php',
        ] as $view) {
            $src = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString('/print', $src,
                "لا زرَّ طباعةٍ في {$view} — والمراجعُ يقف هنا لا في سجلٍّ آخر");
            $this->assertStringContainsString('طباعة ملفّ الحساب', $src,
                "الرابطُ موجودٌ بلا نصٍّ يُقرأ في {$view}");
        }

        // **والإرشادُ القديمُ إلى مكانٍ آخرَ يُرفَع** — وإلّا بقي يقول
        // للمراجع اخرجْ وابحث، بجوار زرٍّ يفعلها مكانه.
        $customer = file_get_contents(
            resource_path('views/admin-views/amial/customer/index.blade.php'));

        $this->assertStringNotContainsString(
            'تُفتح النسخة الكاملة والطباعة من «ملفات التسجيل والأرشفة»', $customer,
            'بقي الإرشادُ إلى سجلٍّ آخرَ بجوار الزرّ — فيُقرأ أنّ الزرَّ ناقص');
    }
}
