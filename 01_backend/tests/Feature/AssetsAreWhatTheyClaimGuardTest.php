<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-ASSET-TRUTH-001 — **الامتدادُ ليس شهادةَ نوع.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع:** التزامٌ عنوانُه «fix: unify merchant financial
 * views across verticals» — أي عن شاشاتٍ ماليّة — كتب بايتاتٍ عشوائيّةً
 * في **أربعة ملفّاتٍ لا صلةَ لها به**:
 *
 *     drawable-xhdpi/splash_icon.png     82332 ← 75060 بايت
 *     drawable-xxhdpi/splash_icon.png   101614 ← 75061
 *     drawable-xxxhdpi/splash_icon.png  157523 ← 75060
 *     routes/api/amial.php              117943 ← 75061   ← وهذا مسارات API
 *
 * **والغثاءُ واحدٌ في الأربعة.** والثلاثةُ الأولى بقيت مفسودةً حتّى
 * قِيست اليوم: **لا توقيعَ PNG في أوّلها إطلاقاً**، وهي أيقونةُ شاشة
 * الإقلاع على كلّ هاتفٍ حديث (xhdpi فما فوق).
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لم يمسكه شيء:**
 *
 *   · `ViewAssetIntegrityTest` يسأل «**هل الملفُّ موجود؟**» — وكان
 *     موجوداً. فمرّ.
 *   · `flutter analyze` لا يفتح صورة، و`php -l` لا يفتح PNG.
 *   · وطبقاتُ البوّابة العشرُ كلُّها تقرأ **نصّاً** — ولا واحدةَ منها
 *     تفتح ملفّاً ثنائيّاً.
 *
 * فبقي **صنفُ عطلٍ كاملٌ خارج القياس**: أصلٌ يُستبدَل بغثاءٍ ويبقى
 * اسمُه وامتدادُه ومكانُه. **والصمتُ قُرئ سلامةً وهو لم يكن قياساً.**
 * (القاعدة السابعة: «غير معروف» ليس صفراً.)
 *
 * **وأثرُه لا يظهر إلّا في يد صاحب المشروع**: يُبنى التطبيقُ بلا خطأ،
 * ويُثبَّت، وتُفتح شاشةُ إقلاعٍ **بلا شعار** — ولا سطرَ في أيّ سجلّ.
 */
class AssetsAreWhatTheyClaimGuardTest extends TestCase
{
    /** التوقيعُ الذي يبدأ به كلُّ ملفٍّ من نوعه — يُقرأ من أوّل بايتاته. */
    private const SIGNATURES = [
        'png' => ["\x89PNG\r\n\x1a\n"],
        'jpg' => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'gif' => ['GIF87a', 'GIF89a'],
        'webp' => ['RIFF'],
        'ico' => ["\x00\x00\x01\x00"],
        'ttf' => ["\x00\x01\x00\x00", 'true', 'ttcf'],
        'otf' => ['OTTO'],
        'woff' => ['wOFF'],
        'woff2' => ['wOF2'],
    ];

    private function repoRoot(): string
    {
        return dirname(base_path());
    }

    /**
     * **الأصولُ المتعقَّبةُ في git وحدَها.**
     *
     * فـ`storage/app/` يمتلئ بملفّاتٍ تكتبها الاختباراتُ نفسُها (ثلاثةٌ
     * وعشرون بايتاً باسم `.jpg`) — وهي غيرُ مشحونةٍ ولا متعقَّبة، فعدُّها
     * فساداً يُغرق الحارسَ في ضجيجٍ حتّى يُتجاهَل.
     */
    private function trackedAssets(): array
    {
        $root = $this->repoRoot();
        $exts = implode('|', array_keys(self::SIGNATURES));

        exec(sprintf('cd %s && git ls-files 2>/dev/null', escapeshellarg($root)), $out, $code);

        if ($code !== 0 || $out === []) {
            $this->markTestSkipped('لا مستودعَ git هنا — والقياسُ يحتاج قائمةَ المتعقَّب.');
        }

        return array_values(array_filter($out, static fn ($p) => (bool) preg_match("/\.($exts)$/i", $p)));
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① كلُّ أصلٍ هو ما يدّعيه امتدادُه.**
     */
    /** @test */
    public function every_tracked_asset_carries_its_own_signature(): void
    {
        $root = $this->repoRoot();
        $corrupt = [];
        $checked = 0;

        foreach ($this->trackedAssets() as $rel) {
            $path = $root.'/'.$rel;

            if (! is_file($path)) {
                continue;
            }

            $checked++;
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $head = (string) file_get_contents($path, false, null, 0, 16);

            foreach (self::SIGNATURES[$ext] as $sig) {
                if (str_starts_with($head, $sig)) {
                    continue 2;
                }
            }

            $corrupt[] = sprintf('  ✗ %s — %d بايت، ولا توقيعَ %s في أوّله',
                $rel, filesize($path), strtoupper($ext));
        }

        // **ولا يُقاس فراغٌ فيُقرأ نجاحاً** — مُطابِقٌ لا يجد ملفّاً
        // واحداً يخرج أخضرَ على صفر، وهو الصمتُ بثوب سلامة.
        $this->assertGreaterThan(50, $checked,
            sprintf('لم يُفحَص إلّا %d أصلاً — والقائمةُ انكمشت، فالحارسُ '
                .'يفحص فراغاً ويخرج أخضر.', $checked));

        $this->assertSame([], $corrupt, sprintf(
            "**أصولٌ ليست من نوعها:**\n%s\n\n"
            .'والامتدادُ وحدَه لا يجعل الملفَّ صورةً: يُبنى التطبيقُ بلا '
            ."خطأ، وتُعرض الشاشةُ بلا شعار، ولا سطرَ في أيّ سجلّ.\n"
            .'وسببُها المقيسُ مرّةً: التزامٌ عن شاشاتٍ ماليّةٍ كتب غثاءً '
            .'في ثلاث أيقوناتٍ وفي `routes/api/amial.php` معها.',
            implode("\n", $corrupt)));
    }

    /**
     * **② والشيفرةُ نفسُها نصٌّ يُقرأ — لا بايتاتٌ عشوائيّة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمنُ الذي دُفع، والجانبُ الأخطرُ من العطل نفسِه:** الغثاءُ الذي
     * أفسد الأيقوناتِ الثلاث أصاب **ملفَّ اختبارٍ حيّاً** أيضاً —
     * `tests/Feature/CustomerCenterTest.php`، في التزامٍ عنوانُه
     * «test: repair customer center PHP syntax».
     *
     *     قبله : 36854 بايتاً · 39 دالّة · صياغةٌ فيها خطأٌ حقيقيّ
     *     بعده : 30060 بايتاً · **صفرُ دوالّ** · بايتاتٌ عشوائيّة
     *
     * **ونيّتُه كانت صحيحةً**: في السطر ٨٤ كان `\"` مهروباً داخل شيفرة
     * PHP — أثرُ كتابةِ ملفٍّ عبر صدَفةٍ بلا اقتباسٍ سليم. **والعلاجُ
     * محا الملفَّ بدل أن يُصلح محرفين.**
     *
     * **فغابت ٣٦ حالةً من المجموعة صامتةً**، ولا شيءَ صرخ:
     *
     *   · **`php -l` يمرّ** — ملفٌّ بلا `<?php` يُعامَل نصّاً عاديّاً،
     *     فيُقال «No syntax errors detected» على غثاءٍ محض. وهي طبقةُ
     *     البوّابة التي بُنيت لهذا بالذات.
     *   · و**PHPUnit لا يشتكي**: لا صنفَ ⇒ لا اختباراتٍ ⇒ لا فشل.
     *   · والعددُ الكلّيُّ ينقص ٣٦ ولا أحدَ يعدّه.
     *
     * **فالفحصُ هنا قبل المُحلِّل لا بعده**: أهو نصٌّ صالحُ الترميز
     * أصلاً؟ وأيبدأ ملفُّ PHP بـ`<?php`؟ — سؤالان لا يجيب عنهما
     * `php -l` ولا `flutter analyze`.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function every_source_file_is_still_readable_text(): void
    {
        $root = $this->repoRoot();
        exec(sprintf('cd %s && git ls-files 2>/dev/null', escapeshellarg($root)), $out, $code);

        if ($code !== 0 || $out === []) {
            $this->markTestSkipped('لا مستودعَ git هنا.');
        }

        $corrupt = [];
        $checked = 0;

        foreach ($out as $rel) {
            if (! preg_match('/\.(php|dart|js|yml|yaml|json|sh)$/i', $rel)) {
                continue;
            }

            $path = $root.'/'.$rel;

            if (! is_file($path)) {
                continue;
            }

            $checked++;
            $body = (string) file_get_contents($path);

            // ① نصٌّ صالحُ الترميز — **ويُقرأ كاملاً لا أوّلُه.**
            //
            // (وقد قِستُ أوّلَ ٤٠٩٦ بايتٍ مرّةً فأخرج القياسُ **١٩٧
            // ملفّاً مفسوداً** — وكلُّها سليمة: حرفٌ عربيٌّ يقع على حدّ
            // القطع فيُبتَر، فيُقرأ الترميزُ خاطئاً. والصحيحُ **واحدٌ من
            // ٢٦٢١**. القاعدة الثالثة: يُقاس ثمّ يُقال — وأداةُ القياس
            // تُقاس أوّلاً.)
            if (! mb_check_encoding($body, 'UTF-8')) {
                $corrupt[] = sprintf('  ✗ ليس نصّاً صالحاً: %s (%d بايت)',
                    $rel, strlen($body));

                continue;
            }

            // ② وملفُّ PHP يبدأ بوسمه — وإلّا مرّ من `php -l` صامتاً.
            //
            // **وقوالبُ Blade مستثناةٌ بحقّ**: تبدأ بـ`@extends` أو بـHTML،
            // ولا وسمَ في أوّلها. (وأوّلُ صياغةٍ كتبتُها لم تستثنِها فأخرجت
            // عشراتِ القوالب السليمة «مفسودة» — والحارسُ الذي يصرخ في غير
            // موضعه يُعوِّد القارئَ تجاهُلَه يومَ يصدق.)
            if (str_ends_with(strtolower($rel), '.php')
                && ! str_ends_with(strtolower($rel), '.blade.php')
                && ! str_starts_with(ltrim($body), '<?php')) {
                $corrupt[] = sprintf('  ✗ لا يبدأ بـ`<?php`: %s', $rel);
            }
        }

        $this->assertGreaterThan(500, $checked,
            sprintf('لم يُفحَص إلّا %d ملفّاً — والقائمةُ انكمشت، فالحارسُ '
                .'يفحص فراغاً ويخرج أخضر.', $checked));

        $this->assertSame([], $corrupt, sprintf(
            "**ملفّاتُ مصدرٍ ليست نصّاً يُقرأ:**\n%s\n\n"
            .'**ولا تمسكه طبقةُ التركيب**: `php -l` يُخرج «No syntax '
            ."errors» على ملفٍّ بلا `<?php` لأنّه يعدّه نصّاً عاديّاً.\n"
            .'وإن كان ملفَّ اختبارٍ فحالاتُه تغيب من المجموعة صامتةً — '
            .'لا صنفَ، فلا اختبارات، فلا فشل.',
            implode("\n", $corrupt)));
    }

    /**
     * **③ وأيقونةُ الإقلاع مربّعةٌ بكلّ كثافة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * ولا تُقرأ أبعادُها إلّا **بعد ثبوت التوقيع**: الحارسُ الذي في
     * `02_flutter_app/test/` كان يقرأ البايتات ١٦..٢٣ من غيرِ صورةٍ
     * فيُخرج «ليست مربّعة» على قياسٍ مخترَع (٣٦٦٠٢٨٦٤٧٢×٢٨٠٨٧٦٥٢٨٨).
     * **فأصاب في المنع وأخطأ في السبب** — ومن صدّقه ذهب يقصّ صورةً
     * سليمةً لا وجودَ لها. (القاعدة الثالثة.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_splash_icon_is_a_square_image_at_every_density(): void
    {
        $base = $this->repoRoot().'/02_flutter_app/android/app/src/main/res';

        if (! is_dir($base)) {
            $this->markTestSkipped('لا مجلّدَ موارد أندرويد في هذه النسخة.');
        }

        $wrong = [];

        foreach (['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'] as $d) {
            $path = "$base/drawable-$d/splash_icon.png";

            if (! is_file($path)) {
                $wrong[] = "  ✗ drawable-$d/splash_icon.png مفقود";

                continue;
            }

            $size = @getimagesize($path);

            if ($size === false) {
                $wrong[] = sprintf('  ✗ drawable-%s/splash_icon.png **ليست صورةً '
                    .'تُقرأ** (%d بايت)', $d, filesize($path));

                continue;
            }

            if ($size[0] !== $size[1]) {
                $wrong[] = sprintf('  ✗ drawable-%s/splash_icon.png ليست مربّعة (%d×%d)',
                    $d, $size[0], $size[1]);
            }
        }

        $this->assertSame([], $wrong, sprintf(
            "**أيقونةُ شاشة الإقلاع:**\n%s\n\n"
            .'وأندرويد ١٢ فما فوق يقصّها في قناعٍ دائريّ — فغيرُ المربّعة '
            .'تُقصّ منزاحة، وغيرُ الصورة لا تُعرض أصلاً.',
            implode("\n", $wrong)));
    }
}
