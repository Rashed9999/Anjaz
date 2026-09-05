<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReadsAdminNavigation;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-MENU-002 — سلامة القائمة الجانبية.
 *
 * **العطل الذي تمنعه:** القائمة نمت أربعين رابطاً واحداً واحداً كلّما بُنيت
 * لوحة، وكلّ إضافةٍ كانت نسخَ سطر HTML وتعديله. ومن هنا جاء التكرار: وجهةٌ
 * واحدة تظهر مرّتين باسمين مختلفين، فيظنّ المستخدم أنّهما شاشتان فيجرّب
 * الاثنتين، ثمّ يشكّ في أنّه فوّت شيئاً حين يجد الشاشة نفسها.
 *
 * **ولماذا لا يكفي أن تُنظَّف مرّةً:** لأنّها ستتّسخ مرّةً أخرى بالطريقة
 * نفسها. فالتنظيف بلا حارسٍ يعيد الحال بعد أسابيع.
 */
class AdminSidebarIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use ReadsAdminNavigation;

    private function admin(): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770004401',
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        DB::table('admin_user_roles')->insert([
            'user_id' => $u->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    /** @test */
    public function no_destination_appears_twice_in_the_menu(): void
    {
        // وجهةٌ واحدة باسمين ليست إزعاجاً بصريّاً فحسب — انظر شرح الصنف.
        //
        // ══════════════════════════════════════════════════════════════
        // **ويُقاس داخلَ كلّ سطحٍ لا بينهما.** فـ«الموظفون والصلاحيات»
        // و«أمان حسابي» مقصودتان في الشريط **و** في مساحة العمل: البابُ
        // السريعُ لأكثرِ ما يُفتح ليس تكراراً بل تصميم. والمحروسُ أن لا
        // تظهر الوجهةُ مرّتين **في الصفحة الواحدة** — فهناك يظنّها
        // القارئُ شاشتين فيجرّب الاثنتين.
        // ══════════════════════════════════════════════════════════════
        $admin = $this->admin();

        $surfaces = [
            'الشريط الجانبي' => $this->sidebarLinks($admin),
            'مساحة العمل' => $this->workspaceLinks($admin),
            'الأكثر استخداماً' => $this->frequentLinks($admin),
        ];

        // **ومُطابِقٌ عمي يخرج أخضرَ على صفر.** سطحٌ يُقرأ فارغاً يجتاز
        // الفحصَ بلا أن يفحص شيئاً — وهو الصمتُ بثوب نجاح.
        foreach ($surfaces as $surface => $links) {
            $this->assertNotEmpty($links, "سطحُ «{$surface}» قُرئ فارغاً — فلم يُفحَص");
        }

        foreach ($surfaces as $surface => $links) {
            $counts = array_count_values($links);
            $dupes = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));

            $this->assertSame([], $dupes,
                "وجهاتٌ مكرّرة في «{$surface}»:\n  " . implode("\n  ", $dupes)
                . "\n\nكلّ وجهةٍ تظهر مرّة واحدة باسمٍ واحد.");
        }
    }

    /** @test */
    public function every_menu_link_points_at_a_route_that_exists(): void
    {
        // رابطٌ إلى مسارٍ محذوف يُسقط **الصفحة كلّها** لا الرابط وحده:
        // `route()` ترمي استثناءً عند التصيير. فالفحص هنا أنّ الصفحتين
        // صُيّرتا أصلاً — وهو ما يفعله `reachableAdminLinks()` بـ`assertOk()`.
        $links = $this->reachableAdminLinks($this->admin());

        $this->assertGreaterThan(25, count($links),
            'عدد الوجهات المتاحة أقلّ ممّا يجب — هل سقطت مجموعة صامتةً؟');
    }

    /** @test */
    public function the_menu_is_grouped_not_one_flat_column(): void
    {
        // ══════════════════════════════════════════════════════════════
        // AMIAL-ADMIN-NAV-SURFACE-001 — **يُقاس التجميعُ لا أسماؤه.**
        //
        // كانت هذه تبحث عن عشرة عناوينَ عربيّةٍ نصّاً («المال والدفتر» …)،
        // فسقطت مرّتين على إعادتَي تنظيمٍ **سليمتين**: مرّةً حين صار
        // التجميعُ بالموضوع فتغيّرت الأسماء، ومرّةً حين انتقلت المجموعاتُ
        // من الشريط إلى تبويبات مساحة العمل.
        //
        // **والمحروسُ أن لا تعود خمسون وجهةً عموداً واحداً** — لا أن
        // تُكتب المجموعةُ بصياغةٍ بعينها. فيُقاس عددُ المجموعات وأن لا
        // تبتلعَ واحدةٌ كلَّ شيء.
        // ══════════════════════════════════════════════════════════════
        $html = $this->workspaceHtml($this->admin());

        preg_match_all('~data-bs-target="#workspace-([a-z-]+)"~', $html, $m);
        $groups = array_unique($m[1]);

        $this->assertGreaterThanOrEqual(6, count($groups),
            'الوجهات عادت عموداً واحداً — التجميع سقط: ' . implode(' · ', $groups));

        // ولا تبتلع مجموعةٌ واحدةٌ أكثرَ من ثلث الوجهات: تجميعٌ بالاسم
        // وحده وقائمةٌ مسطّحةٌ تحته.
        $total = count($this->workspaceLinks($this->admin()));

        preg_match_all('~id="workspace-[a-z-]+".*?(?=<div class="tab-pane|\z)~s', $html, $panes);

        foreach ($panes[0] as $pane) {
            $inPane = preg_match_all('~<a[^>]+href="[^"#]+"~', $pane);

            $this->assertLessThanOrEqual((int) ceil($total / 3), $inPane,
                "مجموعةٌ واحدةٌ تحمل {$inPane} من {$total} وجهة — تجميعٌ بالاسم لا بالمعنى");
        }
    }

    /**
     * @test
     *
     * أنماط الفتح التلقائيّ تُطابق فعلاً.
     *
     * **هذا الاختبار كُتب بعد عطلٍ وقع في أوّل صياغة:** استُعملت صيغة
     * الأقواس `admin/amial/{kyc,aml}*` وهي **لا تعمل** في `Request::is()` —
     * القوسان يُهرَّبان حرفيّاً فلا يُطابق النمط شيئاً أبداً.
     *
     * وكان أثرُه صامتاً تماماً: المجموعات المتعدّدة البادئات تبقى مطويّة
     * دائماً، والصفحة تُفتح، ولا اختبار يسقط. عطلٌ لا يُكتشف إلّا بالاستعمال
     * اليوميّ — ولذلك يُثبَّت هنا.
     */
    public function group_auto_open_patterns_actually_match(): void
    {
        foreach ([
            'admin/amial/kyc*' => 'admin/amial/kyc',
            'admin/amial/aml*' => 'admin/amial/aml',
            'admin/amial/ledger*' => 'admin/amial/ledger',
            'admin/support-center*' => 'admin/support-center',
            'admin/amial/hub/*' => 'admin/amial/hub/customers',
        ] as $pattern => $path) {
            $this->assertTrue(Str::is($pattern, $path),
                "النمط «{$pattern}» لا يطابق «{$path}» — المجموعة لن تُفتح تلقائياً أبداً");
        }

        // والصيغة التي لا تعمل: تُثبَّت لئلّا تُستعمل ثانيةً ظنّاً أنّها تعمل.
        $this->assertFalse(Str::is('admin/amial/{kyc,aml}*', 'admin/amial/kyc'),
            'صيغة الأقواس صارت تعمل — راجع هذا الاختبار وبسّط الأنماط');
    }

    /**
     * @test
     *
     * اللوحات الحرجة تبقى في القائمة بعد إعادة التنظيم.
     *
     * إعادةُ ترتيبٍ تُسقط رابطاً بالسهو هي أسوأ ما قد ينتج عن هذا العمل:
     * اللوحة تبقى تعمل ولا أحد يصل إليها — وهو العطل نفسه الذي بُنيت له
     * `AdminPanelReachabilityGuardTest`.
     */
    public function reorganising_did_not_drop_a_critical_panel(): void
    {
        // **ولا يعنيها في أيّ الصفحتين وُجد الرابط** — بل أن يبلغه المدير
        // من مكانٍ يمرّ به. وهذا هو الاختبارُ الوحيدُ هنا الذي تُقاس فيه
        // الصفحتان معاً: المحروسُ الوصولُ لا الموضع.
        // ══════════════════════════════════════════════════════════════
        // **والمسارُ يُطابَق كاملاً لا جزءاً منه.**
        //
        // كان `str_contains` هو المقياس، فجُرّب هذا الحارسُ بالعكس —
        // حُذفت بطاقةُ «مراجعة الهوية» من مساحة العمل — **فمرّ**: لأنّ
        // «طلبات تحديث بيانات العملاء» تشير إلى `admin/amial/kyc/changes`
        // وفيها المسارُ المطلوب نصّاً.
        //
        // فكان يقول «مراجعةُ الهويّة موصولة» وهي غيرُ موصولة — وحارسٌ
        // يمرّ والعطلُ قائم أسوأ من غيابه.
        // ══════════════════════════════════════════════════════════════
        $paths = array_map(
            static fn (string $l): string => rtrim((string) parse_url($l, PHP_URL_PATH), '/'),
            $this->reachableAdminLinks($this->admin()),
        );

        foreach ([
            '/admin/amial/kyc' => 'مراجعة الهوية',
            '/admin/amial/aml' => 'مكافحة غسل الأموال',
            '/admin/amial/ledger' => 'مركز الدفتر',
            '/admin/amial/partner-settlements' => 'تسويات الشركاء',
            '/admin/support-center' => 'مركز الدعم',
        ] as $path => $label) {
            $this->assertContains($path, $paths,
                "«{$label}» اختفت من القائمة بعد إعادة التنظيم — تعمل ولا أحد يصل إليها");
        }
    }
}
