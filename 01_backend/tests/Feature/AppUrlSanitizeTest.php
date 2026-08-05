<?php

namespace Tests\Feature;

use App\Support\AppUrl;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * AMIAL-APP-URL-SANITIZE-001 — محرفٌ واحدٌ في `APP_URL` لا يُسقط بناءً بصمت.
 *
 * العطل وسلسلتُه ومصفوفة القياس في `app/Support/AppUrl.php`.
 *
 * **والاختبار هنا يفحص عند الطرف الذي انهار فعلاً** — `Request::create`
 * و`SetRequestForConsole` — لا صياغة السطر في `config/app.php`. فاختبارٌ
 * يقرأ نصّ ملفّ الإعدادات يمرّ وإن كُتب التنظيف خطأً.
 */
class AppUrlSanitizeTest extends TestCase
{
    /**
     * @test
     *
     * **القيم التي أسقطت البناء تمرّ بعد التنظيف — ومن حيث انهارت.**
     */
    public function values_that_killed_the_build_now_pass(): void
    {
        $killers = [
            'APP_URL=https://amialpay.com'  => 'السطر كلّه في حقل القيمة (الأرجح)',
            'APP_URL = https://amialpay.com' => 'السطر كلّه بمسافات',
            ' https://amialpay.com'         => 'مسافة في البداية',
            'https://amialpay.com '         => 'مسافة في النهاية',
            "https://amialpay.com\n"        => 'سطرٌ جديد لاصق',
            '"https://amialpay.com"'        => 'علامتا تنصيص',
            "'https://amialpay.com'"        => 'فاصلتان عُلويّتان',
            'https://amialpay.com/'         => 'شرطة مائلة أخيرة',
        ];

        foreach ($killers as $raw => $label) {
            $clean = AppUrl::resolve($raw);

            $this->assertSame('https://amialpay.com', $clean, "لم يُنظَّف: {$label}");

            // الفحص الحقيقيّ: لا استثناء من حيث جاء.
            SymfonyRequest::create($clean, 'GET');
        }

        $this->assertTrue(true);
    }

    /**
     * @test
     *
     * **وبالعكس: الخامَ كان يسقط فعلاً.**
     *
     * بلا هذا لا يُعرف أنّ التنظيف هو ما أصلح — قد يكون الاختبار أعلاه
     * يمرّ لأنّ Symfony لا يرفض شيئاً أصلاً. (القاعدة الثانية.)
     */
    public function the_raw_values_really_did_throw(): void
    {
        $thrown = 0;

        foreach (['APP_URL=https://amialpay.com', ' https://amialpay.com'] as $raw) {
            try {
                SymfonyRequest::create($raw, 'GET');
            } catch (\Throwable $e) {
                $thrown++;
                $this->assertStringContainsString('Scheme is malformed', $e->getMessage());
            }
        }

        $this->assertSame(2, $thrown, 'قيمةٌ ظننتُها مُسقِطةً لا تُسقط — القياس خطأ لا الشيفرة');
    }

    /**
     * @test
     *
     * **علامتا التنصيص وحدهما لم تكونا سبب سقوط النشر.**
     *
     * وقد ظننتُهما السبب أوّلاً. وSymfony ترفضهما فعلاً — لكنّهما **لا تصلان
     * إليها**: `Env::get` يجرّد تنصيصاً مُحيطاً متطابقاً قبل أن يُبنى شيء.
     *
     * والفرق ليس تفصيلاً: من شخّص التنصيص سيحذفها من لوحة النشر ويُعيد
     * النشر — فيسقط ثانيةً ولا يفهم لماذا. فتُثبَّت الحقيقة هنا: الطبقة
     * التي تحمي، لا التي ترفض.
     */
    public function quotes_are_stripped_by_laravel_before_symfony_ever_sees_them(): void
    {
        // ١) Symfony ترفضها لو وصلتها:
        try {
            SymfonyRequest::create('"https://amialpay.com"', 'GET');
            $this->fail('Symfony قبِلت التنصيص — الفرضيّة كلّها تنهار');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Scheme is malformed', $e->getMessage());
        }

        // ٢) ولكنّها لا تصلها: Env::get يجرّدها.
        $key = 'AMIAL_TEST_URL_' . bin2hex(random_bytes(4));
        putenv("{$key}=\"https://amialpay.com\"");

        try {
            $this->assertSame('https://amialpay.com', \Illuminate\Support\Env::get($key),
                'Env::get لم يجرّد التنصيص — فالتشخيص الأوّل كان صحيحاً بعد كلّ شيء');
        } finally {
            putenv($key);
        }
    }

    /**
     * @test
     *
     * **ولا يُخمَّن ما وراء ذلك: القيمة الفاسدة تُوقف البناء برسالةٍ مفهومة.**
     *
     * وتصحيحُها سرّاً أسوأ من الفشل: تُبنى روابط الصور والإيصالات إلى
     * مضيفٍ آخر فتظهر مكسورةً **بلا خطأٍ في أيّ سجلّ**.
     */
    public function a_genuinely_broken_value_stops_the_build_with_a_readable_message(): void
    {
        foreach (['amialpay.com', 'https:/amialpay.com', 'https//amialpay.com', 'ftp://amialpay.com'] as $raw) {
            try {
                AppUrl::resolve($raw);
                $this->fail("قيمةٌ فاسدة مرّت: [{$raw}]");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('APP_URL غير صالح', $e->getMessage());
                $this->assertStringContainsString($raw, $e->getMessage(),
                    'الرسالة لا تعرض القيمة نفسها — فتبقى غامضةً كسابقتها');
                $this->assertStringContainsString('https://amialpay.com', $e->getMessage(),
                    'الرسالة لا تقول ما المتوقَّع');
            }
        }
    }

    /**
     * @test
     *
     * **وغيابُ المتغيّر ليس خطأ ضبط** — بل غيابُه. فيُستعمل الافتراضيّ ولا
     * يُوقَف البناء (تشغيلٌ محلّيّ بلا `.env` مثلاً).
     */
    public function an_absent_value_falls_back_instead_of_stopping_the_build(): void
    {
        foreach ([null, '', '   ', '""', "''", '/'] as $raw) {
            $this->assertSame(AppUrl::FALLBACK, AppUrl::resolve($raw),
                'غيابُ القيمة أوقف البناء بدل أن يقع على الافتراضيّ');
        }
    }

    /**
     * @test
     *
     * **والمُهيّئ الذي انهار يعمل على ما تُنتجه الإعدادات فعلاً.**
     *
     * القاعدة الرابعة: يُختبَر من المدخل الذي سلكه المستعمل — وهو
     * `SetRequestForConsole` أثناء `artisan package:discover`، لا استدعاءٌ
     * مباشر لـ`Request::create`.
     */
    public function the_console_bootstrapper_survives_a_pasted_env_line(): void
    {
        $original = config('app.url');

        try {
            config(['app.url' => AppUrl::resolve('APP_URL=https://amialpay.com')]);

            (new SetRequestForConsole)->bootstrap($this->app);

            $this->assertSame('amialpay.com', $this->app->make('request')->getHost());
            $this->assertSame('https', $this->app->make('request')->getScheme());
        } finally {
            config(['app.url' => $original]);
        }
    }

    /**
     * @test
     *
     * **والمنفذ لا يُقصّ.** `https://amialpay.com:8081` عنوانٌ صالح، وقصّه
     * يُرسل الروابط إلى منفذٍ لا خدمةَ عليه.
     */
    public function a_port_survives_the_cleaning(): void
    {
        $this->assertSame('https://amialpay.com:8081',
            AppUrl::resolve(' https://amialpay.com:8081/ '));
    }
}
