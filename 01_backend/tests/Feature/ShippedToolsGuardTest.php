<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BACKUP-OFFSITE-001 — **أداةٌ تُنادى ولا تُشحَن.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `DatabaseBackupCommand::shipOffsite` تنسخ النسخةَ الاحتياطيّةَ بـ`rclone`
 * متى ضُبطت `AMIAL_BACKUP_REMOTE`. **ولم تكن `rclone` في أيّ من الصورتين.**
 *
 * فالخطوةُ الخامسةُ في مرشد الإطلاق — «نسخةٌ خارج الخادم» — كانت **تسقط
 * لحظةَ تُنفَّذ**: يضبط صاحبُ المشروع الوجهةَ، ويقرأ «الوجهةُ مضبوطة»،
 * ولا نسخةَ بعيدةً تُكتب أبداً.
 *
 * والشيفرةُ تقولها بصدق (`backup.offsite.tool-missing`) — **لكنّ الصدقَ
 * ليس نجاحاً**: الخطوةُ لا تتمّ، والقرصُ الواحدُ يُفقِد الأصلَ والنسخةَ
 * معاً يومَ يذهب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا حارسٌ عامّ لا حارسُ `rclone`.** كلُّ أداةٍ خارجيّةٍ تناديها
 * الشيفرةُ يجب أن تكون في الصورتين معاً — والصورتان تفترقان: Coolify
 * يبني `Dockerfile` والبوّابةُ تفحص `Dockerfile.prod`، فأداةٌ في واحدةٍ
 * دون الأخرى **تعمل في الفحص وتسقط في الإنتاج**.
 */
class ShippedToolsGuardTest extends TestCase
{
    /**
     * الأدواتُ الخارجيّةُ التي تُنفَّذ من PHP — ومَن يناديها.
     *
     * @return array<string,string>
     */
    private function requiredTools(): array
    {
        return [
            'rclone' => 'شحنُ النسخة الاحتياطيّة خارج الخادم',
            'mysqldump' => 'أخذُ النسخة الاحتياطيّة نفسِها',
            'curl' => 'مسبارُ الصحّة في `HEALTHCHECK`',
        ];
    }

    /** @return array<string,string> */
    private function images(): array
    {
        return [
            'Dockerfile' => 'الصورةُ التي يبنيها Coolify — **المنشورةُ فعلاً**',
            'Dockerfile.prod' => 'الصورةُ التي تفحصها البوّابة',
        ];
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_tool_the_code_shells_out_to_is_present_in_both_images(): void
    {
        foreach ($this->images() as $image => $what) {
            $path = base_path($image);

            $this->assertFileExists($path, "{$image} مفقود");

            // ══════════════════════════════════════════════════════════
            // **وتُنزَع تعليقاتُ Dockerfile قبل البحث.**
            //
            // جُرّب هذا بالعكس فمرّ: أُزيلت `rclone` من قائمة الحزم
            // **وبقيت في التعليق الذي يشرح لماذا أُضيفت** — فوجدها
            // الحارسُ في نصٍّ يصفها لا في سطرٍ يُثبّتها.
            //
            // وهي ثالثةُ مرّةٍ يقع فيها هذا في هذه الجلسة، وهي مكتوبةٌ
            // في `CLAUDE.md` منذ قبلها: «التعليق الذي يصف العطل كان
            // يُخفيه».
            // ══════════════════════════════════════════════════════════
            $src = implode("\n", array_filter(
                explode("\n", (string) file_get_contents($path)),
                static fn (string $line): bool
                    => ! str_starts_with(ltrim($line), '#'),
            ));

            foreach ($this->requiredTools() as $tool => $why) {
                // `mysql-client` تحمل `mysqldump`؛ فيُقبل أيُّ الاسمين.
                $present = str_contains($src, $tool)
                    || ($tool === 'mysqldump' && str_contains($src, 'mysql-client'));

                $this->assertTrue($present,
                    "**«{$tool}» ليست في {$image}** ({$what}) — وتُنادى لـ«{$why}». "
                    . 'فالميزةُ مبنيّةٌ وتسقط أوّلَ ما تُستعمَل، ولا شيءَ '
                    . 'يقول ذلك قبل ليلةِ الحاجة.');
            }
        }
    }

    /**
     * **والنداءُ لا يُفترَض نجاحَه** — يُفحَص وجودُ الأداة قبل استعمالها،
     * وإلّا خرج الأمرُ بصفرٍ على لا شيء.
     *
     * @test
     */
    public function the_backup_command_checks_the_tool_before_trusting_it(): void
    {
        $src = (string) file_get_contents(
            app_path('Console/Commands/DatabaseBackupCommand.php'));

        $fn = substr($src, (int) strpos($src, 'function shipOffsite('));
        $fn = substr($fn, 0, (int) strpos($fn, "\n    }"));

        $this->assertStringContainsString('command -v rclone', $fn,
            'يُنادى `rclone` بلا فحصِ وجوده — فيُقرأ فشلُ الصدفة نجاحاً، '
            . 'ويُظنّ أنّ نسخةً بعيدةً كُتبت ولا وجودَ لها');

        $this->assertStringContainsString('backup.offsite.tool-missing', $fn,
            'غيابُ الأداة لا يُرفَع عطلاً — **فالوجهةُ تُوهم بنسخةٍ بعيدة**');
    }

    /**
     * **وغيابُ الوجهة يُقال كذلك** — «لا نسخةَ خارج الخادم» حالةٌ تُعلَن،
     * لا صمتٌ يُقرأ سلامةً. (القاعدةُ السابعة.)
     *
     * @test
     */
    public function an_unconfigured_destination_is_announced_not_silently_skipped(): void
    {
        $src = (string) file_get_contents(
            app_path('Console/Commands/DatabaseBackupCommand.php'));

        $this->assertStringContainsString('backup.offsite.unconfigured', $src,
            'لا وجهةَ خارجيّةً ولا إنذار — فتمضي الليالي وكلُّ النسخ على '
            . 'القرص نفسِه، ولا شيءَ يذكّر');
    }
}
