<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AMIAL-READINESS-METER-001 — **الجاهزيّةُ رقمٌ يُحسَب لا حكمٌ يُصدَر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا وُجد المقياس:**
 *
 * سُئل مراراً «هل نحن جاهزون لألفي مستخدم؟»، وكان الجوابُ في كلّ مرّةٍ
 * فقرةً يكتبها من سُئل. **وحكمٌ يعتمد على كاتبه لا يُراجَع ولا يُتابَع**:
 * لا يُعرف متى تغيّر، ولا يُعرف أنّه صار عشرةً إلّا بأن يُسأل من جديد.
 *
 * فصار كلُّ شرطٍ **يُقاس من مصدره**، والرقمُ حاصلَ القياس — يصير عشرةً
 * حين يصير كذلك، لا حين يقتنع أحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما يُحرَس فيه: ألّا يُقرأ المجهولُ نجاحاً.**
 *
 * ثلاثةٌ لا تُعرف إلّا من الخادم المنشور — أنّ النشرةَ جرت، وأنّ فحصَ
 * الصحّة مضبوطٌ في Coolify، وسقفُ php-fpm الحقيقيّ. **ومقياسٌ يعدّها
 * نجاحاً يُخرج عشرةً على مشروعٍ لم يُنشَر** — وهو الصمتُ بثوب رقم.
 *
 * (القاعدةُ السابعة: الصفرُ يُقرأ «فحصنا فلم نجد»، والغيابُ يُقال بسببه.
 * والقاعدةُ الأولى: «الطبقةُ المُخطّاةُ لا تُعدّ نجاحاً».)
 */
class ReadinessMeterGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **ولا تُسمّى `run`** — فهي نهائيّةٌ في `TestCase`، وتجاوزُها خطأٌ
     * قاتلٌ يُسقط المجموعةَ كلَّها لا هذا الملفَّ وحدَه. (وقع مثلُه في
     * هذه الجلسة على `call()`.)
     */
    private function meter(): string
    {
        Artisan::call('amial:readiness');

        return Artisan::output();
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_prints_a_score_out_of_ten(): void
    {
        $this->assertMatchesRegularExpression('~الجاهزيّة:\s*\d+\s*/\s*10~u', $this->meter(),
            'المقياسُ لا يُخرج رقماً — فيبقى الجوابُ فقرةً يكتبها من سُئل');
    }

    /** @test */
    public function an_unknown_is_never_counted_as_a_pass(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو الشرطُ الذي يجعل الرقمَ صادقاً.** ثلاثةٌ لا تُقاس من
        // هنا؛ ومقياسٌ يعدّها نجاحاً يُخرج عشرةً على مشروعٍ لم يُنشَر.
        // ══════════════════════════════════════════════════════════════
        $out = $this->meter();

        preg_match('~\((\d+) من (\d+) شرطاً مقيساً\)~u', $out, $m);

        $this->assertNotEmpty($m, 'المقياسُ لا يقول كم شرطاً قاس');

        $unknowns = substr_count($out, '؟');

        $this->assertGreaterThan(0, $unknowns,
            'لا شرطَ «غيرُ معروف» — وثلاثةٌ منها لا تُقاس من هنا قطعاً');

        // **والمقامُ يستثنيها.** فلو دخلت البسطَ لصار المجهولُ نجاحاً.
        $this->assertLessThan(count(explode("\n", trim($out))), (int) $m[2],
            'المقامُ يشمل ما لم يُقَس');

        $this->assertStringContainsString('لا يُحسَب نجاحاً', $out,
            'المقياسُ لا يقول إنّ المجهولَ ليس نجاحاً — فيُقرأ رقمُه أعلى ممّا هو');
    }

    /** @test */
    public function it_exits_non_zero_until_everything_is_measured_and_green(): void
    {
        // **ورقمٌ لا يُوقف شيئاً لا يُقرأ.** يخرج بغير صفرٍ ما دام ثمّة
        // شرطٌ ساقطٌ أو مجهول — فيصلح خطوةً في أيّ خطّ نشر.
        $this->assertNotSame(0, Artisan::call('amial:readiness'),
            'يخرج بصفرٍ وثمّة شروطٌ لم تُقَس — فلا يمنع إطلاقاً سابقاً لأوانه');
    }

    /** @test */
    public function every_measured_condition_names_its_source(): void
    {
        // **ومقياسٌ يقول «فشل» بلا سببٍ يُرسل صاحبَه يبحث.** كلُّ سطرٍ
        // يذكر ما قِيس: الرقمَ، أو اسمَ المتغيّر، أو ما يُشغَّل.
        $out = $this->meter();

        foreach (['APP_DEBUG', 'AMIAL_BACKUP_REMOTE', 'pm.max_children', 'المخزن'] as $needle) {
            $this->assertStringContainsString(
                $needle === 'pm.max_children' ? 'عاملاً' : $needle, $out,
                "سطرٌ في المقياس لا يذكر مصدرَه ({$needle})");
        }
    }

    /** @test */
    public function the_capacity_ceiling_is_read_from_the_deployed_image(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والصورةُ المفحوصةُ ليست المشحونة.** Coolify يبني `Dockerfile`
        // لا `Dockerfile.prod` — وهذا مسجَّلٌ في `CLAUDE.md` بعد أن كلّف
        // سقفَ تزامنٍ قدرُه خمسة.
        // ══════════════════════════════════════════════════════════════
        $src = (string) file_get_contents(
            app_path('Console/Commands/ReadinessScoreCommand.php'));

        $this->assertStringContainsString("base_path('Dockerfile')", $src,
            'السقفُ يُقرأ من غير الصورة المنشورة');

        $this->assertStringNotContainsString("base_path('Dockerfile.prod')", $src,
            'يُقرأ من الملفّ الذي لا يُبنى — فيُقاس ما لا يُشحَن');
    }

    /** @test */
    public function the_gate_verdict_is_read_per_tree_not_per_run(): void
    {
        // **وإيصالُ الأمس لا يُقرأ على شيفرة اليوم.** الإيصالُ مفتاحُه
        // شجرةُ المحتوى، فتغيّرُ سطرٍ واحدٍ يُبطله.
        $src = (string) file_get_contents(
            app_path('Console/Commands/ReadinessScoreCommand.php'));

        $this->assertStringContainsString('tree_sha', $src,
            'البوّابةُ تُقرأ بآخر جولةٍ لا بشجرتها — فإيصالُ الأمس يُطمئن اليوم');
    }
}
