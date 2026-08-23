<?php

namespace Tests\Feature;

use App\CentralLogics\Helpers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-UPLOAD-BYTES-001 · AMIAL-CACHE-NAME-DRIFT-001
 *
 * **عطلان من صنفٍ واحد: مكتوبٌ ويُقرأ عاملاً ولا يعمل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **أخرجهما مسحُ Snyk — لا البوّابةُ ولا ٣٠٩٣ اختباراً.** والمسحُ أخرج
 * ١٦٨ نتيجةً، وأعلاها ثلاثٌ: XSS و«اجتيازُ مسار» وSSRF.
 *
 * **والأولى إيجابيّةٌ كاذبة**: `scripts/journey.php` سكربتُ طرفيّةٍ لا
 * مسارَ له، و`echo` فيه يكتب في الطرفيّة لا في متصفّح.
 *
 * **والثانيةُ والثالثةُ كاذبتان أيضاً — ولكن بفارقٍ يهمّ.** قيل أوّلَ مرّة
 * إنّهما مستغَلَّتان، **ونقضَ القياسُ ذلك**:
 *
 *     Helpers::file_uploader("probe/", "png", "/etc/hostname")
 *     →  TypeError: Argument #3 must be of type ?object, string given
 *
 * فتحليلُ التدفّق عند Snyk لم يقرأ تصريحَ النوع. **والحمايةُ كانت تصريحَ
 * نوعٍ لا تنقية** — وهي تسقط مع أوّل من يوسّعه بحسن نيّةٍ ليُصلح العطل
 * أدناه. ولذلك نُقل الفحصُ إلى المحتوى.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقياسُ أخرج ما هو أصدقُ من الثغرة:** المُنادُون الثلاثةُ كلُّهم
 * يمرّرون **سلسلة** — التطبيقُ يرسل الشعارَ base64. فـ`TypeError` يقع
 * **في كلّ مرّة**، و`catch (\Throwable)` يبتلعه ويردّ «تعذّر رفع الشعار».
 *
 * **أي أنّ رفعَ شعار التاجر لم يعمل مرّةً واحدةً منذ بُني**، والفاتورةُ
 * المطبوعةُ بلا شعارٍ أبداً، ولا خطأَ في أيّ سجلّ. (القاعدةُ التاسعة.)
 */
class UploadBytesAndCacheStoreGuardTest extends TestCase
{
    use RefreshDatabase;

    /** أصغرُ PNG صالحةٍ ممكنة — بكسلٌ واحدٌ شفّاف. */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function cleanup(string $name): void
    {
        @unlink(storage_path('app/public/probe/' . $name));
    }

    // ══════════════════════════════════════════════════════════════════
    // ما يجب أن يعمل — وهو ما لم يكن يعمل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_base64_logo_from_the_app_is_actually_stored(): void
    {
        // **هذا هو العطلُ بعينه، مُصاغاً اختباراً.** التطبيقُ يرسل base64،
        // والخادمُ كان يردّ خطأً ثابتاً.
        $name = Helpers::file_uploader('probe/', 'png', self::PNG_1PX);

        $this->assertNotSame('def.png', $name, 'رُدَّت الصورةُ الافتراضيّة — أي لم يُرفَع شيء');
        $this->assertFileExists(storage_path('app/public/probe/' . $name));

        $this->cleanup($name);
    }

    /** @test */
    public function a_data_uri_is_accepted_too(): void
    {
        // **والصيغتان تصلان من الواقع**: بعضُ العملاء يرسل الترويسةَ
        // `data:image/png;base64,` وبعضُهم ينزعها. ومن قَبِل واحدةً وحدَها
        // يعمل على نصف الأجهزة.
        $name = Helpers::file_uploader('probe/', 'png', 'data:image/png;base64,' . self::PNG_1PX);

        $this->assertFileExists(storage_path('app/public/probe/' . $name));
        $this->cleanup($name);
    }

    /** @test */
    public function a_real_uploaded_file_still_works(): void
    {
        // **ولا يُكسَر المسارُ الذي كان يعمل.** التسجيلُ من الويب يرسل
        // ملفّاً حقيقيّاً، وتوسيعُ النوع لا يجوز أن يُسقطه.
        $file = \Illuminate\Http\UploadedFile::fake()->image('id.png', 8, 8);

        $name = Helpers::file_uploader('probe/', 'png', $file);

        $this->assertFileExists(storage_path('app/public/probe/' . $name));
        $this->cleanup($name);
    }

    // ══════════════════════════════════════════════════════════════════
    // ما يجب ألّا يعمل — والحمايةُ الآن في المحتوى لا في تصريح النوع
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     * @dataProvider hostilePaths
     */
    public function a_path_or_url_is_never_opened(string $label, string $payload): void
    {
        // ══════════════════════════════════════════════════════════════
        // **لو قُبلت السلسلةُ إلى `file_get_contents` لصارت مساراً يفتحه
        // الخادم**: `../../.env` يقرأ `APP_KEY` و`DB_PASSWORD` ومفاتيحَ
        // تشفير بيانات العملاء، و`http://` يخرج بطلبٍ من داخل الشبكة —
        // **ثمّ يُنشَر الناتجُ صورةً علنيّة**. ومسارُ التسجيل عامٌّ بلا
        // مصادقة.
        //
        // ولا يكفي أنّ `?object` يمنعه اليوم: **من وسّع النوعَ ليُصلح
        // رفعَ الشعار فتحه**. فالفحصُ على المحتوى، لا على الإعلان.
        // ══════════════════════════════════════════════════════════════
        $this->expectException(\InvalidArgumentException::class);

        Helpers::file_uploader('probe/', 'png', $payload);
    }

    public static function hostilePaths(): array
    {
        return [
            'مسارٌ مطلق' => ['abs', '/etc/hostname'],
            'صعودٌ نسبيّ' => ['rel', '../../.env'],
            'عنوانٌ شبكيّ' => ['http', 'http://127.0.0.1:1/x'],
            'وسيطُ ملفّ' => ['wrapper', 'file:///etc/hostname'],
        ];
    }

    /** @test */
    public function decoded_bytes_that_are_not_an_image_are_refused(): void
    {
        // **وbase64 صالحةُ الترميز ليست صورةً بالضرورة.** بلا فحصِ
        // المحتوى يُرفَع PHP مُرمَّزٌ بامتداد png **ويُخدَم من مجلَّدٍ
        // عامّ** — وهو تنفيذُ شيفرةٍ عن بُعد، لا رفعُ ملفٍّ فحسب.
        $this->expectException(\InvalidArgumentException::class);

        Helpers::file_uploader('probe/', 'png', base64_encode('<?php system($_GET[0]); ?>'));
    }

    /** @test */
    public function a_refused_upload_does_not_delete_the_old_image(): void
    {
        // **ورفضٌ يمحو ما كان أسوأ من رفض.** الحذفُ كان يسبق القراءةَ في
        // الترتيب القديم، فمُدخَلٌ فاسدٌ يُفقد الشعارَ القائم ولا يضع بديلاً.
        \Illuminate\Support\Facades\Storage::disk('public')->put('probe/old.png', 'x');

        try {
            Helpers::file_uploader('probe/', 'png', '/etc/hostname', 'old.png');
            $this->fail('قُبل مسارُ ملفّ');
        } catch (\InvalidArgumentException) {
            // متوقَّع
        }

        $this->assertTrue(
            \Illuminate\Support\Facades\Storage::disk('public')->exists('probe/old.png'),
            'حُذفت الصورةُ القديمة رغم رفض الجديدة');

        \Illuminate\Support\Facades\Storage::disk('public')->delete('probe/old.png');
    }

    // ══════════════════════════════════════════════════════════════════
    // الذاكرة — اسمٌ يُكتب ولا يُقرأ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_cache_config_reads_the_name_the_env_actually_writes(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ما قِيس:** `.env` يقول `CACHE_STORE=database`، والإعدادُ كان
        // يقرأ `CACHE_DRIVER` — وهي `NULL` — فيُحَلّ **file**.
        //
        // والذاكرةُ هنا ليست تسريعاً بل حاملَ الحالة: عدّاداتُ حدّ
        // المعدّل، وسلّمُ قفل الدخول (`auth_locked:`)، وقفلُ رمز
        // العمليّات. وملفَّاتُها **داخل الحاوية**، والحاويةُ تُزال في كلّ
        // نشرة — فكلُّ قفلٍ يُمحى، **ومهاجمٌ بلغ الرابعةَ يعود من الصفر**.
        // ══════════════════════════════════════════════════════════════
        $src = (string) file_get_contents(config_path('cache.php'));
        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? '';
        $src = preg_replace('~^[ \t]*//[^\n]*$~m', '', $src) ?? '';

        $this->assertStringContainsString("env('CACHE_STORE'", $src,
            'الإعدادُ لا يقرأ `CACHE_STORE` — وهو الاسمُ الذي يكتبه `.env`، '
            . 'فيُحَلّ الافتراضُ ويُقرأ المكتوبُ عاملاً وليس');

        $this->assertDoesNotMatchRegularExpression("~'default'\s*=>\s*env\('CACHE_DRIVER'~", $src,
            'الاسمُ القديم وحدَه — والبيئةُ تكتب الجديد');
    }

    /** @test */
    public function the_store_production_would_resolve_is_not_the_ephemeral_file_driver(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ولا يُقاس هذا من `app('cache')`.**
        //
        // كُتب أوّلَ مرّةٍ كذلك — و`phpunit.xml` يضبط `CACHE_DRIVER=array`،
        // فالمخزنُ في الاختبار `ArrayStore` **مهما كان الإعداد**. فمرّ
        // القلبُ (ردُّ الإعداد إلى `CACHE_DRIVER`) والحارسُ أخضر.
        //
        // **وحارسٌ لا يمكن أن يسقط ليس حارساً** — هو سطرٌ يُطمئن.
        //
        // فيُحسَب ما يُحَلُّ في الإنتاج: قيمُ `.env` الحقيقيّة مطبَّقةً على
        // سلسلة الاحتياط التي يكتبها الإعداد نفسُه.
        // ══════════════════════════════════════════════════════════════
        $envPath = file_exists(base_path('.env')) ? base_path('.env') : base_path('.env.example');
        $env = (string) file_get_contents($envPath);

        $read = function (string $key) use ($env): ?string {
            return preg_match('~^' . $key . '=(.*)$~m', $env, $m)
                ? trim($m[1], " \t\"'")
                : null;
        };

        // السلسلةُ نفسُها التي في `config/cache.php`، مقروءةً من الملفّ
        // لا مكتوبةً هنا — فلو تغيّرت هناك سقط هذا معها.
        $cfg = (string) file_get_contents(config_path('cache.php'));
        preg_match("~'default'\s*=>\s*(env\(.+?\)),\s*$~m", $cfg, $chain);

        $this->assertNotEmpty($chain, 'تعذّرت قراءةُ سلسلة الاحتياط من الإعداد');

        $resolved = null;

        foreach (['CACHE_STORE', 'CACHE_DRIVER'] as $key) {
            if (! str_contains($chain[1], "'{$key}'")) {
                continue;
            }
            if (($v = $read($key)) !== null && $v !== '') {
                $resolved = $v;
                break;
            }
        }

        // الافتراضُ الأخير في السلسلة إن لم تُضبَط بيئةٌ يقرؤها الإعداد.
        if ($resolved === null && preg_match("~'([a-z]+)'\s*\)\s*\)?$~", $chain[1], $d)) {
            $resolved = $d[1];
        }

        $this->assertNotSame('file', $resolved,
            "ما يُحَلُّ في الإنتاج هو «{$resolved}» — والذاكرةُ على الملفَّات "
            . 'داخل الحاوية، تُمحى مع كلّ نشرة، ومعها كلُّ قفلِ دخولٍ وكلُّ عدّادِ حدّ');

        $this->assertNotNull($resolved, 'تعذّر تحديدُ مخزن الإنتاج');
    }

    /** @test */
    public function the_env_example_documents_the_name_the_config_reads(): void
    {
        // **ونموذجُ البيئة يُنسَخ يدويّاً إلى الخادم** — فاسمٌ فيه لا
        // يقرؤه الإعدادُ يُعيد العطلَ في أوّل نشرةٍ على خادمٍ جديد.
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('~^CACHE_STORE=~m', $env,
            '`.env.example` لا يذكر `CACHE_STORE` — فخادمٌ جديدٌ يُضبط على اسمٍ لا يُقرأ');
    }
}
