<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /** كلّ روابط القائمة كما تُصيَّر فعلاً لمدير منصّة. */
    private function renderedLinks(): array
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get('/admin')->assertOk()->getContent();

        // من داخل الشريط الجانبي وحده: الصفحة كلّها فيها روابط أخرى.
        preg_match('~<aside[^>]*amial-sidebar.*?</aside>~s', $html, $m);
        $this->assertNotEmpty($m, 'تعذّر العثور على الشريط الجانبي في الصفحة');

        // الفاصل `~` لا `#`: الأخير يظهر داخل صنف المحارف `[^"#]` فيُنهي
        // التعبير قبل أوانه — «Unknown modifier». (سقط الاختبار عليه فعلاً.)
        preg_match_all('~<a[^>]+href="([^"#]+)"~', $m[0], $hrefs);

        return array_values(array_filter(
            $hrefs[1],
            // روابط المجموعات (`#amial-grp-0`) ليست وجهات — تُستبعد.
            static fn (string $h): bool => $h !== '' && !str_starts_with($h, '#'),
        ));
    }

    /** @test */
    public function no_destination_appears_twice_in_the_menu(): void
    {
        // وجهةٌ واحدة باسمين ليست إزعاجاً بصريّاً فحسب — انظر شرح الصنف.
        $links = $this->renderedLinks();

        $counts = array_count_values($links);
        $dupes = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));

        $this->assertSame([], $dupes,
            "وجهاتٌ مكرّرة في القائمة الجانبية:\n  " . implode("\n  ", $dupes)
            . "\n\nكلّ وجهةٍ تظهر مرّة واحدة باسمٍ واحد.");
    }

    /** @test */
    public function every_menu_link_points_at_a_route_that_exists(): void
    {
        // رابطٌ إلى مسارٍ محذوف يُسقط **الصفحة كلّها** لا الرابط وحده:
        // `route()` ترمي استثناءً عند التصيير. فالفحص هنا أنّ الصفحة صُيّرت
        // أصلاً — وهو ما تفعله `renderedLinks()` بـ`assertOk()`.
        $links = $this->renderedLinks();

        $this->assertGreaterThan(25, count($links),
            'عدد روابط القائمة أقلّ ممّا يجب — هل سقطت مجموعة صامتةً؟');
    }

    /** @test */
    public function the_menu_is_grouped_not_one_flat_column(): void
    {
        $html = $this->actingAs($this->admin(), 'user')->get('/admin')->getContent();

        // AMIAL-SIDEBAR-SUBJECT-001 — **الأسماءُ تغيّرت مع التجميع بالموضوع**
        // (عملاء · تجّار · وكلاء …) بدل التجميع بالنظام (مراكز · أمان …).
        // وبقيت هذه القائمةُ على الأسماء القديمة، **فسقط الحارسُ منذ ذلك
        // التغيير ولم يره أحد**: مجموعةُ الاختبارات كانت تنهار قبل بلوغه.
        foreach ([
            'العملاء', 'التجّار', 'رقابة عمل التجّار', 'الوكلاء',
            'المال والدفتر', 'الامتثال والمخاطر', 'الصلاحيات',
            'خدمات المنصّة', 'المحتوى والتواصل', 'الإعدادات والتشغيل',
        ] as $group) {
            $this->assertStringContainsString($group, $html,
                "مجموعة «{$group}» غائبة عن القائمة");
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
        $links = $this->renderedLinks();

        foreach ([
            'admin/amial/kyc' => 'مراجعة الهوية',
            'admin/amial/aml' => 'مكافحة غسل الأموال',
            'admin/amial/ledger' => 'مركز الدفتر',
            'admin/amial/partner-settlements' => 'تسويات الشركاء',
            'admin/support-center' => 'مركز الدعم',
        ] as $needle => $label) {
            $found = array_filter($links, static fn (string $l): bool => str_contains($l, $needle));

            $this->assertNotEmpty($found,
                "«{$label}» اختفت من القائمة بعد إعادة التنظيم — تعمل ولا أحد يصل إليها");
        }
    }
}
