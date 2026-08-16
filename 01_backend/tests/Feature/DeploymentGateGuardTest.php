<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-PROD-READINESS-001 — **بوّابةٌ لا تُطلَق على الفرع المنشور ليست بوّابة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في تدقيق الجاهزيّة، ولم يُكتشف قبله:**
 *
 *   $ git ls-remote --heads origin
 *   refs/heads/claude/project-code-review-yjagv
 *   refs/heads/claude/project-development-continuation-7oxhip
 *
 *   $ head -8 .github/workflows/ci.yml
 *   on: push: branches: [main, develop]
 *
 * **لا `main` ولا `develop` على origin.** فالبوّابةُ — أربعُ وظائفَ فيها
 * بناءُ صورةٍ وتحليلُ Flutter واختباراتُ الخادم كلُّها — **لم تُطلَق مرّةً
 * واحدةً على شيفرةٍ دُفعت**. وآخرُ تشغيلٍ مسجَّلٌ في ١٠ يونيو، والعشرُ
 * الأخيرةُ كلُّها `failure`، وأسماءُ وظائفها لا تطابق `ci.yml` الحاليّ —
 * أي أنّ الملفّ أُعيدت كتابتُه بعده، فما في المستودع اليوم لم يُشغَّل قطّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا نمطُ العطل الأكثر تكراراً في المشروع واقعاً على البوّابة نفسِها:**
 * مبنيٌّ ولا يُوصَل إليه. وهو أخطرُ هنا من أيّ مكانٍ آخر — لأنّ الأداةَ
 * التي تُفترض حارساً هي التي غابت، **وخضرةُ المستودع كانت خضرةَ صمتٍ لا
 * خضرةَ نجاح**.
 *
 * **والحارسُ يقيس المطابقةَ لا يحفظ اسماً:** يقرأ الفرعَ الجاري من git
 * ويطابقه بمرشِّح الـworkflow. فمن غيّر المرشِّح أو أنشأ فرعاً لا يغطّيه
 * يسقط هنا — ولا يشيخ الحارسُ مع أوّل إعادة تسمية.
 */
class DeploymentGateGuardTest extends TestCase
{
    private function workflow(): string
    {
        $path = base_path('../.github/workflows/ci.yml');

        $this->assertFileExists($path, 'ملفُّ البوّابة اختفى — فلا فحصَ آليٌّ إطلاقاً');

        return (string) file_get_contents($path);
    }

    /** الفرعُ الجاري — وهو أحدُ الفرعين المنشورين في هذا المشروع. */
    private function currentBranch(): ?string
    {
        $out = @shell_exec('git -C ' . escapeshellarg(base_path('..'))
            . ' rev-parse --abbrev-ref HEAD 2>/dev/null');

        $b = trim((string) $out);

        return ($b === '' || $b === 'HEAD') ? null : $b;
    }

    /**
     * @return list<string> أنماطُ الفروع في `on.push.branches`
     */
    private function pushBranchPatterns(): array
    {
        $yml = $this->workflow();

        // `on:` ثمّ `push:` ثمّ `branches: [...]` — بصيغة القائمة المضمّنة
        // أو قائمةِ الشُّرَط. وكلتاهما مقبولةٌ في YAML فتُقرآن معاً.
        if (! preg_match('/^on:\s*$(.*?)^\S/ms', $yml . "\nX", $m)) {
            $this->fail('تعذّر قراءةُ كتلة `on:` من ci.yml');
        }

        $onBlock = $m[1];

        if (! preg_match('/^\s{2}push:\s*$(.*?)(?=^\s{2}\S|\z)/ms', $onBlock, $p)) {
            // لا مُطلِقَ عند الدفع إطلاقاً — وهو أسوأُ من مرشِّحٍ خاطئ.
            return [];
        }

        $pushBlock = $p[1];
        $out = [];

        if (preg_match('/branches:\s*\[([^\]]*)\]/', $pushBlock, $inline)) {
            foreach (explode(',', $inline[1]) as $one) {
                $one = trim($one, " \t'\"");
                if ($one !== '') {
                    $out[] = $one;
                }
            }
        }

        if (preg_match('/branches:\s*$(.*?)(?=^\s{4}\w|\z)/ms', $pushBlock, $blk)) {
            foreach (preg_split('/\R/', $blk[1]) as $line) {
                if (preg_match("/^\s*-\s*['\"]?([^'\"\s#]+)/", $line, $one)) {
                    $out[] = $one[1];
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * قواعدُ مرشِّح فروع GitHub Actions: `*` لا تعبر `/`، و`**` تعبرها.
     * تُترجَم إلى تعبيرٍ نمطيٍّ بدل المطابقة الحرفيّة — فـ`claude/**`
     * تغطّي فرعاً لا يُطابَق اسمُه حرفاً بحرف.
     */
    private function globMatches(string $pattern, string $branch): bool
    {
        $rx = '';
        $n = strlen($pattern);

        for ($i = 0; $i < $n; $i++) {
            $c = $pattern[$i];

            if ($c === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $rx .= '.*';
                    $i++;
                } else {
                    $rx .= '[^/]*';
                }

                continue;
            }

            $rx .= preg_quote($c, '~');
        }

        return (bool) preg_match('~\A' . $rx . '\z~', $branch);
    }

    /**
     * @test
     *
     * **المرشِّحُ يغطّي الفرعَ الجاري.**
     *
     * وهو الفحصُ الذي كان سيمنع العطل: `[main, develop]` ضدّ
     * `claude/project-development-continuation-7oxhip` ⇒ لا مطابقة.
     */
    public function the_push_filter_covers_the_branch_we_actually_develop_on(): void
    {
        $branch = $this->currentBranch();

        if ($branch === null) {
            $this->markTestSkipped('لا فرعَ جارٍ (HEAD منفصل) — لا يُقاس هنا');
        }

        $patterns = $this->pushBranchPatterns();

        $this->assertNotEmpty($patterns,
            'لا مرشِّحَ فروعٍ عند الدفع في ci.yml — البوّابةُ لا تُطلَق');

        $hit = false;
        foreach ($patterns as $p) {
            if ($this->globMatches($p, $branch)) {
                $hit = true;
                break;
            }
        }

        $this->assertTrue($hit, sprintf(
            'البوّابةُ لا تُطلَق على «%s» — والمرشِّحُ [%s]. '
            . 'فكلُّ دفعةٍ تمرّ بلا فحصٍ آليٍّ واحد، والمستودعُ أخضرُ صمتاً لا نجاحاً.',
            $branch, implode(', ', $patterns)));
    }

    /**
     * @test
     *
     * **والفرعان المأمورُ بالتطوير عليهما مغطّيان صراحةً.**
     *
     * الأوّلُ يقيس الحاضر، وهذا يقيس ما يُدفَع إليه فعلاً في هذا المشروع
     * — فمن عمل على أحدهما ثمّ ضيّق المرشِّح على الآخر يسقط هنا.
     */
    public function both_designated_branches_are_covered(): void
    {
        $patterns = $this->pushBranchPatterns();

        foreach ([
            'claude/project-development-continuation-7oxhip',
            'claude/project-code-review-yjagv',
        ] as $branch) {
            $hit = false;
            foreach ($patterns as $p) {
                if ($this->globMatches($p, $branch)) {
                    $hit = true;
                    break;
                }
            }

            $this->assertTrue($hit,
                "فرعُ التطوير «{$branch}» خارج مرشِّح البوّابة [" . implode(', ', $patterns) . ']');
        }
    }

    /**
     * @test
     *
     * **والبوّابةُ تبني الصورةَ المنشورةَ لا أختَها فقط.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `DEPLOY_COOLIFY.md` يقول «Build Pack = Dockerfile»، والبوّابةُ كانت
     * تبني `Dockerfile.prod` وحدَه. **فما يُفحَص غيرُ ما يُشحَن.**
     *
     * وقد كلّف هذا عطلاً من قبل بنصّه: تعليقٌ في `docker/entrypoint.sh`
     * يقول إنّ إصلاح مجلّدات PDF «كان في entrypoint.prod.sh وحده، وهذا
     * الملفّ هو المُستعمَل فعلاً — فلم يكن يصل».
     */
    public function the_gate_builds_the_image_that_actually_ships(): void
    {
        $yml = $this->workflow();

        // **يُقرأ من وظيفة البناء وحدَها** — ذكرُ الاسم في تعليقٍ في أعلى
        // الملفّ ليس بناءً. (وهذا نصُّ القاعدة الثانية: حارسٌ يمرّ على
        // تعليقٍ عربيٍّ يصف العطل هو ما وقع في هذا المشروع من قبل.)
        if (! preg_match('/^  docker:\s*$(.*?)(?=^  \w|\z)/ms', $yml, $m)) {
            $this->fail('لا وظيفةَ بناءِ صورةٍ في البوّابة إطلاقاً');
        }

        // تُنزع التعليقاتُ من كتلة الوظيفة نفسِها للسبب نفسِه.
        $job = preg_replace('/^\s*#.*$/m', '', $m[1]);

        // الاسمان يُلتقطان سواءٌ أكانا في مصفوفةٍ أم في خطوتين منفصلتين.
        $this->assertMatchesRegularExpression('/\bDockerfile\b(?!\.prod)/', $job,
            'البوّابةُ لا تبني `Dockerfile` — وهو ما ينشره Coolify. '
            . 'فصورةٌ تُفحَص وأخرى تُشحَن.');

        $this->assertStringContainsString('Dockerfile.prod', $job,
            'البوّابةُ لا تبني `Dockerfile.prod` — والملفّان يتباعدان بلا حارس');
    }

    // ══════════════════════════════════════════════════════════════════
    //  حاجزُ APP_DEBUG — **في الملفّ المنشور، ومُجرَّبٌ بالتشغيل لا بالقراءة**
    // ══════════════════════════════════════════════════════════════════

    /**
     * يُشغّل منطقَ الحاجز وحدَه: تُقتطع الكتلةُ من `entrypoint.sh` وتُنفَّذ
     * في `sh` ببيئةٍ مصنوعة. **فقراءةُ النصّ تُثبت وجودَ الحاجز، والتشغيلُ
     * وحدَه يُثبت أنّه يحجب.**
     *
     * @param  array<string,string>  $env
     */
    private function runDebugGuard(array $env): int
    {
        $src = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $start = strpos($src, 'EFFECTIVE_DEBUG="${APP_DEBUG:-}"');
        $end = strpos($src, '# ── بدء الخادم فوراً');

        $this->assertNotFalse($start, 'حاجزُ APP_DEBUG اختفى من entrypoint.sh');
        $this->assertNotFalse($end, 'تعذّر تحديدُ نهاية كتلة الحاجز');

        $block = substr($src, $start, $end - $start);

        $dir = sys_get_temp_dir() . '/amial-guard-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/g.sh', "#!/bin/sh\nENV_FILE=\"\$PWD/.env\"\n" . $block . "\nexit 0\n");

        // ملفُّ بيئةٍ حقيقيّ حين يطلبه المشهد — فالحاجزُ يقرؤه حين تغيب
        // متغيّراتُ الغلاف، وهي حالةُ Coolify حين يُنسى الضبط.
        if (isset($env['__ENV_FILE_DEBUG'])) {
            file_put_contents($dir . '/.env', "APP_KEY=x\nAPP_DEBUG={$env['__ENV_FILE_DEBUG']}\n");
            unset($env['__ENV_FILE_DEBUG']);
        }

        // **البيئةُ تُفرَّغ أوّلاً (`env -i`).** فعمليّةُ الاختبار نفسُها
        // تحمل `APP_DEBUG=true` (من `.env` عبر Dotenv)، فتتسرّب إلى الغلاف
        // الابن ويُحجَب مشهدُ «لا متغيّرَ إطلاقاً» وهو سليم. **وحارسٌ
        // يقيس بيئةَ من يشغّله لا بيئةَ ما يفحصه يكذب في الاتّجاهين.**
        $prefix = 'env -i PATH=/usr/bin:/bin ';
        foreach ($env as $k => $v) {
            // الاسمُ لا يُقتبَس — `'K'=v` ليس إسناداً في sh بل أمرٌ مجهول (١٢٧).
            $prefix .= $k . '=' . escapeshellarg($v) . ' ';
        }

        $cmd = 'cd ' . escapeshellarg($dir) . ' && ' . $prefix . ' sh g.sh >/dev/null 2>&1; echo $?';
        $code = (int) trim((string) shell_exec($cmd));

        array_map('unlink', glob($dir . '/*') ?: []);
        @unlink($dir . '/.env');
        @rmdir($dir);

        return $code;
    }

    /**
     * @test
     *
     * **ثلاثُ بوّاباتٍ بعتبةٍ واحدةٍ لتحليل Dart.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمن — ظهر في أوّل تشغيلٍ للبوّابة بعد إصلاح P0-١:**
     *
     *   flutter analyze  →  133 issues found  →  exit 1
     *   (صفر خطأ · ١٣ تحذير · ١٢٠ ملاحظة)
     *
     * و`codemagic.yaml` — **وهو الذي يبني التطبيقَ المشحون** —
     * و`scripts/verify.sh` كلاهما يعدّ `error •` وحدَها، بسببٍ مكتوبٍ
     * فيهما: دلالةُ رمز خروج `flutter analyze` تختلف بين الإصدارات.
     *
     * **فكان CI الشاذَّ لا الأشدّ**: يمنع دمجاً يبني codemagic نسخته بلا
     * شكوى. وثلاثُ عتباتٍ تُنتج «أخضرُ هنا وأحمرُ هناك» — وهو ما يُدرَّب
     * النظرُ على تجاهله، فتموت البوّابةُ الثلاثُ معاً.
     *
     * ولا يُقاس تطابقُ النصّ — تُقاس **العتبة**: أنّ الثلاثة يعدّون
     * `error •` ولا يعتمدون رمزَ الخروج المجرَّد.
     */
    public function all_three_gates_use_the_same_dart_threshold(): void
    {
        $sources = [
            'ci.yml' => base_path('../.github/workflows/ci.yml'),
            'codemagic.yaml' => base_path('../codemagic.yaml'),
            'verify.sh' => base_path('scripts/verify.sh'),
        ];

        foreach ($sources as $name => $path) {
            $this->assertFileExists($path, "«{$name}» مفقود");

            $src = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression("~error •~", $src,
                "«{$name}» لا يعدّ `error •` — فعتبتُه غيرُ عتبة أختيه، "
                . 'و«أخضرُ هنا وأحمرُ هناك» يُميت البوّاباتِ الثلاث');

            // **ولا يُترَك `flutter analyze` مجرّداً يقرّر بنفسه.**
            $this->assertDoesNotMatchRegularExpression(
                '~run:\s*flutter analyze\s*$~m', $src,
                "«{$name}» يعتمد رمزَ خروج `flutter analyze` المجرّد — "
                . 'ودلالتُه تختلف بين الإصدارات، فيسقط على ملاحظةٍ أسلوبيّة');
        }
    }

    /**
     * @test
     *
     * **وتجهيزُ بيئة CI يُنشئ ما لا يتتبّعه git.**
     *
     * `storage/` كلُّه مُتجاهَل، فلا وجودَ له في نسخة CI — و`passport:keys`
     * يكتب فيه مباشرةً. سقط الباكندُ عليه في أوّل تشغيلٍ حقيقيّ:
     *
     *   file_put_contents(…/storage/oauth-public.key): No such file or directory
     *
     * ولا يظهر محلّيّاً: المجلّدُ قائمٌ على جهاز التطوير منذ أوّل تشغيل.
     */
    public function the_ci_environment_creates_the_untracked_storage_tree(): void
    {
        // **تُنزع التعليقاتُ أوّلاً.** التعليقُ الشارحُ أعلى الخطوة يذكر
        // `passport:keys` بنصّه، فيجده `strpos` قبل الأمر الحقيقيّ ويُقاس
        // ترتيبٌ لا وجودَ له. (سقط هذا الحارسُ بذلك في أوّل تشغيل — وهو
        // الفخُّ نفسُه الذي وقع في هذا المشروع أربعَ مرّاتٍ الآن.)
        $yml = (string) preg_replace('~^\s*#.*$~m', '', $this->workflow());

        $keys = strpos($yml, 'passport:keys');
        $mkdir = strpos($yml, 'mkdir -p storage');

        $this->assertNotFalse($mkdir,
            'البوّابةُ لا تُنشئ شجرةَ storage — و`passport:keys` يكتب فيها فيسقط');

        $this->assertLessThan($keys, $mkdir,
            'شجرةُ storage تُنشأ **بعد** `passport:keys` — والترتيبُ هنا شرطُ عمل');
    }

    /**
     * @test
     *
     * **الحاجزُ يحجب** — وهو نصفُ الفحص الذي يُنسى.
     */
    public function the_deployed_entrypoint_refuses_to_serve_with_debug_on(): void
    {
        $this->assertSame(1, $this->runDebugGuard(['APP_DEBUG' => 'true']),
            'الصورةُ المنشورةُ تُقدّم الخدمةَ وAPP_DEBUG=true — '
            . 'فأثرُ كلّ استثناءٍ يُعرض لأيّ مستخدمٍ في منصّةٍ ماليّة');
    }

    /**
     * @test
     *
     * **ويقرأ `.env` حين يغيب متغيّرُ الغلاف** — وهي حالةُ Coolify نفسِها:
     * المتغيّرُ غيرُ مضبوطٍ في اللوحة، والقيمةُ في الملفّ.
     */
    public function the_guard_reads_the_env_file_when_the_shell_variable_is_absent(): void
    {
        $this->assertSame(1, $this->runDebugGuard(['__ENV_FILE_DEBUG' => 'true']),
            'الحاجزُ يقرأ متغيّرَ الغلاف وحدَه — فقيمةٌ في .env تمرّ من تحته');
    }

    /**
     * @test
     *
     * **ولا يحجب ما لا ينبغي حجبُه** — حارسٌ يمنع كلَّ شيءٍ يُنزَع بعد يوم.
     */
    public function the_guard_lets_a_correct_deployment_and_local_dev_through(): void
    {
        $this->assertSame(0, $this->runDebugGuard(
            ['APP_DEBUG' => 'false', 'APP_ENV' => 'production']),
            'نشرةٌ سليمةٌ (APP_DEBUG=false) تُحجَب — الحاجزُ يشلّ الإنتاج');

        $this->assertSame(0, $this->runDebugGuard([]),
            'بيئةٌ بلا APP_DEBUG إطلاقاً تُحجَب — وهي الحالةُ الافتراضيّة');

        // بيئةُ الديمو المحلّيّة: debug مفتوحٌ عن قصدٍ ومصرَّحٌ به.
        $this->assertSame(0, $this->runDebugGuard(
            ['APP_DEBUG' => 'true', 'AMIAL_ALLOW_DEBUG' => 'true']),
            'منفذُ AMIAL_ALLOW_DEBUG لا يعمل — فتنكسر بيئةُ التطوير المحلّيّة');
    }

    /**
     * @test
     *
     * **ومنفذُ الخروج مضبوطٌ حيث يُحتاج** — وإلّا سقط `docker compose up`
     * المحلّيُّ وقتَ أوّل تشغيل، ولا أحدَ يعرف لماذا.
     */
    public function the_local_demo_declares_its_debug_exemption(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('AMIAL_ALLOW_DEBUG', $compose,
            'بيئةُ الديمو تعمل بـ APP_DEBUG=true (‏.env.demo:10) ولا تُصرّح '
            . 'بالمنفذ — فالحاجزُ سيوقفها');
    }
}
