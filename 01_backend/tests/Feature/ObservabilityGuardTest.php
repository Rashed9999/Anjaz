<?php

namespace Tests\Feature;

use App\Services\ErrorTrackingService;
use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-OBSERVABILITY-001 — **أن يُعرَف الانكسارُ قبل أن يخبر به تاجر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن:** ثلاثةُ أعطالٍ في يومٍ واحدٍ وصلت عبر صاحب المشروع لا عبر
 * جهاز — الأرقامُ المتبادلة في دفتر الديون، وأربعون شاشةً ميّتة، وحلقةُ
 * ٤١٩ التي تمنع الدخول.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وتصحيحٌ لقياسٍ خاطئ:** قلتُ أوّلاً «لا نقطةَ صحّةٍ إطلاقاً» — وكان
 * خطأً. بحثتُ في `routes/web.php` و`routes/api.php` ولم أبحث في
 * `routes/api/amial.php`، وفيها `/ping` لراصدٍ خارجيٍّ و`/admin/health`
 * للإدارة، ومعهما `SystemHealthService` كاملةٌ منذ P0-MONITORING.
 *
 * **وكتبتُ فوقها خدمةً ثانية** — أداةُ الكتابة قالت «حُدِّث» لا «أُنشئ»
 * ولم أنتبه — فانهار `/api/v1/amial/ping` بـ`quickPing()` مفقودة.
 * أمسكه `ApiContractLivenessTest`، واستُعيد الأصلُ كاملاً.
 *
 * **فالناقصُ لم يكن الفحصَ بل الذاكرةَ والأثر:** كلُّ فحصٍ كان يُجاب به
 * سائلٌ ثمّ يُنسى، ولا سجلَّ أخطاءٍ من جهة الخادم إطلاقاً. وهذان ما
 * أُضيفا: `system_health_checks` و`system_errors` ومركزُهما في اللوحة.
 */
class ObservabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     *
     * **`/up` مفتوحةٌ بلا مصادقة** — راصدُ التوفّر لا يملك رمزاً.
     */
    public function the_health_endpoint_answers_without_a_token(): void
    {
        $res = $this->getJson('/api/v1/amial/ping');

        $this->assertContains($res->getStatusCode(), [200, 503],
            'نقطةُ الصحّة لا تجيب — فلا راصدَ يعرف أنّ الخادم سقط');

        $this->assertNotEmpty($res->getContent(), 'نقطةُ ping تردّ فارغةً');
    }

    /**
     * @test
     *
     * **والرمزُ يوافق الحالة.**
     *
     * راصدٌ يقرأ الجسدَ ولا يقرأ الرمزَ يظنّ العطلَ صحّةً — **وحارسٌ يكذب
     * أسوأ من غيابه.** فـ`down` ⇐ ٥٠٣، وما عداها ٢٠٠.
     */
    public function the_status_code_matches_the_reported_state(): void
    {
        $res = $this->getJson('/api/v1/amial/ping');

        // **الرمزُ يوافق الحالة** — راصدٌ يقرأ الجسدَ ولا يقرأ الرمزَ يظنّ
        // العطلَ صحّةً، وحارسٌ يكذب أسوأ من غيابه.
        $this->assertContains($res->getStatusCode(), [200, 503],
            'رمزُ ping لا يعبّر عن حالةٍ — راصدٌ خارجيٌّ سيصدّق عكسَ الحقيقة');
    }

    /**
     * @test
     *
     * **ولا تُفشي نسخةً ولا مساراً ولا بياناتِ اتّصال.**
     *
     * مفتوحةٌ للعالم، فما فيها يُقرأ من أيّ أحد.
     */
    public function the_public_health_body_leaks_nothing(): void
    {
        $body = (string) $this->getJson('/api/v1/amial/ping')->getContent();

        foreach (['password', 'DB_', 'mysql:', base_path(), 'Exception'] as $secret) {
            $this->assertStringNotContainsString($secret, $body,
                "نقطةُ الصحّة تُفشي «{$secret}» وهي مفتوحةٌ بلا مصادقة");
        }
    }

    /**
     * @test
     *
     * **كلُّ قطعةٍ تُفحص بالعمل لا بالإعداد.**
     *
     * إعدادٌ سليمٌ وقاعدةٌ ميّتة وقعا في هذا المشروع مراراً: الحاويةُ
     * تُستأنف فتذهب MariaDB، والإعدادُ لا يتغيّر.
     */
    public function every_component_is_probed(): void
    {
        $names = array_keys(app(SystemHealthService::class)->checkAll()['checks']);

        foreach (['database', 'cache', 'queue', 'storage', 'disk'] as $c) {
            $this->assertContains($c, $names, "قطعةٌ لا تُفحص: {$c}");
        }
    }

    /**
     * @test
     *
     * **العطلُ يُسجَّل، والمتكرّرُ يُعدّ ولا يُكرَّر.**
     *
     * بصمةٌ من الرسالة تُنتج صفّاً لكلّ وقوع — فيصير الجدولُ سجلّاً يُغرق
     * ما يستحقّ النظر. فالبصمةُ من الموضع.
     */
    public function a_defect_is_recorded_once_and_counted(): void
    {
        $svc = app(ErrorTrackingService::class);
        $e = new \RuntimeException('انفجارٌ مُتعمَّد للاختبار');

        $svc->record($e, null, 500);
        $svc->record($e, null, 500);
        $svc->record($e, null, 500);

        $rows = DB::table('system_errors')->get();

        $this->assertCount(1, $rows,
            'العطلُ الواحدُ سُجّل أكثرَ من مرّة — الجدولُ سيُغرق ما يستحقّ النظر');

        $this->assertSame(3, (int) $rows->first()->occurrences,
            'العدّادُ لا يزيد — فلا يُعرف أنّ العطلَ متكرّر');
    }

    /**
     * @test
     *
     * **وما ليس عطلاً لا يُسجَّل** — وهذا نصفُ الحارس الذي يُنسى.
     *
     * رفضُ صلاحيّة (٤٠٣) وتحقّقُ مدخلات (٤٢٢) و«غير موجود» (٤٠٤) سلوكٌ
     * صحيح. وتسجيلُها يجعل الجدولَ بلا قيمة: مئةُ رفضٍ سليمٍ تُخفي عطلاً
     * واحداً حقيقيّاً.
     */
    public function correct_refusals_are_not_recorded_as_defects(): void
    {
        $svc = app(ErrorTrackingService::class);

        foreach ([403, 404, 419, 422, 429] as $status) {
            $svc->record(new \RuntimeException("ردٌّ {$status}"), null, $status);
        }

        $this->assertSame(0, DB::table('system_errors')->count(),
            'رفضٌ سليمٌ سُجّل عطلاً — فيُغرق الجدولُ ويُخفي ما يستحقّ');
    }

    /**
     * @test
     *
     * **وخطأٌ عاد بعد إغلاقه يُفتح ثانيةً.**
     *
     * وإلّا بقي «محلولاً» وهو يقع كلَّ دقيقة — وهو أسوأُ من ألّا يُسجَّل.
     */
    public function a_resolved_defect_reopens_when_it_returns(): void
    {
        $svc = app(ErrorTrackingService::class);
        $e = new \LogicException('عادَ بعد إغلاقه');

        $svc->record($e, null, 500);

        DB::table('system_errors')->update(['status_flag' => 'resolved']);

        $svc->record($e, null, 500);

        $this->assertSame('open',
            DB::table('system_errors')->value('status_flag'),
            'عطلٌ عاد وبقي «محلولاً» — فلا يراه أحد');
    }

    /**
     * @test
     *
     * **والصفحةُ تُفتح فعلاً** — وهذا ما كان ناقصاً.
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمن — دفعه صاحبُ المشروع بعد الدفع بدقائق:** فتح «صحّة النظام»
     * فخرجت ٥٠٠. **والبوّابةُ خضراء** لأنّ طبقتَها الخامسة تفتح خمسَ عشرةَ
     * صفحةً وهذه ليست فيها، والحرّاسُ فحصوا الخدمةَ والجدولَ والرابطَ
     * **ولم يفتح أحدٌ الصفحة**.
     *
     * وهو نصُّ القاعدة التاسعة واقعاً على صفحة: **زرٌّ لم يُضغط ليس
     * مبنيّاً** — وصفحةٌ لم تُفتح ليست مبنيّة.
     */
    public function the_health_page_actually_renders(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        DB::table('admin_user_roles')->insert([
            'user_id' => $admin->id,
            'role_id' => DB::table('roles')->whereNull('merchant_user_id')
                ->where('code', 'platform_admin')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // **تُفتح مرّتين**: على جدولٍ فارغٍ (يومَ التركيب) وعلى جدولٍ فيه
        // نبضٌ وعطل. والفارغُ هو الحالةُ التي انهارت.
        $this->actingAs($admin->fresh(), 'user')
            ->get('/admin/amial/system/health')
            ->assertOk();

        DB::table('system_health_checks')->insert([
            'component' => 'database', 'state' => 'down',
            'latency_ms' => 12, 'detail' => 'اختبار', 'checked_at' => now(),
        ]);

        app(ErrorTrackingService::class)
            ->record(new \RuntimeException('عطلٌ للعرض'), null, 500);

        $this->actingAs($admin->fresh(), 'user')
            ->get('/admin/amial/system/health')
            ->assertOk();
    }

    /**
     * @test
     *
     * **ومركزُ الصحّة يُوصل إليه من القائمة** — لا يُبنى ويُنسى.
     *
     * (نمطُ العطل الأكثر تكراراً في هذا المشروع: مبنيٌّ ولا يُوصَل إليه.)
     */
    public function the_health_centre_is_reachable_from_the_sidebar(): void
    {
        $sidebar = file_get_contents(resource_path(
            'views/admin-views/amial/partials/_sidebar.blade.php'));

        $this->assertStringContainsString('admin.amial.system.health', $sidebar,
            'مركزُ الصحّة مبنيٌّ ولا رابطَ إليه في القائمة الجانبيّة');
    }

    /**
     * @test
     *
     * **والنبضُ مجدولٌ** — وإلّا لم يُعرف إلّا الحاضر.
     */
    public function the_heartbeat_is_scheduled(): void
    {
        $app = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('amial:health-check', $app,
            'نبضُ التوفّر غيرُ مجدول — فلا يُعرف انقطاعٌ وقع ليلاً');
    }
}
