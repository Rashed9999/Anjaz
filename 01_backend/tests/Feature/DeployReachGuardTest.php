<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-DEPLOY-REACH-001 — **التزامٌ لا يبلغ فرعَ النشر ليس منشوراً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع:** أربعةُ التزاماتٍ متتالية — شاشةُ الكاشير،
 * ومحفظةُ التاجر، ونطاقُ القطاع، ودليلُ الجملة — دُفعت إلى فرع التطوير
 * `claude/project-development-continuation-7oxhip` وحدَه. وCoolify يستورد
 * `claude/project-code-review-yjagv`.
 *
 * فبقي العملُ كلُّه محبوساً، **والبوّابةُ خضراءُ في كلّ مرّة**: ٣٣ فحصاً
 * تمرّ بصفر فشل، وهي صادقةٌ — تفحص الشيفرةَ ولا تسأل **أين ذهبت**.
 * وكشفه صاحبُ المشروع: «هذا ليس الفرع الذي ندفع إليه».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذه أختُ القاعدتين التاسعة والثانية عشرة:** زرٌّ لم يُضغط ليس
 * مبنيّاً، وصفحةٌ لا يُوصل إليها ليست مبنيّة — **والتزامٌ لا يبلغ فرعَ
 * النشر لم يُنشَر**، مهما كان سليماً.
 *
 * **ولا يُحوَّل هذا إلى دفعٍ تلقائيّ.** الفرعُ وجهةٌ مأذونة، والدفعُ
 * قرار. فالحارسُ **يقول** ولا يفعل.
 */
class DeployReachGuardTest extends TestCase
{
    /** فرعُ النشر كما تقوله لوحةُ Coolify: `Importing Rashed9999/Anjaz:<branch>`. */
    private const DEPLOY_BRANCH = 'claude/project-code-review-yjagv';

    private function git(string $args): ?string
    {
        $root = realpath(__DIR__ . '/../../..');
        $out = @shell_exec("git -C " . escapeshellarg((string) $root) . " {$args} 2>/dev/null");

        return $out === null ? null : trim($out);
    }

    /**
     * **لا التزامَ في فرع التطوير غائبٌ عن فرع النشر.**
     *
     * ويُقرأ من `origin/` لا من المحلّيّ: الفرعُ المحلّيُّ قد يكون
     * محدَّثاً ولم يُدفَع بعد، وهو نفسُ العطل بثوبٍ آخر.
     */
    /** @test */
    public function nothing_is_stranded_outside_the_deployment_branch(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والغيابُ يُقال بسببه ولا يُمرَّر صفراً صامتاً.** في بيئةٍ بلا
        // git أو بلا `origin` يُخطَّى الفحصُ صراحةً — فحارسٌ يخرج أخضرَ
        // لأنّه لم يفحص شيئاً هو الصمتُ بثوب نجاح. (القاعدة السابعة.)
        // ══════════════════════════════════════════════════════════════
        if ($this->git('rev-parse --git-dir') === null) {
            $this->markTestSkipped('لا مستودعَ git هنا — لا يُقاس بلوغُ النشر.');
        }

        $deploy = $this->git('rev-parse --verify --quiet origin/' . self::DEPLOY_BRANCH);
        if (! $deploy) {
            $this->markTestSkipped(
                'لا نسخةَ محلّيّةً من `origin/' . self::DEPLOY_BRANCH . '` — '
                . 'يُشغَّل `git fetch origin` أوّلاً.');
        }

        // ══════════════════════════════════════════════════════════════
        // **ولا يُخطَّى الفحصُ لأنّنا واقفون على فرع النشر.** كانت هنا
        // عودةٌ مبكّرةٌ عند `HEAD == deploy` — وهي **تُعمي الحارسَ في
        // اللحظة التي يُحتاج فيها**: العملُ المحبوسُ في فرع التطوير لا
        // يظهر من فرع النشر إلّا بهذا السؤال بعينه.
        //
        // والمقارنةُ من `origin` إلى `origin` فلا تتعلّق بموضعنا أصلاً:
        // الفرعُ المحلّيُّ قد يكون محدَّثاً ولم يُدفَع، وهو نفسُ العطل
        // بثوبٍ آخر. و`HEAD` لا يُسأل عنه — ما لم يُدفَع عملٌ جارٍ لا عطل.
        // ══════════════════════════════════════════════════════════════
        $devRef = 'origin/claude/project-development-continuation-7oxhip';
        if (! $this->git("rev-parse --verify --quiet {$devRef}")) {
            $this->markTestSkipped("لا نسخةَ محلّيّةً من `{$devRef}`.");
        }

        $stranded = (string) $this->git(
            "log --oneline origin/" . self::DEPLOY_BRANCH . "..{$devRef}");

        $lines = array_values(array_filter(explode("\n", $stranded)));

        $this->assertSame([], $lines, sprintf(
            "**التزاماتٌ مدفوعةٌ إلى فرع التطوير ولم تبلغ فرعَ النشر:**\n  %s\n\n"
            . "وCoolify يستورد `%s` — فهذا العملُ **غيرُ منشورٍ مهما كان\n"
            . "سليماً**، والبوّابةُ خضراءُ لأنّها تفحص الشيفرةَ ولا تسأل\n"
            . "أين ذهبت.\n\n"
            . "  العلاج: git checkout %s && git merge --ff-only %s\n"
            . "          && git push -u origin %s",
            implode("\n  ", $lines),
            self::DEPLOY_BRANCH,
            self::DEPLOY_BRANCH, 'claude/project-development-continuation-7oxhip',
            self::DEPLOY_BRANCH));
    }
}
