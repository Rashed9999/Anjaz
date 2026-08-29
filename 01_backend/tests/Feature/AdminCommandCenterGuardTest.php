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
    //  ③ القائمة الجانبيّة
    // ══════════════════════════════════════════════════════════════════

    /**
     * الوجهاتُ التي كانت في القائمة قبل إعادة الترتيب.
     *
     * **قِيست قبل التغيير وبعده فتطابقتا** (٥٢ وجهة)، وتُثبَّت هنا لئلّا
     * تسقط واحدةٌ في إعادة ترتيبٍ لاحقة.
     */
    private const EXPECTED_DESTINATIONS = [
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

    /** الوجهاتُ المقروءةُ من ملفّ القائمة الآن. */
    private function sidebarDestinations(): array
    {
        preg_match_all('/route\(.([a-z0-9._-]+).\)/i',
            (string) file_get_contents(self::SIDEBAR), $m);

        return $m[1];
    }

    /**
     * **إعادةُ الترتيب لا تُسقط رابطاً.**
     *
     * وصفحةٌ لا يُوصل إليها ليست مبنيّة — وهو نمطُ العطل الأكثر تكراراً
     * في هذا المشروع. فإعادةُ ترتيبٍ تحذف رابطاً تُخفي شاشةً كاملةً بلا
     * أن يسقط اختبارٌ واحد.
     */
    public function test_no_sidebar_destination_was_lost_in_the_regrouping(): void
    {
        $present = array_unique($this->sidebarDestinations());

        $missing = array_values(array_diff(self::EXPECTED_DESTINATIONS, $present));

        $this->assertSame([], $missing,
            'سقطت وجهاتٌ من القائمة الجانبيّة — والشاشةُ تصير غيرَ '
            . 'قابلةٍ للوصول بلا أن يسقط شيءٌ آخر: ' . implode('، ', $missing));
    }

    /**
     * **ولا وجهةَ مكرّرة** — مدخلان لشيءٍ واحدٍ هما ما يُقرأ «تكراراً».
     *
     * ══════════════════════════════════════════════════════════════════
     * AMIAL-ADMIN-NAV-SURFACE-001 — **والملفُّ صار منطقتين.**
     *
     * نُقلت الوجهاتُ إلى «مساحة العمل»، وبقي في أعلى الملفّ **بابٌ سريعٌ**
     * لثلاثةٍ يُفتحن كلَّ يوم (مساحةُ العمل · الموظّفون · أمانُ حسابي)،
     * وبقي تعريفُ الوجهات أسفلَه داخل `@if(false)` **مرجعاً للتغطية**.
     *
     * فصارت `ops.roles` و`2fa` في الموضعين، **وقرأهما هذا الحارسُ
     * تكراراً** — وهما مرّةً في البابِ السريع ومرّةً في نصٍّ لا يُصيَّر.
     *
     * **والمكرَّرُ الذي يؤذي هو ما يراه المستعمل مرّتين في شاشةٍ واحدة.**
     * فتُقاس كلُّ منطقةٍ وحدَها: حارسٌ يخلط المرئيَّ بالميّت يُنذر كاذباً،
     * ثمّ يُعوِّد القارئَ تجاهلَ اللافتة يومَ تصدق.
     * ══════════════════════════════════════════════════════════════════
     */
    public function test_no_destination_appears_twice(): void
    {
        $src = (string) file_get_contents(self::SIDEBAR);

        // **والحدُّ `@endphp` لا `@if(false)`**: تعريفُ `$groups` كلُّه —
        // أربعٌ وخمسون وجهةً — يسبق `@if(false)` في الملفّ، فالقسمةُ عنده
        // تضع المرجعَ والبابَ السريعَ في سلّةٍ واحدة، وهو ما كان.
        $split = mb_strpos($src, '@endphp');

        $this->assertNotFalse($split,
            'لم يعد الملفُّ تعريفاً ثمّ عرضاً — راجع هذا الحارس قبل أن تُصدّقه');

        foreach ([
            'مرجعُ الوجهات (تعريفٌ لا يُصيَّر)' => mb_substr($src, 0, $split),
            'ما يُصيَّر فعلاً' => mb_substr($src, $split),
        ] as $region => $chunk) {
            preg_match_all('/route\(.([a-z0-9._-]+).\)/i', $chunk, $m);

            $dupes = array_values(array_unique(
                array_diff_assoc($m[1], array_unique($m[1]))));

            $this->assertSame([], $dupes,
                "وجهةٌ لها مدخلان في «{$region}»: " . implode('، ', $dupes));
        }
    }

    /**
     * **وكلُّ ما يخصّ موضوعاً واحداً في مجموعةٍ واحدة.**
     *
     * وهو جوهرُ الشكوى: كان التاجرُ موزّعاً على أربعة مداخلَ في مجموعتين،
     * فمن أراد شيئاً عنه فتح مجموعتين وخمّن.
     */
    public function test_each_subject_lives_in_one_group(): void
    {
        $src = (string) file_get_contents(self::SIDEBAR);

        foreach ([
            // AMIAL-SIDEBAR-SPLIT-001 — **إدارةُ التاجر ≠ رقابةُ عمله.**
            // فُصلت الثلاثُ إلى مجموعةِ رقابةٍ لأنّها شاشاتٌ عابرةٌ للتجّار
            // تكشف نمطاً، لا إدارةَ تاجرٍ بعينه. **والقاعدةُ نفسُها محفوظة**:
            // كلُّ موضوعٍ في مجموعةٍ واحدة — والمواضيعُ صارت اثنين لا واحداً.
            'التجّار' => ['admin.amial.hub.merchants', 'admin.amial.hub.subscriptions',
                          'admin.amial.invoices.page', 'admin.amial.catalog.page',
                          'admin.amial.entitlements.page'],
            'رقابة عمل التجّار' => ['admin.amial.hub.staff',
                          'admin.amial.fuel.page', 'admin.amial.retail.page'],
            'العملاء' => ['admin.amial.customer.page', 'admin.amial.hub.customers',
                          'admin.support-center.index', 'admin.amial.recovery.index'],
        ] as $group => $routes) {
            $start = mb_strpos($src, "'title' => '{$group}'");

            $this->assertNotFalse($start, "لا مجموعةَ «{$group}» في القائمة");

            $end = mb_strpos($src, "'title' => '", $start + 20);
            $block = mb_substr($src, $start, $end ? $end - $start : null);

            foreach ($routes as $r) {
                $this->assertStringContainsString($r, $block,
                    "«{$r}» خارج مجموعة «{$group}» — والموضوعُ يتشتّت مرّةً أخرى");
            }
        }
    }
}
