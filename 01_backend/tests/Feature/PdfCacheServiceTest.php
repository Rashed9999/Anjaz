<?php

namespace Tests\Feature;

use App\Services\PdfCacheService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-PDF-CACHE-002 — الطبقة التي تمنع إعادة تصيير كل مستند في كل طلب.
 *
 * **لماذا وُجدت:** فشل تنزيل الإيصال بـ «Connection closed while receiving
 * data» كان سببه تصييراً كاملاً داخل الطلب. وحين فُحص المسار وحده تبيّن أن
 * فواتير الجملة وكشوف الآجل وإيصالات الوقود تفعل الشيء نفسه — بل أسوأ: بلا
 * أي تخزين، ومع `no-store` فلا يحتفظ العميل بنسخة أيضاً.
 *
 * والاختبارات هنا تفحص ما يجعل ذاكرةً مخبّأة **صحيحة** لا سريعة فحسب:
 * أن تُخدَم النسخة الصحيحة، وأن يُبطلها التغيير، وأن يبقى المستند يصل حتى
 * لو تعذّر التخزين.
 */
class PdfCacheServiceTest extends TestCase
{
    private PdfCacheService $cache;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cache = new PdfCacheService();
    }

    private const PDF = "%PDF-1.4\n" . 'x';

    private function body(string $marker): string
    {
        return self::PDF . str_repeat($marker, 200);
    }

    public function test_it_renders_once_and_serves_the_file_after(): void
    {
        $calls = 0;
        $render = function () use (&$calls) {
            $calls++;
            return $this->body('a');
        };

        $first = $this->cache->remember('inv_1', $render);
        $second = $this->cache->remember('inv_1', $render);
        $third = $this->cache->remember('inv_1', $render);

        $this->assertSame(1, $calls, 'أُعيد التصيير — وهذا هو العطل بعينه');
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    /**
     * مفتاح مختلف = مستند مختلف. وهذا ما يجعل تعديل فاتورة يُنتج نسخة جديدة،
     * إذ يدخل `updated_at` في المفتاح.
     */
    public function test_a_changed_key_produces_a_fresh_document(): void
    {
        $old = $this->cache->remember('inv_2_100', fn () => $this->body('o'));
        $new = $this->cache->remember('inv_2_200', fn () => $this->body('n'));

        $this->assertNotSame($old, $new,
            'المفتاح تغيّر والمخرجات لم تتغيّر — ستُخدَم أرقام قديمة بثقة');
    }

    /**
     * ملفّ مبتور من تصيير سابق انقطع لا يُخدَم.
     *
     * هذه أخبث حالة: خدمتُه تُنتج نفس عطل «الاتصال انقطع» الذي نعالجه، لكن
     * بلا سبب ظاهر هذه المرّة — فالخادم سريع والملفّ موجود.
     */
    public function test_a_truncated_cached_file_is_regenerated(): void
    {
        $this->cache->remember('inv_3', fn () => $this->body('x'));

        // نُفسد الملفّ كما لو انقطع تصيير سابق في منتصفه
        $path = collect(Storage::disk('local')->files('documents'))->first();
        Storage::disk('local')->put($path, '%PDF-1.4 مبتور');

        $again = $this->cache->remember('inv_3', fn () => $this->body('y'));

        $this->assertGreaterThan(100, strlen($again), 'خُدم ملفّ مبتور');
        $this->assertStringStartsWith('%PDF', $again);
    }

    /** ملفّ لا يبدأ بترويسة PDF ليس PDF مهما كان حجمه. */
    public function test_a_non_pdf_cached_file_is_regenerated(): void
    {
        $this->cache->remember('inv_4', fn () => $this->body('x'));

        $path = collect(Storage::disk('local')->files('documents'))->first();
        Storage::disk('local')->put($path, str_repeat('صفحة خطأ HTML', 100));

        $again = $this->cache->remember('inv_4', fn () => $this->body('y'));

        $this->assertStringStartsWith('%PDF', $again);
    }

    /** إبطال صريح لمستند لا يحمل ختم تعديل. */
    public function test_forget_drops_the_stored_copy(): void
    {
        $calls = 0;
        $render = function () use (&$calls) {
            $calls++;
            return $this->body('a');
        };

        $this->cache->remember('inv_5', $render);
        $this->cache->forget('inv_5');
        $this->cache->remember('inv_5', $render);

        $this->assertSame(2, $calls, 'لم يُبطَل المحفوظ');
    }

    /**
     * المفاتيح تحوي عربية ومسافات وقد تحوي `..` — والاسم يجب أن يبقى آمناً
     * ومميِّزاً معاً.
     */
    public function test_keys_with_arabic_or_traversal_stay_safe_and_distinct(): void
    {
        $a = $this->cache->remember('كشف_حساب/../../etc/passwd', fn () => $this->body('a'));
        $b = $this->cache->remember('كشف_حساب محمد ٢٠٢٦', fn () => $this->body('b'));

        $this->assertNotSame($a, $b, 'مفتاحان مختلفان أنتجا الملفّ نفسه');

        foreach (Storage::disk('local')->files('documents') as $f) {
            $this->assertStringNotContainsString('..', $f, 'اسم الملفّ يسمح بالخروج من المجلّد');
        }
        $this->assertCount(2, Storage::disk('local')->files('documents'));
    }

    /**
     * تعذّر التخزين لا يمنع وصول المستند.
     *
     * إسقاط تنزيل ناجح لأن القرص ممتلئ خسارةٌ بلا مقابل — التخزين تسريعٌ
     * لا شرطُ صحّة.
     */
    public function test_the_document_is_returned_even_if_it_cannot_be_stored(): void
    {
        // عائق حقيقي بدل محاكاة: ملفّ يحمل اسم المجلّد، فلا يمكن إنشاؤه ولا
        // الكتابة داخله. وهو أقرب إلى ما يقع فعلاً (قرص ممتلئ، صلاحيات) من
        // اعتراض الواجهة — وأصدق دلالةً لأن المسار المفحوص هو المسار الحقيقي.
        Storage::disk('local')->put('documents', 'يمنع إنشاء المجلّد');

        $bytes = $this->cache->remember('inv_6', fn () => $this->body('a'));

        $this->assertStringStartsWith('%PDF', $bytes,
            'أُسقط تنزيل ناجح لأن التخزين تعذّر — والتخزين تسريعٌ لا شرط');
        $this->assertGreaterThan(100, strlen($bytes));
    }
}
