<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BACKUP-KEY-001 — **مرشدٌ يضبط مفتاحاً لا تقرؤه الشيفرة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * للنسخة الخارجيّة مساران بُنيا في وقتين:
 *
 *   `scripts/backup.sh`      → `BACKUP_S3_BUCKET`    (aws CLI، يدويّ)
 *   `amial:backup` المجدوَل   → `AMIAL_BACKUP_REMOTE` (rclone، كلَّ ليلة)
 *
 * **ومقياسُ الجاهزيّة يقرأ الثاني.** وكان مرشدُ الإطلاق يسأل عن الأوّل
 * وحدَه — فمن اتّبعه ضبط مفتاحاً **لا يقرؤه الأمرُ الذي يجري كلَّ ليلة**.
 *
 * فيقرأ في التقرير «لا نسخةَ خارج الخادم» ولا يفهم لماذا، **أو أسوأ:
 * يظنّ أنّ له نسخةً بعيدةً ولا شيء** — إلى ليلةِ الحاجة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحارسُ عامّ:** كلُّ مفتاحٍ يكتبه المرشدُ في `.env` يجب أن يقرأه
 * شيءٌ في الشيفرة. ومفتاحٌ يُكتب ولا يُقرأ **إعدادٌ مبنيٌّ ولا يُوصَل
 * إليه** — وهو أخطرُ من غيابه: يُوهم صاحبَه أنّ شيئاً ضُبط.
 */
class WizardKeysMatchTheCodeGuardTest extends TestCase
{
    /**
     * ══════════════════════════════════════════════════════════════════
     * **تُنزَع التعليقاتُ قبل البحث — وإلّا حرس التعليقُ نفسَه.**
     *
     * جُرّب هذا بالعكس فمرّ: قُطع قارئُ `AMIAL_MEASURED_RTO_SECONDS`
     * **وبقي اسمُه في التعليق الذي يشرح لماذا يُقرأ** — فوجده الحارسُ
     * في نصٍّ يصفه لا في شيفرةٍ تقرؤه.
     *
     * **وهي رابعةُ مرّةٍ في هذه السلسلة**، والدرسُ مكتوبٌ في `CLAUDE.md`
     * منذ ما قبلها: «التعليقُ الذي يصف العطل كان يُخفيه». فلا يُتعلَّم
     * بقراءته — يُتعلَّم بأن يصير أداةً تُنادى.
     * ══════════════════════════════════════════════════════════════════
     */
    private function stripComments(string $src): string
    {
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);

        return implode("\n", array_map(
            static function (string $line): string {
                $line = (string) preg_replace('~//.*$~', '', $line);

                return str_starts_with(ltrim($line), '#') ? '' : $line;
            },
            explode("\n", $src),
        ));
    }

    private function wizard(): string
    {
        return (string) file_get_contents(base_path('scripts/wizard-production-blockers.sh'));
    }

    /** كلُّ مفتاحٍ يُمرَّر إلى `write_env`. @return array<int,string> */
    private function writtenKeys(): array
    {
        preg_match_all('~^\s*write_env\s+([A-Z][A-Z0-9_]+)~m', $this->wizard(), $m);

        return array_values(array_unique($m[1]));
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_key_the_wizard_writes_is_read_somewhere_in_the_code(): void
    {
        $keys = $this->writtenKeys();

        $this->assertNotEmpty($keys,
            'المرشدُ لا يكتب مفتاحاً واحداً — فإمّا تغيّرت صيغتُه وإمّا '
            . 'لم يعد يضبط شيئاً، والحارسُ حينئذٍ يحرس الفراغ');

        $haystack = '';
        foreach ([base_path('app'), base_path('config'), base_path('scripts')] as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if (in_array($f->getExtension(), ['php', 'sh'], true)) {
                    $haystack .= $this->stripComments(
                        (string) file_get_contents($f->getPathname()));
                }
            }
        }

        // ══════════════════════════════════════════════════════════════
        // **ويُستثنى المرشدُ نفسُه** — وإلّا حرس نفسَه ولم يسقط أبداً.
        //
        // **ويُستثنى بصيغته المنزوعةِ التعليقات لا بالخام**: أضفتُ
        // النزعَ إلى المُجمَّع ونسيتُ الاستثناءَ، فلم يعد `str_replace`
        // يطابق — فبقي نصُّ المرشد في المُجمَّع، **فوجد كلُّ مفتاحٍ
        // نفسَه فيه ومرّ الحارسُ على عطلٍ قائم**.
        //
        // وهو الدرسُ نفسُه من زاويةٍ أخرى: إصلاحُ حارسٍ في موضعٍ يكسره
        // في موضعٍ آخرَ ما لم يُقلَب بعده. **فالقلبُ بعد كلّ تعديلٍ لا
        // بعد أوّله.**
        // ══════════════════════════════════════════════════════════════
        $haystack = str_replace($this->stripComments($this->wizard()), '', $haystack);

        foreach ($keys as $key) {
            $this->assertStringContainsString($key, $haystack,
                "**«{$key}» يكتبه المرشدُ ولا تقرؤه الشيفرة** — فمن اتّبع "
                . 'المرشدَ ضبط شيئاً لا أثرَ له، وظنّ الخطوةَ تمّت.');
        }
    }

    /**
     * **والمفتاحُ المقروءُ ليلاً هو الذي يُسأل عنه.**
     *
     * @test
     */
    public function the_wizard_asks_for_the_key_the_scheduled_backup_reads(): void
    {
        $command = (string) file_get_contents(
            app_path('Console/Commands/DatabaseBackupCommand.php'));

        $this->assertStringContainsString('AMIAL_BACKUP_REMOTE', $command,
            'الأمرُ المجدوَل لا يقرأ وجهةً خارجيّة');

        $this->assertContains('AMIAL_BACKUP_REMOTE', $this->writtenKeys(),
            '**المرشدُ لا يضبط `AMIAL_BACKUP_REMOTE`** — وهو ما يقرؤه '
            . 'الأمرُ الذي يجري كلَّ ليلة، وما يقرؤه مقياسُ الجاهزيّة. '
            . 'فيتّبع صاحبُ المشروع المرشدَ وتبقى النسخُ كلُّها على القرص.');
    }

    /**
     * **ووقفةُ الإقرار لا تُتخطّى بموافقةٍ مسبقة.**
     *
     * `confirm` سؤالُ إذنٍ يملكه المشغِّل. **و`pause` إقرارٌ بأنّ خطوةً
     * يدويّةً وقعت** — في لوحة Coolify أو على هاتف. وافتراضُ وقوعها
     * يجعل المرشدَ يدّعي ما لم يحدث، وهو نفسُ عطل تمرين التعافي الذي
     * أخرج `PASS` على قاعدةٍ غيرِ موجودة.
     *
     * @test
     */
    public function a_manual_step_is_never_assumed_done_without_a_human(): void
    {
        $src = $this->wizard();

        $fn = substr($src, (int) strpos($src, "\npause() {"));
        $fn = substr($fn, 0, (int) strpos($fn, "\n}"));

        $this->assertStringNotContainsString('AMIAL_WIZARD_YES', $fn,
            '**`pause` تُتخطّى بالموافقة المسبقة** — فيُقرأ التقريرُ «تمّ» '
            . 'على خطوةٍ في لوحة Coolify لم يفتحها أحد');

        $this->assertStringContainsString('UNVERIFIED_STEPS', $fn,
            'الخطوةُ غيرُ المثبَتة لا تُعدّ — فتمرّ صامتةً ولا يعرف '
            . 'أحدٌ كم بقي');
    }
}
