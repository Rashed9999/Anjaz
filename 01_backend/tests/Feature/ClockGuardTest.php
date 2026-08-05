<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-CLOCK-GUARD-001 — ساعةٌ خاطئةٌ لا تُترك تعمل بصمت.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وقع مرّةً واحدة — والثانية كانت كذبةَ الحارس نفسه:**
 *
 *     ٢ أغسطس  — الخادم متأخّرٌ ٣٥ ساعة. **حقيقيّ**، قيس من ترويسة
 *                `Date` في ردّ الخادم مباشرةً.
 *     ٥ أغسطس  — الصفحة تُعلن ٤٤ ساعة. **لم يقع**: قياسٌ مباشرٌ على
 *                المضيف أعطى `System clock synchronized: yes` و
 *                `NTP service: active`. والصفحة كانت مخزَّنةً في
 *                المتصفّح منذ ٣ أغسطس، وقياسُها مطبوعٌ داخلها.
 *
 * والأوّل خطرٌ حقيقيّ: كلُّ ما يُبنى على وقتٍ كاذب كاذب — أوقاتُ
 * العمليّات، وسلسلةُ ساعات العمل المُعمّاة، وصلاحيّةُ رمز التحقّق، وحدودُ
 * اليوم في التسوية. وشهادةُ Let's Encrypt تُقرأ «لم تبدأ صلاحيّتها بعد».
 *
 * **وساعةٌ خاطئةٌ أخطرُ من عطلٍ ظاهر**: العطلُ يُوقف، وهذه تُنتج.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا شبكةَ مضمونةٌ في الإقلاع لسؤال خادم زمن.**
 *
 * لكنّ حقيقةً واحدةً تكفي: لا يمكن أن يكون الآن أقدمَ من لحظة بناء
 * الصورة. فتُختم لحظةُ البناء في الصورة، ويُقارَن بها عند كلّ إقلاع —
 * بلا إنترنت وبلا مقارنةٍ بأحد.
 *
 * وهذا الملفّ يمنع ضياع الطرفين: الختم في `Dockerfile`، والفحص في
 * `entrypoint.sh`. وأحدُهما بلا الآخر لا يكشف شيئاً.
 */
class ClockGuardTest extends TestCase
{
    /**
     * @test
     *
     * **الختم يُكتب في الصورة.** وبلا مصدرِ حقيقةٍ لا مقارنة.
     */
    public function the_image_records_when_it_was_built(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertStringContainsString('/etc/amial-build-epoch', $dockerfile,
            'ختمُ زمن البناء أُزيل — فلا مرجع تُقاس عليه ساعةُ الخادم');

        $this->assertMatchesRegularExpression(
            '~RUN\s+date\s+-u\s+\+%s\s*>\s*/etc/amial-build-epoch~',
            $dockerfile,
            'الختم لا يُكتب بزمنٍ فعليّ');
    }

    /**
     * @test
     *
     * **والفحص يجري عند كلّ إقلاع، لا مرّةً عند النشر.**
     *
     * والساعة تنحرف بين الإقلاعات: انحرفت من ٣٥ إلى ٤٤ ساعةً في يومين.
     */
    public function every_boot_compares_the_clock_against_that_stamp(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString('/etc/amial-build-epoch', $entrypoint,
            'فحصُ الساعة أُزيل من الإقلاع — فالختم يُكتب ولا يُقرأ');

        // الشرط نفسه: الآن أقدم من البناء ⇒ الساعة خاطئة يقيناً.
        $this->assertStringContainsString('-lt "$BUILT_AT"', $entrypoint,
            'المقارنة تغيّرت — تأكّد أنّها ما زالت تكشف التأخّر');
    }

    /**
     * @test
     *
     * **والرسالة تقول ما ينكسر وأين يُصلَح.**
     *
     * فتحذيرٌ يقول «الساعة خاطئة» ويسكت يجعل من يقرؤه يُعيد النشر — ولا
     * يُصلح النشرُ ساعةَ المضيف.
     */
    public function the_warning_names_the_fix_on_the_host(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString('timedatectl set-ntp true', $entrypoint,
            'الرسالة لا تذكر أمر الإصلاح');

        $this->assertStringContainsString('على المضيف لا في الحاوية', $entrypoint,
            'الرسالة لا تقول أين يُصلَح — فيُعاد النشر بلا أثر');
    }

    /**
     * @test
     *
     * **صفحةُ ٤١٩ لا تُخزَّن — وإلّا كذب الحارس، وكذبتُه تكبر.**
     *
     * ══════════════════════════════════════════════════════════════
     * **وهذا عطلٌ وقع في الحارس نفسه.**
     *
     * الصفحة تطبع وقت الخادم داخل HTML ثمّ يقارنه جافاسكربت بساعة
     * المتصفّح. وبلا منعِ تخزين، تُعرَض بعد يومين ووقتُ الخادم فيها مجمّدٌ
     * عند أوّل عرض — فتُعلن فارقاً قدرُه يومان، ويزيد يوماً كلّ يوم.
     *
     * وقد أعلنت تأخّراً قدره ٤٤ ساعة، فأُرسل صاحب المشروع يُصلح ساعة
     * خادمه — وكانت مضبوطةً ومزامَنة (`NTP service: active`).
     *
     * **وحارسٌ يكذب أسوأ من غيابه**: يُرسل من يصدّقه خلف عطلٍ لا وجود له.
     */
    public function the_419_page_is_never_cached(): void
    {
        // **لا يُطلب `/login` بـPOST هنا.** `VerifyCsrfToken` يتخطّى الفحص
        // حين `runningUnitTests()`، فيردّ ٣٠٢ لا ٤١٩ — واختبارٌ يفحص ٣٠٢
        // لا يفحص شيئاً. فيُمرَّر الاستثناءُ نفسه عبر معالج الأخطاء، وهو
        // المسار الذي يسلكه الطلب الحقيقيّ.
        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render(
            \Illuminate\Http\Request::create('/agent/login', 'POST'),
            new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'),
        );

        $this->assertSame(419, $response->getStatusCode(),
            'الاستثناء لم يعد يُنتج ٤١٩ — فالحارس يفحص رَدّاً آخر');

        $cc = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cc,
            'صفحةُ ٤١٩ تُخزَّن — فستُعلن فارق ساعةٍ مجمّداً يكبر كلّ يوم');

        // ويُتحقّق أنّ القياس نفسه ما زال فيها، وإلّا منعنا التخزين عن
        // صفحةٍ لم تعد تقيس شيئاً.
        $this->assertStringContainsString('data-server-ms', $response->getContent());
    }

    /**
     * @test
     *
     * **وصفحةُ ٤١٩ تبقى تقيس الفارق وتقوله.**
     *
     * وهي التي كشفت العطل في المرّتين — من المتصفّح لا من الخادم، لأنّ
     * الخادم لا يعرف أنّ ساعته خطأ: يقارن وقتَه بوقته فيصحّ دائماً.
     */
    public function the_419_page_still_measures_the_skew_in_the_browser(): void
    {
        $page = file_get_contents(resource_path('views/errors/419.blade.php'));

        $this->assertStringContainsString('data-server-ms', $page,
            'وقتُ الخادم لم يعد يصل الصفحة — فلا فارقَ يُحسب');

        $this->assertStringContainsString('timedatectl set-ntp true', $page,
            'الصفحة تقول «الساعة خاطئة» ولا تقول كيف تُصلَح');
    }
}
