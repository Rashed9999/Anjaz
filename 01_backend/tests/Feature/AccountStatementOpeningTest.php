<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerReportService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-STATEMENT-OPENING-001 — **كشفٌ يبدأ من صفرٍ يكذب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * المحورُ ٢٥ يطلب في كشف الحساب: `opening balance` · `closing` · `period`.
 *
 * وكان المتدرّجُ يبدأ من صفرٍ **مهما كان المرشِّح**. فكشفُ حسابٍ مقصورٌ
 * على شهرٍ يعرض رصيداً متدرّجاً كأنّ الحسابَ **وُلد أوّلَ الشهر**:
 *
 *     عميلٌ رصيدُه مليونٌ منذ سنة، أنفق خمسين ألفاً هذا الشهر
 *     ⇒ يُقرأ كشفُه: **«سالبٌ خمسون ألفاً»**
 *
 * **ولا يُنتج ذلك خطأً في أيّ سجلّ.** ولا يظهر إلّا حين يقارن أحدٌ آخرَ
 * سطرٍ في الكشف بالرصيد الحقيقيّ — وقد يُبنى على الرقم قرارٌ قبل ذلك.
 */
class AccountStatementOpeningTest extends TestCase
{
    use RefreshDatabase;

    private function reports(): LedgerReportService
    {
        return app(LedgerReportService::class);
    }

    /** حسابٌ فيه حركةٌ قديمةٌ وأخرى داخلَ النافذة. */
    private function accountWithHistory(): int
    {
        $l = app(LedgerService::class);
        $u = User::factory()->create(['type' => CUSTOMER_TYPE]);
        $wallet = $l->getOrCreateUserWallet($u->id);
        $cash = $l->getOrCreateSystemAccount('TREASURY_CASH_RESERVE', 'asset', 'نقد', 'debit');

        // ① قديمٌ: مليونٌ قبل ستّة أشهر.
        $old = $l->post(
            sourceType: 'add_money', sourceId: 'OLD-1', description: 'إيداعٌ قديم',
            lines: [
                ['account' => $cash->account_code, 'direction' => 'debit', 'amount' => '1000000'],
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => '1000000'],
            ],
            idempotencyKey: 'stmt-old-1',
        );
        DB::table('ledger_journal_entries')->where('id', $old->id)
            ->update(['posted_at' => now()->subMonths(6)]);

        // ② حديثٌ: خمسون ألفاً اليوم.
        $new = $l->post(
            sourceType: 'cash_out_executed', sourceId: 'NEW-1', description: 'سحبٌ حديث',
            lines: [
                ['account' => $wallet->account_code, 'direction' => 'debit', 'amount' => '50000'],
                ['account' => $cash->account_code, 'direction' => 'credit', 'amount' => '50000'],
            ],
            idempotencyKey: 'stmt-new-1',
        );
        DB::table('ledger_journal_entries')->where('id', $new->id)
            ->update(['posted_at' => now()]);

        return (int) $wallet->id;
    }

    /**
     * @test
     *
     * **① كشفٌ مقصورٌ على مدّةٍ يبدأ من رصيدٍ افتتاحيٍّ لا من صفر.**
     */
    public function a_windowed_statement_starts_from_the_opening_balance(): void
    {
        $accountId = $this->accountWithHistory();

        $stmt = $this->reports()->accountStatement(
            $accountId, now()->subDays(3)->toDateString(), now()->toDateString());

        $this->assertSame(0, bccomp($stmt['opening_balance'], '1000000', 4),
            '**الرصيدُ الافتتاحيُّ صفرٌ** — والكشفُ يُقرأ كأنّ الحسابَ وُلد أوّلَ المدّة');

        $this->assertSame(0, bccomp($stmt['closing_balance'], '950000', 4),
            '**الختاميُّ سالبٌ**: عميلٌ رصيدُه مليونٌ يُقرأ كشفُه «سالبٌ خمسون ألفاً»');
    }

    /**
     * @test
     *
     * **② والافتتاحيُّ يُحسب من السطور لا من العمود المخزَّن.**
     *
     * ══════════════════════════════════════════════════════════════════
     * (‏القاعدة السادسة.) ولو قُرئ من `current_balance` لكان الكشفُ يُثبت
     * أنّ العمودَ يساوي نفسَه — **وهو ما يُراجَع لا ما يُبنى عليه**.
     */
    public function the_opening_balance_is_computed_from_the_lines(): void
    {
        $accountId = $this->accountWithHistory();

        // يُفسَد العمودُ المخزَّن وحدَه.
        DB::table('ledger_accounts')->where('id', $accountId)
            ->update(['current_balance' => '7777777.0000']);

        $stmt = $this->reports()->accountStatement(
            $accountId, now()->subDays(3)->toDateString(), now()->toDateString());

        $this->assertSame(0, bccomp($stmt['opening_balance'], '1000000', 4),
            '**الافتتاحيُّ قُرئ من العمود المخزَّن** — فصار الكشفُ يُثبت أنّ العمود يساوي نفسَه');
    }

    /**
     * @test
     *
     * **③ وكشفٌ بلا مرشِّحٍ افتتاحيُّه صفرٌ بحقّ.**
     *
     * فلا شيءَ قبل أوّل سطر — **وصفرٌ هنا قياسٌ لا افتراض**.
     */
    public function an_unfiltered_statement_legitimately_opens_at_zero(): void
    {
        $accountId = $this->accountWithHistory();

        $stmt = $this->reports()->accountStatement($accountId);

        $this->assertSame(0, bccomp($stmt['opening_balance'], '0', 4));
        $this->assertSame(0, bccomp($stmt['closing_balance'], '950000', 4),
            'الختاميُّ لا يطابق مجموعَ الحركة كلِّها');
    }

    /**
     * @test
     *
     * **④ والقطعُ عند الحدّ يُقال.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كشفٌ مقطوعٌ يُقرأ كاملاً، **فيُطابَق آخرُ سطرٍ فيه بالرصيد الحقيقيّ
     * فلا يتّفقان** — ويُفتح تحقيقٌ في فرقٍ لا وجودَ له.
     */
    public function a_truncated_statement_says_so(): void
    {
        $accountId = $this->accountWithHistory();

        $this->assertTrue(
            $this->reports()->accountStatement($accountId, null, null, 1)['truncated'],
            '**قُطع الكشفُ ولم يقل** — فيُفتح تحقيقٌ في فرقٍ لا وجودَ له');

        $this->assertFalse(
            $this->reports()->accountStatement($accountId, null, null, 500)['truncated'],
            'كشفٌ كاملٌ يدّعي القطع — والتحذيرُ يصير ضجيجاً');
    }

    /**
     * @test
     *
     * **⑤ ولا يُكسَر المفتاحُ الذي تقرؤه الشاشةُ اليوم.**
     */
    public function the_existing_computed_balance_key_survives(): void
    {
        $stmt = $this->reports()->accountStatement($this->accountWithHistory());

        $this->assertArrayHasKey('computed_balance', $stmt,
            '**`computed_balance` اختفى** — والشاشةُ تعرض `undefined` بلا خطأٍ في أيّ سجلّ');
        $this->assertSame($stmt['closing_balance'], $stmt['computed_balance']);
    }

    /**
     * @test
     *
     * **⑥ والشاشةُ تعرض الافتتاحيَّ — ورقمٌ لا يُرى ليس مبنيّاً.**
     */
    public function the_screen_shows_the_opening_balance(): void
    {
        $html = (string) file_get_contents(
            resource_path('views/admin-views/amial/ledger/index.blade.php'));

        $this->assertStringContainsString('opening_balance', $html,
            'الافتتاحيُّ في الخدمة ولا يُرى في الشاشة');
        $this->assertStringContainsString('رصيدٌ افتتاحيّ', $html);
    }
}
