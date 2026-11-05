<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-DEBUG-ESCAPE-001 — **منفذٌ يُفتح مرّةً ويبقى مفتوحاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * حاجزُ `APP_DEBUG` في `entrypoint.sh` يمنع الخدمةَ من البدء بوضع
 * التنقيح — **وله منفذُ خروجٍ**: `AMIAL_ALLOW_DEBUG=true`، لبيئة الديمو
 * المحلّيّة التي تعمل بالتنقيح عمداً.
 *
 * **وكان المنفذُ يعمل في الإنتاج أيضاً.** ومنفذٌ يُفتح مرّةً لتجربةٍ
 * عاجلةٍ يبقى مفتوحاً: لا شيءَ يُغلقه، ولا شيءَ يذكّر به، **وصفحةُ
 * الخطأ تُسرّب مساراتِ الملفّات ونصوصَ الاستعلامات وأسماءَ الأعمدة لأيّ
 * مستخدمٍ يستقبل ٥٠٠**. وهي بياناتُ منصّةٍ ماليّة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ هذا يرفع الجاهزيّة:** كان «إيقافُ وضع التطوير» خطوةً يدويّةً في
 * مرشد الإطلاق — أي شرطاً يعتمد على **تذكُّر إنسان**. وصار مضموناً
 * بالبناء: حاويةُ إنتاجٍ تعمل ⇒ التنقيحُ مغلقٌ حتماً.
 *
 * **والأربعُ حالاتٍ تُشغَّل فعلاً** — لا يُقرأ النصُّ ويُفترَض معناه.
 * فسطرُ شِلٍّ يُقرأ صحيحاً وينفَّذ خطأً أمرٌ وقع في هذا المشروع من قبل.
 */
class DebugEscapeGuardTest extends TestCase
{
    /**
     * يُشغّل كتلةَ الحاجز وحدَها بمتغيّراتٍ بعينها، ويُعيد رمزَ الخروج.
     *
     * ولا يُشغَّل `entrypoint.sh` كاملاً — فيه هجراتٌ وكاشٌ وnginx.
     * فتُقتطَع الكتلةُ المعنيّةُ وتُشغَّل بـ`sh -e` وحدَها.
     */
    private function runBarrier(string $appEnv, string $debug, string $escape): int
    {
        $src = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $from = strpos($src, 'DEBUG_ESCAPE="${AMIAL_ALLOW_DEBUG:-false}"');
        $this->assertNotFalse($from, 'كتلةُ الحاجز غيرُ موجودة');

        $to = strpos($src, 'fi', (int) strpos($src, 'exit 1', (int) $from));
        $block = substr($src, (int) $from, ((int) $to + 2) - (int) $from);

        $script = "EFFECTIVE_DEBUG='{$debug}'\n" . $block . "\nexit 0\n";

        $file = tempnam(sys_get_temp_dir(), 'amial-barrier-') . '.sh';
        file_put_contents($file, $script);

        $env = sprintf('APP_ENV=%s AMIAL_ALLOW_DEBUG=%s', escapeshellarg($appEnv), escapeshellarg($escape));
        exec("{$env} sh " . escapeshellarg($file) . ' >/dev/null 2>&1', $out, $code);

        @unlink($file);

        return (int) $code;
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function production_with_debug_on_refuses_to_serve(): void
    {
        $this->assertSame(1, $this->runBarrier('production', 'true', 'false'),
            '**بدأت الخدمةُ بوضع التنقيح في الإنتاج** — فكلُّ خطأٍ ٥٠٠ '
            . 'يُخرج مساراتِ الملفّات ونصوصَ الاستعلامات لمن استقبله');
    }

    /**
     * **وهذا هو العطلُ بعينه.**
     *
     * @test
     */
    public function the_escape_hatch_does_not_work_in_production(): void
    {
        $this->assertSame(1, $this->runBarrier('production', 'true', 'true'),
            '**`AMIAL_ALLOW_DEBUG` فتحت الحاجزَ في الإنتاج** — ومنفذٌ '
            . 'يُفتح لتجربةٍ عاجلةٍ يبقى مفتوحاً إلى الأبد، ولا شيءَ '
            . 'يذكّر به');
    }

    /**
     * **ولا يُقفل بابُ التطوير** — بيئةُ الديمو تعمل بالتنقيح عمداً،
     * ومنعُها يستبدل عطلاً بعطل.
     *
     * @test
     */
    public function a_development_environment_may_still_use_the_hatch(): void
    {
        $this->assertSame(0, $this->runBarrier('local', 'true', 'true'),
            'مُنعت بيئةُ التطوير من التنقيح — فصار الحارسُ يشلّ العملَ '
            . 'الذي بُني ليحميه');
    }

    /**
     * **والمقياسُ يعدّ الشرطَ مقيساً لا مجهولاً.**
     *
     * وإلّا بقي الضمانُ في الشيفرة ولا يُقرأ في أيّ تقرير — وهو نمطُ
     * «مبنيٌّ ولا يُوصَل إليه» واقعاً على القياس نفسِه.
     *
     * @test
     */
    public function the_readiness_meter_counts_the_barrier_as_measured(): void
    {
        // **ورمزُ الخروجُ يبقى ١** — وهو صحيح: خمسةُ شروطٍ ما زالت
        // مجهولةً لأنّها لا تُقاس إلّا من الخادم، والمقياسُ **يرفض
        // اختصارَ المقيسِ والمجهولِ في رقم**. فلا يُطالَب بالنجاح هنا،
        // بل بأن يُعدَّ هذا الشرطُ بعينه مقيساً.
        $this->artisan('amial:readiness')
            ->expectsOutputToContain('مضمونٌ بالبناء');
    }

    /** @test */
    public function debug_off_always_passes(): void
    {
        // **وإنذارٌ كاذبٌ يُعوّد القارئَ على التجاهل يومَ يصدق.**
        $this->assertSame(0, $this->runBarrier('production', 'false', 'false'),
            'وُقفت خدمةٌ والتنقيحُ مغلقٌ أصلاً');

        $this->assertSame(0, $this->runBarrier('local', 'false', 'false'));
    }
}
