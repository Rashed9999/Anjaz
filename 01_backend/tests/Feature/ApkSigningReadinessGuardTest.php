<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-APK-SIGNING-001 — **نسخةُ debug لا تُنشَر، والمفتاحُ لا يُرفَع.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس في اختبار الاختراق:** `release` موقَّعةٌ بمفتاح `debug`
 * (قرارٌ صريح: «ليس أولويّة الآن»). وهي تعمل على الهاتف للتجربة — لكنّ
 * **من فكّكها يزيّف تحديثاً باسم أميال باي**، ولا تُقبَل في جوجل بلاي.
 *
 * **والفجوةُ ليست القرار — بل ألّا يوجد ما يذكّر به عند النشر.** فيُبنى
 * appbundle بمفتاح debug ويُرفَع إلى المتجر، ولا شيءَ صرخ. (نمطُ
 * `DEMO_OTP`: مؤقّتٌ يبقى إلى الأبد ما لم يوجد ما يمنعه.)
 *
 * فهذا الحارسُ يثبت ثلاثاً: **المفتاحُ محميٌّ من الرفع**، **والمرشدُ
 * البشريُّ قائمٌ**، **والتوثيقُ يقول إنّ debug ليست للنشر**. ولا يفرض
 * تفعيلَ التوقيع الحقيقيّ الآن — فذاك قرارُ صاحب المشروع لحظةَ النشر.
 */
class ApkSigningReadinessGuardTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(base_path());
    }

    private function read(string $rel): string
    {
        $path = $this->repoRoot().'/'.$rel;
        $this->assertFileExists($path, "مفقود: {$rel}");

        return (string) file_get_contents($path);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① مفاتيحُ التوقيع لا تُرفَع إلى git أبداً.**
     *
     * فمفتاحٌ في المستودع = من نسخه زيّف تحديثاً باسمك. وهذا أخطرُ من
     * كلمة مرورٍ مسرَّبة: يُوقَّع به كودٌ خبيثٌ يثق به هاتفُ العميل.
     */
    /** @test */
    public function signing_keys_are_gitignored(): void
    {
        $root = $this->repoRoot();
        exec(sprintf('cd %s && git ls-files 2>/dev/null', escapeshellarg($root)), $out);

        $leaked = array_filter($out, static fn ($p) => (bool) preg_match(
            '/\.(jks|keystore)$|key\.properties$/i', $p));

        $this->assertSame([], array_values($leaked), sprintf(
            "**مفاتيحُ توقيعٍ مرفوعةٌ إلى git:**\n  %s\n\n"
            .'من نسخها وقّع تطبيقاً خبيثاً يثق به هاتفُ عميلك.',
            implode("\n  ", $leaked)));

        $ignore = $this->read('02_flutter_app/android/.gitignore');
        $this->assertStringContainsString('key.properties', $ignore);
        $this->assertStringContainsString('*.jks', $ignore);
        $this->assertStringContainsString('*.keystore', $ignore);
    }

    /**
     * **② والمرشدُ البشريُّ لإنشاء المفتاح قائم.**
     *
     * فخطوةٌ لا يفعلها إلّا إنسانٌ بلا مرشدٍ تُؤجَّل حتّى تُنسى، أو تُفعَل
     * خطأً (مفتاحٌ في المستودع، كلمةُ مرورٍ في ملفٍّ يُرفَع).
     */
    /** @test */
    public function the_human_signing_wizard_exists(): void
    {
        $wizard = $this->read('02_flutter_app/android/wizard-signing-key.sh');

        $this->assertStringContainsString('keytool -genkey', $wizard,
            '**المرشدُ لا ينشئ مفتاحاً فعلاً.**');

        $this->assertStringContainsString('.gitignore', $wizard,
            '**المرشدُ لا ينبّه أنّ المفتاحَ لا يُرفَع** — فقد يُحفَظ في '
            .'المستودع بحسن نيّة.');
    }

    /**
     * **③ والتوثيقُ يقول صراحةً إنّ debug ليست للنشر.**
     *
     * فقرارُ «موقَّعٌ بـ debug مؤقّتاً» يُقرأ لاحقاً «جاهزٌ للنشر» ما لم
     * يُكتَب عكسُه حيث يُقرأ — في `build.gradle.kts` نفسِه.
     */
    /** @test */
    public function release_build_never_uses_ephemeral_debug_signing(): void
    {
        $gradle = $this->read('02_flutter_app/android/app/build.gradle.kts');

        $this->assertStringContainsString(
            'signingConfig = signingConfigs.getByName("release")',
            $gradle
        );
        $this->assertStringNotContainsString(
            'signingConfig = signingConfigs.getByName("debug")',
            $gradle,
            'release لا يجوز أن يعود إلى debug.keystore لأن هوية التوقيع ستتغير بين GitHub runners.'
        );

        $workflow = $this->read('.github/workflows/ci.yml');
        $this->assertStringContainsString('AMIAL_ANDROID_KEYSTORE_B64', $workflow);
        $this->assertStringContainsString('AMIAL_ANDROID_KEYSTORE_PASSWORD', $workflow);
        $this->assertStringContainsString('1db2799f07dad246a5163fc5fa9ca813b47dd65b6db55eb296632bed3bbabcc7', strtolower($workflow));
        $this->assertStringContainsString('توقيع APK لا يطابق هوية أميال الثابتة', $workflow);

        // AMIAL-APK-BUILD-MODE-004 — غياب أسرار Release لا يجوز أن يحوّل
        // النسخة المطلوبة إلى Debug ضخم أو يجعل الزر أحمر إلى الأبد.
        // installable يبني Release فعلياً بتوقيع مؤقت للتجربة، بينما
        // release_strict وحده يفرض مفتاح أميال الإنتاجي الثابت.
        $this->assertStringContainsString('default: installable', $workflow);
        $this->assertStringContainsString('release_strict', $workflow);
        $this->assertStringContainsString("if: env.APK_BUILD_TYPE == 'release'", $workflow);
        $this->assertStringContainsString('Release خفيف بتوقيع مؤقت', $workflow);
        $this->assertStringContainsString('Release مؤقت: التوقيع صالح للتثبيت', $workflow);
        $this->assertStringContainsString('amial-temp', $workflow);
    }
}
