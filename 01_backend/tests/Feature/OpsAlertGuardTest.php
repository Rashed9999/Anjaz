<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-PROD-READINESS-001 — **لا إنذارَ يسقط في الفراغ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — أخطرُ ما في تدقيق الجاهزيّة:**
 *
 *   $ php artisan tinker --execute="var_export(config('amial.reconciliation.alert_numbers'));"
 *   array ( )
 *
 * ثلاثُ أدواتِ رصدٍ تعمل — المصالحةُ الليليّة، ونبضُ الصحّة، وتتبّعُ
 * الأخطاء — **وثلاثتُها تكتب لمن يفتح اللوحةَ صدفة.** وكلٌّ منها كتب
 * لنفسه الفرعَ الصامتَ نفسَه: «إن لم تكن ثَمّ قناةٌ فاصمت».
 *
 * فاختلالُ ثابتٍ ماليٍّ يُكتشَف الساعةَ الثانية، ويُطبَع في ملفّ، ويبقى
 * يتراكم حتّى يشتكي تاجر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحارسُ يقيس القاعدةَ الجديدة في اتّجاهيها:**
 *
 *   ① بلا قناةٍ خارجيّة ⇒ **الأثرُ يُكتب رغم ذلك** (وهذا ما كان يسقط).
 *   ② بلا قناةٍ خارجيّة ⇒ **الفجوةُ نفسُها تُرفَع عطلاً**، ولا تُترك صمتاً.
 *   ③ ولا يُكرَّر الصفُّ — عشرُ ليالٍ فيها فرقٌ سطرٌ واحدٌ بعدّاده.
 *   ④ والصفحةُ التي يقرؤها المشرف **تقول الفجوةَ صراحةً**.
 */
class OpsAlertGuardTest extends TestCase
{
    use RefreshDatabase;

    private function withoutChannel(): void
    {
        config(['amial.reconciliation.alert_numbers' => []]);
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        DB::table('admin_user_roles')->insert([
            'user_id' => $admin->id,
            'role_id' => DB::table('roles')->whereNull('merchant_user_id')
                ->where('code', 'platform_admin')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $admin->fresh();
    }

    /**
     * @test
     *
     * **الأثرُ يُكتب ولو لم تكن ثَمّ قناة** — وهو نصُّ العطل المُصلَح.
     */
    public function an_alarm_is_recorded_even_with_no_external_channel(): void
    {
        $this->withoutChannel();

        $sent = app(OpsAlertService::class)->raise(
            'recon.nightly_diverged', 'فرقٌ في المصالحة', 'تفصيل');

        $this->assertFalse($sent, 'ادّعى الخروجَ ولا قناةَ — والادّعاءُ أسوأ من الصمت');

        $this->assertTrue(
            DB::table('system_errors')
                ->where('exception', 'recon.nightly_diverged')->exists(),
            'وقع إنذارٌ ولا أثرَ له إطلاقاً — يضيع كما كان يضيع في السجلّ');
    }

    /**
     * @test
     *
     * **وغيابُ القناة يُرفَع عطلاً بنفسه.**
     *
     * فالفجوةُ التي تُخفي الأعطال لا يجوز أن تكون هي أخفاها.
     */
    public function the_missing_channel_is_itself_raised_as_a_defect(): void
    {
        $this->withoutChannel();

        app(OpsAlertService::class)->raise('health.down.database', 'القاعدةُ ساقطة', 'تفصيل');

        $this->assertTrue(
            DB::table('system_errors')
                ->where('exception', OpsAlertService::NO_CHANNEL_KEY)->exists(),
            'لا قناةَ إنذارٍ ولا شيءَ يقول ذلك — فتُقرأ اللوحةُ الهادئةُ سلامةً');
    }

    /**
     * @test
     *
     * **والمتكرّرُ يُعدّ ولا يُكرَّر** — وإلّا أغرق الجدولَ ما يستحقّ النظر.
     */
    public function a_repeating_alarm_is_counted_not_duplicated(): void
    {
        $this->withoutChannel();
        $svc = app(OpsAlertService::class);

        for ($i = 0; $i < 4; $i++) {
            $svc->raise('recon.nightly_diverged', 'فرقٌ في المصالحة', "ليلة {$i}");
        }

        $rows = DB::table('system_errors')
            ->where('exception', 'recon.nightly_diverged')->get();

        $this->assertCount(1, $rows, 'أربعُ ليالٍ ⇐ أربعةُ صفوف — الجدولُ يُغرق');
        $this->assertSame(4, (int) $rows->first()->occurrences,
            'العدّادُ لا يزيد — فلا يُعرف أنّ الفرقَ يتكرّر كلَّ ليلة');
    }

    /**
     * @test
     *
     * **وما أُقفل ثمّ عاد يُفتح ثانيةً.**
     *
     * وفرقٌ ماليٌّ أُغلق ثمّ عاد الليلةَ التالية أخطرُ من أوّل مرّة.
     */
    public function a_resolved_alarm_reopens_when_it_returns(): void
    {
        $this->withoutChannel();
        $svc = app(OpsAlertService::class);

        $svc->raise('recon.nightly_diverged', 'فرقٌ', 'الليلة الأولى');

        DB::table('system_errors')
            ->where('exception', 'recon.nightly_diverged')
            ->update(['status_flag' => 'resolved']);

        $svc->raise('recon.nightly_diverged', 'فرقٌ', 'الليلة الثانية');

        $this->assertSame('open', DB::table('system_errors')
            ->where('exception', 'recon.nightly_diverged')->value('status_flag'),
            'فرقٌ عاد وبقي «محلولاً» — فلا يراه أحد');
    }

    /**
     * @test
     *
     * **والمصالحةُ الليليّةُ تسلك هذا المسار** — لا فرعَها الصامتَ القديم.
     *
     * وقياسُه على الأمر نفسِه لا على الخدمة: الفرعُ الصامتُ كان **في
     * الأمر**، ونجاحُ الخدمةِ وحدَها لا يُثبت أنّه نُزع.
     */
    public function the_nightly_reconciliation_routes_through_the_alarm_path(): void
    {
        $this->withoutChannel();

        $src = (string) file_get_contents(
            base_path('app/Console/Commands/ReconcileNightly.php'));

        // التعليقُ يقتبس الفرعَ القديم لشرحه — فتُنزع التعليقاتُ أوّلاً.
        // (سقط حارسٌ في هذا المشروع ثلاثَ مرّاتٍ على شرحِ نفسِه.)
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $src);

        $this->assertStringContainsString('OpsAlertService', (string) $code,
            'المصالحةُ لا تمرّ بمسار الإنذار الموحَّد');

        $this->assertStringNotContainsString('WhatsappModule', (string) $code,
            'المصالحةُ ما تزال تُنذر بنفسها — فيعود الفرعُ الصامتُ معها');
    }

    /**
     * @test
     *
     * **ونبضُ الصحّة يُنذر عند السقوط** — لا يكتب في جدولٍ وينتهي.
     */
    public function the_heartbeat_raises_an_alarm_when_a_component_is_down(): void
    {
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s',
            '', (string) file_get_contents(
                base_path('app/Console/Commands/RecordHealthCheck.php')));

        $this->assertStringContainsString('OpsAlertService', (string) $code,
            'قطعةٌ تسقط الثالثةَ فجراً تُكتب في جدولٍ ولا تُنذر أحداً');
    }

    /**
     * @test
     *
     * **والصفحةُ تقول الفجوةَ لمن يفتحها.**
     *
     * (القاعدةُ السابعة: «غير معروف» ليس صفراً — ولوحةٌ هادئةٌ بلا قناةِ
     * إنذارٍ تُقرأ سلامةً وهي عمىً.)
     */
    public function the_admin_page_declares_a_missing_alert_channel(): void
    {
        $this->withoutChannel();

        $html = $this->actingAs($this->platformAdmin(), 'user')
            ->get('/admin/amial/system/health')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('لا قناةَ إنذارٍ خارجيّةٌ مضبوطة', (string) $html,
            'الصفحةُ لا تقول إنّ الإنذارَ لا يخرج — فيُظنّ الرصدُ كاملاً');
    }

    /**
     * @test
     *
     * **ولا تُعلَن الفجوةُ حين لا تكون** — حارسٌ يُنذر دائماً يُتجاهَل.
     */
    public function the_warning_disappears_once_a_channel_exists(): void
    {
        config(['amial.reconciliation.alert_numbers' => ['967771234567']]);

        $html = $this->actingAs($this->platformAdmin(), 'user')
            ->get('/admin/amial/system/health')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('لا قناةَ إنذارٍ خارجيّةٌ مضبوطة', (string) $html,
            'اللافتةُ تظهر والقناةُ مضبوطة — إنذارٌ دائمٌ يصير خلفيّةً تُتجاهَل');
    }

    /**
     * @test
     *
     * **وأمرُ الفحص موجودٌ فعلاً** — الصفحةُ تَعِد به بنصّه.
     *
     * فوعدٌ بأداةٍ لا وجودَ لها هو العطلُ نفسُه في ثوبٍ آخر: يقرأ المشرفُ
     * السطرَ فيثق، ثمّ يجد الأمرَ غيرَ معروف.
     */
    public function the_promised_self_test_command_exists(): void
    {
        $this->withoutChannel();

        $this->artisan('amial:alert-test')
            ->assertFailed();   // بلا قناةٍ ⇒ فشلٌ صريح، لا نجاحٌ صامت

        config(['amial.reconciliation.alert_numbers' => ['967771234567']]);

        $blade = (string) file_get_contents(base_path(
            'resources/views/admin-views/amial/system/health.blade.php'));

        $this->assertStringContainsString('amial:alert-test', $blade,
            'الصفحةُ لا تدلّ على كيفيّة إثبات وصول الإنذار');
    }
}
