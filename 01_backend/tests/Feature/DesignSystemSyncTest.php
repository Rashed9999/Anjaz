<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-DS-001 — نظامُ التصميم: لا ينجرف عن الشيفرة، ونصُّه يُقرأ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لمَ حارسٌ لمعايناتٍ لا يراها مستعمل:**
 *
 * نظامُ التصميم مرجعٌ يُبنى عليه. ومرجعٌ يكذب أسوأ من غيابه — يُرسل من
 * يصدّقه خلف لونٍ لم يعد مستعملاً، أو يُخفي عنه لوناً صار.
 *
 * وهذا وقع في هذا المشروع بعينه: كان الأزرقُ `#053391` في التطبيق
 * و`#0f2b46` في لوحة الإدارة — **أزرقان لعلامةٍ واحدة** — لأنّ القيمة
 * نُسخت ولم تُشتق، ولا حارسَ يقرأ الاثنين.
 *
 * فالمعايناتُ مولَّدةٌ من `amial-tokens.css`، وهذا الحارسُ يُعيد توليدها
 * ويقارن. **ومن غيّر توكِناً ونسي التوليد يسقط عنده الفحص.**
 */
class DesignSystemSyncTest extends TestCase
{
    private function ds(string $rel = ''): string
    {
        return base_path('../design-system' . ($rel !== '' ? '/' . $rel : ''));
    }

    /**
     * @test
     *
     * **المعايناتُ مطابقةٌ لما يولّده المصدرُ الآن — لا انجراف.**
     *
     * ══════════════════════════════════════════════════════════════════
     * ويُعاد التوليدُ إلى نسخةٍ مؤقّتة ثمّ يُقارن، فلا يُصلح الحارسُ ما
     * يحرسه. (حارسٌ يُصلح العطلَ ثمّ يقول «لا عطل» لا يحرس شيئاً.)
     */
    public function the_previews_match_what_the_tokens_generate_today(): void
    {
        $this->assertFileExists($this->ds('build.php'), 'مولّدُ نظام التصميم مفقود');

        $before = [];

        foreach (glob($this->ds('foundations/*.html')) as $f) {
            $before[basename($f)] = file_get_contents($f);
        }

        $this->assertNotEmpty($before, 'لا معايناتٍ مولَّدةٌ إطلاقاً — شغّل php design-system/build.php');

        // يُشغَّل المولّد ثمّ تُقارن الملفّات بما كانت عليه.
        exec('php ' . escapeshellarg($this->ds('build.php')) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, "المولّد سقط:\n" . implode("\n", $out));

        $drifted = [];

        foreach ($before as $name => $old) {
            $now = file_get_contents($this->ds('foundations/' . $name));

            if ($now !== $old) {
                $drifted[] = $name;

                // تُعاد النسخةُ الملتزَمة فلا يُغيّر الفحصُ شجرةَ العمل.
                file_put_contents($this->ds('foundations/' . $name), $old);
            }
        }

        $this->assertSame([], $drifted,
            "معايناتُ نظام التصميم انجرفت عن التوكِنز: " . implode('، ', $drifted) . "\n"
            . "غُيّر توكِنٌ في amial-tokens.css ولم يُعَد التوليد.\n"
            . "الحلّ: php design-system/build.php ثمّ التزم الناتج.");
    }

    /**
     * @test
     *
     * **وكلُّ معاينةٍ تحمل بطاقتَها في سطرها الأوّل.**
     *
     * فبلا `@dsCard` لا تظهر في لوحة كلود ديزاين — **تُرفع ولا تُرى**،
     * وهو نمطُ العطل الأكثر تكراراً في هذا المشروع: مبنيٌّ ولا يُوصَل إليه.
     */
    public function every_preview_carries_its_card_marker(): void
    {
        $files = glob($this->ds('foundations/*.html'));

        $this->assertNotEmpty($files);

        foreach ($files as $f) {
            $first = strtok(file_get_contents($f), "\n");

            $this->assertStringContainsString('@dsCard', (string) $first,
                basename($f) . ': لا بطاقةَ في السطر الأوّل — تُرفع ولا تظهر في اللوحة');

            $this->assertMatchesRegularExpression('/group="[^"]+"\s+name="[^"]+"/u', (string) $first,
                basename($f) . ': بطاقةٌ بلا group أو name');
        }
    }

    /**
     * ألوانُ النصّ وأقلُّ تباينٍ مقبولٍ لها على الأسطح — WCAG AA = 4.5.
     *
     * **والاستثناءُ الواحد مكتوبٌ بقيمته المقيسة، لا مسكوتٌ عنه.**
     */
    private const TEXT_TOKENS = [
        '--amial-text' => 4.5,
        '--amial-text-secondary' => 4.5,

        // ⚠ `--amial-text-muted` (#8B97A8) يقيس 2.96:1 على السطح الأبيض
        //   و2.67:1 على الخلفيّة — **دون حدّ AA بل ودون حدّ النصّ الكبير**.
        //   وهو مستعملٌ في ١٨٥ موضعاً بـ٧٤ ملفّاً، فتغييرُه قرارُ علامةٍ لا
        //   إصلاحٌ صامت. يُثبَّت هنا بقيمته الحاليّة **فلا يزداد سوءاً**،
        //   ويبقى ظاهراً في هذا الجدول حتّى يُحسم.
        //   (القاعدة السابعة: الغيابُ يُقال صراحةً مع سببه — لا يُسكت عنه.)
        '--amial-text-muted' => 2.6,
    ];

    /**
     * @test
     *
     * **ولا لونَ نصٍّ ينزل تحت حدّه المكتوب.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وليس هذا تجميلاً: التطبيقُ يعرض **مبالغَ مالية**، ويُستعمل في شمس
     * صنعاء على شاشاتٍ رخيصة. ونصٌّ لا يُقرأ في مبلغٍ عطلٌ لا ذوق.
     */
    public function no_text_colour_falls_below_its_readable_floor(): void
    {
        $css = file_get_contents(
            base_path('public/assets/css/amial-tokens.css'));

        preg_match('/:root\s*\{(.*?)\}/s', $css, $m);
        preg_match_all('/(--amial-[a-z-]+)\s*:\s*(#[0-9A-Fa-f]{6})/', $m[1], $t, PREG_SET_ORDER);

        $tok = [];

        foreach ($t as $x) {
            $tok[$x[1]] = $x[2];
        }

        foreach (self::TEXT_TOKENS as $name => $floor) {
            $this->assertArrayHasKey($name, $tok, "توكِنُ نصٍّ مفقود: {$name}");

            foreach (['--amial-surface', '--amial-background'] as $bg) {
                $ratio = $this->contrast($tok[$name], $tok[$bg]);

                $this->assertGreaterThanOrEqual($floor, $ratio, sprintf(
                    "%s (%s) على %s (%s) = %.2f:1 — والحدُّ %.1f.\n"
                    . "التطبيق يعرض مبالغَ مالية، ونصٌّ لا يُقرأ عطلٌ لا ذوق.",
                    $name, $tok[$name], $bg, $tok[$bg], $ratio, $floor,
                ));
            }
        }
    }

    private function contrast(string $a, string $b): float
    {
        $l = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $c = [];

            foreach ([0, 2, 4] as $i) {
                $v = hexdec(substr($hex, $i, 2)) / 255;
                $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };

        $la = $l($a);
        $lb = $l($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
