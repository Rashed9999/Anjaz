<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-DEBUGBAR-DEVONLY-001 — **الافتراضُ الذي بُني عليه صمتُ المسبار.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * مسبارُ المتصفّح يتجاهل ثلاثةَ سكربتاتٍ يمنعها CSP في التطوير
 * (`jQuery.noConflict` · `Sfdump` · `phpdebugbar`) **لأنّها لا تصل
 * الإنتاجَ**: الحزمةُ في `require-dev` والصورةُ تُبنى بـ`--no-dev`.
 *
 * **وافتراضٌ يُبنى عليه تجاهلٌ يجب أن يُحرَس.** فإن نُقلت الحزمةُ يوماً
 * إلى `require`، أو سقط `--no-dev` من `Dockerfile`، وقع أمران معاً:
 *
 *   ① تصير تلك السكربتاتُ الثلاثةُ عطلاً حقيقيّاً في الإنتاج —
 *     **والمسبارُ يتجاهلها فلا يقول**. (‏حارسٌ يكذب أسوأ من غيابه.)
 *   ② ويعمل **شريطُ تنقيحٍ في الإنتاج**: يكشف كلَّ استعلامٍ ومتغيّرِ
 *     بيئةٍ وجلسةٍ لمن يفتح الصفحة. وهو تسريبٌ لا زينةٌ زائدة.
 *
 * فيُقفَل البابان بحارسٍ واحد.
 */
class DebugbarStaysDevOnlyTest extends TestCase
{
    private function composer(): array
    {
        return (array) json_decode(
            (string) file_get_contents(base_path('composer.json')), true);
    }

    /**
     * @test
     *
     * **① شريطُ التنقيح في `require-dev` لا `require`.**
     */
    public function the_debugbar_never_ships_to_production(): void
    {
        $c = $this->composer();

        $this->assertArrayNotHasKey('barryvdh/laravel-debugbar', (array) ($c['require'] ?? []),
            '**شريطُ التنقيح انتقل إلى `require`** — فيُشحن إلى الإنتاج، '
            . 'ويكشف الاستعلاماتِ ومتغيّراتِ البيئة لمن يفتح الصفحة. '
            . 'ومعه تصير سكربتاتُه الثلاثةُ المحجوبةُ بـCSP عطلاً حقيقيّاً '
            . 'يتجاهله مسبارُ المتصفّح لأنّه بُني على أنّها لا تصل.');
    }

    /**
     * @test
     *
     * **② والصورةُ المنشورةُ تُبنى بلا حزم التطوير.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فوجودُها في `require-dev` وحدَه لا يكفي: `composer install` بلا
     * `--no-dev` يجلبها كاملةً. **والملفُّ المفحوصُ هو المنشور** —
     * `Dockerfile` هو ما يبنيه Coolify، لا `Dockerfile.prod`.
     */
    public function the_shipped_image_installs_without_dev_dependencies(): void
    {
        foreach (['Dockerfile', 'Dockerfile.prod'] as $name) {
            $path = base_path($name);

            if (! is_file($path)) {
                continue;
            }

            $src = (string) file_get_contents($path);

            // **الأمرُ قد يمتدّ أسطراً بشرطة `\` في آخر كلٍّ.** ومرشِّحٌ
            // يقف عند أوّل سطرٍ جديد يقرأ `RUN composer install \` وحدَه
            // فلا يرى `--no-dev` في السطر التالي — **فيسقط الحارسُ على
            // ملفٍّ سليم**. وإنذارٌ كاذبٌ يُفقد الحارسَ قيمتَه كصامت.
            // فتُطوى الأسطرُ الموصولةُ أوّلاً.
            $joined = preg_replace('~\\\\\s*\n\s*~', ' ', $src);

            preg_match_all('~^\s*RUN\s+composer\s+install[^\n]*~mi', (string) $joined, $m);

            $this->assertNotEmpty($m[0],
                "«{$name}» لا يُركّب الاعتماديّاتِ إطلاقاً — أو تغيّرت الصيغةُ "
                . 'فصار هذا الحارسُ يمرّ فارغاً');

            foreach ($m[0] as $line) {
                $this->assertStringContainsString('--no-dev', $line,
                    "«{$name}»: `composer install` بلا `--no-dev` — "
                    . "**فحزمُ التطوير تُشحن**، وفيها شريطُ التنقيح:\n  "
                    . trim($line));
            }
        }
    }
}
