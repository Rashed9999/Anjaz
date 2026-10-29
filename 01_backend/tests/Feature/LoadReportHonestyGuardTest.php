<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-LOAD-TRUTH-001 — **تقريرُ طاقةٍ يدّعي ما لم يُقَس.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `scripts/http-load.php` كان يطبع «هذه الجولةُ تمرّ بـnginx وphp-fpm
 * الحقيقيّين» **لمجرّد أنّ `BASE` مضبوط** — أيّاً كان ما خلف العنوان.
 *
 * ووُجّه إلى `php artisan serve`، **وهو خادمٌ أحاديُّ الخيط يخدم طلباً
 * واحداً في كلّ لحظة**، فأخرج ١٦ طلباً/ث وطبع السطرَ نفسَه، ثمّ حكم:
 * «دونَ الحدّ المقدَّر لألفي مستخدم» وخرج بالرمز ١.
 *
 * **وهو حكمٌ كاذبٌ على بنيةٍ سليمة.** ورقمُ طاقةٍ خاطئٌ يُرسل صاحبَ
 * المشروع يشتري عتاداً لا يحتاجه، أو يؤجّل إطلاقاً جاهزاً — وكلاهما
 * ثمنٌ يُدفع على قياسٍ لم يقع.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهو صنفُ «حارسٍ يكذب» بعينه** — المكتوبُ في `CLAUDE.md`: «حارسٌ
 * يمرّ والعطل قائم أسوأ من غيابه»، وأخته: حارسٌ **يُسقط** ما ليس ساقطاً.
 */
class LoadReportHonestyGuardTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(base_path('scripts/http-load.php'));
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_stack_is_read_from_the_response_not_assumed_from_base(): void
    {
        $src = $this->script();

        $this->assertStringContainsString('get_headers', $src,
            '**لا يُسأل الخادمُ عن هويّته** — فيُدّعى مكدَّسُ الإنتاج على '
            . 'أيّ عنوانٍ يُمرَّر، بما فيه خادمُ التطوير');

        foreach (['Server', 'X-Powered-By'] as $header) {
            $this->assertStringContainsString($header, $src,
                "ترويسة «{$header}» لا تُقرأ — وهي بصمةُ المكدَّس");
        }
    }

    /**
     * **والادّعاءُ القديم لا يعود** — لا سطرَ يقول «nginx وphp-fpm»
     * إلّا خلف فحصٍ يُثبته.
     *
     * @test
     */
    public function no_line_claims_a_production_stack_unconditionally(): void
    {
        $src = $this->script();

        // تُنزَع التعليقاتُ أوّلاً: التعليقُ الذي **يشرح** العطلَ يذكر
        // العبارةَ نفسَها، ولا يُنفَّذ. (وقد مرّ حارسٌ في هذه الجلسة
        // لهذا السبب بعينه.)
        $code = preg_replace('~^\s*(//|\*|/\*).*$~mu', '', $src) ?? '';

        $at = mb_strpos($code, 'جولةٌ تمرّ بمكدّس الإنتاج');

        $this->assertNotFalse($at,
            'اختفى سطرُ إقرار الإنتاج كلّيّاً — والجولةُ الحقيقيّةُ يجب '
            . 'أن تُعلن أنّها حقيقيّة');

        $before = mb_substr($code, 0, $at);

        $this->assertStringContainsString('isProdStack', $before,
            '**سطرُ «مكدّس الإنتاج» ليس خلف فحصٍ يُثبته** — فيُطبَع على '
            . 'خادم تطويرٍ كما طُبع من قبل');
    }

    /**
     * **ورقمُ خادمِ التطوير لا يُقرأ حكماً — لا نجاحاً ولا فشلاً.**
     *
     * @test
     */
    public function a_dev_server_run_suspends_the_verdict_instead_of_failing(): void
    {
        $src = $this->script();
        $code = preg_replace('~^\s*(//|\*|/\*).*$~mu', '', $src) ?? '';

        // **ويُقصَد فرعُ الحكم لا فرعُ اللافتة.** `$isDevServer` تُقرأ
        // مرّتين: مرّةً لطبع التحذير أعلى التقرير، ومرّةً عند الحكم.
        // والبحثُ عن أوّل ورودٍ يقيس اللافتةَ ويظنّها الحكم.
        $at = mb_strpos($code, 'empty($isDevServer)');

        $this->assertNotFalse($at,
            'لا فرعَ حكمٍ لخادم التطوير — فيُحكَم على طاقته كأنّها طاقةُ الإنتاج');

        // ══════════════════════════════════════════════════════════════
        // **وتُقتصَر الشريحةُ على الفرع لا على عددِ محارف.**
        //
        // نافذةٌ ثابتةٌ (٧٠٠ محرف) تجاوزت قوسَ الإغلاق فبلعت الفرعَ
        // التالي — وفيه `exit(1)` مشروع. فسقط الحارسُ على شيفرةٍ
        // سليمة. **وهو ثالثُ حدٍّ اعتباطيٍّ يقيس المسافةَ لا البنية
        // في هذه الجلسة** — والدرسُ يُكتب لا يُكرَّر.
        // ══════════════════════════════════════════════════════════════
        $rest = mb_substr($code, $at);
        $close = mb_strpos($rest, "\n}");
        $branch = $close === false ? $rest : mb_substr($rest, 0, $close);

        $this->assertStringContainsString('exit(0)', $branch,
            '**جولةُ خادمِ تطويرٍ تخرج بفشل** — فتُسقط البوّابةَ على '
            . 'بنيةٍ سليمة، ويُقرأ «غيرُ معروف» فشلاً');

        $this->assertStringNotContainsString('exit(1)', $branch,
            'الفرعُ يُنهي بالفشل — و«غير معروف» ليس فشلاً كما أنّه ليس نجاحاً');
    }

    /**
     * **والمتغيّرُ يُهيَّأ قبل كلّ فرع.**
     *
     * `$isDevServer` كان يُعرَّف داخل فرع `BASE` وحدَه. ومتغيّرٌ غيرُ
     * معرَّفٍ يُقرأ `null` صامتاً في PHP — فيمرّ حكمُ الطاقة على جولةٍ
     * محلّيّةٍ كأنّها إنتاج، **بلا تحذيرٍ في أيّ سجلّ**.
     *
     * @test
     */
    public function the_flag_is_initialised_before_any_branch_can_read_it(): void
    {
        $code = preg_replace('~^\s*(//|\*|/\*).*$~mu', '', $this->script()) ?? '';

        $init = mb_strpos($code, '$isDevServer = false;');
        $use = mb_strpos($code, 'isDevServer)');

        $this->assertNotFalse($init, '`$isDevServer` لا تُهيَّأ إطلاقاً');
        $this->assertLessThan($use, $init,
            'تُقرأ قبل أن تُهيَّأ — فتُقرأ فارغةً ويمرّ الحكمُ صامتاً');
    }
}
