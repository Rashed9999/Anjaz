<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentStaffService;
use App\Services\PlatformRoleService;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-PORTAL-HOSTS-001 — لكلّ بوّابةٍ مضيفُها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما في الفصل ليس ٤٠٤ — بل جلسةٌ تضيع في الطريق.**
 *
 * كوكي الجلسة لا تُشارَك بين المضيفَين (عمداً — وإلّا ذهب العزل). فمن دخل
 * من `amialpay.com/login` ونُقل إلى `amialpay.com/agent`، ثمّ ردّه وسيطُ
 * المضيف إلى `agent.amialpay.com/agent` — **وصل بلا جلسة**، فرأى شاشة
 * الدخول من جديدٍ ولا يفهم لماذا. حلقةٌ من طرفين، وكلُّ ردٍّ فيها سليم.
 *
 * فيُرسَل من البداية إلى حيث ستكون جلسته.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والفصل مطفأٌ افتراضيّاً.** فيُفحص الوجهان: مطفأً (كما اليوم على
 * الخادم) ومشغَّلاً (كما سيصير بعد سجلّي DNS).
 */
class PortalHostSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_HOST = 'admin.amialpay.com';
    private const AGENT_HOST = 'agent.amialpay.com';

    private function enableSeparation(): void
    {
        config([
            'amial.hosts.admin' => self::ADMIN_HOST,
            'amial.hosts.agent' => self::AGENT_HOST,
        ]);
    }

    private function admin(string $phone = '967770001001'): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'المنصّة', 'phone' => $phone,
            'email' => $phone . '@amialpay.test',
            'type' => ADMIN_TYPE, 'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        app(PlatformRoleService::class)->ensureHasSomeRole($u);

        return $u->fresh();
    }

    private function company(string $phone = '967770001002'): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => $phone,
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1,
        ])->save();
        EMoney::create(['user_id' => $u->id, 'current_balance' => '0']);

        return $u;
    }

    // ══════════════════════════════════════════════════════════════
    // مطفأً — وهو حال الخادم اليوم
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **بلا ضبطٍ لا يتغيّر شيء.**
     *
     * وهذا شرطُ دفعِ الشيفرة اليوم: النطاقان لم يُترجَما بعد، وربطُ
     * المسارات بمضيفٍ غائبٍ يُنتج ٤٠٤ على اللوحة كلّها.
     */
    public function nothing_changes_until_the_hosts_are_configured(): void
    {
        config(['amial.hosts.admin' => '', 'amial.hosts.agent' => '']);

        $this->assertFalse(PortalHost::enabled());
        $this->assertNull(PortalHost::expectedFor('admin/auth/login'));

        $this->get('/admin/auth/login')->assertOk();
        $this->get('/agent/login')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/')->assertOk();
    }

    // ══════════════════════════════════════════════════════════════
    // مشغَّلاً
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **العنوان يُقرأ مضيفاً لا رابطاً.**
     *
     * ومن كتب `https://admin.amialpay.com/` في المتغيّر — وهو ما يقع —
     * لا يُطابق مضيفاً أبداً، فتُقفل اللوحة بصمت.
     */
    public function a_full_url_in_the_env_is_read_as_a_host(): void
    {
        foreach (['https://admin.amialpay.com/', ' HTTPS://Admin.AmialPay.com ', 'admin.amialpay.com']
                 as $raw) {
            config(['amial.hosts.admin' => $raw]);

            $this->assertSame(self::ADMIN_HOST, PortalHost::admin(), "لم يُنظَّف: [{$raw}]");
        }
    }

    /**
     * @test
     *
     * **من طرق البابَ الخطأ يُحوَّل — لا يُمنع.**
     *
     * فالروابط القديمة لا تموت: مفضّلةٌ ورسالةٌ وسطرٌ في دليل. ومن رأى ٤٠٤
     * ظنّ النظام سقط.
     */
    public function a_request_on_the_wrong_host_is_redirected_not_refused(): void
    {
        $this->enableSeparation();

        $this->get('http://amialpay.com/admin/auth/login')
            ->assertRedirect('http://' . self::ADMIN_HOST . '/admin/auth/login');

        $this->get('http://amialpay.com/agent/login')
            ->assertRedirect('http://' . self::AGENT_HOST . '/agent/login');

        // وعلى مضيفه الصحيح: لا تحويل.
        $this->get('http://' . self::ADMIN_HOST . '/admin/auth/login')->assertOk();
        $this->get('http://' . self::AGENT_HOST . '/agent/login')->assertOk();
    }

    /**
     * @test
     *
     * **والموقع العامّ يبقى على النطاق الأمّ بلا تحويل.**
     */
    public function the_public_site_stays_on_the_apex(): void
    {
        $this->enableSeparation();

        foreach (['/', '/personal', '/exchange', '/login'] as $path) {
            $this->get('http://amialpay.com' . $path)->assertOk();
        }
    }

    /**
     * @test
     *
     * **ولا يُحوَّل POST — يُردّ ٤٢١ بمضيفه.**
     *
     * تحويلُ طلبِ إرسالٍ يُفقد جسمَه، فيصل الخادمَ نموذجٌ فارغ فيقول «الحقل
     * مطلوب» على حقلٍ مُلئ فعلاً — وهو أسوأ من الرفض لأنّه يكذب.
     */
    public function a_post_to_the_wrong_host_is_not_silently_emptied(): void
    {
        $this->enableSeparation();

        $this->post('http://amialpay.com/agent/login',
            ['username' => 'MKL-014', 'password' => 'secret123'])
            ->assertStatus(421)
            ->assertJsonPath('meta.expected_host', self::AGENT_HOST);
    }

    /**
     * @test
     *
     * **الدخول الموحّد يُرسل إلى المضيف مباشرةً — فلا تضيع الجلسة.**
     *
     * وهو أخطرُ ما في الفصل: نقلٌ إلى `amialpay.com/agent` يردّه الوسيط
     * إلى `agent.amialpay.com/agent` **بلا كوكي**، فيرى الصرّاف شاشة
     * الدخول من جديدٍ ولا يفهم لماذا.
     */
    public function the_unified_login_sends_you_straight_to_your_portal_host(): void
    {
        $this->enableSeparation();

        $company = $this->company();
        $hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($company, 'hq123456');

        $this->post('http://amialpay.com/login',
            ['username' => $hq->username, 'password' => 'hq123456'])
            ->assertRedirect('http://' . self::AGENT_HOST . '/agent');

        $this->flushSession();

        $admin = $this->admin();

        $this->post('http://amialpay.com/login',
            ['username' => $admin->phone, 'password' => 'admin12345'])
            ->assertRedirect('http://' . self::ADMIN_HOST . '/admin');
    }

    /**
     * @test
     *
     * **ومن دخل على مضيفه لا يُنقل إلى مضيفٍ آخر بلا داعٍ.**
     *
     * وإلّا فقدَ جلستَه في كلّ دخولٍ صحيح — عطلٌ يصنعه العلاج نفسه.
     */
    public function logging_in_on_the_right_host_does_not_bounce_you_away(): void
    {
        $this->enableSeparation();

        $company = $this->company('967770001003');
        $hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($company, 'hq123456');

        $this->post('http://' . self::AGENT_HOST . '/login',
            ['username' => $hq->username, 'password' => 'hq123456'])
            ->assertRedirect(route('agent.dashboard'));
    }
}
