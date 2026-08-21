<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BUILD-FLOOR-001 — **حدُّ Gradle يُقاس من Flutter لا من الذاكرة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل — وقع مرّتين بالصيغة نفسِها:**
 *
 *   الأولى: `Your project's Android Gradle Plugin version (8.9.1) is
 *           lower than Flutter's minimum supported version of 8.11.1`
 *   الثانية: `Your project's Kotlin version (2.1.0) is lower than
 *           Flutter's minimum supported version of 2.2.20`
 *
 * والسببُ واحد: `codemagic.yaml` يطلب `flutter: stable` — **وهو يتحرّك من
 * تلقائه**. فترفع نسخةٌ جديدةٌ حدَّها الأدنى، والمشروعُ مثبَّتٌ فيتخلّف.
 *
 * **ولا يظهر محلّيّاً إطلاقاً:** `flutter analyze` لا يمسّ Gradle، ولا
 * مُصرِّفَ أندرويد في هذه الحاوية. فأوّلُ من يقرؤه Codemagic — بعد دقيقةٍ
 * وستّ ثوانٍ من البناء، وفي شاشة صاحب المشروع.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحدُّ يُقرأ من `DependencyVersionChecker.kt` في Flutter المثبَّت** —
 * لا يُكتب رقماً هنا. فرقمٌ مكتوبٌ يشيخ مع أوّل تحديث، وحارسٌ يشيخ يُطمئن
 * ولا يحرس.
 *
 * **ويُقاس على حدّ «التحذير» لا «الخطأ»** — وهذا بيتُ القصيد. فـFlutter
 * يحذّر إصداراً أو اثنين ثمّ يُخطئ:
 *
 *   Kotlin 2.1.0  مقابل  warn 2.2.20 · error 2.0.0
 *
 * فحارسٌ على «الخطأ» كان سيمرّ محلّيّاً **بينما Codemagic يسقط** — لأنّ
 * نسختَه أحدث. وحارسٌ على «التحذير» يمسكه قبل أن يصير خطأً، ويعطي مهلةً
 * بدل مفاجأةٍ في منتصف إصدار.
 */
class GradleFloorGuardTest extends TestCase
{
    private function app_(string $rel): string
    {
        return base_path('../02_flutter_app/' . $rel);
    }

    /** مسارُ فاحص النسخ في Flutter المثبَّت — أو null إن غاب Flutter. */
    private function checkerPath(): ?string
    {
        $sdk = trim((string) shell_exec('command -v flutter 2>/dev/null'));

        if ($sdk === '') {
            foreach (['/opt/flutter', getenv('HOME') . '/flutter'] as $guess) {
                if (is_dir($guess . '/packages')) {
                    $sdk = $guess . '/bin/flutter';
                    break;
                }
            }
        }

        if ($sdk === '') {
            return null;
        }

        $root = dirname(dirname(realpath($sdk)));
        $p = $root . '/packages/flutter_tools/gradle/src/main/kotlin/DependencyVersionChecker.kt';

        return is_file($p) ? $p : null;
    }

    /**
     * @test
     *
     * **كلُّ نسخةٍ مثبَّتةٍ فوق حدّ التحذير الذي يُعلنه Flutter.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وإن غاب Flutter **تُقال الطبقةُ مُخطّاةً صراحةً** ولا تُعدّ نجاحاً —
     * فحارسٌ يمرّ لأنّه لم يجد ما يفحص هو الصمتُ نفسُه.
     */
    public function every_pinned_version_clears_the_floor_flutter_declares(): void
    {
        $checker = $this->checkerPath();

        if ($checker === null) {
            $this->markTestSkipped(
                'Flutter غير مثبَّت — حدُّ Gradle غيرُ مفحوص. '
                . 'شغّل bash scripts/install-flutter.sh (نفسَ ما يستعمله Codemagic).');
        }

        $src = file_get_contents($checker);

        // warnAGPVersion: AndroidPluginVersion(8, 11, 1)
        // warnKGPVersion / warnGradleVersion: Version(2, 2, 20)
        //
        // **والحدّان يُقرآن معاً — لا التحذيرُ وحدَه.**
        //
        // كان هذا الحارسُ يقرأ `warn*` فقط، **فالرقمُ الذي يُسقط البناءَ
        // فعلاً كان خارجَ نظره**. وقِيس عند أوّل تشغيلٍ حقيقيّ (بعد تثبيت
        // Flutter) أنّ اثنين من ثلاثةٍ يجلسان على حدّ الخطأ **بالضبط**:
        // AGP 8.11.1 = خطأ 8.11.1، وKGP 2.2.20 = خطأ 2.2.20. أي أنّ
        // إصداراً واحداً من stable يكفي لكسر البناء، والحارسُ ساكت.
        $floors = [];
        $errors = [];

        foreach (['AGP', 'KGP', 'Gradle'] as $what) {
            foreach (['warn' => &$floors, 'error' => &$errors] as $tier => &$into) {
                if (! preg_match(
                    '~' . $tier . $what . 'Version[^=]*=\s*(?:AndroidPlugin)?Version\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)~',
                    $src, $m)) {
                    $this->fail(
                        "لم يُعثر على {$tier}{$what}Version في {$checker}.\n"
                        . 'غيّر Flutter بنيةَ الفاحص — يُقرأ الملفّ ويُحدَّث هذا التعبير، '
                        . 'ولا يُحذف الفحص.');
                }

                $into[$what] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            }
            unset($into);
        }

        $settings = file_get_contents($this->app_('android/settings.gradle.kts'));
        $wrapper = file_get_contents($this->app_('android/gradle/wrapper/gradle-wrapper.properties'));

        $pinned = [];

        preg_match('~id\("com\.android\.application"\)\s*version\s*"([\d.]+)"~', $settings, $m);
        $pinned['AGP'] = $m[1] ?? null;

        preg_match('~id\("org\.jetbrains\.kotlin\.android"\)\s*version\s*"([\d.]+)"~', $settings, $m);
        $pinned['KGP'] = $m[1] ?? null;

        preg_match('~gradle-([\d.]+)-~', $wrapper, $m);
        $pinned['Gradle'] = $m[1] ?? null;

        $waivers = $this->waivers();

        $broken = [];   // دون حدّ الخطأ — يُسقط البناءَ اليوم. لا إقرارَ يُقبل.
        $behind = [];   // دون حدّ التحذير بلا إقرارٍ مطابق.

        foreach ($floors as $what => $floor) {
            $this->assertNotNull($pinned[$what],
                "لم تُقرأ نسخةُ {$what} من ملفّات Gradle — الحارسُ يفحص شيئاً غير موجود");

            $have = array_map('intval', array_pad(explode('.', $pinned[$what]), 3, 0));

            // ①  حدُّ الخطأ — **لا يُؤجَّل بورقة.** Flutter يوقف البناءَ هنا.
            if ($this->cmp($have, $errors[$what]) < 0) {
                $broken[] = sprintf('%s: المثبَّت %s · وحدُّ الخطأ %s',
                    $what, $pinned[$what], implode('.', $errors[$what]));

                continue;
            }

            if ($this->cmp($have, $floor) >= 0) {
                continue;
            }

            // ②  دون التحذير — يُقبل إقرارٌ مكتوبٌ **مطابقٌ للحدّين معاً**.
            //
            // والمطابقةُ الحرفيّةُ هي ما يجعله ينتهي من تلقائه: أوّلُ تحريكٍ
            // من Flutter لأيّ حدٍّ يُبطل الإقرار، فيسقط الحارسُ ويُعاد النظر.
            // **وإقرارٌ لا ينتهي صمتٌ مؤجَّل.**
            $w = $waivers[$what] ?? null;

            $matches = $w
                && ($w['pinned'] ?? null) === $pinned[$what]
                && ($w['warn_floor_when_written'] ?? null) === implode('.', $floor)
                && ($w['error_floor_when_written'] ?? null) === implode('.', $errors[$what])
                && mb_strlen(trim((string) ($w['reason'] ?? ''))) >= 20;

            if (! $matches) {
                $behind[] = sprintf(
                    '%s: المثبَّت %s · وحدُّ التحذير %s · وحدُّ الخطأ %s — %s',
                    $what, $pinned[$what], implode('.', $floor),
                    implode('.', $errors[$what]),
                    $w === null ? 'ولا إقرارَ مكتوب' : 'والإقرارُ المكتوبُ لم يعد مطابقاً');
            }
        }

        $this->assertSame([], $broken,
            "نسخةٌ **دون حدّ الخطأ** الذي يُعلنه Flutter المثبَّت:\n  "
            . implode("\n  ", $broken) . "\n\n"
            . "وهذا ليس تحذيراً: Flutter يوقف البناءَ عنده. ولا يُقبل إقرارٌ\n"
            . "على حدّ الخطأ — يُرفع الرقمُ في android/settings.gradle.kts.");

        $this->assertSame([], $behind,
            "نسخةٌ دون حدّ التحذير الذي يُعلنه Flutter المثبَّت:\n  "
            . implode("\n  ", $behind) . "\n\n"
            . "و«تحذيرُ» اليوم «خطأُ» غد: Codemagic يسحب stable وهو أحدثُ من\n"
            . "المثبَّت هنا، فيسقط البناءُ بعد دقيقةٍ من assembleRelease.\n\n"
            . "فإمّا يُرفع الرقمُ في android/settings.gradle.kts،\n"
            . "وإمّا — إن كان الرفعُ هجرةً لا سطراً — يُكتب إقرارٌ بسببه في\n"
            . '02_flutter_app/android/gradle-floor-waiver.json، يذكر الحدّين معاً.');
    }

    /**
     * الإقراراتُ المكتوبة — أو مصفوفةٌ فارغةٌ إن غاب الملفّ.
     *
     * **وملفٌّ مُفسَدٌ ليس ملفّاً غائباً**: غيابُه يعني «لا إقرار» فيسقط
     * الحارسُ بالرسالة الصحيحة، أمّا JSON مكسورٌ فيُسقط الحارسَ برسالةٍ
     * تدلّ على موضعه — لا يُبتلع بصمتٍ فيُقرأ «لا إقرار».
     *
     * @return array<string,array<string,mixed>>
     */
    private function waivers(): array
    {
        $p = $this->app_('android/gradle-floor-waiver.json');

        if (! is_file($p)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($p), true);

        if (! is_array($json)) {
            $this->fail("ملفُّ الإقرارات مكسورٌ ولا يُقرأ: {$p} — " . json_last_error_msg());
        }

        return is_array($json['waivers'] ?? null) ? $json['waivers'] : [];
    }

    /**
     * @test
     *
     * **ولا يُقفز إلى AGP 9 صامتاً.**
     *
     * رسالةُ البناء نفسُها تحذّر: التاسعُ يقرأ `newDsl` وحدَه، فيحتاج
     * إعادةَ كتابة ملفّات Gradle كلِّها. فرفعُه بحسن نيّةٍ يكسر البناء
     * بطريقةٍ لا تُشبه سببَها.
     */
    public function the_agp_major_stays_on_eight_until_the_dsl_is_migrated(): void
    {
        $settings = file_get_contents($this->app_('android/settings.gradle.kts'));

        preg_match('~id\("com\.android\.application"\)\s*version\s*"(\d+)\.~', $settings, $m);

        $this->assertSame('8', $m[1] ?? '',
            'AGP خرج من الإصدار الثامن — والتاسع يقرأ newDsl وحدَه. '
            . 'إن كان مقصوداً فأُعيدت كتابةُ ملفّات Gradle، فحدِّث هذا الحارس بذلك.');
    }

    /** @param array<int> $a @param array<int> $b */
    private function cmp(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            if (($a[$i] ?? 0) !== ($b[$i] ?? 0)) {
                return ($a[$i] ?? 0) <=> ($b[$i] ?? 0);
            }
        }

        return 0;
    }
}
