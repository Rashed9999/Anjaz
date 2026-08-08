<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SPLASH-C-001 — حركةُ الشعار عند الإقلاع: طبقاتُها ومراحلُها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لمَ حارسٌ لصورٍ ورسمٍ:**
 *
 * الشعارُ المُسلَّم صورةٌ مسطّحةٌ واحدة، والمواصفةُ تُحرّك أجزاءه
 * **منفصلة**. فلا سبيل إلى «يظهر الخطُّ الأحمر أسفله» وهو مطبوعٌ في
 * الصورة منذ اللحظة الأولى. فقُيست بنيةُ الشعار بالبكسل وفُصلت طبقاته
 * الأربع من الأصل نفسِه.
 *
 * **وطبقةٌ تُفرَّغ بالخطأ لا تُنتج خطأً في أيّ سجلّ**: يُبنى التطبيق،
 * وتعمل الحركة، **ويغيب جزءٌ من الشعار صامتاً**. فيُفحص محتوى كلّ ملفّ
 * لا وجودُه فحسب. (القاعدة التاسعة في صورتها البصريّة.)
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا Dart في بيئة البناء** (`dart: command not found`)، فلا اختبارَ
 * ودجت. وحارسٌ يعمل خيرٌ من حارسٍ صحيحٍ لا يُشغَّل — فيقرأ هذا المصدرَ
 * والصورَ ويسقط في `verify.sh` مع كلّ فحص.
 */
class SplashBrandGuardTest extends TestCase
{
    private function appFile(string $rel): string
    {
        return base_path('../02_flutter_app/' . $rel);
    }

    /**
     * الطبقاتُ الأربع، ولكلٍّ لونُها الغالب.
     *
     * @return array<string,array{0:string,1:int}> الملفّ ⇒ [اللون، أدنى نسبة]
     */
    private const LAYERS = [
        'logo_wordmark' => ['blue', 60],
        'logo_swoosh' => ['red', 80],
        'logo_latin' => ['blue', 60],
        'logo_tagline' => ['blue', 30],
    ];

    /**
     * @test
     *
     * **الطبقاتُ الأربع موجودةٌ وفيها ما ينبغي — لا ملفّاتٌ فارغة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا أهمُّ حارسٍ هنا: ملفٌّ شفّافٌ بالكامل **يُبنى ويعمل** ولا
     * يُنتج خطأً — ويغيب جزءٌ من الشعار في وجه كلّ مستعمل.
     *
     * ويُفحص اللونُ الغالب كذلك: الخطُّ الأحمر إن حمل ذيولَ الحروف
     * الزرقاء رُسمت مرّتين — مرّةً في طبقة الاسم ومرّةً في طبقته.
     */
    public function the_four_logo_layers_exist_and_hold_their_colour(): void
    {
        foreach (self::LAYERS as $name => [$want, $minPct]) {
            $p = $this->appFile("assets/brand/{$name}.png");

            $this->assertFileExists($p, "طبقةُ الشعار مفقودة: {$name}");

            $im = @imagecreatefrompng($p);
            $this->assertNotFalse($im, "تعذّرت قراءةُ {$name}.png");

            $w = imagesx($im);
            $h = imagesy($im);

            $visible = 0;
            $blue = 0;
            $red = 0;

            // مسحٌ بخطوةٍ أربع — يكفي للنسب ولا يُبطئ الفحص.
            for ($y = 0; $y < $h; $y += 4) {
                for ($x = 0; $x < $w; $x += 4) {
                    $c = imagecolorat($im, $x, $y);
                    $a = ($c >> 24) & 0x7F;      // 0 معتم · 127 شفّاف

                    if ($a > 40) {
                        continue;
                    }

                    $visible++;
                    $r = ($c >> 16) & 0xFF;
                    $g = ($c >> 8) & 0xFF;
                    $b = $c & 0xFF;

                    if ($b > 90 && $b > $r + 40) {
                        $blue++;
                    }

                    if ($r > 110 && $r > $g + 45 && $r > $b + 45) {
                        $red++;
                    }
                }
            }

            imagedestroy($im);

            $this->assertGreaterThan(200, $visible,
                "طبقةُ {$name} شبهُ فارغة — تُبنى وتعمل ويغيب جزءٌ من الشعار صامتاً");

            $pct = (int) (($want === 'blue' ? $blue : $red) * 100 / max($visible, 1));

            $this->assertGreaterThanOrEqual($minPct, $pct,
                "طبقةُ {$name}: اللونُ {$want} {$pct}٪ والمطلوب {$minPct}٪ على الأقلّ");
        }
    }

    /**
     * @test
     *
     * **والخطُّ الأحمر أحمرُ خالص — بلا ذيولِ حروفٍ زرقاء.**
     *
     * فشريطُه في الشعار الأصليّ يتداخل مع أسفل «أميال». ولو نُسخ كما هو
     * لرُسمت أطرافُ الحروف مرّتين، فتظهر مزدوجةً أثناء الحركة.
     */
    public function the_swoosh_carries_no_blue_letter_tails(): void
    {
        $im = imagecreatefrompng($this->appFile('assets/brand/logo_swoosh.png'));

        $w = imagesx($im);
        $h = imagesy($im);
        $visible = 0;
        $blue = 0;

        for ($y = 0; $y < $h; $y += 3) {
            for ($x = 0; $x < $w; $x += 3) {
                $c = imagecolorat($im, $x, $y);

                if ((($c >> 24) & 0x7F) > 40) {
                    continue;
                }

                $visible++;

                if ((($c & 0xFF) > 90) && (($c & 0xFF) > (($c >> 16) & 0xFF) + 40)) {
                    $blue++;
                }
            }
        }

        imagedestroy($im);

        $pct = (int) ($blue * 100 / max($visible, 1));

        $this->assertLessThan(5, $pct,
            "الخطُّ الأحمر فيه {$pct}٪ أزرق — ذيولُ حروفٍ تُرسم مرّتين");
    }

    /**
     * @test
     *
     * **والمراحلُ الستّ مبنيّةٌ كما وُصفت — بمدّةٍ ١٫٨ ثانية.**
     */
    public function the_six_stages_are_built_with_the_specified_duration(): void
    {
        $src = file_get_contents(
            $this->appFile('lib/features/splash/widgets/brand_splash_animation.dart'));

        $this->assertStringContainsString('milliseconds: 1800', $src,
            'المدّةُ ليست ١٫٨ ثانية كما في المواصفة');

        foreach (['_spark', '_converge', '_settle', '_swoosh', '_latin', '_tagline'] as $stage) {
            $this->assertStringContainsString($stage, $src, "مرحلةٌ ناقصة: {$stage}");
        }

        // وكلُّ طبقةٍ تُستعمل فعلاً — لا صورةٌ تُستخرج ولا تُرسم.
        foreach (array_keys(self::LAYERS) as $layer) {
            $this->assertStringContainsString("assets/brand/{$layer}.png", $src,
                "طبقةُ {$layer} مستخرَجةٌ ولا تُرسم في الحركة");
        }
    }

    /**
     * @test
     *
     * **والانتقالُ مشروطٌ بالحركة والبيانات معاً — ومرّةً واحدة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا يحسم توتّراً حقيقيّاً في هذا الملفّ نفسِه:**
     *
     * مكتوبٌ فيه منذ AMIAL-STARTUP-FLOW-001 «لا تأخير صناعي — كانت ثانيةٌ
     * كاملة فوق سبلاش النظام = شاشتا شعار». والمواصفةُ تطلب ١٫٨ ثانية.
     *
     * فالانتقالُ يقع حين يجتمع الأمران: انتهاءُ الحركة **و** جهوزيّةُ
     * البيانات. والحركةُ لا تُعاد ولا تُمدَّد، فالسقفُ ١٫٨ لا أكثر.
     *
     * **والحارسُ ضدّ النداء المزدوج:** البياناتُ والحركةُ تنتهيان بأيّ
     * ترتيب وكلاهما ينادي — وبلا قفلٍ تُفتح شاشةُ الدخول فوق نفسها.
     */
    public function navigation_waits_for_both_and_fires_once(): void
    {
        $src = file_get_contents($this->appFile('lib/features/splash/screens/splash_screen.dart'));

        foreach (['_dataReady', '_animationDone', '_navigated', '_goIfReady'] as $part) {
            $this->assertStringContainsString($part, $src, "ناقصٌ من التنسيق: {$part}");
        }

        $this->assertStringContainsString('if (!_dataReady || !_animationDone) return;', $src,
            'الانتقالُ لا ينتظر الأمرين معاً');

        // **ولا `Get.offNamed` خارج البوّابة.**
        // فنداءٌ ثانٍ من مكانٍ آخر يتجاوز القفلَ ويُفتح الشاشةُ مرّتين.
        $this->assertSame(1, substr_count($src, 'Get.offNamed('),
            'أكثرُ من نداء انتقالٍ في شاشة البدء — واحدٌ منها يتجاوز القفل');

        $this->assertStringContainsString('BrandSplashAnimation', $src,
            'الشاشةُ لا تستعمل الحركةَ الجديدة');
    }

    /**
     * @test
     *
     * **وخلفيّةُ السبلاش من التوكِنز لا رقماً مكتوباً.**
     *
     * فلونٌ يُنسخ يعيش وحده ويفترق عن مصدره — وهو ما أنتج الأزرقين.
     */
    public function the_splash_background_comes_from_the_tokens(): void
    {
        $src = file_get_contents($this->appFile('lib/features/splash/screens/splash_screen.dart'));

        $this->assertStringContainsString('backgroundColor: AmialColors.yellow', $src,
            'خلفيّةُ السبلاش رقمٌ مكتوبٌ لا توكِن');

        // ويُتأكَّد أنّ التوكِن هو أصفرُ العلامة فعلاً.
        $colors = file_get_contents($this->appFile('lib/theme/amial_colors.dart'));

        $this->assertMatchesRegularExpression('/yellow\s*=\s*Color\(0x[Ff]{2}FECA1E\)/i', $colors,
            'أصفرُ العلامة تغيّر — راجع خلفيّةَ السبلاش وسبلاشَ النظام معه');
    }
}
