<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SKILL-POINTER-001 — **المؤشِّرُ يعمل حيث يُقرأ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** سبعَ عشرةَ مهارةً — ٢٧٠٠ سطر — استُدعي منها اثنتان،
 * وكلتاهما بعد مطالبةٍ صريحة. فبُني خطّافٌ يحقن فهرسَها في كلّ رسالة.
 *
 * **وقِيس فلم يُطلَق الخطّافُ مرّةً واحدةً في جلسةٍ كاملة**: مسجَّلٌ في
 * `Anjaz/.claude/settings.json`، وجذرُ المشروع في بيئة التشغيل ليس
 * `Anjaz`. فالأداةُ التي بُنيت لتمنع «مبنيٌّ ولا يُوصَل إليه» وقعت فيه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقناتان المضمونتان اثنتان لا ثلاث:**
 *
 *   ① `CLAUDE.md` — يُحمَّل في كلّ جلسة.
 *   ② وصفُ كلّ مهارة — يبقى في السياق دائماً.
 *
 * فهذا الحارسُ يحرسهما وحدَهما، ويمنع ثلاثةَ ارتدادات:
 *
 *   · أن يعود وعدُ أمانٍ لخطّافٍ لا يُطلَق. (**وعدٌ كاذبٌ أسوأ من صمت**:
 *     يجعل من يصدّقه يترك الاحتياط.)
 *   · أن يهبط جدولُ الاستدعاء من رأس الملفّ إلى قاعٍ لا يُقرأ.
 *   · أن تعود الأوصافُ تبدأ كلُّها بالكلمة نفسِها، فلا يميّز أوّلُ ما
 *     يُقرأ منها شيئاً.
 *
 * والمرجع: مهارة `writing-for-agents` — «قدِّم الكلمةَ الرائدة؛ المؤشِّرُ
 * هو موضعُ عمله»، و«هدفٌ لا غنى عنه خلف مؤشِّرٍ ضعيفِ الصياغة هو عطلُ
 * تباين — اشحذ الصياغةَ أوّلاً».
 */
class SkillPointerIntegrityGuardTest extends TestCase
{
    private const SKILLS_DIR = '/../.claude/skills';

    /**
     * **يُسوّى البياضُ قبل المطابقة.**
     *
     * الملفُّ ملفوفٌ على ٧٥ محرفاً، فجملةٌ واحدةٌ تنقسم على سطرين —
     * ومطابقةٌ نصّيّةٌ عليها تسقط وهي تصف نصّاً موجوداً. (سقط هذا الحارسُ
     * بهذا في أوّل تشغيل.)
     */
    private function claudeMd(): string
    {
        return (string) preg_replace(
            '/\s+/u', ' ', (string) file_get_contents(base_path('CLAUDE.md')));
    }

    /** النصُّ الخام — للمواضع التي يهمّ فيها الترتيبُ لا الصياغة. */
    private function claudeMdRaw(): string
    {
        return (string) file_get_contents(base_path('CLAUDE.md'));
    }

    /** @return array<string,string> اسمُ المهارة ⇐ وصفُها */
    private function descriptions(): array
    {
        $dir = base_path(self::SKILLS_DIR);
        $out = [];

        foreach (glob($dir . '/*/SKILL.md') as $file) {
            $text = (string) file_get_contents($file);

            if (! preg_match('/^---\n(.*?)\n---/s', $text, $m)) {
                continue;
            }
            if (preg_match('/^description:\s*(.+?)(?=\n\w+:|\z)/ms', $m[1], $d)) {
                $out[basename(dirname($file))] = trim(preg_replace('/\s+/u', ' ', $d[1]));
            }
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① القناةُ المضمونة: رأسُ CLAUDE.md
    // ══════════════════════════════════════════════════════════════════

    public function test_the_invocation_table_sits_at_the_top_not_the_bottom(): void
    {
        $md = $this->claudeMdRaw();

        $table = mb_strpos($md, 'ما تفعله الآن');
        $this->assertNotFalse($table, 'لا جدولَ استدعاءٍ في CLAUDE.md');

        $firstRule = mb_strpos($md, '## القاعدة الأولى');
        $this->assertNotFalse($firstRule);

        // **قبل القاعدة الأولى** — فالمؤشِّرُ يعمل حيث يُقرأ أوّلاً.
        $this->assertLessThan($firstRule, $table,
            'جدولُ الاستدعاء تحت القواعد — والمؤشِّرُ في القاع لا يُطلق شيئاً');
    }

    public function test_the_top_block_states_a_completion_criterion(): void
    {
        $md = mb_substr($this->claudeMd(), 0, mb_strpos($this->claudeMd(), '## القاعدة الأولى'));

        // **«يُكتب سطران» بلا حدٍّ للتمام يدعو إلى إنهاءٍ مبكّر.** فيُقال
        // متى يكون التصريحُ تامّاً: كلُّ صنفٍ مذكورٌ — مستدعىً أو مستثنىً.
        $this->assertStringContainsString('المهارات المنطبقة', $md);
        $this->assertStringContainsString('ولا تنطبق', $md);
        $this->assertStringContainsString('الصمتُ عن صنفٍ ليس استثناءً له', $md,
            'لا حدَّ للتمام — فيُكتفى بذكر مهارةٍ واحدةٍ ويُسمّى تصريحاً');
    }

    public function test_no_promise_of_a_safety_net_that_does_not_fire(): void
    {
        $md = $this->claudeMd();

        // **الإثباتُ موجبٌ لا سالب.** نفيُ العبارة وحدَه يسقط على اقتباسها
        // لتصحيحها — وهو الاستعمالُ الصحيح. فيُطلَب بدلَه أن يكون القياسُ
        // مكتوباً: أنّ الخطّافَ لم يُطلَق. فمن يُعيد الوعدَ يحذف هذا معه.
        $this->assertStringContainsString('فلم يُطلَق الخطّافُ', $md,
            'اختفى القياسُ الذي يقول إنّ الخطّافَ لا يُطلَق — '
            . 'ووعدُ أمانٍ بلا تكذيبه يُقرأ صحيحاً');

        $this->assertStringContainsString('القناةُ الوحيدةُ المضمونة', $md,
            'لا يُقال أيُّ قناةٍ يُعتمد عليها فعلاً');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② القناةُ الثانية: أوصافُ المهارات
    // ══════════════════════════════════════════════════════════════════

    public function test_every_skill_carries_a_description(): void
    {
        $d = $this->descriptions();

        $this->assertGreaterThanOrEqual(21, count($d),
            'مهارةٌ بلا وصفٍ لا يصل النموذجَ منها شيء');

        foreach ($d as $name => $desc) {
            $this->assertGreaterThan(40, mb_strlen($desc),
                "وصفُ «{$name}» أقصرُ من أن يُميّز حالةَ إطلاقه");
        }
    }

    public function test_descriptions_do_not_all_open_with_the_same_word(): void
    {
        $d = $this->descriptions();

        $leading = [];
        foreach ($d as $desc) {
            $leading[] = mb_substr(trim($desc), 0, mb_strpos(trim($desc) . ' ', ' '));
        }

        $counts = array_count_values($leading);
        arsort($counts);
        $topWord = (string) array_key_first($counts);
        $topCount = (int) reset($counts);

        // **كلمةٌ رائدةٌ واحدةٌ في كلّ الأوصاف = مؤشِّرٌ لا يميّز.**
        // كانت إحدى وعشرون كلُّها تبدأ بـ«تُستدعى»، فأوّلُ ما يُقرأ من كلّ
        // وصفٍ لا يقول شيئاً عمّا يُطلقه.
        $this->assertLessThanOrEqual(
            (int) ceil(count($leading) / 3),
            $topCount,
            "«{$topWord}» تتصدّر {$topCount} وصفاً — الكلمةُ الرائدة لا تميّز",
        );
    }

    public function test_the_mandatory_two_are_named_mandatory_in_both_channels(): void
    {
        $md = $this->claudeMd();
        $d = $this->descriptions();

        foreach (['amial-impact', 'amial-completeness'] as $skill) {
            $this->assertStringContainsString($skill, $md, "«{$skill}» غائبةٌ عن CLAUDE.md");

            $this->assertStringContainsString('إلزاميّة', $d[$skill] ?? '',
                "«{$skill}» إلزاميّةٌ في الجدول وليست كذلك في وصفها — "
                . 'والقناتان يجب أن تقولا الشيءَ نفسه');
        }
    }

    public function test_the_table_covers_every_skill_that_exists(): void
    {
        $md = $this->claudeMd();

        foreach (array_keys($this->descriptions()) as $skill) {
            // **مهارةٌ خارج الجدول لا يُصرَّح عنها ولا تُستثنى** — فتسقط
            // من حدّ التمام صامتةً، وهو بابُ العطل الأصليّ نفسُه.
            $this->assertStringContainsString($skill, $md,
                "«{$skill}» موجودةٌ ولا يذكرها جدولُ CLAUDE.md");
        }
    }
}
