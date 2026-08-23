<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RATELIMIT-CGNAT-002 — **حدٌّ بالـIP يقتل تجربةً في اليمن.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل، وقد قِيس بجولةٍ حقيقيّةٍ عبر HTTP لا بقراءة:**
 *
 *     360 طلباً · 12 متوازية  →  59 نجحت · **301 ردَّت 429**
 *     وبعد الإصلاح            →  360 نجحت · **صفرُ فشل** · 89 طلب/ث
 *
 * `throttle:60,1` بصيغته العدديّة **يُفتاح بالـIP** لغير المصادَق.
 * ومشغّلو الهاتف في اليمن على CGNAT — آلافُ المشتركين خلف عناوينَ
 * معدودة. فألفا مستخدمٍ على ثلاثة عناوين ⇒ ~٦٦٠ لكلٍّ ⇒ **طلبٌ واحدٌ
 * كلَّ إحدى عشرةَ دقيقةً للمستعمل**.
 *
 * ويراه العميلُ «التطبيقُ لا يعمل»، **ولا خطأَ في أيّ سجلٍّ عندنا** —
 * الحدُّ يعمل كما كُتب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لم يره اختبارٌ واحدٌ من ٣٠٦٠:**
 *
 *   · مجموعةُ الاختبارات تجري في عمليّةٍ واحدة، وكلُّ اختبارٍ يُصفّر الذاكرة
 *   · واختبارُ الضغط الماليّ **يستدعي الخدماتِ مباشرةً** ولا يمرّ بوسيطة
 *   · ومسبارُ الأزرار يفتح صفحةً في كلّ مرّة
 *
 * **فلا شيءَ في البوّابة كلِّها كان يمرّ بحدّ المعدّل تحت التوازي.**
 * وُلد `scripts/http-load.php` لذلك، وأخرج العطلَ في أوّل تشغيل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وعطلٌ ثانٍ كُشف في الطريق:** `RateLimiter::for('api')` كان مضبوطاً
 * في `RouteServiceProvider` **ولا يُستعمَل** — الصيغةُ العدديّةُ
 * `throttle:60,1` لا تقرؤه. **إعدادٌ مبنيٌّ ولا يُوصَل إليه**، والنمطُ
 * نفسُه واقعاً على حدّ المعدّل.
 */
class RateLimitCgnatGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function the_api_group_uses_the_named_limiter_not_a_bare_number(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وصيغةٌ عدديّةٌ تتجاهل كلَّ ضبطٍ مكتوب.** فالمُحدِّدُ المسمّى
        // موجودٌ ومشروحٌ في `RouteServiceProvider`، ولا يقرؤه شيء.
        // ══════════════════════════════════════════════════════════════
        // ══════════════════════════════════════════════════════════════
        // **ونزعُ التعليق يُكتب على مرحلتين لا بتعبيرٍ واحدٍ جشع.**
        //
        // كُتب أوّلَ مرّةٍ `~/\*.*?\*/|^\s*//.*$~ms` — و`s` تجعل النقطةَ
        // تبتلع الأسطر، فـ`.*$` من أوّل تعليقٍ سطريٍّ **تمحو بقيّةَ
        // الملفّ كلَّها**. فسقط الحارسُ على شيفرةٍ صحيحة.
        //
        // وهي القاعدةُ الخامسةُ بنصّها، واقعةً على حارسٍ كُتب لحراسة
        // غيره — **وحارسٌ يسقط على الصواب يُطفَأ عند أوّل مرّة.**
        // ══════════════════════════════════════════════════════════════
        $raw = (string) file_get_contents(base_path('bootstrap/app.php'));

        $src = preg_replace('~/\*.*?\*/~s', '', $raw) ?? '';       // الكتليّ: `s` مطلوبة
        $src = preg_replace('~^[ \t]*//[^\n]*$~m', '', $src) ?? ''; // السطريّ: بلا `s`

        $this->assertMatchesRegularExpression(
            "~group\('api',\s*\[\s*'throttle:api'~", $src,
            'مجموعةُ api تستعمل حدّاً عدديّاً — فالمُحدِّدُ المسمّى مبنيٌّ ولا يُنادى');
    }

    /** @test */
    public function two_devices_behind_one_ip_do_not_share_a_bucket(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه، مُصاغاً اختباراً.**
        //
        // جهازان من العنوان نفسِه — وهو ما يقع لكلّ مستعملي مشغّلٍ
        // واحدٍ في اليمن. فإن تقاسما السلّةَ، أغرق أحدُهما الآخر.
        // ══════════════════════════════════════════════════════════════
        $resolve = function (string $device) {
            $req = \Illuminate\Http\Request::create('/api/v1/amial/ping', 'GET');
            $req->headers->set('device-id', $device);
            $req->server->set('REMOTE_ADDR', '41.87.10.5');

            $cb = \Illuminate\Support\Facades\RateLimiter::limiter('api');
            $this->assertNotNull($cb, 'لا مُحدِّدَ باسم api — فالمجموعةُ تشير إلى فراغ');

            return $cb($req);
        };

        $a = $resolve('device-AAA');
        $b = $resolve('device-BBB');

        $this->assertNotSame($a->key, $b->key,
            'جهازان خلف عنوانٍ واحدٍ يتقاسمان السلّةَ نفسَها — '
            . 'وتحت CGNAT يعني هذا أنّ ألفَ مستعملٍ يُغرقون بعضَهم');
    }

    /** @test */
    public function an_authenticated_user_is_keyed_by_account_not_address(): void
    {
        // **والمصادَقُ يُحدُّ بحسابه** — فلا يُعاقَب بجيرانه على الشبكة،
        // ولا يهرب من حدّه بتبديل عنوان.
        $u = \App\Models\User::factory()->create(['type' => 2]);

        $mk = function (string $ip) use ($u) {
            $req = \Illuminate\Http\Request::create('/api/v1/amial/me/access', 'GET');
            $req->server->set('REMOTE_ADDR', $ip);
            $req->setUserResolver(fn () => $u);

            return (\Illuminate\Support\Facades\RateLimiter::limiter('api'))($req)->key;
        };

        $this->assertSame($mk('41.87.10.5'), $mk('197.1.2.3'),
            'المصادَقُ يُفتاح بعنوانه — فيهرب من حدّه بتبديل شبكة');

        $this->assertStringContainsString((string) $u->id, $mk('41.87.10.5'));
    }

    /** @test */
    public function a_client_without_a_device_header_still_gets_a_limit(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ولا يُفتَح البابُ بترويسةٍ غائبة.** من لا يرسل جهازاً يبقى
        // على العنوان وحدَه — فأسوأُ ما يبلغه مهاجمٌ أن يُعامَل كجهازٍ
        // إضافيّ، وحدُّه هو نفسُه.
        // ══════════════════════════════════════════════════════════════
        $req = \Illuminate\Http\Request::create('/api/v1/amial/ping', 'GET');
        $req->server->set('REMOTE_ADDR', '41.87.10.5');

        $limit = (\Illuminate\Support\Facades\RateLimiter::limiter('api'))($req);

        $this->assertNotNull($limit->key, 'طلبٌ بلا جهازٍ خرج بلا مفتاح — أي بلا حدّ');
        $this->assertGreaterThan(0, $limit->maxAttempts);
    }

    /** @test */
    public function a_forged_device_header_cannot_widen_the_key_without_bound(): void
    {
        // **وترويسةٌ يكتبها المهاجمُ لا تُوسّع المفتاحَ بلا حدّ**: تُنظَّف
        // وتُقصَّر، وإلّا صار كلُّ طلبٍ سلّةً جديدةً — أي لا حدَّ إطلاقاً.
        $req = \Illuminate\Http\Request::create('/api/v1/amial/ping', 'GET');
        $req->server->set('REMOTE_ADDR', '41.87.10.5');
        $req->headers->set('device-id', str_repeat('x', 5000) . '/../*&^%');

        $key = (\Illuminate\Support\Facades\RateLimiter::limiter('api'))($req)->key;

        $this->assertLessThan(120, strlen($key),
            'مفتاحُ الحدّ يتمدّد بترويسةٍ من المهاجم — فكلُّ طلبٍ سلّةٌ جديدة');

        $this->assertDoesNotMatchRegularExpression('~[/*&^%]~', $key,
            'محارفُ غيرُ مُنقّاةٍ في مفتاح الحدّ');
    }

    /** @test */
    public function the_probe_that_found_this_exists_and_says_what_it_cannot_measure(): void
    {
        // **وأداةٌ تدّعي أكثرَ ممّا تقيس أسوأ من غيابها.** فالمسبارُ
        // يقول صراحةً إنّه لا يقيس سقفَ php-fpm — وذاك العنقُ الحقيقيّ.
        $p = base_path('scripts/http-load.php');

        $this->assertFileExists($p,
            'اختفى المسبارُ الذي أخرج هذا العطل — فلا شيءَ يعيد قياسَه');

        $src = (string) file_get_contents($p);

        $this->assertStringContainsString('لا يقيس سقفَ php-fpm', $src,
            'المسبارُ لا يقول حدَّه — فيُقرأ رقمُه وعداً بالإنتاج');
    }
}
