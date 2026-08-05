<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-DASH-TRUTH-001 — أرقام لوحة الإدارة تُقارَن بالحقيقة لا بنفسها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ أرقامٍ كانت تُعرض على المدير وهي كاذبة — ولا واحدٌ يشتكي.**
 *
 * والنقصُ يُرى، والكذبُ لا: صفحةٌ ناقصةٌ يُقال «ينقصها كذا»، ورقمٌ كاذبٌ
 * يُبنى عليه قرار.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُكتفى بـ«الرقم ليس صفراً».**
 *
 * ذاك يمرّ على كلّ الأخطاء الثلاثة. فيُبنى المصدرُ بأرقامٍ معلومة، ويُقارَن
 * ما تعرضه اللوحة **بالمجموع المحسوب يدويّاً**. (القاعدة السادسة: الرقم
 * يُحسب من مصدره لا من عمودٍ مخزَّن — وهنا: يُقارَن بمصدره.)
 */
class AdminDashboardTruthTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'المنصّة', 'phone' => '967770002001',
            'email' => 'p@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        return $u;
    }

    /** عدّادٌ يضمن `transaction_id` فريداً — والعمود `UNIQUE NOT NULL`. */
    private int $seq = 0;

    private function tx(int $userId, string $when, float $debit, float $charge = 0): void
    {
        DB::table('transactions')->insert([
            'user_id'          => $userId,
            'ref_trans_id'     => 0,
            'transaction_type' => 'send_money',
            'debit'            => $debit,
            'credit'           => 0,
            'charge'           => $charge,
            'amount'           => $debit,
            'transaction_id'   => 'TRUTH-' . (++$this->seq),
            'created_at'       => $when,
            'updated_at'       => $when,
        ]);
    }

    /**
     * @test
     *
     * **«حجم الشهر» مجموعُ المنصّة — لا أعلى مستخدمٍ فيها.**
     *
     * وكان `groupBy('user_id')->orderByDesc->first()` يأخذ أكبر عميلٍ
     * وحده. والقياس المضبوط: ١٠٠+٥٠ لمستخدم، و٣٠ و٢٠ لآخرين ⇒ المجموع
     * ٢٠٠، وكان يُعرض ١٥٠.
     */
    public function the_monthly_volume_is_the_platform_total_not_the_top_user(): void
    {
        $a = $this->platformAdmin()->id;
        $when = Carbon::create((int) now()->year, 3, 15, 12)->toDateTimeString();

        $this->tx($a, $when, 100);
        $this->tx($a, $when, 50);
        $this->tx($a + 1, $when, 30);
        $this->tx($a + 2, $when, 20);

        $volume = app(AdminDashboardService::class)->monthlyVolume();

        $this->assertSame(200.0, $volume[3],
            'حجمُ الشهر ليس المجموع — أيُعرض أعلى مستخدمٍ وحده كما كان؟');

        // والنفي الحاسم: ١٥٠ هو ما كان يُعرض. فلو ظهر ثانيةً فالعطل عاد.
        $this->assertNotSame(150.0, $volume[3],
            'الرقم يساوي مجموعَ أكبر مستخدمٍ — العطلُ القديم عاد');
    }

    /**
     * @test
     *
     * **واليومُ الأخير من الشهر لا يسقط.**
     *
     * كان الحدّ `date('Y-m-30')`: فبراير يُسأل عن ٣٠ (تاريخٌ لا يوجد)،
     * وسبعةُ أشهرٍ من ٣١ يوماً يسقط يومُها الأخير **صامتاً** — يومٌ كاملٌ
     * من العمليّات يختفي من كلّ واحدٍ منها.
     */
    public function the_last_day_of_a_31_day_month_is_not_dropped(): void
    {
        $a = $this->platformAdmin()->id;
        $y = (int) now()->year;

        // آذار ٣١ — كان يسقط.
        $this->tx($a, Carbon::create($y, 3, 31, 23, 30)->toDateTimeString(), 777);

        // وفبراير ٢٨ — كان المدى يسأل عن ٣٠ فبراير.
        $this->tx($a, Carbon::create($y, 2, 28, 23, 30)->toDateTimeString(), 555);

        $volume = app(AdminDashboardService::class)->monthlyVolume($y);

        $this->assertSame(777.0, $volume[3], 'اليوم ٣١ من آذار سقط');
        $this->assertSame(555.0, $volume[2], 'آخرُ يومٍ في فبراير سقط');
    }

    /**
     * @test
     *
     * **وأرباحُ الرسوم للمنصّة — لا لمن يفتح اللوحة.**
     *
     * كان `e_money->where('user_id', Auth::id())->charge_earned`، بينما
     * بقيّةُ الدالّة تستعمل `get_admin_id()`. هويّتان في دالّةٍ واحدة،
     * فالرقم يتغيّر بتغيّر من يفتح الصفحة.
     *
     * ويُحسب الآن من `transactions.charge` — مصدرِه لا عمودٍ مخزَّن.
     */
    public function fee_earnings_belong_to_the_platform_not_to_whoever_is_logged_in(): void
    {
        $a = $this->platformAdmin();
        $when = now()->toDateTimeString();

        $this->tx($a->id, $when, 100, 7.5);
        $this->tx($a->id + 1, $when, 200, 12.5);

        $svc = app(AdminDashboardService::class);

        $this->assertSame(20.0, $svc->feesEarned(), 'الأرباح ليست مجموع الرسوم');

        // **والاختبار الحاسم: الرقم لا يتغيّر بتغيّر الداخل.**
        $other = new User();
        $other->forceFill([
            'f_name' => 'مدير', 'l_name' => 'آخر', 'phone' => '967770002002',
            'email' => 'p2@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        $this->actingAs($a, 'user');
        $first = $svc->feesEarned();

        $this->actingAs($other, 'user');
        $second = app(AdminDashboardService::class)->feesEarned();

        $this->assertSame($first, $second,
            'الرقم تغيّر بتغيّر من يفتح اللوحة — وهو عطلُ Auth::id() بعينه');
    }

    /**
     * @test
     *
     * **والصفحة نفسها تعرض ما تحسبه الخدمة — لا حساباً ثانياً.**
     *
     * (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها. فالخدمةُ
     * صحيحةٌ ولا تُنادى هو العطل نفسه.)
     */
    public function the_page_renders_the_number_the_service_computes(): void
    {
        $a = $this->platformAdmin();
        app(\App\Services\PlatformRoleService::class)->ensureHasSomeRole($a);

        $when = Carbon::create((int) now()->year, (int) now()->month, 1, 10)->toDateTimeString();
        $this->tx($a->id, $when, 100);
        $this->tx($a->id + 1, $when, 50);

        $html = $this->actingAs($a, 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        // ١٥٠ = المجموع. و١٠٠ = ما كان يُعرض (أعلى مستخدم).
        $this->assertStringContainsString(number_format(150, 0), $html,
            'الصفحة لا تعرض المجموع الذي تحسبه الخدمة');
    }

    /**
     * @test
     *
     * **وإعفاؤها من دفتر الأستاذ يُقاس لا يُدَّعى.**
     *
     * أُدرجت `AdminDashboardService` في `LedgerCoverageGuardTest::EXEMPT`
     * بحجّة أنّها تقرأ ولا تكتب. وتلك **جملةٌ في تعليق** — ومن يضيف غداً
     * `update` فيها لن يعود إلى نصٍّ عربيٍّ ليصحّحه، ويبقى الإعفاء يُطمئن
     * وهو كاذب.
     *
     * فيُقاس الادّعاء: تُشغَّل كلّ دوالّها ويُلتقط **كلّ استعلامٍ فعليّ**،
     * ثمّ يُتأكَّد أنّ فيها لا `insert` ولا `update` ولا `delete`.
     *
     * (القاعدة السادسة والثانية معاً: يُحسب من المصدر، والحارس يُجرَّب.
     * ولو كتبت هذه سطراً لصار فتحُ اللوحة حدثاً محاسبياً: يتغيّر الدفتر
     * لأنّ المدير نظر.)
     */
    public function the_dashboard_service_writes_nothing_which_is_why_it_is_ledger_exempt(): void
    {
        $a = $this->platformAdmin();
        $this->tx($a->id, now()->toDateTimeString(), 100, 5);

        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });

        $svc = app(AdminDashboardService::class);
        $svc->monthlyVolume();
        $svc->feesEarned();
        $svc->balances();
        $svc->today();

        $this->assertNotEmpty($statements, 'لم يُلتقط استعلامٌ واحد — فالقياس لاغٍ');

        $writes = array_values(array_filter($statements, fn ($sql) => (bool) preg_match(
            '/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $sql
        )));

        $this->assertSame([], $writes,
            'الخدمة تكتب — وإعفاؤها في LedgerCoverageGuardTest::EXEMPT صار كذباً');
    }
}
