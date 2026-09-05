<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-DEMO-OTP-GUARD-001 — **رمزُ العرض الثابت يمنع الإطلاق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الفجوةُ التي يحرسها، وهي أخطرُ ما في المنصّة:** ما دام
 * `AMIAL_DEMO_OTP` مضبوطاً، يُقبل رمزُه (‏`123456`) رمزَ تحقّقٍ **لأيّ
 * رقمٍ في اليمن**. فمن يعرفه يسجّل باسم أيّ رقمٍ ويستقبل أيَّ حساب —
 * تخطٍّ كاملٌ للمصادقة من جذرها.
 *
 * **ويبقى عمداً طوال التجربة** بقرار صاحب المشروع. لكنّ «مؤقّتاً» يبقى
 * إلى الأبد ما لم يوجد ما يمنعه — كما تكرّر في هذا المشروع مراراً
 * (مبنيٌّ ولا يُوصَل إليه، وحاجزٌ يُنزَع صامتاً). فالحاجزُ في
 * `entrypoint.prod.sh` **يرفض إقلاعَ الإنتاج** ما دام الرمزُ موجوداً،
 * إلّا بمنفذٍ صريح.
 *
 * **وهذا الحارسُ يقيس أنّ ذلك الحاجزَ موجودٌ ولا يُنزَع صامتاً.** فحاجزُ
 * إقلاعٍ في سكربتٍ لا يراه اختبارٌ يُحذَف في تنظيفٍ عابرٍ ولا يُسقط شيئاً
 * — حتّى ليلةَ الإطلاق. (وهو نمطُ عطلٍ دُفع ثمنُه هنا: بناءُ APK المجّانيّ
 * حُذف صامتاً من البوّابة لأنّ لا اختبارَ يحرسها.)
 */
class DemoOtpBlocksLaunchGuardTest extends TestCase
{
    private function entrypoint(): string
    {
        $path = base_path('docker/entrypoint.prod.sh');

        $this->assertFileExists($path,
            'مدخلُ الإنتاج مفقود — ولا حاجزَ بلا مدخل.');

        return (string) file_get_contents($path);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الحاجزُ موجودٌ ويفحص المتغيّرَ الصحيح.**
     */
    /** @test */
    public function the_entrypoint_refuses_to_boot_with_a_live_demo_otp(): void
    {
        $sh = $this->entrypoint();

        $this->assertStringContainsString('AMIAL_DEMO_OTP', $sh,
            '**لا حاجزَ على `AMIAL_DEMO_OTP` في مدخل الإنتاج.** فمنصّةٌ '
            .'ماليّةٌ تُطلَق ورمزُ تحقّقها الثابتُ يقبل أيَّ رقم.');

        // يجب أن يوقف الإقلاعَ فعلاً — لا مجرّد تحذيرٍ يُطبَع ويُمضى.
        $blockBlock = $this->slice($sh, 'AMIAL_DEMO_OTP', 'fi');

        $this->assertStringContainsString('exit 1', $blockBlock,
            '**الحاجزُ يُحذّر ولا يوقف.** فتحذيرٌ يُطبَع ثمّ يُقلع الخادمُ '
            .'بابُه مفتوح — والتحذيرُ الذي لا يوقف يُقرأ ويُمضى.');
    }

    /**
     * **② وله منفذٌ صريحٌ للتجربة — لا يُفتح بالصمت.**
     *
     * فحاجزٌ بلا منفذٍ يُنزَع كلَّه أوّلَ ما يُحتاج تجاوزُه؛ ومنفذٌ صريحٌ
     * يُفتح بيدٍ تعرف أنّها تفتحه. (نفسُ نمط `AMIAL_ALLOW_DEBUG`.)
     */
    /** @test */
    public function it_has_an_explicit_opt_in_not_a_silent_hole(): void
    {
        $sh = $this->entrypoint();

        $this->assertStringContainsString('AMIAL_ALLOW_DEMO_OTP', $sh,
            '**لا منفذَ صريحٌ للتجربة.** فمن احتاج تجربةً على خادمٍ حقيقيٍّ '
            .'ينزع الحاجزَ كلَّه — ولا يعود.');
    }

    /**
     * **③ والنيّةُ موثَّقةٌ حيث تُقرأ — لا في رأس مطوّرٍ يُنسى.**
     */
    /** @test */
    public function the_reason_is_documented_at_the_gate(): void
    {
        $sh = $this->entrypoint();
        $block = $this->slice($sh, 'AMIAL-DEMO-OTP-GUARD-001', 'exit 1');

        $this->assertStringContainsString('أيّ رقم', $block,
            '**الحاجزُ بلا سببٍ مكتوب.** فمن قرأه لاحقاً لا يعرف لماذا '
            .'يوقف الإقلاع، فينزعه ظانّاً أنّه زائد.');
    }

    /** يقصّ من أوّل مطابقةٍ إلى أوّل خاتمةٍ بعدها. */
    private function slice(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        $this->assertNotFalse($start, "لم يُوجَد «{$from}» في المدخل.");

        $end = strpos($haystack, $to, $start);

        return $end === false
            ? substr($haystack, $start)
            : substr($haystack, $start, $end - $start + strlen($to));
    }
}
