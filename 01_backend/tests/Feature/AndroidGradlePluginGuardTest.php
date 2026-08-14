<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BUILD-AGP-001 — **إضافةُ Gradle تتخلّف عن Flutter بصمت.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الذي وُلد منه:** سقط بناءُ Codemagic بعد ٦٤ ثانيةً من
 * `assembleRelease`:
 *
 *     Your project's Android Gradle Plugin version (8.9.1) is lower than
 *     Flutter's minimum supported version of 8.11.1.
 *
 * والسببُ أنّ `codemagic.yaml` يطلب `flutter: stable` — **وهو هدفٌ
 * متحرّك**. فترفع نسخةٌ جديدةٌ من Flutter حدَّها الأدنى، والمشروعُ يثبّت
 * `8.9.1` منذ شهور، فيتخلّف من تلقاء نفسه بلا أن يمسّ أحدٌ سطراً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لا تمسكه الطبقةُ السابعة:** `flutter analyze` **لا يمسّ Gradle
 * إطلاقاً** — يحلّل Dart وحده. فتخرج البوّابةُ المحلّيّة بصفر خطأ، ويُدفع
 * الالتزام، ثمّ يسقط البناءُ بعد دقيقةٍ على خادمٍ بعيد. وهو نصُّ الدرس
 * الذي وُلدت منه الطبقةُ الثامنة: **أوّلُ من يقرأ هذا Codemagic**.
 *
 * فيُقرأ الحدُّ من مصدره: Flutter نفسُه يُعلن في
 * `packages/flutter_tools/gradle/build.gradle.kts` أيَّ نسخةٍ من AGP
 * يُصرَّف عليها — وتلك هي التي يفرضها. (القاعدة السادسة: الرقمُ يُقرأ من
 * مصدره لا من ذاكرتي.)
 */
class AndroidGradlePluginGuardTest extends TestCase
{
    private const SETTINGS = __DIR__ . '/../../../02_flutter_app/android/settings.gradle.kts';

    /** أين يُعلن Flutter نسخةَ AGP التي يعمل عليها. */
    private const FLUTTER_GRADLE = '/opt/flutter/packages/flutter_tools/gradle/build.gradle.kts';

    /** نسخةُ AGP المثبّتة في المشروع. */
    private function projectAgp(): string
    {
        $this->assertFileExists(self::SETTINGS);

        preg_match(
            '/id\("com\.android\.application"\)\s+version\s+"([0-9]+\.[0-9]+\.[0-9]+)"/',
            (string) file_get_contents(self::SETTINGS),
            $m,
        );

        $this->assertNotEmpty($m[1] ?? null,
            'لا نسخةَ AGP مقروءةٌ في settings.gradle.kts — تغيّرت صيغةُ الملفّ '
            . 'وصار هذا الحارسُ يقرأ فراغاً. **وحارسٌ يقرأ فراغاً يمرّ دائماً.**');

        return $m[1];
    }

    /** ونسخةُ AGP التي يفرضها Flutter المثبَّت — أو `null` إن غاب. */
    private function flutterAgp(): ?string
    {
        if (! is_file(self::FLUTTER_GRADLE)) {
            return null;
        }

        preg_match(
            '/com\.android\.tools\.build:gradle:([0-9]+\.[0-9]+\.[0-9]+)/',
            (string) file_get_contents(self::FLUTTER_GRADLE),
            $m,
        );

        return $m[1] ?? null;
    }

    /**
     * **المثبَّتُ في المشروع ليس أدنى ممّا يفرضه Flutter.**
     */
    public function test_the_pinned_agp_is_not_older_than_flutter_demands(): void
    {
        $flutter = $this->flutterAgp();

        if ($flutter === null) {
            // **والغيابُ يُقال، ولا يُعدّ نجاحاً** (القاعدة السابعة):
            // «لم يُفحص» ليست «سليم».
            $this->markTestSkipped(
                'Flutter غير مثبَّتٍ هنا فلا مرجعَ لحدّ AGP — '
                . 'شغّل: bash scripts/install-flutter.sh');
        }

        $project = $this->projectAgp();

        $this->assertGreaterThanOrEqual(0, version_compare($project, $flutter),
            "AGP المثبَّت «{$project}» أدنى ممّا يفرضه Flutter «{$flutter}» — "
            . 'وسيسقط بناءُ Codemagic عند `assembleRelease` برسالةٍ تقول '
            . 'ذلك بعد دقيقةٍ من البناء. ارفعه في '
            . '`02_flutter_app/android/settings.gradle.kts`.');
    }

    /**
     * **ولا يُقفز إلى AGP 9** — رسالةُ البناء نفسُها تحذّر أنّ التاسع
     * يقرأ `newDsl` وحدَه، فيحتاج إعادةَ كتابة ملفّات Gradle كلِّها.
     * وقفزةٌ كهذه تُتَّخذ قراراً لا تقع مصادفةً.
     */
    public function test_the_project_has_not_silently_jumped_to_the_new_dsl_major(): void
    {
        $major = (int) explode('.', $this->projectAgp())[0];

        $this->assertLessThan(9, $major,
            'AGP 9 يقرأ `android.newDsl` وحدَه — وملفّاتُ Gradle هنا '
            . 'مكتوبةٌ بالواجهة القديمة. الترقيةُ ممكنةٌ لكنّها مشروعٌ '
            . 'قائمٌ بذاته لا رفعُ رقم.');
    }

    /**
     * **وGradle نفسُه يكفي لتشغيل AGP المثبَّت.**
     *
     * فرفعُ AGP وحدَه دون غلاف Gradle يُنتج عطلاً آخر مكان الأوّل:
     * «Minimum supported Gradle version is …».
     */
    public function test_the_gradle_wrapper_can_run_that_agp(): void
    {
        $wrapper = __DIR__ . '/../../../02_flutter_app/android/gradle/wrapper/gradle-wrapper.properties';

        $this->assertFileExists($wrapper);

        preg_match('/gradle-([0-9]+\.[0-9]+(?:\.[0-9]+)?)-/',
            (string) file_get_contents($wrapper), $m);

        $this->assertNotEmpty($m[1] ?? null, 'لا نسخةَ Gradle مقروءةٌ في الغلاف');

        // AGP 8.11 يطلب Gradle 8.13 فأعلى (موثَّقٌ في ملاحظات إصداره).
        $this->assertGreaterThanOrEqual(0, version_compare($m[1], '8.13'),
            "غلافُ Gradle «{$m[1]}» أقدمُ ممّا يطلبه AGP 8.11 (‏8.13 فأعلى) — "
            . 'فيسقط البناءُ برسالةٍ عن Gradle لا عن AGP.');
    }
}
