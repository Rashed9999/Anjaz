<?php

namespace Tests\Feature;

use App\Models\Aml\AmlInvestigation;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AmlDashboardService;
use App\Services\AmlInvestigationService;
use App\Services\AmlRegulatoryReportService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-AML-DASHBOARD-001 — الفصل ١٠: لوحة المؤشّرات والتبويبات ٢ و٣ و٦.
 *
 * **الاختبار الأهمّ في هذا الملفّ ليس حسابياً بل عن الصدق:**
 * `an_unbuilt_control_reports_not_configured_never_zero`.
 *
 * لأنّ «٠» في لوحة امتثال تُقرأ «فحصنا فلم نجد»، لا «لم نفحص». والفرق
 * بينهما هو الفرق بين منصّةٍ نظيفة وأخرى عمياء — ومن يقرأ اللوحة (مديرٌ أو
 * مدقّق أو منظّم) سيبني على ما قرأ، وقد يُكرّره أمام جهةٍ رقابية.
 */
class AmlDashboardAndScreeningTest extends TestCase
{
    use RefreshDatabase;

    private AmlDashboardService $dash;
    private User $officer;
    private User $secondOfficer;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dash = app(AmlDashboardService::class);

        $this->officer = User::factory()->create(['type' => 0, 'role' => 'super_admin', 'phone' => '967770007701']);
        $this->secondOfficer = User::factory()->create(['type' => 0, 'role' => 'super_admin', 'phone' => '967770007702']);

        $this->customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '770007703', 'zone_code' => 'SOUTH',
            'f_name' => 'أحمد', 'l_name' => 'صالح',
        ]);
        EMoney::updateOrCreate(['user_id' => $this->customer->id],
            ['current_balance' => '9000000', 'zone_code' => 'SOUTH']);
    }

    // ── الصدق في المؤشّرات ──────────────────────────────────────────────

    /** @test */
    public function an_unbuilt_control_reports_not_configured_never_zero(): void
    {
        $m = $this->dash->metrics();

        // قوائم المراقبة وPEP غير مبنيّتين في هذا النظام. وعرضُ «٠» عنهما
        // كذبٌ صريح يُقرأ «فحصنا فلم نجد».
        foreach (['watchlist', 'pep'] as $key) {
            $this->assertFalse($m[$key]['configured'],
                "المؤشّر «{$key}» يدّعي أنّه مُفعَّل وهو غير مبنيّ");

            $this->assertArrayNotHasKey('count', $m[$key],
                "المؤشّر «{$key}» يعرض رقماً عن ضابطٍ لم يُبنَ");

            $this->assertNotEmpty($m[$key]['why'],
                "المؤشّر «{$key}» يقول «غير مُفعَّل» بلا سبب — فلا يُعرف ما ينقص");
        }
    }

    /** @test */
    public function an_empty_sanction_list_is_declared_not_reported_as_clean(): void
    {
        // قائمةٌ فارغة تعني أنّ الفحص يمرّ دائماً بلا مطابقة — وهو «نظيف»
        // كاذب. وهذه الحالة هي الافتراضية في نظامٍ لم تُحمَّل فيه القوائم بعد.
        $this->assertSame(0, DB::table('sanction_list_entries')->count(),
            'الاختبار يفترض قائمةً فارغة');

        $m = $this->dash->metrics();

        $this->assertFalse($m['sanctions']['configured'],
            'قائمة العقوبات فارغة واللوحة تعرض فحصاً عاملاً');
        $this->assertStringContainsString('فارغة', $m['sanctions']['why']);
    }

    /** @test */
    public function high_risk_users_are_counted_by_type_not_lumped_together(): void
    {
        // وكيلٌ عالي الخطر مشكلةٌ من نوعٍ آخر: نقطةُ دخولٍ للنقد إلى المنصّة
        // كلّها لا حسابٌ واحد. وجمعُهم في رقمٍ واحد يُخفي ذلك.
        $agent = User::factory()->create(['type' => AGENT_TYPE, 'phone' => '770007704']);

        foreach ([$this->customer->id, $agent->id] as $uid) {
            DB::table('aml_user_risk_profiles')->insert([
                'user_id' => $uid, 'current_risk_score' => 85, 'risk_level' => 'high',
                'manual_override' => 'none', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $hr = $this->dash->metrics()['high_risk'];

        $this->assertTrue($hr['configured']);
        $this->assertSame(1, $hr['customers']);
        $this->assertSame(1, $hr['agents']);
        $this->assertSame(0, $hr['merchants']);
    }

    /** @test */
    public function whitelisted_users_are_shown_beside_the_risk_not_hidden(): void
    {
        // عميلٌ مستثنى من الرقابة معلومةٌ رقابية بذاتها، وإخفاؤها يجعل اللوحة
        // تبدو أنظف ممّا هي.
        DB::table('aml_user_risk_profiles')->insert([
            'user_id' => $this->customer->id, 'current_risk_score' => 10, 'risk_level' => 'low',
            'manual_override' => 'whitelist', 'override_reason' => 'عميل موثوق',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->dash->metrics()['high_risk']['whitelisted'],
            'المستثنون من الرقابة لا يظهرون في اللوحة');
    }

    /** @test */
    public function the_dashboard_names_the_oldest_open_case(): void
    {
        // قضيّةٌ مفتوحة منذ ستّة أشهر ليست تحقيقاً بل إهمالاً — والعدد وحده
        // لا يُظهر ذلك.
        $inv = app(AmlInvestigationService::class)->open($this->customer->id, $this->officer);

        $m = $this->dash->metrics()['investigations'];

        $this->assertSame(1, $m['open']);
        $this->assertSame(1, $m['unassigned'], 'القضايا بلا ضابط مُسنَد لا تُعدّ');
        $this->assertSame($inv->case_number, $m['oldest_open_case']);
    }

    /** @test */
    public function large_transactions_are_compared_against_what_was_actually_reported(): void
    {
        // هذا هو الرقم الذي يُسأل عنه: كم عمليةً فوق الحدّ **ولم يُبلَّغ عنها**.
        $threshold = app(AmlRegulatoryReportService::class)->ctrThreshold();

        DB::table('aml_flagged_transactions')->insert([
            'flag_ulid' => str_repeat('F', 26),
            'transaction_ulid' => str_repeat('T', 26),
            'transaction_type' => 'send_money',
            'actor_user_id' => $this->customer->id,
            'amount' => bcadd($threshold, '1000', 4),
            'total_risk_score' => 60,
            'triggered_rules' => json_encode([['code' => 'MAX_SINGLE_TX_SOFT']]),
            'initial_decision' => 'flag',
            'current_status' => 'pending_review',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lt = $this->dash->metrics()['large_transactions'];

        $this->assertSame(1, $lt['flagged_30d']);
        $this->assertSame(0, $lt['ctr_generated_30d'],
            'العدد المُبلَّغ عنه غير صفر بلا بلاغ — فلا تظهر الفجوة');

        // والقائمة تُظهر «لم يُبلَّغ» على الصفّ نفسه.
        $rows = $this->dash->largeTransactionList();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['ctr_report'],
            'الصفّ يدّعي وجود بلاغ عملة ولا بلاغ');
    }

    // ── فحص العقوبات ────────────────────────────────────────────────────

    private function seedMatch(string $result = 'potential_match', float $score = 92.0): int
    {
        DB::table('sanction_list_entries')->insert([
            'id' => 1, 'list_source' => 'OFAC', 'entry_type' => 'individual',
            'full_name' => 'Ahmed Saleh', 'normalized_name' => 'ahmed saleh',
            'nationality' => 'YE', 'program' => 'SDGT', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('sanction_screening_logs')->insertGetId([
            'user_id' => $this->customer->id,
            'screened_name' => 'أحمد صالح',
            'result' => $result,
            'match_score' => $score,
            'matched_entry_id' => 1,
            'screening_context' => 'registration',
            'screened_at' => now(),
        ]);
    }

    /** @test */
    public function a_potential_match_starts_unreviewed_not_dismissed(): void
    {
        // الافتراض الآمن أنّ المطابقة **لم تُراجَع**، لا أنّها استُبعدت.
        // وسجلٌّ يبدأ مستبعَداً يجعل الصمت يبدو قراراً.
        $id = $this->seedMatch();

        $this->assertSame('pending',
            DB::table('sanction_screening_logs')->where('id', $id)->value('review_status'));

        $rows = $this->dash->sanctionList('pending');
        $this->assertCount(1, $rows);
        $this->assertSame('OFAC', $rows[0]['list_source']);
    }

    /** @test */
    public function dismissing_a_match_demands_a_longer_reason_than_confirming_it(): void
    {
        // مقلوبٌ عن الحدس ومقصود: التأكيد يوقف الحساب وأثره ظاهرٌ يُراجَع من
        // نفسه. أمّا الاستبعاد فيُعيد العميل إلى العمل بلا أثر — وهو القرار
        // الذي يُسأل عنه في التفتيش.
        $id = $this->seedMatch();

        try {
            $this->dash->reviewSanctionMatch($id, $this->officer, 'dismissed', 'شخص آخر');
            $this->fail('قُبل استبعادٌ بسببٍ من كلمتين');
        } catch (DomainException $e) {
            $this->assertStringContainsString('٢٠ حرفاً', $e->getMessage());
        }

        $out = $this->dash->reviewSanctionMatch($id, $this->officer, 'dismissed',
            'قارنتُ رقم الهوية وتاريخ الميلاد والجنسية فاختلفت الثلاثة عن المُدرَج');

        $this->assertSame('dismissed', $out['review_status']);
    }

    /** @test */
    public function confirming_a_match_actually_blocks_the_account(): void
    {
        // قرارٌ يُسجَّل ولا يقع أخطر من عدمه: من يقرأ السجلّ يظنّ الحساب
        // موقوفاً وهو يعمل.
        $id = $this->seedMatch();

        $this->dash->reviewSanctionMatch($id, $this->officer, 'confirmed',
            'تطابق الاسم ورقم الجواز مع المُدرَج في قائمة OFAC');

        $this->assertSame('blocked',
            User::find($this->customer->id)->sanction_status,
            'أُكِّدت المطابقة ولم يُوقَف الحساب');
    }

    /** @test */
    public function a_match_cannot_be_decided_twice(): void
    {
        // بتٌّ ثانٍ يمحو الأوّل بلا أثر — فيصير الاستبعاد بعد التأكيد تراجعاً
        // صامتاً عن قرارٍ أوقف حساباً.
        $id = $this->seedMatch();

        $this->dash->reviewSanctionMatch($id, $this->officer, 'confirmed',
            'تطابق الاسم ورقم الجواز مع المُدرَج');

        $this->expectException(DomainException::class);
        $this->dash->reviewSanctionMatch($id, $this->secondOfficer, 'dismissed',
            'أعدتُ الفحص فتبيّن أنّه شخصٌ مختلف تماماً عن المُدرَج');
    }

    /** @test */
    public function once_the_list_has_entries_the_sanction_metric_becomes_real(): void
    {
        $this->seedMatch();

        $sc = $this->dash->metrics()['sanctions'];

        $this->assertTrue($sc['configured'], 'القائمة فيها مُدرَجون واللوحة تقول غير مُفعَّل');
        $this->assertSame(1, $sc['list_entries']);
        $this->assertSame(1, $sc['potential_pending']);
    }

    // ── تقسيم العمليات ──────────────────────────────────────────────────

    /** @test */
    public function structuring_is_grouped_per_customer_because_it_is_a_pattern(): void
    {
        // التقسيم نمطٌ لا حادثة. وصفٌّ لكلّ عملية يُخفي أنّ العشرين صفّاً
        // لشخصٍ واحد.
        $ruleId = DB::table('aml_rules')->insertGetId([
            'code' => 'STRUCTURING_CLASSIC', 'name_ar' => 'تقسيم كلاسيكيّ',
            'rule_type' => 'pattern', 'applies_to' => '*', 'parameters' => json_encode([]),
            'action_on_match' => 'flag', 'risk_score_contribution' => 40,
            'priority' => 10, 'is_active' => true, 'shadow_mode' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < 4; $i++) {
            DB::table('aml_rule_evaluations')->insert([
                'transaction_ulid' => str_repeat((string) $i, 26),
                'transaction_type' => 'send_money',
                'actor_user_id' => $this->customer->id,
                'amount' => '400000',
                'rule_id' => $ruleId, 'rule_code' => 'STRUCTURING_CLASSIC',
                'matched' => true, 'contributed_risk_score' => 40,
                'created_at' => now()->subHours(6 - $i),
            ]);
        }

        $rows = $this->dash->structuringList();

        $this->assertCount(1, $rows, 'أربع عمليات لشخصٍ واحد ظهرت أربعة صفوف');
        $this->assertSame(4, $rows[0]['transactions']);
        $this->assertSame(0, bccomp($rows[0]['total_amount'], '1600000', 0));

        // ونمطٌ مكشوف بلا قضيّة هو الرصد الذي يقف عنده النظام.
        $this->assertNull($rows[0]['investigation']);

        app(AmlInvestigationService::class)->open($this->customer->id, $this->officer);
        $this->assertNotNull($this->dash->structuringList()[0]['investigation'],
            'فُتحت قضيّة ولا تظهر أمام النمط');
    }
}
