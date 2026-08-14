<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-GIT-GUARD-001 — **الحاجزُ يفرّق بين المأذون والممنوع، لا بين git وغيره.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * مهارةُ `git-guardrails-claude-code` تحجب `git push` كلَّه. وهذا المشروع
 * **مأمورٌ بالدفع** إلى فرعين بعينهما، فنسخُها كما هي يمنع العملَ المطلوب
 * ويترك الخطأَ الحقيقيّ مفتوحاً: **الدفعَ إلى فرعٍ غيرِ مأذون**.
 *
 * فأُعيد الحدُّ: تُحجب الوجهةُ لا الأداة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الحارسُ يُجرّب الحاجزَ بالعكس** (القاعدة الثانية): لا يُكتفى بأنّ
 * الممنوع يُحجب — يُجرَّب أنّ المأذونَ يمرّ. فحاجزٌ يحجب كلَّ شيء يمرّ في
 * نصف اختبارٍ ويشلّ المشروع.
 *
 * **ولا يُطلَق هذا الخطّافُ في بيئة التشغيل عن بُعد** — قِيس:
 * `CLAUDE_PROJECT_DIR` غيرُ مضبوط، وجذرُ المشروع ليس `Anjaz`. فهو يحمي
 * جهازَ صاحب المشروع وحدَه، **ومنطقُه يُحرَس هنا وإن لم يُطلَق هناك**.
 */
class GitGuardrailHookTest extends TestCase
{
    private function hook(): string
    {
        return dirname(base_path()) . '/.claude/hooks/block-dangerous-git.sh';
    }

    /** يُشغَّل الخطّافُ على أمرٍ نصّيّ ويُعاد رمزُ خروجه. */
    private function runHook(string $command): int
    {
        $payload = json_encode(
            ['tool_input' => ['command' => $command]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $p = proc_open(
            escapeshellarg($this->hook()),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($p, 'تعذّر تشغيلُ الخطّاف');

        fwrite($pipes[0], (string) $payload);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($p);
    }

    /** @test */
    public function the_hook_exists_and_is_executable(): void
    {
        $this->assertFileExists($this->hook(),
            'حاجزُ git اختفى — والدفعُ إلى فرعٍ خطأ يمرّ بلا مانع');

        $this->assertTrue(is_executable($this->hook()),
            'الحاجزُ غيرُ قابلٍ للتنفيذ — فلا يُطلَق ولو سُجّل');
    }

    /** @test */
    public function it_is_registered_as_a_pretooluse_hook_on_bash(): void
    {
        $settings = json_decode((string) file_get_contents(
            dirname(base_path()) . '/.claude/settings.json'), true);

        $entries = $settings['hooks']['PreToolUse'] ?? [];

        $this->assertNotEmpty($entries,
            'الحاجزُ مبنيٌّ ولا يُوصَل إليه — لا تسجيلَ له في PreToolUse');

        $matchers = array_column($entries, 'matcher');
        $this->assertContains('Bash', $matchers,
            'الحاجزُ مسجَّلٌ على غير أداة Bash — فلا يرى أمرَ git أصلاً');
    }

    /**
     * @test
     *
     * **الممنوعُ يُحجب.** ولو مرّ واحدٌ منها لَما كان حاجزاً.
     */
    public function destructive_and_unauthorised_commands_are_blocked(): void
    {
        $blocked = [
            'git push origin main',
            'git push origin master',
            'git push',                       // بلا وجهةٍ صريحة ⇐ الفرعُ الحاليّ مجهول
            'git push -u origin develop',
            'git push --force origin claude/project-development-continuation-7oxhip',
            'git reset --hard origin/main',
            'cd /home/user/Anjaz && git reset --hard',
            'git clean -fd',
            'git clean -f',
            'git branch -D claude/project-code-review-yjagv',
            'git checkout .',
            'git restore .',
        ];

        foreach ($blocked as $cmd) {
            $this->assertSame(2, $this->runHook($cmd),
                "مرّ أمرٌ كان يجب حجبُه: «{$cmd}»");
        }
    }

    /**
     * @test
     *
     * **والمأذونُ يمرّ** — وهذا نصفُ الحارس الذي يُنسى.
     *
     * حاجزٌ يحجب كلَّ شيء يجتاز فحصَ «هل يحجب الخطر؟» ثمّ يشلّ كلَّ التزامٍ
     * سليم. والمشروعُ مأمورٌ بالدفع إلى فرعين — فمنعُهما عطلٌ لا حماية.
     */
    public function the_two_authorised_branches_and_ordinary_git_pass(): void
    {
        $allowed = [
            'git push -u origin claude/project-development-continuation-7oxhip',
            'git push -u origin claude/project-code-review-yjagv',
            // مأذونةٌ صراحةً: إعادةُ تأسيس فرعٍ دُمج طلبُه.
            'git push --force-with-lease -u origin claude/project-development-continuation-7oxhip',
            'git status --short',
            'git commit -m "رسالة"',
            'git checkout -b claude/project-development-continuation-7oxhip',
            'git restore --staged app/Models/User.php',
            'bash scripts/verify.sh',
        ];

        foreach ($allowed as $cmd) {
            $this->assertSame(0, $this->runHook($cmd),
                "حُجب أمرٌ مأذون: «{$cmd}» — الحاجزُ يشلّ العملَ المطلوب");
        }
    }
}
