<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\CustomerWithdrawService;
use App\Services\Reconciliation\LedgerFlowCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-FLOW-COVERAGE-001 — **الدعوى تصير رقماً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * الشاشةُ تكتب «حركة المال — كلُّ ريال»، والوثيقةُ تشترط
 * `wallet-changing operations absent from financial feed = 0`.
 *
 * وكان إثباتُ ذلك **قائمةً مكتوبةً باليد** في الإعدادات. وقِيس في يومٍ
 * واحدٍ أنّها تشيخ في الاتّجاهين: تعتذر عمّا لا ثغرةَ فيه، وتسكت عمّا
 * سُدّ. فصار الجوابُ يُقرأ من البيانات.
 */
class LedgerFlowCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LedgerFlowCoverageService
    {
        return app(LedgerFlowCoverageService::class);
    }

    private function window(): array
    {
        return [now()->subDay(), now()->addDay()];
    }

    private function funded(int $type, string $balance): User
    {
        $u = User::factory()->create(['type' => $type, 'zone_code' => 'SOUTH']);
        EMoney::where('user_id', $u->id)->delete();
        EMoney::create([
            'user_id' => $u->id, 'current_balance' => $balance,
            'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
            'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0,
        ]);

        return $u;
    }

    /**
     * @test
     *
     * **① نافذةٌ بلا حركةٍ تُقال «—» لا «١٠٠٪».**
     *
     * ══════════════════════════════════════════════════════════════════
     * القاعدةُ السابعة: «غيرُ معروف» ليس صفراً — وهنا مقلوبةً: **لا حركةَ
     * ليست تغطيةً تامّة**. ولوحةٌ تكتب «١٠٠٪ من التدفّقات مغطّاة» فوق
     * قاعدةٍ لم يقع فيها شيءٌ تُطمئن على ما لم يُفحَص.
     */
    public function an_empty_window_says_so_rather_than_claiming_full_coverage(): void
    {
        $c = $this->service()->coverage(...$this->window());

        $this->assertTrue($c['measurable']);
        $this->assertSame(0, $c['totals']['moves']);
        $this->assertSame('—', $c['totals']['percent'],
            'نافذةٌ فارغةٌ كُتبت «١٠٠٪» — وذلك اطمئنانٌ على ما لم يُفحَص');
    }

    /**
     * @test
     *
     * **② تدفّقٌ يُرحَّل يُقرأ «مرحَّلاً» — ولو كان مفتاحُ قيده غيرَ رقم المعاملة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا أهمُّ ما يُفحص في هذه الأداة.** السحبُ النقديُّ يُرقّم قيدَه
     * برقم **الطلب** لا المعاملة. فمطابقةٌ بمفتاحٍ واحدٍ تُخرج «غيرَ
     * مرحَّل» عن مسارٍ رُحِّل بالكامل — **وإنذارٌ كاذبٌ واحدٌ يُفقد
     * التقريرَ قيمتَه كلَّها**: من رآه مرّةً كاذباً لم يصدّقه صادقاً.
     */
    public function a_flow_keyed_by_something_other_than_the_transaction_id_still_reads_as_covered(): void
    {
        $customer = $this->funded(CUSTOMER_TYPE, '50000');
        $agent = $this->funded(AGENT_TYPE, '0');

        $svc = app(CustomerWithdrawService::class);
        $req = $svc->request($customer, '10000');
        $svc->execute($agent, $req->op_code, '');

        $c = $this->service()->coverage(...$this->window());
        $row = collect($c['rows'])->firstWhere('type', 'cash_out');

        $this->assertNotNull($row, 'لم يُقرأ تدفّقُ السحب النقديّ إطلاقاً');
        $this->assertSame('مرحَّل', $row['state'],
            '**إنذارٌ كاذب**: السحبُ النقديُّ مُرحَّلٌ بالكامل وقُرئ غيرَ مرحَّل — '
            . 'لأنّ مفتاحَ قيده رقمُ الطلب لا رقمُ المعاملة');
        $this->assertSame(0, $row['uncovered']);
    }

    /**
     * @test
     *
     * **③ وتدفّقٌ لا يُرحَّل يُسمّى بالاسم.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا الحارسُ مقلوبٌ بحكم بنائه:** يُصنَع صفُّ معاملةٍ يحرّك مالاً
     * بلا قيدٍ خلفه — أي يُعاد العطلُ عمداً — ويُتأكَّد أنّ الأداةَ تراه.
     * فأداةٌ لا تُخرج إلّا «مرحَّل» ليست أداةَ قياس.
     */
    public function a_flow_with_no_ledger_entry_is_named(): void
    {
        $u = $this->funded(CUSTOMER_TYPE, '1000');

        DB::table('transactions')->insert([
            'user_id' => $u->id,
            'transaction_id' => 'GHOST-'.uniqid(),
            'ref_trans_id' => null,
            'transaction_type' => 'ghost_flow',
            'debit' => 500, 'credit' => 0, 'balance' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $c = $this->service()->coverage(...$this->window());
        $row = collect($c['rows'])->firstWhere('type', 'ghost_flow');

        $this->assertNotNull($row, '**الأداةُ عمياء**: تدفّقٌ يحرّك مالاً بلا قيدٍ ولم تره');
        $this->assertSame('غيرُ مرحَّل', $row['state']);
        $this->assertSame(1, $row['uncovered']);

        $uncovered = $this->service()->uncoveredFlows(...$this->window());
        $this->assertContains('ghost_flow', array_column($uncovered, 'type'),
            'التدفّقُ غيرُ المرحَّل لم يظهر في قائمة غير المرحَّل');
    }

    /**
     * @test
     *
     * **④ وحركةُ المخزون ليست مالاً — ولا تُعدّ ثغرة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * جردٌ وتالفٌ ونقلٌ بين فروعٍ تُسجَّل في الجدول نفسِه وتحمل كمّيّاتٍ
     * لا ريالات. وعدُّها تدفّقاً غيرَ مرحَّلٍ يُنتج ثغراتٍ دائمةً لا تُسدّ
     * أبداً — **وتقريرٌ فيه ثغرةٌ دائمةٌ يُقرأ ضجيجاً فيُهمَل كلُّه**.
     */
    public function stock_movements_are_not_counted_as_unposted_money(): void
    {
        $u = $this->funded(MERCHANT_TYPE, '1000');

        foreach (array_keys(LedgerFlowCoverageService::NON_MONETARY) as $type) {
            DB::table('transactions')->insert([
                'user_id' => $u->id,
                'transaction_id' => strtoupper($type).'-'.uniqid(),
                'transaction_type' => $type,
                'debit' => 40, 'credit' => 0, 'balance' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $types = array_column($this->service()->coverage(...$this->window())['rows'], 'type');

        foreach (array_keys(LedgerFlowCoverageService::NON_MONETARY) as $type) {
            $this->assertNotContains($type, $types,
                "«{$type}» كمّيّاتٌ لا مال، وعدُّه ثغرةً يُنتج ضجيجاً دائماً");
        }
    }

    /**
     * @test
     *
     * **⑤ وكلُّ استثناءٍ له سببٌ مكتوب.**
     *
     * فسطرٌ بلا سببٍ هو الطريقةُ الوحيدةُ لخداع هذا القياس.
     */
    public function every_non_monetary_exemption_carries_a_written_reason(): void
    {
        foreach (LedgerFlowCoverageService::NON_MONETARY as $type => $why) {
            $this->assertNotSame('', trim((string) $why),
                "«{$type}» مستثنىً بلا سبب — وذلك بابُ إخفاءِ تدفّق");
            $this->assertGreaterThan(8, mb_strlen(trim((string) $why)),
                "سببُ استثناء «{$type}» أقصرُ من أن يُراجَع");
        }
    }
}
