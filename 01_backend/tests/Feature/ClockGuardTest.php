<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-CLOCK-GUARD-001 — ساعةٌ خاطئةٌ لا تُترك تعمل بصمت.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وقع مرّتين:**
 *
 *     ٢ أغسطس  — الخادم متأخّرٌ ٣٥ ساعة
 *     ٥ أغسطس  — الخادم متأخّرٌ ٤٤ ساعة
 *
 * ولم يُكتشف في المرّتين إلّا حين اصطدم بها مستخدمٌ برسالة «انتهت صلاحية
 * الصفحة». وبينهما يومان كان النظام يعمل ويُصدر أرقاماً — **وكلُّ ما بُني
 * على الوقت فيهما كاذب**: أوقاتُ العمليّات، وسلسلةُ ساعات العمل المُعمّاة،
 * وصلاحيّةُ رمز التحقّق، وحدودُ اليوم في التسوية. وشهادةُ Let's Encrypt
 * تُقرأ «لم تبدأ صلاحيّتها بعد»، وتجديدُها يفشل.
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
