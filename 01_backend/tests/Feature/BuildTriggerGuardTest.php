<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-CI-TRIGGER-001 — **بناءٌ لا يُطلقه شيءٌ ليس بناءً آليّاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع، بنصّ صاحب المشروع:**
 *
 *     «منذ أيّامٍ وأنا لم أستطع عملَ بناءٍ للتطبيق أو نشرَ المشروع...
 *      بسبب لم أسمع تمّ الدفع.... فقط البوّابات»
 *
 * **وقِيس فكان `codemagic.yaml` بلا `triggering` إطلاقاً** — لا فرعاً
 * يُراقَب ولا حدثاً يُسمَع. فكلُّ التزامٍ يُدفَع يقف عند الباب حتّى
 * يفتحه إنسانٌ بيده. والشيفرةُ في المستودع، والتطبيقُ القديمُ على
 * الهاتف، **ولا سطرَ خطأٍ في أيّ مكان يقول لماذا.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهي المرّةُ الثالثةُ لهذا الصنف في هذا المشروع:**
 *
 *   ① GitHub Actions → `branches: [main, develop]` ولا وجودَ لهما على
 *      origin ⇒ **صفرُ تشغيلٍ منذ ١٠ يونيو**
 *   ② Coolify        → `Source: Manual` ⇒ الدفعُ لا ينشر
 *   ③ Codemagic      → **لا مُطلِقَ أصلاً** ⇒ الدفعُ لا يبني
 *
 * ثلاثةُ ملفَّاتِ إعدادٍ مبنيّةٍ بعناية، **ولا واحدٌ منها موصولٌ بحدث**.
 * وهو نمطُ العطل الأكثرُ تكراراً في أميال باي — مبنيٌّ ولا يُوصَل إليه —
 * واقعاً على الطبقة التي تُوصِّل كلَّ شيءٍ آخر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والأنماطُ لا الأسماء.** فرعُ التطوير يتغيّر اسمُه مع كلّ جلسة
 * (`claude/project-…`)، **واسمٌ مكتوبٌ حرفيّاً يشيخ عند أوّل تغيير**
 * فيعود الصمتُ نفسُه بلا أن يلاحظه أحد. وهذا بعينه ما وقع في ①.
 */
class BuildTriggerGuardTest extends TestCase
{
    /**
     * **ويُقرأ بمحلّلٍ حقيقيّ لا بتعبيرٍ نمطيّ.** امتدادُ `yaml` غيرُ
     * مركَّبٍ هنا، و`symfony/yaml` موجودٌ ضمن التبعيّات — ومطابقةُ
     * `triggering` نصّاً تمرّ على الكلمة داخل تعليق.
     */
    private function codemagic(): array
    {
        $path = base_path('../codemagic.yaml');

        $this->assertFileExists($path, 'اختفى ملفُّ بناء التطبيق');

        return (array) \Symfony\Component\Yaml\Yaml::parseFile($path);
    }

    /** @return array<string,mixed> */
    private function workflow(): array
    {
        $flows = $this->codemagic()['workflows'] ?? [];

        $this->assertNotEmpty($flows, 'لا مسارَ بناءٍ في الملفّ');

        return (array) reset($flows);
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_push_actually_starts_a_build(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** الملفُّ كامل — بصمةُ مصدرٍ، وبوّابةُ
        // تصريف، وبناءٌ مقسَّمٌ حسب المعالج — **ولا شيءَ يُطلقه**.
        // ══════════════════════════════════════════════════════════════
        $trigger = $this->workflow()['triggering'] ?? null;

        $this->assertNotNull($trigger,
            'لا مُطلِقَ في codemagic.yaml — فالدفعُ لا يبني شيئاً، '
            . 'ويقف كلُّ التزامٍ حتّى يفتح إنسانٌ البابَ بيده');

        $this->assertContains('push', (array) ($trigger['events'] ?? []),
            'البناءُ لا يسمع الدفعَ — وهو الحدثُ الوحيدُ الذي يقع فعلاً');
    }

    /** @test */
    public function the_development_branch_matches_the_pattern(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ومُطلِقٌ على فرعٍ لا وجودَ له صمتٌ بثوب إعداد.** GitHub Actions
        // كان يراقب `main` و`develop` ولا وجودَ لهما ⇒ صفرُ تشغيلٍ لأربعة
        // أشهر، ولا أحدَ لاحظ. **فيُطابَق الفرعُ الجاري بالنمط فعلاً.**
        // ══════════════════════════════════════════════════════════════
        $patterns = array_column(
            (array) ($this->workflow()['triggering']['branch_patterns'] ?? []),
            'include', 'pattern');

        $branch = trim((string) shell_exec(
            'git -C ' . escapeshellarg(base_path('..')) . ' rev-parse --abbrev-ref HEAD 2>/dev/null'));

        if ($branch === '' || $branch === 'HEAD') {
            $this->markTestSkipped('لا فرعَ جارٍ — بناءٌ منفصلُ الرأس');
        }

        $matched = false;

        foreach ($patterns as $pattern => $include) {
            if ($include && fnmatch((string) $pattern, $branch)) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, sprintf(
            'الفرعُ الجاري «%s» لا يطابق أيَّ نمطٍ في codemagic.yaml (%s) — '
            . 'فالدفعُ إليه لا يبني شيئاً، بصمتٍ تامّ',
            $branch, implode(' · ', array_keys($patterns))));
    }

    /** @test */
    public function the_patterns_are_globs_not_frozen_names(): void
    {
        // **واسمُ فرعٍ مكتوبٌ حرفيّاً يشيخ عند أوّل جلسة.** أسماءُ فروع
        // `claude/…` تتغيّر، والنمطُ يبقى.
        $patterns = array_column(
            (array) ($this->workflow()['triggering']['branch_patterns'] ?? []),
            'pattern');

        $globs = array_filter($patterns, fn ($p) => str_contains((string) $p, '*'));

        $this->assertNotEmpty($globs,
            'كلُّ الأنماط أسماءٌ ثابتة — فأوّلُ فرعٍ جديدٍ يعيد الصمت');
    }

    /** @test */
    public function the_source_fingerprint_step_survives(): void
    {
        // **و«أين تعديلاتي؟» أكثرُ سؤالٍ تكرّر بعد كلّ بناء.** الخطوةُ
        // تطبع الفرعَ والالتزامَ في رأس السجلّ، فيُحسم من السجلّ لا بالظنّ.
        $names = array_column((array) ($this->workflow()['scripts'] ?? []), 'name');

        $this->assertNotEmpty(
            array_filter($names, fn ($n) => str_contains((string) $n, 'بصمة المصدر')),
            'سقطت بصمةُ المصدر — فيعود سؤالُ «أين تعديلاتي؟» بلا جواب');
    }

    /** @test */
    public function the_compile_gate_still_runs_before_gradle(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وبناءُ Gradle يستغرق ~٤ دقائقَ ثمّ يسقط على خطأ تصريفٍ يُكشَف
        // في عشر ثوانٍ.** والمُطلِقُ الجديدُ يجعل هذا يقع مع كلّ دفعة —
        // فترتيبُ الخطوتين صار أهمَّ لا أقلّ.
        // ══════════════════════════════════════════════════════════════
        $names = array_map('strval', array_column((array) ($this->workflow()['scripts'] ?? []), 'name'));

        $gate = null;
        $build = null;

        foreach ($names as $i => $n) {
            if ($gate === null && str_contains($n, 'بوّابة التصريف')) {
                $gate = $i;
            }
            if ($build === null && str_contains($n, 'بناء APK')) {
                $build = $i;
            }
        }

        $this->assertNotNull($gate, 'سقطت بوّابةُ التصريف');
        $this->assertNotNull($build, 'سقطت خطوةُ البناء');

        $this->assertLessThan($build, $gate,
            'بوّابةُ التصريف بعد البناء — فأربعُ دقائقَ تُهدَر على خطأٍ يُكشَف في عشر ثوانٍ');
    }
}
