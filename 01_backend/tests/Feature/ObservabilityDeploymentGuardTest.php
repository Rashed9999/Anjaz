<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-OBSERVABILITY-DEPLOY-001 — **ثلاثُ خطواتٍ كانت «يدويّة»، وليست.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الحكمُ الذي وُلد منه هذا الملفّ:** قِيس محورُ الرصد **٣ من ١٠**، وكُتب
 * أنّ علاجَه «خطواتٌ في لوحة Coolify». **وقياسٌ ثانٍ نقض ذلك**: ثلاثٌ
 * منها تُحسَم في الشيفرة، وإنّما ظلّت يدويّةً لأنّ أحداً لم ينظر.
 *
 *   ① فحصُ الصحّة  — **الصورةُ تُعلنه بـ`HEALTHCHECK`**، لا اللوحة
 *   ② جولاتُ ساهر  — كانت مؤجَّلةً لغياب شاشة، **والشاشةُ بُنيت**
 *   ③ نسخةٌ بعيدة  — **الأمرُ يشحنها بنفسه** متى وُجدت وجهة
 *
 * **وخطوةٌ يدويّةٌ تُنسى.** فما يُمكن أن يصير شيفرةً يصير شيفرة، وما
 * يبقى للإنسان يُقال صراحةً ويبقى قصيراً.
 */
class ObservabilityDeploymentGuardTest extends TestCase
{
    use RefreshDatabase;

    private function deployedImage(): string
    {
        $p = base_path('Dockerfile');
        $this->assertFileExists($p);

        // **ويُقرأ ما يُنفَّذ لا ما يُشرَح** — التعليقُ أعلاه يذكر
        // `HEALTHCHECK` بالاسم، ومطابقةٌ عمياءُ تقرؤه ضبطاً.
        return preg_replace('/^\s*#.*$/m', '', (string) file_get_contents($p)) ?? '';
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① فحصُ الصحّة في الصورة المنشورة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_deployed_image_declares_its_own_health_check(): void
    {
        // **ولوحةُ Coolify تقول `Running (no health check)`** — فكلُّ
        // نشرةٍ «ناجحة» مهما كانت حالةُ التطبيق. والصورةُ تُغني عن الضبط.
        $this->assertStringContainsString('HEALTHCHECK', $this->deployedImage(),
            'الصورةُ المنشورةُ بلا فحصِ صحّة — والضبطُ اليدويُّ يُنسى');
    }

    /** @test */
    public function it_asks_readiness_not_the_lenient_deploy_probe(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ومسبارٌ متسامحٌ لا يصلح حارساً دائماً.**
        //
        // `/railway-health` يقول «نعم» أثناء الهجرات **عمداً** (نافذةُ
        // سماحٍ ١٨٠ث) لئلّا تُسقَط نشرةٌ سليمة. فاتّخاذُه فحصاً دائماً
        // يُنتج حاويةً خضراءَ فوق قاعدةٍ ساقطة.
        // ══════════════════════════════════════════════════════════════
        $img = $this->deployedImage();

        $this->assertStringContainsString('/health/readiness', $img,
            'فحصُ الصحّة لا يسأل الجاهزيّةَ الحقيقيّة');

        $this->assertStringNotContainsString('railway-health', $img,
            'فحصُ الصحّة يسأل مسبارَ النشر المتسامح — فيبقى أخضرَ فوق قاعدةٍ ساقطة');
    }

    /** @test */
    public function it_tries_every_port_the_entrypoint_may_listen_on(): void
    {
        // **ومنفذٌ مكتوبٌ رقماً واحداً يجعل الفحصَ يسقط دائماً**، وفحصٌ
        // يسقط دائماً أسوأ من غيابه: يُعيد تشغيلَ حاويةٍ سليمةٍ كلَّ دقيقة.
        //
        // و`entrypoint.sh` يُنشئ إصغاءً على `$PORT` و9000 و8080.
        $img = $this->deployedImage();

        foreach (['PORT', '9000', '8080'] as $port) {
            $this->assertStringContainsString($port, $img,
                "فحصُ الصحّة لا يجرّب المنفذ {$port} — و`entrypoint` يُصغي عليه");
        }
    }

    /** @test */
    public function the_start_period_matches_the_migration_grace_window(): void
    {
        // **وإلّا قُتلت نشرةٌ سليمةٌ هجرتُها تجري.**
        $this->assertMatchesRegularExpression(
            '/start-period=1[0-9]{2}s/', $this->deployedImage(),
            'نافذةُ بدءِ الفحص أقصرُ من زمن الهجرات — فتُقتَل نشرةٌ سليمة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② ساهر يجري من تلقائه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_saher_collector_is_scheduled(): void
    {
        // **ورادارٌ لا يجري ليس راداراً.** كان التأجيلُ مُعلَّلاً بغياب
        // شاشةٍ تقرأ نتائجَه — **والشاشةُ بُنيت**، فزال العذر.
        $src = (string) file_get_contents(base_path('routes/console.php'));

        foreach (['saher:scan-guards', 'saher:scan-data', 'saher:scan-gate'] as $cmd) {
            $this->assertMatchesRegularExpression(
                '/Schedule::command\(\'' . preg_quote($cmd, '/') . '\'\)/', $src,
                "«{$cmd}» مبنيٌّ ولا يجري — فلا يُكتشف شيءٌ بين جولتين يدويّتين");
        }
    }

    /** @test */
    public function the_scans_do_not_collide_with_the_nightly_reconciliation(): void
    {
        // **وثلاثةُ ماسحاتٍ ومصالحةٌ في الساعة نفسِها تتنازع على القاعدة**،
        // فتبطئ المصالحةَ — وهي التي يُبنى عليها كشفُ فرقِ المال.
        $src = (string) file_get_contents(base_path('routes/console.php'));

        preg_match('/reconcile-nightly.{0,120}?dailyAt\(\'(\d\d):/s', $src, $recon);
        preg_match_all('/saher:scan-\w+.{0,120}?dailyAt\(\'(\d\d):/s', $src, $saher);

        $this->assertNotEmpty($recon, 'تعذّر قياسُ ساعة المصالحة');
        $this->assertNotEmpty($saher[1], 'تعذّر قياسُ ساعات ساهر');

        foreach ($saher[1] as $h) {
            $this->assertNotSame($recon[1], $h,
                "ساهر يجري في الساعة {$h} كالمصالحة — فيتنازعان على القاعدة");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والنسخةُ تخرج من الخادم
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_backup_command_can_ship_off_the_server(): void
    {
        // **ونسخةٌ بجانب القاعدة تموت معها.** وزمنُ الاستعادة المقيس
        // (٣ ثوانٍ) لا يعني شيئاً بلا ما يُستعاد منه.
        $src = (string) file_get_contents(
            app_path('Console/Commands/DatabaseBackupCommand.php'));

        $this->assertStringContainsString('AMIAL_BACKUP_REMOTE', $src,
            'أمرُ النسخ لا يعرف وجهةً خارج الخادم');

        $this->assertStringContainsString('shipOffsite', $src);
    }

    /** @test */
    public function it_ships_only_after_the_archive_is_verified(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ورفعُ أرشيفٍ مقطوعٍ إلى مخزنٍ بعيدٍ يُنتج طمأنينةً كاذبةً في
        // مكانين بدل واحد.**
        //
        // فيُقاس الترتيبُ لا الوجود: الشحنُ بعد فحص `gzip -t` وخاتمةِ
        // `Dump completed`.
        // ══════════════════════════════════════════════════════════════
        $src = (string) file_get_contents(
            app_path('Console/Commands/DatabaseBackupCommand.php'));

        $verifyAt = strpos($src, "str_contains(\$tail, 'Dump completed')");
        $shipAt = strpos($src, '$this->shipOffsite(');

        $this->assertNotFalse($verifyAt, 'اختفى فحصُ خاتمة التفريغ');
        $this->assertNotFalse($shipAt, 'لا شحنَ في الأمر');

        // نداءُ الشحن يقع بعد كتلة التحقّق في مسار النجاح.
        $rejectAt = strpos($src, 'أُنتجت نسخةٌ ثمّ رُفضت');

        $this->assertNotFalse($rejectAt);
        $this->assertGreaterThan($rejectAt, $shipAt,
            'الشحنُ يقع قبل رفضِ النسخة الفاسدة — فتُرفَع نسخةٌ مقطوعةٌ بعيداً');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ وغيابُ قناة الإنذار يُقال في الصفحة الأولى
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_missing_alert_channel_is_shouted_on_the_first_screen(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **الفجوةُ كانت مكتوبةً حيث لا تُقرأ.**
        //
        // `OpsAlertService::hasExternalChannel()` وصفُها يقول «تقرؤه
        // اللوحةُ لتقول الفجوةَ صراحةً» — **وقِيس فلم يقرأها ملفٌّ واحد**.
        // واللافتةُ الوحيدةُ في «صحّة النظام»، وهي صفحةٌ فرعيّةٌ لا تُفتح
        // يوميّاً.
        //
        // **ولافتةٌ في صفحةٍ لا تُفتح ليست لافتة.** فموضعُها الصفحةُ
        // الأولى — يراها كلُّ مشرفٍ في كلّ دخول.
        // ══════════════════════════════════════════════════════════════
        $src = (string) file_get_contents(
            resource_path('views/admin-views/dashboard.blade.php'));

        $this->assertStringContainsString('hasExternalChannel', $src,
            'لوحةُ القيادة لا تسأل عن قناة الإنذار — فيبقى غيابُها مستوراً');

        $this->assertStringContainsString('dash-no-alert-channel', $src);
    }

    /** @test */
    public function the_banner_disappears_once_a_channel_is_set(): void
    {
        // **ولافتةٌ لا تختفي تُعلَّم عينُ القارئ تجاهلَها**، ثمّ تُتجاهَل
        // يومَ تصدق. فيُقاس الشرطُ لا وجودُ النصّ.
        $src = (string) file_get_contents(
            resource_path('views/admin-views/dashboard.blade.php'));

        $this->assertMatchesRegularExpression(
            '/@unless\s*\(\s*\$hasAlertChannel\s*\)/', $src,
            'اللافتةُ تُعرَض دائماً — فتُقرأ زينةً لا إنذاراً');
    }

    /** @test */
    public function the_service_actually_answers_both_ways(): void
    {
        // **وحارسٌ على نصٍّ في قالبٍ لا يُثبت أنّ الدالّةَ تعمل.**
        config(['amial.reconciliation.alert_numbers' => [],
                'amial.reconciliation.alert_emails' => []]);

        $this->assertFalse(\App\Services\OpsAlertService::hasExternalChannel(),
            'قالت «القناةُ مضبوطة» وهي فارغة — والصفحةُ ستصمت عن الفجوة');

        config(['amial.reconciliation.alert_numbers' => ['967771000001']]);

        $this->assertTrue(\App\Services\OpsAlertService::hasExternalChannel(),
            'قالت «لا قناة» وهي مضبوطة — فتبقى اللافتةُ الحمراءُ إلى الأبد');
    }

    /** @test */
    public function a_missing_destination_is_said_not_swallowed(): void
    {
        // **وغيابُ الوجهة ليس فشلاً — هو حالةٌ تُقال.** ومن لم يضبطها
        // يبقى على نسخةٍ محلّيّةٍ صالحة، **ويُرفع عطلٌ يقول ذلك** فلا
        // يُقرأ الصمتُ أماناً. (القاعدة السابعة.)
        $src = (string) file_get_contents(
            app_path('Console/Commands/DatabaseBackupCommand.php'));

        $this->assertStringContainsString('backup.offsite.unconfigured', $src,
            'غيابُ الوجهة يمرّ صامتاً — فيُظنّ أنّ نسخةً بعيدةً موجودة');

        // **وفشلُ الشحن لا يُسقط ما نجح** — النسخةُ المحلّيّةُ محقَّقة.
        $this->assertStringContainsString('backup.offsite.failed', $src);
    }
}
