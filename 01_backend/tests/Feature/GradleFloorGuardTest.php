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
        $floors = [];

        foreach (['AGP', 'KGP', 'Gradle'] as $what) {
            if (! preg_match(
                '~warn' . $what . 'Version[^=]*=\s*(?:AndroidPlugin)?Version\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)~',
                $src, $m)) {
                $this->fail(
                    "لم يُعثر على warn{$what}Version في {$checker}.\n"
                    . 'غيّر Flutter بنيةَ الفاحص — يُقرأ الملفّ ويُحدَّث هذا التعبير، '
                    . 'ولا يُحذف الفحص.');
            }

            $floors[$what] = [(int) $m[1], (int) $m[2], (int) $m[3]];
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

        $behind = [];

        foreach ($floors as $what => $floor) {
            $this->assertNotNull($pinned[$what],
                "لم تُقرأ نسخةُ {$what} من ملفّات Gradle — الحارسُ يفحص شيئاً غير موجود");

            $have = array_map('intval', array_pad(explode('.', $pinned[$what]), 3, 0));

            if ($this->cmp($have, $floor) < 0) {
                $behind[] = sprintf('%s: المثبَّت %s · وحدُّ التحذير %s',
                    $what, $pinned[$what], implode('.', $floor));
            }
        }

        $this->assertSame([], $behind,
            "نسخةٌ دون حدّ التحذير الذي يُعلنه Flutter المثبَّت:\n  "
            . implode("\n  ", $behind) . "\n\n"
            . "و«تحذيرُ» اليوم «خطأُ» غد: Codemagic يسحب stable وهو أحدثُ من\n"
            . "المثبَّت هنا، فيسقط البناءُ بعد دقيقةٍ من assembleRelease.\n"
            . "الحلّ: ارفع الرقمَ في android/settings.gradle.kts.");
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
