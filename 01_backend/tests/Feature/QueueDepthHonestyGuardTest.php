<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-HEALTH-QUEUE-001 — **صفحةُ صحّةٍ خضراءُ فوق طابورٍ ممتلئ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `checkQueueDepth` كانت تردّ `'healthy' => true` **دائماً**: على
 * `overloaded`، وفي `catch` حين يتعذّر القياسُ أصلاً.
 *
 * فطابورٌ فيه خمسون ألفَ مهمّةٍ عالقةٍ يُقرأ «سليماً»، ولا إيصالَ ولا
 * إشعارَ يصل أحداً، **والصفحةُ خضراء**. وهو نمطُ «حارسٌ يكذب» الذي دفع
 * المشروعُ ثمنَه مرّاتٍ: يُطمئن ولا يحرس.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا أوّلُ ما يقع في تجربةِ ألفَي مستخدم.** الطابورُ أوّلُ ما يمتلئ
 * تحت الحمل — وكان آخرَ ما يُرى.
 */
class QueueDepthHonestyGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function readiness(): array
    {
        return $this->getJson('/health/readiness')->json();
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_quiet_queue_reads_healthy(): void
    {
        // **وإنذارٌ كاذبٌ يُعوّد القارئَ على التجاهل يومَ يصدق** — فيُثبَت
        // أوّلاً أنّ الحالةَ السليمةَ تبقى سليمة.
        $r = $this->readiness();

        $this->assertTrue($r['checks']['queue']['healthy'],
            'طابورٌ فارغٌ قُرئ معطوباً — والإنذارُ الكاذبُ يُفقد الثقةَ بالصفحة');

        $this->assertSame('normal', $r['checks']['queue']['status']);
        $this->assertSame('ready', $r['status']);
    }

    /** @test */
    public function an_overloaded_queue_is_not_reported_healthy(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** خمسةُ آلافٍ فما فوق = `overloaded`،
        // وكانت تُردّ `healthy => true` معها.
        // ══════════════════════════════════════════════════════════════
        Queue::shouldReceive('size')->with('default')->andReturn(6000);
        Queue::shouldReceive('size')->with('receipts')->andReturn(0);

        $r = $this->readiness();

        $this->assertSame('overloaded', $r['checks']['queue']['status']);

        $this->assertFalse($r['checks']['queue']['healthy'],
            '**طابورٌ ممتلئٌ قُرئ سليماً** — فالصفحةُ خضراءُ ولا إيصالَ '
            . 'يصل أحداً، ولا شيءَ يقول إنّ شيئاً يجري');
    }

    /**
     * **والامتلاءُ لا يُسقط الحاوية.**
     *
     * `readiness` يقرؤه `HEALTHCHECK` في الصورة المنشورة. وإسقاطُ
     * الحاوية على طابورٍ ممتلئٍ **يقتل العاملَ الذي يُفرغه** — فيُعالَج
     * العطلُ بما يزيده، وتدخل النشرةُ حلقةَ إعادة تشغيل.
     *
     * @test
     */
    public function a_full_queue_does_not_kill_a_working_container(): void
    {
        Queue::shouldReceive('size')->with('default')->andReturn(9000);
        Queue::shouldReceive('size')->with('receipts')->andReturn(0);

        $this->getJson('/health/readiness')->assertStatus(200);

        $this->assertSame('degraded', $this->readiness()['status'],
            'الطابورُ ممتلئٌ والحالةُ العامّةُ تقول «ready» — فتُقرأ '
            . 'الصفحةُ خضراءَ وهي ليست كذلك');
    }

    /**
     * **و«تعذّر القياس» ليس «سليماً»** — القاعدةُ السابعة بنصّها.
     *
     * @test
     */
    public function an_unmeasurable_queue_says_unknown_not_healthy(): void
    {
        Queue::shouldReceive('size')->andThrow(new \RuntimeException('لا اتّصال'));

        $q = $this->readiness()['checks']['queue'];

        $this->assertSame('unknown', $q['status'],
            'تعذّر قياسُ الطابور فقيل «normal» — وهو رقمٌ لم يُقَس');

        $this->assertFalse($q['healthy'],
            '**عجزٌ عن القياس قُرئ سلامةً** — فلا يُعرف أممتلئٌ هو أم '
            . 'فارغ، ويُقال إنّه بخير');
    }

    /**
     * **وعطلُ الرصد لا يُسقط ما يرصده.**
     *
     * رفعُ الإنذار داخل صفحة الصحّة يجب أن يكون محروساً: لو رمى
     * `OpsAlertService` لسقطت الصفحةُ كلُّها، **فيصير عطلٌ في الرصد
     * انقطاعاً في الخدمة** — والحاويةُ تُقتل بـ`HEALTHCHECK`.
     *
     * @test
     */
    public function the_alert_is_wrapped_so_a_failing_alerter_cannot_take_the_page_down(): void
    {
        $src = (string) file_get_contents(
            app_path('Http/Controllers/Api/HealthCheckController.php'));

        $fn = substr($src, (int) strpos($src, 'private function noteOps('), 500);

        $this->assertStringContainsString('catch', $fn,
            'نداءُ الإنذار غيرُ محروس — فرميةٌ فيه تُسقط صفحةَ الصحّة، '
            . 'ويُقتَل التطبيقُ السليمُ لأنّ راصدَه عطب');
    }
}
