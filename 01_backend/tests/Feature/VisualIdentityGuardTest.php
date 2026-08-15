<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-TYPE-001 · AMIAL-STATE-001 · AMIAL-SIGNATURE-001
 * — **ثلاثةُ أسطحٍ تقرأ كمنتجٍ واحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس قبل هذا الملفّ — لا مفترَضاً:**
 *
 *   لوحةُ الإدارة   : ٦٥ من ١١٥ قالباً فيها لونٌ خام · ‎#053391 مكتوبٌ
 *                     ١٠٢ مرّةً بدل التوكِن · وثلاثةُ أزرقٍ دخيلة
 *   بوّابةُ الوكيل  : **لا تُحمّل ملفَّ التوكِنز إطلاقاً** — علامةٌ ثالثة
 *   التطبيق         : ٤١١ لوناً خاماً في ٩٩ ملفّاً · وستّةُ أخضرَ تعني «نجح»
 *   الثلاثةُ معاً   : **لا خطَّ يكتب العربيّة**. `Rubik` مُضمَّنٌ وفيه صفرُ
 *                     حرفٍ عربيّ، و`'Tajawal'` مذكورٌ ولا يُحمَّل
 *
 * وأخطرُها الأخير: نصٌّ يسقط على خطّ الجهاز يختلف على كلّ جهاز —
 * **فالشاشةُ التي أُقرّها ليست الشاشةَ التي يراها المستعمل**، ولا خطأَ في
 * أيّ سجلّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ لا تصحيحٌ مرّةً واحدة:** التصحيحُ اليوم لا يمنع لوناً
 * يُخترع غداً. وكلُّ قيمةٍ من هذه وُلدت من مطوّرٍ احتاج لوناً فلم يجده.
 */
class VisualIdentityGuardTest extends TestCase
{
    private function app_(string $rel): string
    {
        return base_path('../02_flutter_app/' . $rel);
    }

    /**
     * @test
     *
     * **الخطُّ مُضمَّنٌ في السطحين، ويكتب العربيّة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * ولا يُكتفى بوجود الملفّ: يُقرأ جدولُ محارفه ويُتأكَّد أنّ العربيّة
     * فيه. فخطٌّ مُضمَّنٌ لا يكتب لغةَ المنتج ليس خطّاً — وهو ما كان
     * `Rubik` بالضبط: ٦٩٠ محرفاً، ولا حرفَ عربيٍّ واحد.
     */
    public function the_bundled_typeface_can_write_arabic(): void
    {
        $web = public_path('assets/fonts/IBMPlexSansArabic-Regular.woff2');
        $app = $this->app_('assets/fonts/IBMPlexSansArabic-Regular.ttf');

        $this->assertFileExists($web, 'خطُّ الويب غيرُ مُضمَّن — يسقط على خطّ الجهاز');
        $this->assertFileExists($app, 'خطُّ التطبيق غيرُ مُضمَّن');

        // رخصةُ الخطّ تُنشر معه — المستودعُ عامّ.
        $this->assertFileExists(public_path('assets/fonts/IBMPlexSansArabic-LICENSE.txt'),
            'رخصةُ الخطّ غيرُ منشورةٍ مع ملفّاته');

        // جدولُ المحارف: تُقرأ `cmap` من TTF ويُبحث عن حرفٍ عربيٍّ ورقمٍ لاتينيّ.
        $covered = $this->cmapCovers($app, [0x0645 /* م */, 0x0031 /* 1 */]);

        $this->assertTrue($covered[0x0645],
            'الخطُّ المُضمَّن لا يكتب العربيّة — وهي كلُّ نصوص المنتج');
        $this->assertTrue($covered[0x0031],
            'الخطُّ لا يكتب الأرقام اللاتينيّة — وهي كلُّ المبالغ');
    }

    /**
     * @test
     *
     * **وهو العائلةُ الفعليّة في الوضعين، لا في الوضع الفاتح وحده.**
     *
     * كان الفاتحُ على `Rubik` والمظلمُ على `Roboto` — عائلتان في تطبيقٍ
     * واحد، ولا واحدةَ منهما تكتب العربيّة.
     */
    public function both_themes_use_the_bundled_family(): void
    {
        foreach (['lib/theme/light_theme.dart', 'lib/theme/dark_theme.dart', 'lib/util/styles.dart'] as $rel) {
            $src = file_get_contents($this->app_($rel));

            $this->assertStringContainsString("fontFamily: 'IBMPlexSansArabic'", $src,
                "{$rel}: العائلةُ ليست الخطَّ المُضمَّن");

            // وما بقي من عائلاتٍ لا تكتب العربيّة (خارج التعليقات).
            $live = preg_replace('~//.*~', '', $src);

            $this->assertDoesNotMatchRegularExpression("~fontFamily:\s*'(Rubik|Roboto)'~", (string) $live,
                "{$rel}: عائلةٌ لا تكتب العربيّة ما زالت مضبوطة");
        }

        $pub = file_get_contents($this->app_('pubspec.yaml'));

        foreach (['Regular' => 400, 'SemiBold' => 600, 'Bold' => 700] as $w => $weight) {
            $this->assertStringContainsString("IBMPlexSansArabic-{$w}.ttf", $pub,
                "الوزن {$weight} غيرُ مسجَّلٍ في pubspec — يُطلَب ولا يُحمَّل");
        }
    }

    /**
     * توكِنات الحالة والزوجُ المميِّز — في السطحين بالقيمة نفسِها.
     *
     * @return array<string,string> توكِنُ CSS ⇒ ثابتُ Dart
     */
    private const SEMANTIC = [
        'success' => 'success',
        'warning' => 'warning',
        'info' => 'info',
        'cash' => 'cash',
        'emoney' => 'emoney',
    ];

    /**
     * @test
     *
     * **طبقةُ الحالات موجودةٌ في السطحين وقيمتُها واحدة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وغيابُها هو ما ولّد ستّةَ أخضرَ: من احتاج أخضرَ نجاحٍ لم يجد واحداً
     * فاخترعه. **فالعلاجُ وجودُ التوكِن، لا تذكُّرُ المطوّر.**
     */
    public function the_state_layer_exists_on_both_surfaces_with_one_value(): void
    {
        $css = file_get_contents(public_path('assets/css/amial-tokens.css'));
        $dart = file_get_contents($this->app_('lib/theme/amial_colors.dart'));

        $mismatch = [];

        foreach (self::SEMANTIC as $token => $name) {
            if (! preg_match('~--amial-' . $token . '\s*:\s*#([0-9A-Fa-f]{6})~', $css, $c)) {
                $this->fail("توكِنُ الحالة «--amial-{$token}» مفقودٌ من CSS — فيُخترَع في كلّ ملفّ");
            }

            if (! preg_match('~' . $name . '\s*=\s*Color\(0x[Ff]{2}([0-9A-Fa-f]{6})\)~', $dart, $d)) {
                $this->fail("ثابتُ «{$name}» مفقودٌ من ألوان التطبيق");
            }

            if (strtolower($c[1]) !== strtolower($d[1])) {
                $mismatch[] = "{$token}: اللوحة #{$c[1]} · التطبيق #{$d[1]}";
            }
        }

        $this->assertSame([], $mismatch, 'الحالةُ بلونين — ' . implode(' · ', $mismatch));
    }

    /**
     * @test
     *
     * **وبوّابةُ الوكيل تقرأ التوكِنز — كانت لا تقرؤها إطلاقاً.**
     *
     * وموظّفُ الصرافة يفتح البوّابةَ ثمّ يفتح التطبيق. فسطحان بعلامتين
     * ليسا تفصيلاً بصريّاً: هما منتجان في ذهنه.
     */
    public function the_agent_portal_reads_the_tokens(): void
    {
        $dir = resource_path('views/agent-views');

        foreach (['dashboard', 'login', 'receipt'] as $page) {
            $src = file_get_contents("{$dir}/{$page}.blade.php");

            $this->assertStringContainsString('amial-tokens.css', $src,
                "بوّابةُ الوكيل — {$page}: لا تُحمّل التوكِنز، فهي علامةٌ ثالثة");

            // ولا خطَّ يُطلَب بالاسم ولا يُحمَّل. (`'Tajawal'` كان مذكوراً بلا ملفّ.)
            //
            // **وتُنزَع تعليقاتُ Blade أوّلاً** — فهي تذكر العطلَ شرحاً لا
            // تطبيقاً، وحارسٌ يمسك تعليقاً يشرح الإصلاح يمسك نفسَه.
            $live = preg_replace('~\{\{--.*?--\}\}~s', '', $src);

            $this->assertStringNotContainsString("'Tajawal'", (string) $live,
                "بوّابةُ الوكيل — {$page}: خطٌّ يُطلَب بالاسم ولا يُحمَّل، فيسقط على خطّ الجهاز");
        }
    }

    /**
     * @test
     *
     * **ولا يعود لونُ العلامة يُكتب خاماً في سياق CSS.**
     *
     * ══════════════════════════════════════════════════════════════════
     * والحدُّ **سياقُ CSS** لا الملفُّ كلُّه: مصفوفاتُ PHP تمرّر ألواناً
     * إلى الرسوم وإلى dompdf، **وdompdf لا يفهم `var()`** — فاستبدالٌ
     * هناك يمحو اللونَ من الإيصال بدل أن يوحّده.
     *
     * وقوالبُ الطباعة مستثناةٌ صراحةً للسبب نفسِه، مسمّاةً لا مسكوتاً عنها.
     */
    public function the_brand_blue_is_no_longer_written_raw_in_css(): void
    {
        $excluded = ['receipts/', 'pdf/', 'reports/', 'admin-views/transaction/statement.blade.php'];
        $offenders = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($it as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }

            $rel = str_replace(resource_path('views') . '/', '', (string) $file);

            foreach ($excluded as $skip) {
                if (str_starts_with($rel, $skip) || $rel === $skip) {
                    continue 2;
                }
            }

            $src = file_get_contents((string) $file);

            // سياقُ CSS وحدَه: <style>…</style> و style="…"
            preg_match_all('~(<style\b[^>]*>.*?</style>)|(\sstyle\s*=\s*"[^"]*")~si', $src, $spans);

            foreach (array_filter(array_merge($spans[1], $spans[2])) as $span) {
                // ══════════════════════════════════════════════════════
                //  **القائمةُ كانت ناقصةً وكشفه الإبطال.** كُتبت أوّلاً
                //  بأربعةٍ فمرّ الفحصُ وأنا أُعيد `#0B435B` عمداً — وهو
                //  أزرقٌ دخيلٌ في اثنَي عشرَ موضعاً. **حارسٌ يمرّ والعطلُ
                //  قائمٌ أسوأ من غيابه**، ولم يظهر إلّا لأنّه جُرّب بالعكس.
                // ══════════════════════════════════════════════════════
                if (preg_match('~#(053391|0b3f98|0B435B|0f2b46|021F5C|1D4FB8)\b~i', $span, $m)) {
                    $offenders[] = "{$rel}: #{$m[1]}";
                }
            }
        }

        $this->assertSame([], $offenders,
            "لونُ العلامة مكتوبٌ خاماً في CSS بدل التوكِن — وهكذا وُلد الأزرقان:\n  "
            . implode("\n  ", array_unique($offenders)));
    }

    /**
     * يقرأ `cmap` من ملفّ TTF ويقول أيُّ نقاطِ الترميز مغطّاة.
     *
     * @param  array<int>  $codepoints
     * @return array<int,bool>
     */
    private function cmapCovers(string $ttf, array $codepoints): array
    {
        $bin = file_get_contents($ttf);
        $out = array_fill_keys($codepoints, false);

        $numTables = unpack('n', substr($bin, 4, 2))[1];
        $cmapOff = null;

        for ($i = 0; $i < $numTables; $i++) {
            $rec = substr($bin, 12 + $i * 16, 16);

            if (substr($rec, 0, 4) === 'cmap') {
                $cmapOff = unpack('N', substr($rec, 8, 4))[1];
                break;
            }
        }

        $this->assertNotNull($cmapOff, 'الخطُّ بلا جدول cmap — ملفٌّ تالف');

        $n = unpack('n', substr($bin, $cmapOff + 2, 2))[1];
        $sub = null;

        for ($i = 0; $i < $n; $i++) {
            $rec = unpack('nplat/nenc/Noff', substr($bin, $cmapOff + 4 + $i * 8, 8));

            // 3/1 = Windows Unicode BMP — يكفي للعربيّة واللاتينيّة.
            if ($rec['plat'] === 3 && ($rec['enc'] === 1 || $rec['enc'] === 10)) {
                $sub = $cmapOff + $rec['off'];
                break;
            }
        }

        $this->assertNotNull($sub, 'لا جدولَ يونيكود في cmap');

        $fmt = unpack('n', substr($bin, $sub, 2))[1];

        $this->assertSame(4, $fmt, "صيغةُ cmap غيرُ متوقّعة: {$fmt}");

        $segX2 = unpack('n', substr($bin, $sub + 6, 2))[1];
        $seg = (int) ($segX2 / 2);
        $ends = array_values(unpack("n{$seg}", substr($bin, $sub + 14, $segX2)));
        $starts = array_values(unpack("n{$seg}", substr($bin, $sub + 16 + $segX2, $segX2)));

        foreach ($codepoints as $cp) {
            for ($i = 0; $i < $seg; $i++) {
                if ($cp >= $starts[$i] && $cp <= $ends[$i] && $ends[$i] !== 0xFFFF) {
                    $out[$cp] = true;
                    break;
                }
            }
        }

        return $out;
    }
}
