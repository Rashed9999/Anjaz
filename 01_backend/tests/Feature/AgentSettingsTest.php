<?php

namespace Tests\Feature;

use App\Models\Agent\AgentAnnouncement;
use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-AGENT-SETTINGS-001 — إعدادات شركة الصرافة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما يُثبَت هنا أنّ ما كان مبنيّاً بلا شاشة صار مضبوطاً فعلاً** — وأنّ
 * ضبطه محصورٌ بمن يملكه.
 */
class AgentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967776100001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '750000']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967776100099', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '900000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع المكلا', 'status' => 'active', 'zone_code' => 'SOUTH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->branch = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع المكلا', 'code' => 'MKL', 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
        ]);
        $this->branch->till()->create([
            'cash_on_hand' => '500000', 'max_cash_on_hand' => '0', 'min_cash_alert' => '0',
        ]);

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'محمد علي', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // حدود الخزنة — العتبة التي تُبنى عليها التنبيهات
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الصفر يُقال «غير مضبوط» لا يُعرَض حدّاً.**
     *
     * فخزنةٌ حدُّها صفر لا تُنبِّه أبداً، ومن يقرأ «٠» يظنّ الحدّ مضبوطاً
     * على الصفر عمداً — فيظنّ أنّ التنبيه يعمل وهو صامتٌ للأبد.
     */
    public function an_unset_threshold_is_reported_as_unset(): void
    {
        $this->actingAs($this->hq, 'agent_staff');

        $b = $this->getJson(route('agent.settings'))->assertOk()->json('meta.branches.0');

        $this->assertFalse($b['alerts_configured'],
            'حدٌّ صفريّ عُرض مضبوطاً — فيظنّ صاحب الشركة أنّ التنبيه يعمل وهو صامت');
    }

    /** @test */
    public function setting_the_threshold_makes_the_low_cash_alert_possible(): void
    {
        $this->actingAs($this->hq, 'agent_staff');

        $this->postJson(route('agent.branch.thresholds', $this->branch->id), [
            'min_cash_alert' => '100000', 'max_cash_on_hand' => '5000000',
        ])->assertOk();

        $till = $this->branch->fresh('till')->till;

        $this->assertSame(0, bccomp('100000', (string) $till->min_cash_alert, 4));
        // وهي العتبة نفسها التي يقرؤها `isLow()` فتُطلق تنبيه واتساب.
        $this->assertFalse($till->isLow(), 'الخزنة فيها ٥٠٠ ألف والحدّ ١٠٠ ألف');
    }

    /**
     * @test
     *
     * سقفٌ دون حدّ التنبيه يجعل الخزنة «منخفضةً وممتلئة» معاً — حالةٌ لا
     * معنى لها تُنتج تنبيهين متناقضين في الدقيقة نفسها.
     */
    public function a_ceiling_below_the_alert_threshold_is_refused(): void
    {
        $this->actingAs($this->hq, 'agent_staff');

        $this->postJson(route('agent.branch.thresholds', $this->branch->id), [
            'min_cash_alert' => '500000', 'max_cash_on_hand' => '100000',
        ])->assertStatus(422);
    }

    /** @test */
    public function a_teller_cannot_change_the_branch_thresholds(): void
    {
        $this->actingAs($this->teller, 'agent_staff');

        $this->postJson(route('agent.branch.thresholds', $this->branch->id), [
            'min_cash_alert' => '1', 'max_cash_on_hand' => '0',
        ])->assertStatus(403);
    }

    /**
     * @test
     *
     * **ولا يضبط أحدٌ حدود فرعٍ ليس فرعه.**
     */
    public function thresholds_of_another_companys_branch_are_out_of_reach(): void
    {
        $rival = new User();
        $rival->forceFill([
            'f_name' => 'منافس', 'l_name' => 'للصرافة', 'phone' => '967776100777',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();

        $ru = new User();
        $ru->forceFill([
            'f_name' => 'فرع المنافس', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967776100778', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();

        $other = AgentBranch::create([
            'agent_user_id' => $rival->id, 'branch_user_id' => $ru->id,
            'name' => 'فرع المنافس', 'code' => 'RVL', 'city' => 'عدن',
            'phone' => $ru->phone, 'is_active' => true,
        ]);

        $this->actingAs($this->hq, 'agent_staff');

        // ٤٠٣ لا ٤٠٤: الحارس القائم (`authorizedBranch`) يقيس على قائمة
        // الفروع التي حسبها الوسيط من الجلسة، ويردّ «ليس لك». وتوقُّعي
        // كان ٤٠٤ — والشيفرة أصحّ: توحيدُ الردّ عبر المتحكّم كلّه أهمّ من
        // إخفاء وجود المعرّف في مسارٍ واحد.
        $this->postJson(route('agent.branch.thresholds', $other->id), [
            'min_cash_alert' => '1', 'max_cash_on_hand' => '0',
        ])->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════
    // التعاميم
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **التعميم يُختم بشركة كاتبه من جلسته لا من طلبه.**
     */
    public function an_announcement_is_stamped_with_the_writers_own_company(): void
    {
        $this->actingAs($this->hq, 'agent_staff');

        $this->postJson(route('agent.announcements.create'), [
            'title' => 'صيانة الليلة', 'body' => 'من ١٢ إلى ١', 'severity' => 'warning',
        ])->assertOk();

        $row = AgentAnnouncement::first();

        $this->assertSame((int) $this->company->id, (int) $row->agent_user_id);
        $this->assertTrue((bool) $row->is_active);
    }

    /** @test */
    public function a_teller_cannot_publish_an_announcement(): void
    {
        $this->actingAs($this->teller, 'agent_staff');

        $this->postJson(route('agent.announcements.create'), [
            'title' => 'من الصرّاف', 'body' => 'لا ينبغي',
        ])->assertStatus(403);
    }

    /** @test */
    public function another_companys_announcement_cannot_be_toggled(): void
    {
        $rival = new User();
        $rival->forceFill([
            'f_name' => 'منافس', 'l_name' => 'للصرافة', 'phone' => '967776100666',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();

        $row = AgentAnnouncement::create([
            'agent_user_id' => $rival->id, 'audience' => 'all', 'severity' => 'info',
            'title' => 'سرّ المنافس', 'body' => 'لا يُمسّ', 'is_active' => true,
        ]);

        $this->actingAs($this->hq, 'agent_staff');

        $this->postJson(route('agent.announcements.toggle', $row->id))->assertStatus(404);
        $this->assertTrue((bool) $row->fresh()->is_active);
    }

    // ══════════════════════════════════════════════════════════════════
    // كلمة السرّ
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الحاليّة تُطلب.** ومن غيرها يكفي أن يترك الموظّف شاشته مفتوحة
     * دقيقةً ليُقفل حسابُه عليه بكلمةٍ لا يعرفها.
     */
    public function changing_my_password_requires_the_current_one(): void
    {
        $this->actingAs($this->teller, 'agent_staff');

        $this->postJson(route('agent.settings.password'), [
            'current' => 'wrongpass', 'password' => 'newpass1',
            'password_confirmation' => 'newpass1',
        ])->assertStatus(422);

        // ولم تتغيّر.
        $this->assertTrue(Hash::check('teller12', (string) $this->teller->fresh()->password));
    }

    /** @test */
    public function a_staff_member_can_change_their_own_password(): void
    {
        $this->actingAs($this->teller, 'agent_staff');

        $this->postJson(route('agent.settings.password'), [
            'current' => 'teller12', 'password' => 'newpass1',
            'password_confirmation' => 'newpass1',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpass1', (string) $this->teller->fresh()->password));
    }

    // ══════════════════════════════════════════════════════════════════
    // وضعيّة التشفير
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الوضعيّة تُقاس من الطلب لا من متغيّر بيئة.**
     *
     * فمن يضبط `APP_URL` إلى `https://…` ويقرأ منه يستنتج أنّ التشفير
     * يعمل، ولو كان الخادم يردّ على HTTP وحده.
     */
    public function the_security_posture_is_measured_from_the_request_itself(): void
    {
        config(['app.url' => 'https://amial.example']);

        $this->actingAs($this->hq, 'agent_staff');

        $s = $this->getJson(route('agent.settings'))->assertOk()->json('meta.security');

        $this->assertFalse($s['secure'], 'قيل إنّ الاتّصال مشفَّر لأنّ APP_URL تقول https');
        $this->assertStringContainsString('غير مشفَّر', $s['headline']);
    }

    /**
     * @test
     *
     * **كوكي الجلسة تصير آمنةً بنفسها — ولا تُضبط بيد.**
     *
     * فمفتاحٌ يدويّ له وضعان خاطئان: يُضبط قبل HTTPS فيُقفل النظام على
     * الجميع بـ٤١٩، أو يبقى بعده فتُسرَق الجلسات من طلبٍ غير مشفَّر.
     */
    public function the_session_cookie_becomes_secure_by_itself_over_https(): void
    {
        $mw = new \App\Http\Middleware\HttpsPosture();

        $plain = \Illuminate\Http\Request::create('http://amial.test/agent');
        $mw->handle($plain, fn () => new \Illuminate\Http\Response('ok'));
        $this->assertFalse((bool) config('session.secure'),
            'كوكي آمنة على HTTP — لن يحفظها المتصفّح، فيُقفل النظام بـ٤١٩');

        $tls = \Illuminate\Http\Request::create('https://amial.test/agent');
        $res = $mw->handle($tls, fn () => new \Illuminate\Http\Response('ok'));
        $this->assertTrue((bool) config('session.secure'),
            'الاتّصال مشفَّر والكوكي تُرسَل على HTTP أيضاً — تُسرَق من طلبٍ واحد');

        // وHSTS لا تُرسَل إلّا على المشفَّر: إرسالُها على HTTP قد يُثبّتها
        // في المتصفّح لسنة، فإن انقطع HTTPS صار الموقع لا يُفتح إطلاقاً.
        $this->assertNotNull($res->headers->get('Strict-Transport-Security'));
        $this->assertNull(
            $mw->handle($plain, fn () => new \Illuminate\Http\Response('ok'))
               ->headers->get('Strict-Transport-Security'),
            'أُرسلت HSTS على اتّصالٍ غير مشفَّر — وهي قرارٌ لا رجعة فيه من جهة الخادم',
        );
    }

    /** @test */
    public function the_settings_screen_is_reachable_from_the_dashboard(): void
    {
        // **الحارس الذي أدخل هذا الملفّ:** ساعاتُ عمل الفرع لها نقطةُ
        // نهاية منذ شهور ولا زرّ لها في أيّ شاشة.
        $dash = file_get_contents(base_path('resources/views/agent-views/dashboard.blade.php'));

        $this->assertStringContainsString("@include('agent-views._settings')", $dash);
        $this->assertStringContainsString('data-testid="ag-tab-settings"', $dash);
    }
}
