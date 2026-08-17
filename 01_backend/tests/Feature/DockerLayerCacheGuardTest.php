<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BUILD-002 — **الحزمُ لا تتبع الشيفرة في ترتيب الطبقات.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس من سجلّ Coolify:**
 *
 * كان في `Dockerfile` (‏وهو **المنشور**) `COPY . .` ثمّ `composer install`.
 * وطبقةُ Docker تُبطَل بكلّ ما بعد أوّل تغيير، **فأيُّ تعديلٍ في سطرٍ من
 * PHP يُعيد تثبيتَ مئةِ حزمةٍ من الصفر**:
 *
 *     #8 … #16  CACHED          ← ما قبل نسخ الشيفرة
 *     #17 COPY . .  DONE 0.8s   ← بطلت الطبقة
 *     #20 composer install → 532 ثانيةً ثمّ سقط البناء
 *
 * تسعُ دقائقَ في كلّ نشرة، ونافذةُ سقوطٍ تُفتَح في كلّ مرّة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما فيه أنّ الصوابَ كان مكتوباً في الملفّ الآخر.**
 *
 * `Dockerfile.prod` يفعله منذ `AMIAL-PROD-READINESS-002`. **وCoolify يبني
 * `Dockerfile` لا `.prod`** — وهو نمطٌ سبق أن كلّف هذا المشروعَ حاجزَ
 * `APP_DEBUG` كلَّه: «الصورةُ المفحوصةُ ليست المشحونة».
 *
 * فالحارسُ يقيس **الملفَّ المنشور** أوّلاً، ويقيس الاثنين على القاعدة نفسِها
 * — فانفرادُ أحدهما بالصواب هو العطلُ بعينه.
 */
class DockerLayerCacheGuardTest extends TestCase
{
    /**
     * الملفّاتُ التي تُبنى منها صورة.
     *
     * **والمنشورُ أوّلاً وبالاسم**: Coolify مضبوطٌ على `Dockerfile`
     * (‏`Build Pack = Dockerfile` في لوحته)، فهو الذي يصل المستعمل.
     *
     * @return array<int,string>
     */
    private function dockerfiles(): array
    {
        return array_values(array_filter([
            base_path('Dockerfile'),
            base_path('Dockerfile.prod'),
        ], 'is_file'));
    }

    /** أسطرُ التعليمات بلا تعليقاتٍ ولا فراغ. */
    private function instructions(string $path): array
    {
        $out = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
            $t = trim($line);

            if ($t === '' || str_starts_with($t, '#')) {
                continue;
            }

            $out[] = ['n' => $i + 1, 'text' => $t];
        }

        return $out;
    }

    /**
     * @test
     *
     * **① لا `composer install` بعد نسخ الشيفرة كلِّها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * والفحصُ على `COPY . .` وحدَها — أي **نسخِ الشجرة كلِّها**. ونسخُ
     * `composer.json`/`composer.lock` قبلها هو الصوابُ نفسُه، فلا يُحسَب
     * مخالفة.
     */
    public function no_dockerfile_installs_packages_after_copying_the_whole_tree(): void
    {
        $offenders = [];

        foreach ($this->dockerfiles() as $path) {
            $copiedAll = null;

            foreach ($this->instructions($path) as $ins) {
                // `COPY . .` أو `COPY . /var/www/html` — نسخُ الشجرة كلِّها.
                if ($copiedAll === null
                    && preg_match('~^COPY\s+(?:--\S+\s+)*\.\s+\S+~i', $ins['text'])) {
                    $copiedAll = $ins['n'];

                    continue;
                }

                if ($copiedAll === null) {
                    continue;
                }

                // **`install` وحدَها هي المخالفة** — `dump-autoload` بعد
                // النسخ **مطلوبٌ**: الخريطةُ تحتاج الشيفرة.
                if (preg_match('~composer\s+install~i', $ins['text'])) {
                    $offenders[] = sprintf(
                        '%s: سطر %d — `composer install` بعد `COPY . .` (سطر %d)',
                        basename($path), $ins['n'], $copiedAll);
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "تثبيتُ الحزم يقع **بعد** نسخ الشيفرة، فتُبطله كلُّ تعديلةٍ في "
            . "سطرٍ واحد:\n  %s\n\n"
            . "الترتيبُ الصحيح:\n"
            . "  COPY composer.json composer.lock ./\n"
            . "  RUN composer install --no-dev --no-scripts --no-autoloader\n"
            . "  COPY . .\n"
            . '  RUN composer dump-autoload --no-dev --optimize',
            implode("\n  ", $offenders)));
    }

    /**
     * @test
     *
     * **② والملفُّ المنشورُ يُثبّت الحزمَ قبل نسخ الشيفرة — بالإيجاب.**
     *
     * فالفحصُ الأوّلُ سلبيّ: يمرّ أيضاً على ملفٍّ **لا يثبّت الحزمَ
     * إطلاقاً**. وصورةٌ بلا `vendor` تسقط عند أوّل طلب.
     */
    public function the_shipped_dockerfile_installs_packages_before_the_code(): void
    {
        $path = base_path('Dockerfile');

        $this->assertFileExists($path,
            'لا `Dockerfile` — وهو ما يبنيه Coolify');

        $ins = $this->instructions($path);

        $lockCopy = null;
        $install = null;
        $copyAll = null;

        foreach ($ins as $i) {
            if ($lockCopy === null && preg_match('~^COPY\s+composer\.json~i', $i['text'])) {
                $lockCopy = $i['n'];
            }

            if ($install === null && preg_match('~composer\s+install~i', $i['text'])) {
                $install = $i['n'];
            }

            if ($copyAll === null && preg_match('~^COPY\s+(?:--\S+\s+)*\.\s+\S+~i', $i['text'])) {
                $copyAll = $i['n'];
            }
        }

        $this->assertNotNull($lockCopy, '`composer.json`/`lock` لا تُنسخان وحدَهما');
        $this->assertNotNull($install, 'لا `composer install` إطلاقاً — صورةٌ بلا حزم');
        $this->assertNotNull($copyAll, 'لا `COPY . .` — الشيفرةُ لا تدخل الصورة');

        $this->assertLessThan($install, $lockCopy,
            'نسخُ `composer.lock` يقع بعد التثبيت');

        $this->assertLessThan($copyAll, $install,
            'التثبيتُ يقع بعد نسخ الشيفرة — وهو العطلُ نفسُه');
    }

    /**
     * @test
     *
     * **③ وخريطةُ التحميل تُبنى بعد نسخ الشيفرة.**
     *
     * فتثبيتٌ بـ`--no-autoloader` بلا `dump-autoload` بعده يُنتج صورةً
     * **بلا خريطةِ أصنافٍ للتطبيق**: الحزمُ موجودةٌ وشيفرةُ المشروع لا
     * تُحمَّل، فيسقط أوّلُ طلبٍ على `Class not found`.
     */
    public function the_autoloader_is_dumped_after_the_code_is_copied(): void
    {
        $ins = $this->instructions(base_path('Dockerfile'));

        $copyAll = null;
        $dump = null;
        $noAutoloader = false;

        foreach ($ins as $i) {
            if ($copyAll === null && preg_match('~^COPY\s+(?:--\S+\s+)*\.\s+\S+~i', $i['text'])) {
                $copyAll = $i['n'];
            }

            if (preg_match('~composer\s+install~i', $i['text'])
                && str_contains($i['text'], '--no-autoloader')) {
                $noAutoloader = true;
            }

            if ($copyAll !== null && $dump === null
                && preg_match('~composer\s+dump-autoload~i', $i['text'])
                && $i['n'] > $copyAll) {
                $dump = $i['n'];
            }
        }

        if (! $noAutoloader) {
            $this->markTestSkipped('التثبيتُ يبني الخريطةَ بنفسه — لا حاجةَ لبنائها ثانيةً');
        }

        $this->assertNotNull($dump,
            'التثبيتُ بـ`--no-autoloader` ولا `dump-autoload` بعد نسخ الشيفرة — '
            . '**صورةٌ لا تُحمَّل فيها أصنافُ التطبيق**');
    }

    /**
     * @test
     *
     * **④ وحدُّ ذاكرة composer مرفوعٌ صراحةً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `memory_limit=256M` يُكتب في `php.ini` لأجل التطبيق، **وcomposer
     * يقرأ الملفَّ نفسَه**. وسقوطُه على الذاكرة لا يقول «نفدت الذاكرة»:
     * يُقتَل فيبدو البناءُ منقطعاً بلا سبب.
     */
    public function composer_gets_an_explicit_memory_limit(): void
    {
        $src = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression(
            '~ENV\s+COMPOSER_MEMORY_LIMIT\s*=\s*-1~',
            $src,
            'لا `COMPOSER_MEMORY_LIMIT` — وحدُّ php.ini (‏256M) يسري على composer، '
            . 'فيُقتَل في شجرةٍ كبيرةٍ بلا رسالةٍ تدلّ');
    }
}
