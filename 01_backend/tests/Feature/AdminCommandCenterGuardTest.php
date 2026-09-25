<?php

namespace Tests\Feature;

use App\Models\AuditDecision;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Admin\MerchantThreeSixtyService;
use App\Services\AuditService;
use App\Services\PlatformRoleService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-DETAIL-002 · AMIAL-MERCHANT-360-001 · AMIAL-SIDEBAR-SUBJECT-001
 *
 * ══════════════════════════════════════════════════════════════════════
 * ثلاثُ شكاوى من رسالةٍ واحدة، ولكلٍّ حارسُها هنا:
 *
 *   ① «هل سجلّ التدقيق يعمل؟ ولماذا لا تفاصيل ولا أزرار حقيقيّة؟»
 *      — كان يعرض ستّةً من سبعةَ عشرَ عموداً، ويُخفي السياقَ والمعاملةَ
 *        والموضوعَ و**سلسلةَ البصمات التي لم يكن يتحقّق منها شيء**.
 *
 *   ② «ملفّ التاجر لا تفاصيل ماليّة ولا إداريّة ولا تفاصيل عمله»
 *      — وحسابُ محطّةِ وقودٍ كان يُعرض بلا ذكرِ خزّانٍ ولا مضخّة.
 *
 *   ③ «القائمة الجانبيّة فيها تكرار»
 *      — لا تكرارَ حرفيّاً (٥٢ وجهةً فريدة)، بل تشتُّتُ الموضوع الواحد
 *        على مجموعات. فأُعيد التجميعُ بالموضوع، **وهذا الحارسُ يمنع
 *        سقوطَ رابطٍ في إعادة الترتيب**.
 */
class AdminCommandCenterGuardTest extends TestCase
{
    use RefreshDatabase;

    private const SIDEBAR = __DIR__ . '/../../resources/views/admin-views/amial/partials/_sidebar.blade.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function operator(string $role = PlatformRoleService::ADMIN): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, $role);

        return $u;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① سجلّ التدقيق
    // ══════════════════════════════════════════════════════════════════

    private function decision(array $over = []): AuditDecision
    {
        app(AuditService::class)->record(array_merge([
            'actor_type' => 'admin',
            'actor_user_id' => $this->operator()->id,
            'subject_type' => 'user',
            'subject_id' => '7',
            'action' => 'ADMIN_KYC_REVIEW',
            'decision_code' => 'TX_OK',
            'reason' => 'مراجعةُ وثائق',
            'context' => ['before' => 'pending', 'after' => 'verified'],
            'severity' => 'info',
        ], $over));

        return AuditDecision::latest('id')->firstOrFail();
    }

    /** **القرارُ يُفتح كاملاً** — لا ستّةَ أعمدةٍ من سبعةَ عشر. */
    public function test_a_decision_opens_with_everything_it_holds(): void
    {
        $d = $this->decision();

        $data = $this->actingAs($this->operator(), 'user')
            ->getJson("/admin/amial/audit/{$d->id}.json")
            ->assertOk()->json('data');

        foreach (['decision_id', 'action', 'decision_code', 'severity', 'reason',
                  'actor', 'subject', 'context', 'integrity'] as $key) {
            $this->assertArrayHasKey($key, $data, "القرارُ بلا «{$key}»");
        }

        // **المنفِّذ اسمٌ لا رقم** — كان يُعرض «admin#8».
        $this->assertNotEmpty($data['actor']['name']);

        // **والموضوعُ رابطٌ يُنقر** — رقمٌ لا يُفضي إلى صاحبه ليس تتبّعاً.
        $this->assertNotNull($data['subject']['url'],
            'موضوعُ القرار حسابٌ ولا رابطَ إليه');

        $this->assertSame('verified', $data['context']['after'] ?? null,
            'السياقُ لا يصل — وهو القصّةُ كاملةً');
    }

    /**
     * **سلسلةُ البصمات تُتحقَّق.**
     *
     * وهي مكتوبةٌ منذ `AuditService` ولم يكن شيءٌ يتحقّق منها في أيّ
     * شاشة — وسلسلةٌ مقاوِمةٌ للعبث لا يُتحقَّق منها ليست كذلك.
     */
    public function test_the_hash_chain_is_actually_verified(): void
    {
        $d = $this->decision();

        $ok = $this->actingAs($this->operator(), 'user')
            ->getJson("/admin/amial/audit/{$d->id}.json")
            ->assertOk()->json('data.integrity');

        $this->assertTrue($ok['hash_matches'], 'بصمةُ قرارٍ سليمٍ لا تطابق');
        $this->assertNotEmpty($ok['entry_hash']);

        // **والعبثُ يُكشف**: نعدّل الصفَّ مباشرةً كما يفعل من يدخل القاعدة.
        \DB::table('audit_decisions')->where('id', $d->id)
            ->update(['reason' => 'سببٌ مزوَّر']);

        $after = $this->actingAs($this->operator(), 'user')
            ->getJson("/admin/amial/audit/{$d->id}.json")
            ->assertOk()->json('data.integrity');

        $this->assertFalse($after['hash_matches'],
            'عُدّل السببُ في القاعدة والبصمةُ ما زالت «مطابِقة» — '
            . 'فالسلسلةُ زينةٌ لا حراسة');
    }

    /** **والتصديرُ يتبع الفلتر** — لا يُخرج الجدولَ كلَّه. */
    public function test_the_export_respects_the_active_filter(): void
    {
        $this->decision(['decision_code' => 'TX_OK']);
        $this->decision(['decision_code' => 'TX_ZONE_BLOCKED']);

        $csv = $this->actingAs($this->operator(), 'user')
            ->get('/admin/amial/audit/export.csv?decision_code=TX_ZONE_BLOCKED')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('TX_ZONE_BLOCKED', $csv);
        $this->assertStringNotContainsString('TX_OK', $csv,
            'المُصدِّرُ تجاهل الفلتر — فالملفّ لا يطابق الشاشة');
    }

    /** ولا يُقرأ سجلُّ التدقيق بلا صلاحيّة. */
    public function test_audit_reading_needs_the_audit_permission(): void
    {
        $this->actingAs($this->operator(PlatformRoleService::MAINTENANCE), 'user')
            ->getJson('/admin/amial/audit/1.json')
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② ملفّ التاجر
    // ══════════════════════════════════════════════════════════════════

    private function merchant(string $vertical): User
    {
        $u = User::factory()->create(['type' => MERCHANT_TYPE, 'is_active' => 1]);

        MerchantProfile::create([
            'user_id' => $u->id,
            'business_name' => 'منشأة',
            'business_type' => $vertical,
            'daily_receive_limit' => '5000000',
            'single_receive_limit' => '1000000',
        ]);

        return $u;
    }

    /** **الماليُّ موجودٌ ومحسوبٌ من مصدره.** */
    public function test_the_merchant_file_answers_what_it_earns(): void
    {
        $m = $this->merchant(A::BIZ_RETAIL);

        $f = app(MerchantThreeSixtyService::class)->build($m)['financial'];

        foreach (['sales_total', 'sales_count', 'sales_avg', 'sales_30d'] as $k) {
            $this->assertArrayHasKey($k, $f, "الملفُّ بلا «{$k}»");
        }

        // **ومتوسّطٌ على صفرِ عمليّاتٍ لا يقسم على صفر.**
        $this->assertSame('0.00', $f['sales_avg']);
    }

    /**
     * **والتشغيليُّ يتبع النشاط** — محطّةُ وقودٍ تُعرض بخزّاناتها.
     */
    public function test_a_fuel_merchant_file_shows_its_station(): void
    {
        $m = $this->merchant(A::BIZ_FUEL);

        app(\App\Services\Vertical\VerticalBootstrapService::class)->ensureFor($m);

        $ops = app(MerchantThreeSixtyService::class)->build($m)['operations'];

        $this->assertSame(A::BIZ_FUEL, $ops['vertical']);

        // ══════════════════════════════════════════════════════════════
        // AMIAL-VERTICAL-OOP-004 — **يُقاس المصدرُ لا الهجاء.**
        //
        // كان هنا `'محطّة وقود'` نصّاً. ولوحةُ ٣٦٠ كانت تكتب أسماءَ
        // القطاعات بيدها، فافترقت عن قائمة إنشاء الحساب — يُنشئ المديرُ
        // «محطة وقود» وتعرض لوحتُه «محطّة وقود».
        //
        // فلمّا وُصلت اللوحةُ بمصدر الأسماء الواحد سقط هذا الحارسُ **على
        // إصلاحٍ سليم**، لأنّه يشترط هجاءً بعينه. والمحروسُ أن **تُسمَّى
        // المحطّةُ باسمها**، لا أن يُكتب بشدّةٍ أو بدونها.
        // ══════════════════════════════════════════════════════════════
        $this->assertSame(
            \App\Domain\Verticals\VerticalRegistry::find(A::BIZ_FUEL)->nameAr(),
            $ops['label'],
            'لوحةُ ٣٦٠ تسمّي القطاعَ بغير اسمه في مصدر الأسماء');

        $this->assertNotSame('', trim((string) $ops['label']),
            'اسمُ القطاع فارغ — والفراغُ يمرّ على مقارنةٍ بمصدرٍ فارغ');
        $this->assertNotEmpty($ops['metrics'],
            'ملفُّ محطّةٍ بلا مؤشّرٍ واحد — لا خزّان ولا مضخّة ولا ورديّة');

        $labels = array_column($ops['metrics'], 'label');
        $this->assertContains('الخزّانات', $labels);
    }

    /** **وحسابُ محطّةٍ بلا محطّة يُقال** — لا يُعرض صفراً. (القاعدة ٧.) */
    public function test_a_missing_vertical_record_is_stated_not_zeroed(): void
    {
        $m = $this->merchant(A::BIZ_FUEL);

        $ops = app(MerchantThreeSixtyService::class)->build($m)['operations'];

        $this->assertArrayHasKey('missing', $ops,
            'لا سجلَّ محطّةٍ والملفُّ صامت — يُقرأ «صفر خزّانات» وهو غيرُ صحيح');
    }

    /** ونشاطٌ بلا لوحةٍ قطاعيّة يُقال أيضاً. */
    public function test_an_unmapped_vertical_says_so(): void
    {
        $m = $this->merchant('');

        $ops = app(MerchantThreeSixtyService::class)->build($m)['operations'];

        $this->assertSame([], $ops['metrics']);
        $this->assertNotEmpty($ops['label']);
    }

    /** **والملفُّ يصل الشاشةَ فعلاً** — لا خدمةً بلا قارئ. */
    public function test_the_account_screen_receives_the_360_payload(): void
    {
        $m = $this->merchant(A::BIZ_RETAIL);

        $json = $this->actingAs($this->operator(), 'user')
            ->getJson("/admin/amial/hub/users/{$m->id}/detail.json")
            ->assertOk()->json();

        $this->assertArrayHasKey('merchant360', $json,
            'الخدمةُ مبنيّةٌ ولا تصل الشاشة (القاعدة ١٢)');

        $view = (string) file_get_contents(
            __DIR__ . '/../../resources/views/admin-views/amial/hub/account.blade.php');

        $this->assertStringContainsString('merchant360', $view);
        $this->assertStringContainsString('الأداء المالي', $view);
        $this->assertStringContainsString('تفاصيل العمل', $view);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ تنقّل الإدارة بعد توحيد العميل
    // ══════════════════════════════════════════════════════════════════

    /**
     * الوجهة قد تكون في الشريط العام أو مساحة العمل أو مركز العملاء.
     * المطلوب الوصول، لا تكرار كل باب في كل سطح.
     */
    private const EXPECTED_NAV_DESTINATIONS = [
        'admin.amial.2fa.page',
        'admin.amial.aml.page',
        'admin.amial.audit.index',
        'admin.amial.catalog.page',
        'admin.amial.charity.page',
        'admin.amial.customer.page',
        'admin.amial.entitlements.page',
        'admin.amial.executive.index',
        'admin.amial.fees.index',
        'admin.amial.fuel.page',
        'admin.amial.hub.agents',
        'admin.amial.hub.customers',
        'admin.amial.hub.disputes',
        'admin.amial.hub.finance',
        'admin.amial.hub.merchants',
        'admin.amial.hub.settings',
        'admin.amial.hub.settlements',
        'admin.amial.hub.staff',
        'admin.amial.hub.subscriptions',
        'admin.amial.hub.verification',
        'admin.amial.hub.zones.index',
        'admin.amial.invoices.page',
        'admin.amial.kyc.page',
        'admin.amial.kyc.changes.page',
        'admin.amial.registration-dossiers.page',
        'admin.amial.ledger.page',
        'admin.amial.legal.index',
        'admin.amial.ops.index',
        'admin.amial.ops.roles.index',
        'admin.amial.otp.page',
        'admin.amial.partner-settlements.page',
        'admin.amial.recovery.index',
        'admin.amial.retail.page',
        'admin.amial.security-events.index',
        'admin.amial.sentinel.index',
        'admin.amial.supervision.index',
        'admin.amial.surface.bill-providers',
        'admin.amial.surface.funds',
        'admin.amial.surface.payment-requests',
        'admin.amial.surface.rbac',
        'admin.amial.whatsapp.limits.page',
        'admin.banner.index',
        'admin.business-settings.business-setup',
        'admin.business-settings.fcm-index',
        'admin.business-settings.language.index',
        'admin.emoney.index',
        'admin.expense.index',
        'admin.faq.index',
        'admin.notification.add-new',
        'admin.support-center.index',
        'admin.transaction.index',
        'admin.withdraw.index',
        'agent.login',
    ];

    /** @return list<string> */
    private function navigationDestinations(): array
    {
        $src = implode("\n", [
            (string) file_get_contents(self::SIDEBAR),
            (string) file_get_contents(
                resource_path('views/admin-views/amial/ops/workspace.blade.php')
            ),
            (string) file_get_contents(
                resource_path('views/admin-views/amial/customer/index.blade.php')
            ),
        ]);

        preg_match_all('/route\(\s*[\'\"]([a-z0-9._-]+)[\'\"]/i', $src, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_no_admin_destination_was_lost_when_customer_navigation_was_unified(): void
    {
        $present = $this->navigationDestinations();
        $missing = array_values(array_diff(self::EXPECTED_NAV_DESTINATIONS, $present));

        $this->assertSame([], $missing,
            'واجهات إدارية أصبحت يتيمة بعد توحيد التنقّل: ' . implode('، ', $missing));
    }

    public function test_customer_has_one_primary_sidebar_door_and_specialized_tools_live_inside_it(): void
    {
        $sidebar = (string) file_get_contents(self::SIDEBAR);
        $center = (string) file_get_contents(
            resource_path('views/admin-views/amial/customer/index.blade.php')
        );

        $this->assertSame(
            1,
            substr_count($sidebar, "route('admin.amial.customer.page')"),
            'مركز العملاء يجب أن يظهر مرة واحدة فقط في الشريط'
        );

        // التحقق الموحّد يخص العميل والتاجر والوكيل، ويظل مدخل رقابة عامّاً
        // في الشريط. أدوات العميل البحتة وحدها تبقى داخل مركز العملاء.
        foreach ([
            'admin.amial.hub.customers',
            'admin.amial.kyc.changes.page',
        ] as $specialized) {
            $needle = "route('{$specialized}'";
            $this->assertStringNotContainsString(
                $needle,
                $sidebar,
                "الأداة {$specialized} عادت كمدخل عميل مستقل في الشريط"
            );
            $this->assertStringContainsString(
                $needle,
                $center,
                "الأداة {$specialized} اختفت بدلاً من أن تنتقل إلى مركز العملاء"
            );
        }

        // «مركز أنظمة العميل» القديم دُمج فعلياً داخل الشاشة، فلا نطلب
        // رابطاً يعيدنا إلى صفحة ثانية. الحارس الجديد يقيس التبويب نفسه.
        $this->assertStringNotContainsString(
            "route('admin.amial.customer-systems.index'",
            $sidebar
        );
        $this->assertStringContainsString('data-op="systems"', $center);
        $this->assertStringContainsString("get('/ops/' + name)", $center);
    }

    public function test_sidebar_itself_has_no_duplicate_named_route(): void
    {
        $src = (string) file_get_contents(self::SIDEBAR);
        preg_match_all('/route\(\s*[\'\"]([a-z0-9._-]+)[\'\"]/i', $src, $m);

        $counts = array_count_values($m[1]);
        $dupes = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));

        $this->assertSame([], $dupes,
            'وجهات مكررة داخل الشريط الجانبي: ' . implode('، ', $dupes));
    }

    public function test_otp_is_an_operations_setting_not_a_customer_door(): void
    {
        $sidebar = (string) file_get_contents(self::SIDEBAR);

        $this->assertStringContainsString(
            "['🔐 التحقق والرسائل (OTP وبوابات الإرسال)', route('admin.amial.otp.page')",
            $sidebar
        );
        $this->assertStringNotContainsString('مركز التحقّق (OTP وبوّابات الإرسال)', $sidebar);
    }
}
