<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use App\Services\LedgerReportService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-CENTER-001 — الفصل ١٧: مركز الدفتر.
 *
 * **المفارقة التي يُنهيها:** أُصلح الدفتر في هذا المشروع بالكامل — القيد
 * الافتتاحيّ، والترحيل الإلزاميّ، وتغطية كلّ مسارات المال، وحرّاسٌ تمنع
 * تراجعه — **ولا شاشة تقرؤه**.
 *
 * **وأهمّ اختبارٍ هنا هو `catches_drift_a_cached_read_would_miss`.**
 *
 * لأنّ الطريقة السهلة لبناء ميزان مراجعة هي قراءة `current_balance` من
 * جدول الحسابات — أسرع بمئة مرّة من تجميع ملايين السطور. وهي تُنتج ميزاناً
 * **متوازناً دائماً**: يُثبت أنّ العمود يساوي نفسه.
 *
 * والانحراف بين العمود المخزَّن ومجموع الحركة هو **الشيء الوحيد** الذي
 * يستحقّ الكشف هنا — وهو بالضبط ما تُخفيه الطريقة السهلة.
 */
class LedgerCenterTest extends TestCase
{
    use RefreshDatabase;

    private LedgerReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = app(LedgerReportService::class);
    }

    private function customer(string $phone, string $balance = '100000'): User
    {
        $u = User::factory()->create(['phone' => $phone, 'zone_code' => 'SOUTH']);
        EMoney::updateOrCreate(['user_id' => $u->id],
            ['current_balance' => $balance, 'zone_code' => 'SOUTH']);

        return $u;
    }

    // ── المعادلة الأساسية ───────────────────────────────────────────────

    /** @test */
    public function a_healthy_ledger_reports_equal_debits_and_credits(): void
    {
        // كلّ ريالٍ خرج من حسابٍ دخل آخر. واختلافُ المجموعين يعني قيداً غير
        // متوازن — أي مالاً ظهر أو اختفى من العدم.
        $this->customer('770005501', '50000');
        $this->customer('770005502', '30000');

        $tb = $this->reports->trialBalance();

        $this->assertTrue($tb['balanced'],
            "الدفتر غير متوازن: مدين {$tb['total_debit']} دائن {$tb['total_credit']}");
        $this->assertSame(0, bccomp($tb['difference'], '0', 4));
        $this->assertSame([], $tb['unbalanced_entries']);
    }

    /** @test */
    public function an_unbalanced_entry_is_named_not_just_counted(): void
    {
        // ولا يكفي فحصُ الإجمالي: قيدان مختلّان بمقدارين متعاكسين يُصلحان
        // الإجمالي ويبقيان خاطئين. فيُفحص كلّ قيدٍ على حدة.
        $u = $this->customer('770005503', '10000');
        $acc = LedgerAccount::where('account_code', 'like', 'USER_WALLET_%')->first();
        $this->assertNotNull($acc, 'لم يُنشأ حساب محفظة');

        $entryId = DB::table('ledger_journal_entries')->insertGetId([
            'entry_ulid' => str_repeat('X', 26),
            'source_type' => 'manual_test',
            'description_ar' => 'قيد مختلّ عمداً',
            'total_amount' => '500',
            'status' => 'posted',
            'posted_at' => now(),
            // لا `updated_at`: جدول القيود مُلحَقٌ لا يُعدَّل — قيدٌ يمكن
            // تحريره بعد ترحيله يُبطل الدفتر كلّه. (كشفه هذا الاختبار حين
            // كتبتُه على افتراض الأعمدة المعتادة، فسقط.)
            'created_at' => now(),
        ]);

        // مدينٌ بلا دائن يقابله.
        DB::table('ledger_entry_lines')->insert([
            'journal_entry_id' => $entryId, 'account_id' => $acc->id,
            'direction' => 'debit', 'amount' => '500',
            'balance_before' => '0', 'balance_after' => '0',
            'created_at' => now(),
        ]);

        $tb = $this->reports->trialBalance();

        $this->assertFalse($tb['balanced'], 'قيدٌ مختلّ مرّ والميزان يقول متوازن');

        $ulids = array_column($tb['unbalanced_entries'], 'ulid');
        $this->assertContains(str_repeat('X', 26), $ulids,
            'القيد المختلّ لم يُسمَّ — فلا يُعرف أين يُبحث');
    }

    // ── الاختبار الأهمّ ─────────────────────────────────────────────────

    /** @test */
    public function it_catches_drift_a_cached_read_would_miss(): void
    {
        // **هذا هو الاختبار الذي يبرّر الحساب من السطور.**
        //
        // يُعبَث بالعمود المخزَّن وحده ولا تُمسّ القيود. فميزانٌ يُبنى من
        // العمود يبقى «سليماً» — لأنّه يقرأ الرقم المُتلاعَب به ويقارنه بنفسه.
        // وميزانٌ يُبنى من الحركة يكشف الفرق فوراً.
        $u = $this->customer('770005504', '75000');

        $acc = LedgerAccount::where('owner_user_id', $u->id)
            ->where('account_code', 'like', 'USER_WALLET_%')->first();
        $this->assertNotNull($acc);

        // سليمٌ قبل العبث.
        $before = collect($this->reports->trialBalance()['accounts'])->firstWhere('id', $acc->id);
        $this->assertFalse($before['has_drift'], 'ظهر انحراف قبل أيّ عبث');

        // العبث: العمود وحده.
        DB::table('ledger_accounts')->where('id', $acc->id)
            ->update(['current_balance' => '999999']);

        $tb = $this->reports->trialBalance();
        $after = collect($tb['accounts'])->firstWhere('id', $acc->id);

        $this->assertTrue($after['has_drift'],
            'لم يُكشف الانحراف — الميزان يقرأ العمود المخزَّن لا الحركة');
        $this->assertSame('75000.0000', $after['computed_balance'],
            'الرصيد المحسوب تأثّر بالعبث في العمود');
        $this->assertSame(1, $tb['drifted_accounts']);

        // والدفتر نفسه يبقى **متوازناً**: العبث في عمودٍ لا يُخلّ بالقيود.
        // وهذا هو بيت القصيد — التوازن وحده لا يكشف الانحراف.
        $this->assertTrue($tb['balanced'],
            'اختلّ التوازن — فالاختبار لا يعزل الانحراف عن عدم التوازن');
    }

    // ── مطابقة المحافظ ──────────────────────────────────────────────────

    /** @test */
    public function a_perfectly_balanced_ledger_can_still_diverge_from_the_wallets(): void
    {
        // التوازن الداخليّ لا يعني المطابقة الخارجية. ودفترٌ بلا قيدٍ واحد
        // متوازنٌ تماماً (مدين ٠ = دائن ٠) ويختلف عن كلّ محفظة.
        $u = $this->customer('770005505', '40000');

        // مالٌ يتحرّك في المحفظة بلا قيد — وهو العطل الذي أُصلح في الجلسة.
        EMoney::where('user_id', $u->id)->update(['current_balance' => '25000']);

        $tb = $this->reports->trialBalance();
        $rec = $this->reports->walletReconciliation();

        $this->assertTrue($tb['balanced'], 'الاختبار يفترض دفتراً متوازناً');

        $row = collect($rec['rows'])->firstWhere('user_id', $u->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['diverged'], 'انحرافُ المحفظة عن الدفتر لم يُكشف');
        $this->assertSame(0, bccomp($row['gap'], '-15000', 4));
        $this->assertSame(1, $rec['divergent']);
    }

    /** @test */
    public function divergent_wallets_are_listed_first(): void
    {
        // المنحرفة هي ما يُنظر فيه، والباقي حشو. وقائمةٌ بترتيب المعرّف تدفن
        // الانحراف الواحد بين مئتين سليمة.
        $ok = $this->customer('770005506', '10000');
        $bad = $this->customer('770005507', '20000');
        EMoney::where('user_id', $bad->id)->update(['current_balance' => '5000']);

        $rec = $this->reports->walletReconciliation();

        $this->assertTrue($rec['rows'][0]['diverged'],
            'المحفظة المنحرفة ليست أوّل الصفّ');
    }

    // ── كشف الحساب ──────────────────────────────────────────────────────

    /** @test */
    public function the_statement_recomputes_the_running_balance_instead_of_replaying_it(): void
    {
        // `balance_after` مخزَّنٌ في السطر، وهو ما نراجعه. وقراءتُه تجعل
        // الكشف يُعيد سرد ما قيل بدل أن يُراجعه.
        $u = $this->customer('770005508', '60000');
        $acc = LedgerAccount::where('owner_user_id', $u->id)
            ->where('account_code', 'like', 'USER_WALLET_%')->first();

        $st = $this->reports->accountStatement($acc->id);

        $this->assertNotEmpty($st['lines'], 'لا سطور في كشف حسابٍ مموَّل');
        $this->assertSame(0, bccomp($st['computed_balance'], '60000', 4));
        $this->assertSame(0, $st['mismatched_lines']);

        // ويُكشف العبث في `balance_after` وحده.
        DB::table('ledger_entry_lines')
            ->where('id', $st['lines'][0]['id'])
            ->update(['balance_after' => '1']);

        $this->assertSame(1, $this->reports->accountStatement($acc->id)['mismatched_lines'],
            'عُبث بالرصيد المخزَّن في السطر ولم يُكشف');
    }

    // ── الوصول عبر اللوحة ───────────────────────────────────────────────

    /** @test */
    public function the_ledger_centre_opens_and_all_four_tabs_answer(): void
    {
        $admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770005509',
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
        DB::table('admin_user_roles')->insert([
            'user_id' => $admin->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'user')->get('/admin/amial/ledger')->assertOk();

        foreach ([
            '/admin/amial/ledger/trial-balance',
            '/admin/amial/ledger/reconciliation',
            '/admin/amial/ledger/accounts',
            '/admin/amial/ledger/entries',
        ] as $url) {
            $this->actingAs($admin, 'user')->getJson($url)
                ->assertOk()->assertJsonPath('success', true);
        }
    }

    /** @test */
    public function reading_the_ledger_requires_the_audit_permission(): void
    {
        // قراءة الدفتر اطّلاعٌ على حركة المال كلّها — من جنس التدقيق لا من
        // جنس خدمة العملاء.
        $admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770005510',
        ]);

        $this->actingAs($admin, 'user')->get('/admin/amial/ledger')->assertStatus(403);
    }
}
