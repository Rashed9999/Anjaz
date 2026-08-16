<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-PROD-READINESS-001 — **«نجح النشر» يجب أن تعني أكثر من «أقلع nginx».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في تدقيق الجاهزيّة:**
 *
 *   $ grep -n 'Healthcheck' DEPLOY_COOLIFY.md
 *   Healthcheck Path: /railway-health
 *
 *   $ grep -A4 'railway-health' docker/nginx.conf
 *   location = /railway-health { return 200 "ok"; }
 *
 * سطرٌ في nginx يجيب **قبل PHP وقبل قاعدة البيانات**. فنشرةٌ قاعدتُها
 * ساقطةٌ، أو هجرتُها لم تبدأ، أو تردّ ٥٠٠ على كلّ طلب — تُقاس **ناجحة**،
 * وتحلّ محلَّ نشرةٍ سليمةٍ كانت تعمل.
 *
 * **وفي المقابل `/health/readiness` كانت مبنيّةً وصحيحةً** تفحص القاعدةَ
 * والتخزينَ والطابورَ بالعمل — **ولا يسألها النشر**. مبنيٌّ ولا يُوصَل
 * إليه، على طبقة التشغيل هذه المرّة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحارسُ يقيس الاتّجاهين** — لأنّ مسباراً يردّ ٥٠٣ دائماً يشلّ النشرَ
 * كلَّه، وهو عطلٌ آخرُ مكان الأوّل:
 *
 *   ① سليمٌ ⇒ ٢٠٠.
 *   ② مكسورٌ بعد نافذة الإقلاع ⇒ ٥٠٣ (وهذا ما كان غائباً).
 *   ③ مكسورٌ داخلها ⇒ ٢٠٠ ومعها `warming` — فلا تُسقَط نشرةٌ سليمةٌ
 *     هجرتُها تجري.
 */
class DeploymentProbeGuardTest extends TestCase
{
    use RefreshDatabase;

    private const BOOT_STAMP = '/tmp/amial-boot-epoch';

    private ?string $realConnection = null;

    protected function tearDown(): void
    {
        // **يُعاد الاتّصالُ الحقيقيُّ قبل تنظيف `RefreshDatabase`** — فهو
        // يتراجع عن معاملته على `database.default`، فإن بقي مشيراً إلى
        // الوجهة الميّتة انفجر التنظيفُ وقُرئ العطلُ في الحارس لا في
        // المقيس. (وقع هذا هنا في أوّل تشغيلين.)
        if ($this->realConnection !== null) {
            config(['database.default' => $this->realConnection]);
            $this->realConnection = null;
        }

        @unlink(self::BOOT_STAMP);
        parent::tearDown();
    }

    /**
     * يجعل الجاهزيّةَ تسقط فعلاً — **بقاعدةٍ ميّتةٍ حقيقيّةٍ لا بمحاكاة.**
     *
     * ويُحوَّل الاتّصالُ الافتراضيُّ إلى وجهةٍ ميّتةٍ بدل كسر الاتّصال
     * القائم: `RefreshDatabase` يحتجز معاملةً على الاتّصال الأصليّ،
     * و`DB::purge()` عليه يفجّر التنظيفَ بعد الاختبار — فيُقرأ العطلُ في
     * الحارس لا في المقيس. (وقع هذا هنا في أوّل تشغيل.)
     */
    private function breakTheDatabase(): void
    {
        $this->realConnection = (string) config('database.default');

        config(['database.connections.amial_dead_probe' => array_merge(
            (array) config('database.connections.' . config('database.default')),
            ['host' => '127.0.0.1', 'port' => 1],
        )]);

        config(['database.default' => 'amial_dead_probe']);
    }

    /**
     * @test
     *
     * **المسارُ يصل إلى التطبيق** — لا يُبتلَع في nginx.
     */
    public function the_deploy_probe_is_served_by_the_application(): void
    {
        $res = $this->getJson('/railway-health');

        $this->assertContains($res->getStatusCode(), [200, 503],
            'مسبارُ النشر لا يجيب من التطبيق إطلاقاً');

        $this->assertArrayHasKey('checks', $res->json(),
            'المسبارُ يردّ بلا فحوصٍ — فهو ردٌّ ثابتٌ لا قياس');
    }

    /**
     * @test
     *
     * **ولا يعود الردُّ الثابتُ إلى nginx.**
     *
     * فمن أعاد سطرَ `return 200 "ok"` أبطل كلَّ ما فوقه بسطرٍ واحد،
     * ولا اختبارَ من جهة PHP يراه — nginx يعترض الطلبَ قبل أن يصل.
     */
    public function nginx_no_longer_short_circuits_the_probe(): void
    {
        foreach (['docker/nginx.conf', 'docker/nginx.prod.conf'] as $rel) {
            $path = base_path($rel);

            if (! is_file($path)) {
                continue;
            }

            // تُنزع التعليقاتُ أوّلاً: التعليقُ الذي يشرح العطلَ المُزال
            // يذكر نصَّه، وحارسٌ يسقط على شرحِ نفسِه لا يحرس شيئاً.
            // (وقع هذا في هذا المشروع ثلاث مرّات.)
            $conf = preg_replace('/^\s*#.*$/m', '', (string) file_get_contents($path));

            $this->assertDoesNotMatchRegularExpression(
                '~location\s*=?\s*/railway-health~', (string) $conf,
                "«{$rel}» يعترض /railway-health في nginx — فيردّ قبل PHP "
                . 'وقبل القاعدة، و«نجح النشر» تعود تعني «أقلع nginx»');
        }
    }

    /**
     * @test
     *
     * **سليمٌ ⇒ ٢٠٠.**
     */
    public function a_healthy_instance_answers_two_hundred(): void
    {
        $this->getJson('/railway-health')->assertOk();
    }

    /**
     * @test
     *
     * **ومكسورٌ بعد النافذة ⇒ ٥٠٣** — وهذا هو الفحصُ الذي كان غائباً.
     */
    public function a_broken_instance_fails_the_probe_after_the_grace_window(): void
    {
        // إقلاعٌ قديم: النافذةُ (١٨٠ ثانية) انتهت يقيناً.
        file_put_contents(self::BOOT_STAMP, (string) (time() - 3600));

        $this->breakTheDatabase();

        $this->getJson('/railway-health')->assertStatus(503);
    }

    /**
     * @test
     *
     * **ومكسورٌ داخل النافذة ⇒ ٢٠٠ مع `warming`.**
     *
     * وإلّا سقطت كلُّ نشرةٍ سليمةٍ ما دامت الهجرةُ تجري في الخلفيّة —
     * وحارسٌ يمنع الصوابَ يُنزَع بعد يومين.
     */
    public function a_booting_instance_is_given_its_grace_window(): void
    {
        file_put_contents(self::BOOT_STAMP, (string) time());

        $this->breakTheDatabase();

        $res = $this->getJson('/railway-health');

        $res->assertOk();
        $this->assertSame('warming', $res->json('status'));
        $this->assertGreaterThan(0, (int) $res->json('grace_seconds_left'),
            'لا تُقال الثواني المتبقّية — فالأخضرُ يُقرأ صحّةً وهو مهلة');
    }

    /**
     * @test
     *
     * **وبلا ختمِ إقلاعٍ تكون الصرامةُ هي الافتراض.**
     *
     * ملفٌّ غائبٌ = وقتٌ مجهول. و«غير معروف» ليس «حديثُ الإقلاع» —
     * وتفسيرُه تساهلاً يعيد الردَّ الثابتَ من بابٍ آخر.
     */
    public function an_unknown_boot_time_is_treated_strictly_not_leniently(): void
    {
        @unlink(self::BOOT_STAMP);

        $this->breakTheDatabase();

        $this->getJson('/railway-health')->assertStatus(503);
    }

    /**
     * @test
     *
     * **وختمُ الإقلاع يُكتب فعلاً في الصورة المنشورة.**
     *
     * فمسبارٌ يعتمد ملفّاً لا يكتبه أحدٌ يكون صارماً دائماً — فيسقط أوّلُ
     * نشرةٍ سليمة، ويُقال «المسبار مكسور» ويُنزَع.
     */
    public function the_deployed_entrypoint_stamps_the_boot_time(): void
    {
        foreach (['docker/entrypoint.sh', 'docker/entrypoint.prod.sh'] as $rel) {
            $path = base_path($rel);

            if (! is_file($path)) {
                continue;
            }

            $src = preg_replace('/^\s*#.*$/m', '', (string) file_get_contents($path));

            $this->assertStringContainsString('/tmp/amial-boot-epoch', (string) $src,
                "«{$rel}» لا يختم لحظةَ الإقلاع — فنافذةُ السماح لا تعمل، "
                . 'والمسبارُ يُسقط أوّلَ نشرةٍ سليمة');
        }
    }
}
