<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SKILLS-001 — مهارات المشروع تُحمَّل فعلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل: سبعَ عشرةَ مهارةً كُتبت ولم تُفتح ولا مرّة.**
 *
 * كلُّ ملفٍّ رُفع بترويستين لا واحدة. والمُحمِّل يتوقّف عند أوّل ترويسةٍ
 * مغلقة، فقرأ الأولى وحدها:
 *
 *     name: 1
 *     description: "T"
 *
 * وترك الثانية — الحقيقيّة — نصّاً عاديّاً داخل المتن. فوصلتني القائمة
 * ثلاثة أسطر: `1: T` و`2: T` و`17: T`.
 *
 * **والوصفُ هو ما يُقرّر الاستدعاء.** لا تُفتح كلُّ مهارةٍ لتُقرأ ثمّ
 * يُحكم؛ يُقرأ سطرُ الوصف ويُحكم. فوصفٌ حرفُه `T` لا يُستدعى أبداً.
 *
 * وأخطرُ ما فيه أنّه **لا يُنتج خطأً**: المهارة موجودةٌ ومقروءةٌ وصالحة —
 * وميّتة. مثلُ زرٍّ مبنيٍّ لا يصل إليه أحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * وهذا الملفّ يمنع ثلاثة: عودةَ الترويسة المزدوجة، ووصفاً لا يقول متى،
 * وضياعَ المهارات من المستودع (فموضعُها في الحاوية يموت معها).
 */
class ProjectSkillsGuardTest extends TestCase
{
    private function skillsDir(): ?string
    {
        $p = base_path('../.claude/skills');

        return is_dir($p) ? $p : null;
    }

    /** @return array<string,string> المجلّد => محتوى SKILL.md */
    private function skills(): array
    {
        $dir = $this->skillsDir();
        $out = [];

        foreach (glob($dir . '/*/SKILL.md') as $f) {
            $out[basename(dirname($f))] = file_get_contents($f);
        }

        return $out;
    }

    /**
     * @test
     *
     * **المهارات في المستودع لا في الحاوية.**
     *
     * الحاوية تُستعاد بعد فترة خمول، فما في `/root/.claude` يضيع. وما في
     * المستودع يُدفع ويُقرأ في كلّ جلسة.
     */
    public function the_skills_live_in_the_repository(): void
    {
        $dir = $this->skillsDir();

        $this->assertNotNull($dir, 'مجلّد .claude/skills غير موجود في المستودع');

        $this->assertGreaterThanOrEqual(17, count($this->skills()),
            'عددُ المهارات أقلّ ممّا رُكّب — أضاع دمجٌ بعضَها؟');
    }

    /**
     * @test
     *
     * **ترويسةٌ واحدةٌ لا اثنتان — وهو العطل نفسه.**
     */
    public function no_skill_has_a_second_front_matter_block(): void
    {
        foreach ($this->skills() as $slug => $src) {
            $body = preg_replace('~\A---\n.*?\n---\n~s', '', $src, 1);

            $this->assertNotNull($body);

            // ترويسةٌ ثانيةٌ = سطر `---` يليه `name:` مباشرةً.
            $this->assertDoesNotMatchRegularExpression(
                '~^---\s*\nname:~m',
                (string) $body,
                "المهارة «{$slug}» فيها ترويسةٌ ثانية — فلا يُقرأ منها إلّا الأولى");
        }
    }

    /**
     * @test
     *
     * **ولكلٍّ وصفٌ يقول متى تُستدعى.**
     *
     * ولا يُكتفى بـ«ليس فارغاً»: `T` ليست فارغةً وهي لا تقول شيئاً. فيُشترط
     * طولٌ معقول، ولفظٌ يدلّ على الحالة لا على المحتوى.
     */
    public function every_skill_says_when_it_should_be_invoked(): void
    {
        foreach ($this->skills() as $slug => $src) {
            $this->assertMatchesRegularExpression('~\A---\nname:\s*(\S+)~', $src,
                "المهارة «{$slug}» بلا اسمٍ في الترويسة");

            preg_match('~^name:\s*(.+)$~m', $src, $n);
            $this->assertSame($slug, trim($n[1] ?? ''),
                "اسمُ «{$slug}» في الترويسة يخالف مجلّدها — فتُستدعى باسمٍ لا يدلّ عليها");

            preg_match('~^description:\s*(.+)$~m', $src, $m);
            $desc = trim($m[1] ?? '');

            $this->assertGreaterThan(40, mb_strlen($desc),
                "وصفُ «{$slug}» أقصرُ من أن يقول متى تُستدعى: «{$desc}»");

            $this->assertMatchesRegularExpression('~تُستدعى|عند |قبل ~u', $desc,
                "وصفُ «{$slug}» يصف ماذا تحوي لا متى تُستدعى — فلا تُفتح");
        }
    }

    /**
     * @test
     *
     * **الاستدعاء مُلزَمٌ ببنية، لا بتذكُّر.**
     *
     * ══════════════════════════════════════════════════════════════
     * **الثمن:** سبعَ عشرةَ مهارةً — ٢٧٠٠ سطر — واستُدعي منها اثنتان،
     * وكلتاهما بعد أن طالب بهما صاحبُ المشروع صراحةً.
     *
     * والسبب ليس نسياناً: المهارات اختياريّةٌ بحكم بنائها. يصل النموذجَ
     * سطرُ وصفٍ لكلٍّ ويقرّر هو، ولا شيء يُلزمه. **واختياريٌّ = غيرُ
     * موجود.**
     *
     * فيُحقن فهرسُها في كلّ رسالة (`UserPromptSubmit`)، ويُطلب تصريحٌ
     * مكتوبٌ قبل كلّ تغيير (القاعدة الحادية عشرة). ونزعُ أيٍّ منهما يُعيد
     * المهارات إلى الرفّ.
     */
    public function invoking_the_skills_is_bound_by_structure_not_memory(): void
    {
        $root = dirname(base_path());

        // ١) الـhook موجودٌ ويعمل.
        $hook = $root . '/.claude/hooks/skills-index.sh';

        $this->assertFileExists($hook, 'فهرسُ المهارات لم يعد يُحقن — فتعود اختياريّة');
        $this->assertTrue(is_executable($hook), 'الـhook غير قابلٍ للتنفيذ');

        $out = (string) shell_exec(escapeshellarg($hook) . ' 2>/dev/null');

        $this->assertStringContainsString('amial-impact', $out,
            'الفهرس لا يذكر المهارة الإلزاميّة عند التعديل');
        $this->assertStringContainsString('amial-completeness', $out,
            'الفهرس لا يذكر المهارة الإلزاميّة قبل قول «تمّ»');

        // ٢) ومسجَّلٌ على كلّ رسالة لا على بدء الجلسة وحده.
        //    (بدءُ الجلسة يُقرأ مرّةً ثمّ يُنسى تحت طول المحادثة.)
        $settings = json_decode(
            file_get_contents($root . '/.claude/settings.json'), true);

        $this->assertArrayHasKey('UserPromptSubmit', $settings['hooks'] ?? [],
            'الفهرس لم يعد يُحقن في كلّ رسالة — فيُنسى مع طول الجلسة');

        // ٣) والقاعدة الحادية عشرة تطلب التصريح المكتوب.
        $claude = file_get_contents(base_path('CLAUDE.md'));

        $this->assertStringContainsString('القاعدة الحادية عشرة', $claude,
            'قاعدةُ التصريح بالمهارات أُزيلت من CLAUDE.md');
        $this->assertStringContainsString('الصمت ليس جواباً', $claude,
            'لم يعد يُطلب تصريحٌ صريحٌ بعدم الانطباق — والصمتُ لا يُراجَع');
    }

    /**
     * @test
     *
     * **والتعارضاتُ المقيسة تبقى مكتوبةً حتى تُحسم.**
     *
     * سبعةٌ منها تناقض ما هو مبنيٌّ اليوم (GetX مقابل Riverpod، وغيابُ نمط
     * Repository، وصيغةُ ردّ API…). وحذفُ الجدول يجعلها تُقرأ كأنّها
     * مُعتمدة، فأُنفّذ قاعدةً تكسر ما يعمل.
     */
    public function the_measured_conflicts_stay_recorded(): void
    {
        $readme = $this->skillsDir() . '/README.md';

        $this->assertFileExists($readme, 'سجلّ التعارضات حُذف');

        $src = file_get_contents($readme);

        foreach (['GetX', 'Repository', 'uuid'] as $needle) {
            $this->assertStringContainsString($needle, $src,
                "تعارضٌ مقيسٌ اختفى من السجلّ: {$needle}");
        }

        // والمبتوران يبقيان معلومَين: ما لم يصل لا يُملأ بالتخمين.
        $this->assertStringContainsString('مبتوران', $src,
            'لم يعد يُقال إنّ ملفّين وصلا ناقصَين');
    }
}
