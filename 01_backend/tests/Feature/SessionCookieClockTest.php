<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-CLOCK-001 — كوكي الجلسة لا تحمل تاريخ انتهاء.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الذي أدخل هذا الملفّ، وكيف بدا.**
 *
 * تأخّرت ساعةُ خادم الإنتاج خمساً وثلاثين ساعة. فصارت كلّ كوكي يُصدرها
 * تحمل `expires` **في الماضي** بنظر هاتف المستعمل — لأنّ الهاتف ساعتُه
 * صحيحة والخادم لا. فيرميها المتصفّح لحظةَ وصولها.
 *
 * وظهر ذلك في مكانٍ لا يدلّ عليه:
 *
 *     GET  /agent/login   → ٢٠٠ سليمة، والصفحة تُرسَم كاملة
 *     POST /agent/login   → ٤١٩ «PAGE EXPIRED» صفحةً بيضاء
 *
 * لأنّ الطلب الثاني وصل **بلا جلسة**، فلا رمزَ CSRF يُطابَق. ولم يظهر في
 * سجلٍّ ولا اختبار: الخادم لم يُخطئ في شيء، والمتصفّح لم يُخطئ في شيء —
 * العطل بين ساعتين.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما يُثبَت هنا هو الحصانة لا الساعة:** كوكي جلسةٍ بلا `expires`
 * ولا `Max-Age` يحفظها المتصفّح ما دام مفتوحاً مهما كانت ساعة الخادم.
 * والعمر الحقيقيّ يفرضه الخادم من `lifetime` بمقارنة وقته بوقته — وهي
 * مقارنةٌ تصحّ وإن كانت الساعة كلّها خطأ.
 */
class SessionCookieClockTest extends TestCase
{
    /**
     * @test
     *
     * ولو عادت `expire_on_close` إلى `false` لسقط هذا — وهو المطلوب.
     */
    public function the_session_cookie_carries_no_expiry_so_a_wrong_clock_cannot_kill_it(): void
    {
        $this->assertTrue(
            config('session.expire_on_close'),
            'كوكي الجلسة تحمل تاريخ انتهاء — وساعةُ خادمٍ خاطئة تجعله في الماضي فتُرمى الكوكي',
        );

        $response = $this->get('/agent/login');
        $response->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'لم تُصدَر كوكي جلسةٍ إطلاقاً');

        // صفرٌ في Symfony تعني «كوكي جلسة»: بلا expires وبلا Max-Age.
        $this->assertSame(0, $cookie->getExpiresTime(),
            'كوكي الجلسة تحمل انتهاءً محسوباً من ساعة الخادم — وهي بالضبط نقطةُ الانكسار');
    }

    /**
     * @test
     *
     * صفحة ٤١٩ تقول ما جرى وما يُفعَل — بالعربيّة.
     *
     * والافتراضيّة سطرٌ إنجليزيّ واحد على صفحةٍ بيضاء، يقف أمامه صاحبُ
     * شركة صرافةٍ فيظنّ النظام معطَّلاً ويتّصل.
     */
    public function the_419_page_explains_itself_in_arabic(): void
    {
        $html = view('errors.419')->render();

        $this->assertStringContainsString('انتهت صلاحية', $html);
        $this->assertStringContainsString('إعادة المحاولة', $html);
        // وتذكر الساعةَ صراحةً: هي السبب الذي لا يخطر لأحد.
        $this->assertStringContainsString('ساعة الخادم', $html);
    }

    /**
     * @test
     *
     * حارسُ الساعة موصولٌ بالشاشتين — لا مبنيٌّ ولا منادى.
     */
    public function the_clock_guard_is_reachable_from_both_panels(): void
    {
        foreach ([
            'resources/views/agent-views/dashboard.blade.php',
            'resources/views/layouts/admin/app.blade.php',
        ] as $file) {
            $this->assertStringContainsString(
                "@include('partials._clock_guard')",
                file_get_contents(base_path($file)),
                "حارس الساعة غير موصولٍ بـ{$file}",
            );
        }
    }
}
