<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-419-LOOP-001 — **صفحةٌ تحمل رمزاً لا تُخزَّن، وحلقةُ ٤١٩ لها مخرج.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل — من شاشة صاحب المشروع:**
 *
 * فتح لوحةَ الإدارة على هاتفه فخرجت «انتهت صلاحية هذه الصفحة» (٤١٩)،
 * وتكرّرت مع كلّ ضغطةٍ على «إعادة المحاولة».
 *
 * وصفحةُ ٤١٩ نفسُها كانت قد كتبت الجواب ولم يُطبَّق على من يُصدر الرمز:
 *
 *   «`no-cache` ليست `no-store`: الأولى تعني «تحقّق قبل الاستعمال»،
 *    والمتصفّح يحتفظ بالنسخة ويستعملها **حين يتعذّر التحقّق**.»
 *
 * فقِيست ترويسةُ `/admin/auth/login` فإذا هي `no-cache, private`. وسرعةُ
 * الاتّصال في اللقطة **٤٫١ ك.ب/ث** — فالتحقّقُ يتعذّر، ويُقدَّم النسخُ
 * المخزَّن ومعه `_token` من جلسةٍ ماتت ⇒ ٤١٩.
 *
 * **وزرُّ «إعادة المحاولة» كان `location.reload()`** — وإعادةُ تحميل ردِّ
 * ٤١٩ تُعيد إرسال الطلب نفسِه برمزه الفاسد. فلا مخرجَ إلّا مسحُ بيانات
 * المتصفّح، وهو ما لا يعرفه المستعمل.
 */
class CsrfPageNotStoredGuardTest extends TestCase
{
    /**
     * @test
     *
     * **كلُّ صفحةٍ تحمل رمزَ CSRF تردّ `no-store`.**
     *
     * والحدُّ **محتوى الردّ لا عنوانُه**: ما يُكتب غداً من نماذجَ يُحمى بلا
     * تذكُّرٍ ولا قائمةِ مسارات.
     */
    public function a_page_carrying_a_csrf_token_is_never_stored(): void
    {
        foreach (['/admin/auth/login', '/agent/login'] as $path) {
            $res = $this->get($path);

            $res->assertOk();

            $body = $res->getContent();

            $this->assertTrue(
                str_contains((string) $body, 'name="_token"')
                || str_contains((string) $body, 'name="csrf-token"'),
                "{$path}: لا رمزَ في الصفحة — الحارسُ يفحص شيئاً غير موجود");

            $cc = (string) $res->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cc, sprintf(
                "%s تردّ «%s» — و«no-cache» وحدَها تسمح للمتصفّح باستعمال\n"
                . "النسخة المخزَّنة **حين يتعذّر التحقّق** (اتّصالٌ ضعيف).\n"
                . "فيصل رمزٌ من جلسةٍ ماتت ⇒ ٤١٩ لا مخرجَ منها.",
                $path, $cc));
        }
    }

    /**
     * @test
     *
     * **وما لا رمزَ فيه يبقى قابلاً للتخزين.**
     *
     * نصفُ الحارس الذي يُنسى: منعُ تخزين كلّ شيءٍ يُبطئ كلَّ صفحةٍ على
     * اتّصالٍ ضعيف — **وهو عكسُ المقصود بالضبط**.
     */
    public function a_page_without_a_token_stays_cacheable(): void
    {
        $res = $this->get('/assets/css/amial-tokens.css');

        if ($res->getStatusCode() !== 200) {
            $this->markTestSkipped('ملفُّ التوكِنز لا يُخدَم في هذه البيئة');
        }

        $this->assertStringNotContainsString('no-store',
            (string) $res->headers->get('Cache-Control'),
            'أصلٌ ثابتٌ صار غيرَ قابلٍ للتخزين — يُبطئ كلَّ صفحة');
    }

    /**
     * @test
     *
     * **ولصفحة ٤١٩ مخرجٌ لا حلقة.**
     *
     * `location.reload()` على ردِّ ٤١٩ يُعيد إرسال الطلب نفسِه برمزه
     * الفاسد. فالمخرجُ **طلبُ صفحةٍ جديدةً بـGET** تُنشئ جلسةً ورمزاً.
     */
    public function the_419_page_offers_a_way_out_not_a_reload(): void
    {
        $view = file_get_contents(
            resource_path('views/errors/419.blade.php'));

        // **تُنزَع تعليقاتُ Blade أوّلاً** — فهي تذكر العطلَ شرحاً لا
        // تطبيقاً، وحارسٌ يمسك تعليقاً يشرح الإصلاح يمسك نفسَه.
        // (وقع هذا عينُه مع حارس بوّابة الوكيل و«Tajawal».)
        $live = (string) preg_replace('~\{\{--.*?--\}\}~s', '', $view);

        $this->assertStringNotContainsString('location.reload()', $live,
            'زرُّ ٤١٩ يُعيد التحميل — وهو يُعيد إرسال الطلب برمزه الفاسد، '
            . 'فيدور المستعملُ بلا مخرج.');

        $this->assertMatchesRegularExpression('~<a\s[^>]*href=~', $live,
            'لا رابطَ خروجٍ في صفحة ٤١٩ — والزرُّ وحده لا يُنشئ جلسةً جديدة');
    }
}
