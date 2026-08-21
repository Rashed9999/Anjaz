<?php

namespace Tests\Feature;

use App\Models\ReconciliationCase;
use App\Services\LedgerService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-DRIFT-CASE-001 — **الفرقُ بين «يُعرَض» و«يُتابَع».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `ledger_accounts.current_balance` عمودٌ مخبّأٌ يُحدَّث مع كلّ ترحيل،
 * والحقيقةُ في السطور. وميزانُ المراجعة **كان يحسب الانحرافَ ويعرضه**
 * في لافتةٍ صفراء — **ولا يُفتح له شيء**.
 *
 * فهو رقمٌ يراه من فتح الصفحةَ اليوم، ويختفي حين يُغلقها، ولا يُسأل عنه
 * أحدٌ غداً. **وذلك الفرقُ بين لوحةٍ ومركزِ رقابة**: قضيّةٌ تحمل تاريخَ
 * أوّلِ ظهورٍ وعددَ مرّاتٍ وتتصعّد بالتكرار تُجبر على جواب.
 *
 * **وخطرُ الانحراف أنّه لا يُنتج خطأً:** ميزانُ المراجعة يُحسب من
 * السطور فيبقى متوازناً، **بينما كلُّ شاشةٍ تقرأ العمودَ تكذب**.
 */
class LedgerDriftCaseTest extends TestCase
{
    use RefreshDatabase;

    /** يصنع حساباً سليماً ثمّ يُفسد عمودَه المخبّأ وحدَه. */
    private function driftedAccount(string $wrong = '999999.0000'): array
    {
        $ledger = app(LedgerService::class);
        $a = $ledger->getOrCreateSystemAccount('DRIFT_PROBE_A', 'asset', 'حسابُ اختبار', 'debit');
        $b = $ledger->getOrCreateSystemAccount('DRIFT_PROBE_B', 'liability', 'مقابلُه', 'credit');

        $ledger->post(
            sourceType: 'test_seed', sourceId: 'drift-1',
            description: 'قيدٌ سليم',
            lines: [
                ['account' => $a->account_code, 'direction' => 'debit', 'amount' => '1000'],
                ['account' => $b->account_code, 'direction' => 'credit', 'amount' => '1000'],
            ],
            idempotencyKey: 'drift-probe-1',
        );

        // **العمودُ وحدَه يُفسَد** — كتعديلٍ مباشرٍ على الجدول، أو ترحيلٍ
        // سقط نصفُه. والسطورُ تبقى سليمةً فيبقى الميزانُ متوازناً.
        DB::table('ledger_accounts')->where('id', $a->id)->update(['current_balance' => $wrong]);

        return [$a->id, $a->fresh()];
    }

    /**
     * @test
     *
     * **① الانحرافُ يُقاس من السطور لا من العمود.**
     *
     * (‏القاعدة السادسة: مقارنةُ العمود بنفسه تُخرج صفراً دائماً.)
     */
    public function the_drift_is_measured_against_the_lines(): void
    {
        [$id] = $this->driftedAccount('999999.0000');

        $drift = collect(app(ReconciliationService::class)->accountDrift())
            ->firstWhere('account_id', $id);

        $this->assertNotNull($drift, '**المصالحةُ عمياء**: عمودٌ يقول ٩٩٩٩٩٩ وسطورُه ١٠٠٠ ولم تره');
        $this->assertSame(0, bccomp($drift['computed'], '1000', 4),
            'المحسوبُ من السطور خطأ');
        $this->assertSame(0, bccomp($drift['stored'], '999999', 4));
        $this->assertSame(0, bccomp($drift['drift'], '-998999', 4),
            'اتّجاهُ الفرق مقلوب — والمحسوبُ هو المتوقَّع لا المخزَّن');
    }

    /**
     * @test
     *
     * **② والتشغيلُ الليليُّ يفتح له قضيّة — لا يكتفي بعرضه.**
     */
    public function the_nightly_run_opens_a_case_for_the_drift(): void
    {
        [$id] = $this->driftedAccount();

        app(ReconciliationService::class)->run();

        $case = ReconciliationCase::where('case_type', 'ledger_drift')
            ->where('ledger_account_id', $id)->first();

        $this->assertNotNull($case,
            '**لا قضيّة**: الانحرافُ عُرض ولم يُتابَع — يُقرأ اليوم ويُنسى غداً');
        $this->assertSame('detected', $case->status);
        $this->assertSame('cached_balance_drift', $case->root_cause);
        $this->assertSame(0, bccomp((string) $case->expected_amount, '1000', 4),
            '«المتوقَّع» ليس المحسوبَ من السطور — فيبحث المحقّقُ عن خطأٍ في القيود');
    }

    /**
     * @test
     *
     * **③ ولا تُضاعَف القضيّةُ ليلةً بعد ليلة — بل تتصعّد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * قضيّةٌ جديدةٌ كلَّ ليلةٍ لنفس الفرق تُغرق الطابورَ في أسبوع فيُهمَل
     * كلُّه. **والتكرارُ نفسُه معلومة**: فرقٌ ظهر ثلاثَ ليالٍ ليس تقريباً.
     */
    public function a_repeated_drift_escalates_instead_of_duplicating(): void
    {
        [$id] = $this->driftedAccount();
        $svc = app(ReconciliationService::class);

        $svc->run();
        $svc->run();
        $svc->run();

        $cases = ReconciliationCase::where('case_type', 'ledger_drift')
            ->where('ledger_account_id', $id)->get();

        $this->assertCount(1, $cases,
            'ثلاثُ قضايا لفرقٍ واحد — الطابورُ يغرق في أسبوعٍ فيُهمَل كلُّه');
        $this->assertSame(3, (int) $cases[0]->detection_count);
        $this->assertSame('critical', $cases[0]->severity,
            'فرقٌ ظهر ثلاثَ ليالٍ ما زال `warning` — والتكرارُ نفسُه معلومة');
    }

    /**
     * @test
     *
     * **④ ولا يُصلَح تلقائيّاً — العمودُ يبقى كما هو.**
     *
     * ══════════════════════════════════════════════════════════════════
     * والوثيقةُ تقولها صراحةً: «لا تستخدم auto-update فقط». **وتحديثُ
     * العمود ليطابق السطورَ يمحو الدليلَ ويترك السبب** — فيعود الانحرافُ
     * بعد أسبوعٍ ولا يُعرف من أين جاء.
     */
    public function the_cached_column_is_never_silently_repaired(): void
    {
        [$id] = $this->driftedAccount('999999.0000');

        app(ReconciliationService::class)->run();

        $this->assertSame(0, bccomp(
            (string) DB::table('ledger_accounts')->where('id', $id)->value('current_balance'),
            '999999', 4),
            '**أُصلح العمودُ تلقائيّاً** — مُحي الدليلُ وبقي السبب');
    }

    /**
     * @test
     *
     * **⑤ وقضيّةٌ اختفى فرقُها لا تُغلَق تلقائيّاً.**
     *
     * فاختفاءُ الفرق قد يكون إصلاحاً، **وقد يكون تعديلاً مباشراً على
     * الجدول محا الأثر**. والتمييزُ بينهما قرارُ إنسان.
     */
    public function a_vanished_drift_waits_for_a_reviewer_rather_than_closing(): void
    {
        [$id, $account] = $this->driftedAccount('999999.0000');
        $svc = app(ReconciliationService::class);
        $svc->run();

        // يُعاد العمودُ إلى الصواب — كأنّ أحداً «أصلحه».
        DB::table('ledger_accounts')->where('id', $id)->update(['current_balance' => '1000.0000']);
        $svc->run();

        $case = ReconciliationCase::where('case_type', 'ledger_drift')
            ->where('ledger_account_id', $id)->first();

        $this->assertNotNull($case, 'حُذفت القضيّةُ — ولا يُحذف سجلٌّ رقابيّ');
        $this->assertSame('verifying', $case->status,
            '**أُغلقت القضيّةُ تلقائيّاً** — واختفاءُ الفرق قد يكون محواً للأثر لا إصلاحاً');
    }
}
