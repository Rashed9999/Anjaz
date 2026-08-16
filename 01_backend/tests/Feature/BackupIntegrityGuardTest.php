<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-PROD-READINESS-001 — **ملفٌّ موجودٌ ليس نسخةً احتياطيّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ أعطالٍ قِيست في تدقيق الجاهزيّة، وهذا الحارسُ يمنع عودتها:**
 *
 *   ① **`scripts/backup.sh` كان ملفّاً فارغاً (٠ بايت)** منذ التزام
 *      `3691383` في ٨ أغسطس — أُفرغ عرضاً في التزامٍ عن حرّاس الإملاء.
 *      فسلسلةُ التعافي كلُّها (`run_dr.sh` يستدعيه) كانت مكسورةً ثمانيةَ
 *      أيّام، **ولم يلحظ أحد** لأنّ التمرينَ لم يكن في أيّ بوّابة.
 *
 *   ② **وتمرينُ التعافي كان يكذب**: أخرج `VERDICT: PASS ✓ التعافي كامل`
 *      على قاعدةٍ غيرِ موجودة — بصمةٌ فارغةٌ قبل، وفارغةٌ بعد، و`"" = ""`.
 *      وخرج بالرمز صفر مهما وقع، فلا بوّابةَ تستطيع قراءتَه.
 *
 *   ③ **و`amial:backup` كان يقبل تفريغاً مقطوعاً**: الفحصُ «رمزُ الخروج
 *      صفرٌ وحجمٌ > صفر»، و`mysqldump` المقطوعُ خلف `| gzip` يترك ملفّاً
 *      غيرَ فارغٍ ورمزَ خروجٍ صفراً. فتُقرأ نسخةٌ ناقصةٌ سليمةً — ولا
 *      يُكتشَف ذلك إلّا ليلةَ الحاجة، وهي أسوأُ ليلةٍ لاكتشافه.
 */
class BackupIntegrityGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     *
     * **سكربتُ النسخ ليس فارغاً** — وهو العطلُ الحرفيُّ الذي وقع.
     */
    public function the_backup_script_is_not_an_empty_file(): void
    {
        $path = base_path('scripts/backup.sh');

        $this->assertFileExists($path, 'سكربتُ النسخ الاحتياطيّ اختفى');

        $this->assertGreaterThan(500, (int) filesize($path),
            'scripts/backup.sh فارغٌ أو مبتور — وسلسلةُ التعافي كلُّها تستدعيه. '
            . '(وقع هذا فعلاً: أُفرغ في 3691383 وبقي مكسوراً ثمانيةَ أيّام.)');

        $src = (string) file_get_contents($path);

        foreach (['mysqldump', 'sha256sum'] as $needed) {
            $this->assertStringContainsString($needed, $src,
                "سكربتُ النسخ بلا «{$needed}» — نسخةٌ بلا بصمةٍ لا يُكشَف فسادُها");
        }
    }

    /**
     * @test
     *
     * **وتمرينُ التعافي يخرج برمزٍ يُقرأ.**
     *
     * كان `exit 0` دائماً — فحتّى لو أُدرج في بوّابةٍ لقرأته ناجحاً أبداً.
     */
    public function the_recovery_drill_reports_failure_through_its_exit_code(): void
    {
        $src = (string) file_get_contents(base_path('scripts/run_dr.sh'));

        $this->assertStringContainsString('exit 1', $src,
            'تمرينُ التعافي لا يخرج بفشلٍ إطلاقاً — فلا بوّابةَ تستطيع قراءتَه');

        $this->assertMatchesRegularExpression('/die\s*\(\)/', $src,
            'لا شروطَ مسبقةً في التمرين — فيمرّ على قاعدةٍ فارغةٍ ويقول «تعافٍ كامل»');
    }

    /**
     * @test
     *
     * **والبوّابةُ تُشغّله** — وإلّا انكسر ثانيةً بلا أن يعلم أحد.
     *
     * (وهذا نصُّ ما وقع: التمرينُ مبنيٌّ منذ AMIAL-DR-001، ومكسورٌ ثمانيةَ
     * أيّامٍ لأنّ لا شيءَ يشغّله.)
     */
    public function the_gate_runs_the_recovery_drill(): void
    {
        $gate = preg_replace('~^\s*#.*$~m', '',
            (string) file_get_contents(base_path('scripts/verify.sh')));

        $this->assertStringContainsString('run_dr.sh', (string) $gate,
            'تمرينُ التعافي خارجَ البوّابة — فينكسر صامتاً كما انكسر من قبل');
    }

    /**
     * @test
     *
     * **والنسخةُ المنتَجةُ تُفحَص وتُبصَم.**
     */
    public function a_produced_backup_is_verified_and_fingerprinted(): void
    {
        $dir = storage_path('app/backups');
        $before = glob($dir . '/db-*.sql.gz') ?: [];

        $this->artisan('amial:backup', ['--no-cleanup' => true])->assertSuccessful();

        $after = array_values(array_diff(glob($dir . '/db-*.sql.gz') ?: [], $before));

        $this->assertNotEmpty($after, 'لم تُنتَج نسخةٌ إطلاقاً');

        $file = $after[0];

        $this->assertFileExists($file . '.sha256',
            'نسخةٌ بلا بصمة — و`scripts/restore.sh` لا يستطيع رفضَ فسادها');

        $this->assertSame(hash_file('sha256', $file),
            explode(' ', (string) file_get_contents($file . '.sha256'))[0],
            'البصمةُ لا تطابق الملفّ — فترفض الاستعادةُ نسخةً سليمة');

        // تنظيفٌ — لا تُترك آثارُ الاختبار في مجلّد النسخ.
        @unlink($file);
        @unlink($file . '.sha256');
    }

    /**
     * @test
     *
     * **وتفريغٌ مقطوعٌ يُرفَض** — وهو ما كان يمرّ.
     *
     * ══════════════════════════════════════════════════════════════════
     * والمشهدُ مصنوعٌ بدقّة: ملفٌّ **مضغوطٌ سليمٌ تماماً** (‏`gzip -t`
     * يقبله) وينقصه ختمُ `mysqldump` وحدَه. فهو يمرّ من الفحص الأوّل
     * ويجب أن يسقط من الثاني — **ولولا الثاني لمرّ كلَّه.**
     */
    public function a_truncated_dump_is_rejected_even_though_the_gzip_is_valid(): void
    {
        $tmp = sys_get_temp_dir() . '/amial-trunc-' . bin2hex(random_bytes(5)) . '.sql.gz';

        // تفريغٌ يبدأ صحيحاً ثمّ يتوقّف في منتصف عبارةٍ — كما يقع حين
        // يمتلئ القرصُ أو ينقطع الاتّصال.
        file_put_contents($tmp, gzencode(
            "-- MySQL dump 10.13\nCREATE TABLE `x` (`a` int);\n"
            . "INSERT INTO `x` VALUES (1),(2),(3\n"));

        $this->assertSame(0, $this->gzipTestExitCode($tmp),
            'المشهدُ باطل: الأرشيفُ نفسُه مكسورٌ فلن يُقاس الفحصُ الثاني');

        $verdict = $this->callVerify($tmp);

        $this->assertNotNull($verdict,
            'تفريغٌ مقطوعٌ قُبل نسخةً صالحة — ولا يُكتشَف ذلك إلّا ليلةَ الحاجة');

        $this->assertStringContainsString('ناقصة', $verdict);

        @unlink($tmp);
    }

    /**
     * @test
     *
     * **ولا يُرفَض التفريغُ الكامل** — حارسٌ يرفض كلَّ شيءٍ يُنزَع.
     */
    public function a_complete_dump_passes_verification(): void
    {
        $tmp = sys_get_temp_dir() . '/amial-ok-' . bin2hex(random_bytes(5)) . '.sql.gz';

        file_put_contents($tmp, gzencode(
            "-- MySQL dump 10.13\nCREATE TABLE `x` (`a` int);\n"
            . "INSERT INTO `x` VALUES (1),(2),(3);\n"
            . "-- Dump completed on 2026-08-16  2:31:10\n"));

        $this->assertNull($this->callVerify($tmp),
            'نسخةٌ كاملةٌ رُفضت — الحارسُ يمنع كلَّ نسخةٍ فيُنزَع');

        @unlink($tmp);
    }

    /**
     * @test
     *
     * **ونسخةٌ فارغةٌ تماماً تُرفَض وتُنذِر.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا المشهدُ كشف عطلاً حيّاً لم يكن في التقرير:**
     *
     * بكلمة مرورٍ خاطئة، `mysqldump` لا يكتب بايتاً واحداً — لكنّ
     * `| gzip` يُنتج أرشيفاً صالحاً من عشرين بايتاً، و`exec` يُعيد صفراً
     * لأنّ آخرَ حلقةٍ في الأنبوب نجحت. فالفحصُ القديم:
     *
     *     if ($exitCode !== 0 || filesize($outFile) === 0) → فشل
     *
     * **يمرّ في الحالتين**، ويُعلَن «✅ تمّ النسخ» على ملفٍّ لا يحوي
     * قاعدةَ بياناتٍ إطلاقاً. وهو أسوأُ من غياب النسخة: غيابُها يُرى،
     * ووجودُها الكاذبُ يُطمئن حتّى ليلةِ الحاجة.
     *
     * **وقِيس حيّاً:** الأمرُ يخرج الآن بـ«ناقصة — لا خاتمةَ Dump completed».
     */
    public function an_empty_dump_is_rejected_and_raises_an_alarm(): void
    {
        config(['amial.reconciliation.alert_numbers' => []]);

        $dir = storage_path('app/backups');
        $before = glob($dir . '/db-*.sql.gz') ?: [];

        // **كلمةُ مرورٍ خاطئةٌ لا اسمُ قاعدةٍ خاطئ.** `mysqldump` يقرأ
        // الإعدادَ فيسقط، بينما اتّصالُ التطبيق **مفتوحٌ سلفاً** فيبقى
        // قادراً على كتابة الأثر. ولو كُسر الاتّصالُ نفسُه لما استطاع
        // المُنذِرُ الكتابةَ — فيُقاس صمتُ قاعدةٍ ميّتةٍ لا صمتُ الشيفرة.
        config(['database.connections.mysql.password' => 'كلمةٌ-خاطئةٌ-عمداً']);

        $this->artisan('amial:backup')->assertFailed();

        $this->assertTrue(
            DB::table('system_errors')
                ->whereIn('exception', ['backup.failed', 'backup.corrupt'])->exists(),
            'سقطت النسخةُ ولا أثرَ في مركز الأعطال — ليلةٌ بلا نسخةٍ لا يعرفها أحد');

        // **ولا يُترَك الملفُّ الكاذبُ في مجلّد النسخ.** بقاؤه يجعل
        // «آخرُ نسخةٍ: اليوم» صحيحةً وكاذبةً معاً.
        $after = array_values(array_diff(glob($dir . '/db-*.sql.gz') ?: [], $before));

        $this->assertSame([], $after,
            'بقيت النسخةُ الفارغةُ على القرص — فتُقرأ نسخةً صالحةً ليلةَ الحاجة');
    }

    // ══════════════════════════════════════════════════════════════════

    private function gzipTestExitCode(string $file): int
    {
        exec('gzip -t ' . escapeshellarg($file) . ' 2>/dev/null', $o, $rc);

        return $rc;
    }

    /** يستدعي `verifyArchive` الخاصّة — الفحصُ نفسُه لا نسخةٌ منه. */
    private function callVerify(string $file): ?string
    {
        $cmd = new \App\Console\Commands\DatabaseBackupCommand;
        $m = new \ReflectionMethod($cmd, 'verifyArchive');
        $m->setAccessible(true);

        /** @var string|null $out */
        $out = $m->invoke($cmd, $file);

        return $out;
    }
}
