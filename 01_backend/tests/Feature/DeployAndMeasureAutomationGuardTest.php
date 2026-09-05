<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * AMIAL-CD-TRIGGER-001 · AMIAL-READINESS-METER-002 — **آخرُ بابين يُفتَحان
 * باليد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع، بنصّ صاحب المشروع:** «منذ أيّامٍ وأنا لم أستطع عملَ
 * بناءٍ للتطبيق أو نشرَ المشروع... بسبب لم أسمع تمّ الدفع.... فقط
 * البوّابات».
 *
 * وقِيس فكان الدفعُ واصلاً كلَّه، والأبوابُ بعده مغلقة:
 *
 *     codemagic.yaml  →  صفرُ triggering        ⇒ الدفعُ لا يبني
 *     Coolify         →  Source: Manual         ⇒ الدفعُ لا ينشر
 *     amial:readiness →  لا يجري إلّا إن كُتب   ⇒ الجاهزيّةُ لا تُقاس
 *
 * **وبابٌ يُفتَح باليد يُنسى.** فتبقى الشيفرةُ الجديدةُ في git والقديمةُ
 * تخدم العملاء، ولا سطرَ في أيّ مكانٍ يقول ذلك.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُضبَط Coolify من مستودع** — هو لوحةٌ خارجيّة. لكنّه يقبل خطّافَ
 * نشرٍ بعنوانٍ واحد، فصار النشرُ سرّاً واحداً يُضبَط مرّةً: بعدها كلُّ
 * دفعةٍ إلى فرع النشر — **وقد اجتازت البوّابةَ** — تُطلق نشرة.
 *
 * والقياسُ صار مجدوَلاً على الخادم، **حيث تُقاس الشروطُ كلُّها** فيصير
 * الرقمُ محسوباً لا مجهولاً.
 */
class DeployAndMeasureAutomationGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function ci(): array
    {
        $path = base_path('../.github/workflows/ci.yml');

        $this->assertFileExists($path, 'اختفى ملفُّ البوّابة');

        return (array) Yaml::parseFile($path);
    }

    /** @return array<string,mixed> */
    private function deployJob(): array
    {
        $jobs = (array) ($this->ci()['jobs'] ?? []);

        $this->assertArrayHasKey('deploy', $jobs,
            'لا وظيفةَ نشرٍ في البوّابة — فالدفعُ لا ينشر، ويقف كلُّ التزامٍ '
            . 'حتّى يفتح إنسانٌ لوحةَ Coolify');

        return (array) $jobs['deploy'];
    }

    // ══════════════════════════════════════════════════════════════════
    // ① النشرُ يُطلَق — وبعد البوّابة لا معها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_deploy_job_waits_for_every_gate(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ونشرٌ يسبق الفحصَ أسوأ من نشرٍ يدويّ.** اليدويُّ بطيءٌ ويشحن
        // المفحوص؛ وهذا سريعٌ ويشحن ما لم يُفحَص — إلى منصّةٍ ماليّةٍ حيّة.
        // ══════════════════════════════════════════════════════════════
        $needs = (array) ($this->deployJob()['needs'] ?? []);

        foreach (['structural', 'backend', 'flutter', 'docker'] as $gate) {
            $this->assertContains($gate, $needs,
                "النشرُ لا ينتظر «{$gate}» — فيُشحَن ما لم يُفحَص");
        }
    }

    /** @test */
    public function only_the_deployed_branch_triggers_a_release(): void
    {
        // **وفرعُ التطوير ليس فرعَ النشر.** Coolify يستورد
        // `claude/project-code-review-yjagv` وحدَه؛ وخطّافٌ يُطلَق من كلّ
        // فرعٍ ينشر شيفرةً ليست هي المقصودة.
        $if = (string) ($this->deployJob()['if'] ?? '');

        $this->assertStringContainsString('claude/project-code-review-yjagv', $if,
            'النشرُ يُطلَق من أيّ فرع — فقد تُنشَر شيفرةٌ غيرُ المقصودة');
    }

    /** @test */
    public function a_missing_secret_is_reported_not_failed(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ووظيفةٌ حمراءُ على إعدادٍ لم يُضبَط بعد تُعوّد القارئَ على
        // تجاهل الأحمر** — ثمّ لا يراه يومَ يصدق. فالغيابُ يُقال بسببه
        // ولا يُعدّ فشلاً. (القاعدةُ السابعة.)
        // ══════════════════════════════════════════════════════════════
        $steps = (array) ($this->deployJob()['steps'] ?? []);
        $script = implode("\n", array_map(fn ($s) => (string) ($s['run'] ?? ''), $steps));

        $this->assertStringContainsString('COOLIFY_DEPLOY_HOOK', $script,
            'الخطّافُ لا يُقرأ من سرٍّ — فعنوانُ النشر مكشوفٌ أو غائب');

        $this->assertMatchesRegularExpression('~if \[ -z "\$HOOK" \][\s\S]{0,600}exit 0~', $script,
            'غيابُ السرّ يُسقط البوّابةَ حمراءَ بدل أن يُقال ويُخطَّى');
    }

    /** @test */
    public function the_hook_failure_shows_why(): void
    {
        // **و«فشل» بلا سببٍ يُرسل صاحبَه يبحث.** خطّافٌ خاطئٌ يردّ ٤٠٤،
        // و`--fail` وحدَها تبتلع نصَّ الردّ.
        $steps = (array) ($this->deployJob()['steps'] ?? []);
        $script = implode("\n", array_map(fn ($s) => (string) ($s['run'] ?? ''), $steps));

        $this->assertStringContainsString('--fail-with-body', $script,
            'سببُ فشل الخطّاف يُبتلَع — فيُقال «فشل» بلا نصّ الردّ');
    }

    // ══════════════════════════════════════════════════════════════════
    // ② والقياسُ يجري من تلقائه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_meter_runs_itself_on_the_server(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وقياسٌ يحتاج من يشغّله لا يُقاس.** الجاهزيّةُ تُعرف يومَ
        // يُسأل عنها لا يومَ تتغيّر — وهو العيبُ الذي وُجد المقياسُ
        // ليُصلحه: «حكمٌ يعتمد على من يكتبه لا يُراجَع ولا يُتابَع».
        // ══════════════════════════════════════════════════════════════
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'amial:readiness'));

        $this->assertNotEmpty($events,
            'المقياسُ غيرُ مجدوَل — فالرقمُ لا يُحسَب إلّا إن تذكّر أحدٌ أن يكتبه');
    }

    /** @test */
    public function an_incomplete_readiness_reaches_the_error_centre(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وملفُّ سجلٍّ ليس شاشة.** المصالحةُ كانت تجد الفرقَ ٠٢:٠٠
        // فتطبع سطراً في ملفٍّ لا يقرؤه أحد — والعطلُ نفسُه يتكرّر هنا
        // لو اكتفى المقياسُ بـ`readiness.log`.
        //
        // ويُجرَّب على الهدف: من جهاز التطوير سبعةُ شروطٍ مجهولةٌ
        // بالضرورة، وإنذارٌ يوميٌّ عنها يُعوّد القارئَ على التجاهل.
        // ══════════════════════════════════════════════════════════════
        $this->app['env'] = 'production';

        Artisan::call('amial:readiness');

        $this->assertDatabaseHas('system_errors', [
            'fingerprint' => hash('sha256', 'ops|readiness.incomplete'),
        ]);

        // **والوسيطُ الثالثُ في `assertDatabaseHas` اسمُ اتّصالٍ لا رسالة** —
        // ورسالةٌ فيه تُفسَّر قاعدةً غيرَ موجودة. (سقط الحارسُ عليها أوّلَ مرّة.)
        $this->assertNotNull(
            DB::table('system_errors')
                ->where('fingerprint', hash('sha256', 'ops|readiness.incomplete'))->first(),
            'الجاهزيّةُ ناقصةٌ ولا أثرَ في مركز الأعطال — فتبقى مجهولةً حتّى يُسأل عنها');
    }

    /** @test */
    public function the_dev_box_is_never_alarmed(): void
    {
        // **وسبعةُ شروطٍ مجهولةٌ هنا بالضرورة** — فإنذارٌ عنها كلَّ يومٍ
        // ضجيجٌ يدفن ما يصدق.
        Artisan::call('amial:readiness');

        $this->assertDatabaseMissing('system_errors', [
            'fingerprint' => hash('sha256', 'ops|readiness.incomplete'),
        ]);

        $this->assertNull(
            DB::table('system_errors')
                ->where('fingerprint', hash('sha256', 'ops|readiness.incomplete'))->first(),
            'جهازُ التطوير يرفع إنذارَ جاهزيّةٍ — وهو لا يُقاس منه أصلاً');
    }

    /** @test */
    public function a_complete_readiness_says_nothing(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ومن أغلق الصفَّ لا يُفتَح عليه ثانيةً بلا سبب.** لو نادى
        // المقياسُ `note` في كلّ حال لأعاد فتحَ ما أُغلق كلَّ صباح —
        // فيصير مركزُ الأعطال دفترَ حضورٍ لا شاشةَ أعطال.
        // ══════════════════════════════════════════════════════════════
        $src = (string) file_get_contents(
            app_path('Console/Commands/ReadinessScoreCommand.php'));
        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? '';
        $src = preg_replace('~^[ \t]*//[^\n]*$~m', '', $src) ?? '';

        $this->assertMatchesRegularExpression(
            '~if \(\$onTarget && ! \$ready\)~', $src,
            'الإنذارُ يُرفَع بلا شرطٍ — فيُفتَح كلَّ صباحٍ ما أُغلق، وتُقرأ '
            . 'الجاهزيّةُ التامّةُ عطلاً');
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_alert_names_what_is_missing(): void
    {
        // **وإنذارٌ يقول «لم تكتمل» بلا أن يقول ما الناقص يُرسل قارئَه
        // يبحث** — وهو ما يجعل اللافتةَ تُتجاهَل.
        $this->app['env'] = 'production';

        Artisan::call('amial:readiness');

        $row = DB::table('system_errors')
            ->where('fingerprint', hash('sha256', 'ops|readiness.incomplete'))
            ->first();

        $this->assertNotNull($row);

        $this->assertMatchesRegularExpression('~الناقص:\s*\S~u', (string) $row->message,
            'الإنذارُ لا يسمّي الشروطَ الناقصة');
    }
}
