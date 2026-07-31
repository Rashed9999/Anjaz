<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-CASHOUT-001 — السحب النقديّ عبر وكيل يدخل الدفتر.
 *
 * **العطل:** هذا المسار كان **بلا ترحيل إطلاقاً**. لا مؤجَّلاً ولا مبلوعاً
 * كبقيّة المسارات، بل غير مكتوبٍ أصلاً: يخرج المال من محفظة العميل ويدخل
 * محفظة الوكيل ولا يمرّ بدفتر الأستاذ بحرف.
 *
 * وهو من أكثر المسارات حركةً في محفظةٍ يمنيّة: السحب النقديّ من وكيل هو ما
 * يفعله المستخدم فعلاً، لا التحويل بين المحافظ. فغيابه عن الدفتر يعني أن
 * أكثر الحركة المالية حجماً لا أثر لها محاسبياً.
 *
 * **وأخطر ما فيه الرسم لا المبلغ.** المبلغ ينتقل من طرفٍ إلى طرف فالخطأ فيه
 * يظهر في الأرصدة. أمّا الرسم فيُقسَم بين الوكيل والمنصّة، وخطأُ القسمة لا
 * يُخلّ بالتوازن — يبقى الدفتر متوازناً وتصير حصّة أحدهما مكان الآخر. ولذلك
 * تُقاس الحصّتان كلٌّ على حدة، لا مجموعهما.
 */
class LedgerCashOutTest extends TestCase
{
    use RefreshDatabase;
    use TransactionTrait;

    private function makeUser(string $balance): User
    {
        $u = User::factory()->create();
        EMoney::updateOrCreate(['user_id' => $u->id],
            ['current_balance' => $balance, 'zone_code' => 'SOUTH']);

        return $u;
    }

    private function ledgerBalanceOf(int $userId): string
    {
        $acc = LedgerAccount::where('account_code', "USER_WALLET_{$userId}")->first();
        if (!$acc) return '0.0000';

        $d = (string) LedgerEntryLine::where('account_id', $acc->id)
            ->where('direction', 'debit')->sum('amount');
        $c = (string) LedgerEntryLine::where('account_id', $acc->id)
            ->where('direction', 'credit')->sum('amount');

        return bcsub($c ?: '0', $d ?: '0', 4);
    }

    private function systemBalance(string $code): string
    {
        $acc = LedgerAccount::where('account_code', $code)->first();
        if (!$acc) return '0.0000';

        $d = (string) LedgerEntryLine::where('account_id', $acc->id)
            ->where('direction', 'debit')->sum('amount');
        $c = (string) LedgerEntryLine::where('account_id', $acc->id)
            ->where('direction', 'credit')->sum('amount');

        return bcsub($c ?: '0', $d ?: '0', 4);
    }

    private function walletOf(int $userId): string
    {
        return bcadd((string) EMoney::where('user_id', $userId)->value('current_balance'), '0', 4);
    }

    public function test_a_cash_out_writes_a_ledger_entry_at_all(): void
    {
        // أوّل سؤال، وهو الذي لم يُطرح على هذا المسار قطّ: هل كُتب شيء؟
        $customer = $this->makeUser('10000');
        $agent = $this->makeUser('0');

        $this->customer_cash_out_transaction(
            from_user_id: $customer->id, to_user_id: $agent->id,
            amount: '1000', charge: '50',
        );

        $this->assertSame(1,
            LedgerJournalEntry::where('source_type', 'agent_cash_out')->count(),
            'لم يُكتب قيدُ سحب — عاد المسار إلى ما كان عليه: مالٌ يتحرّك بلا أثر');
    }

    public function test_both_wallets_agree_with_the_ledger_after_a_cash_out(): void
    {
        $customer = $this->makeUser('10000');
        $agent = $this->makeUser('500');

        $this->customer_cash_out_transaction(
            from_user_id: $customer->id, to_user_id: $agent->id,
            amount: '1000', charge: '50',
        );

        foreach ([$customer->id, $agent->id] as $id) {
            $this->assertSame(0, bccomp($this->walletOf($id), $this->ledgerBalanceOf($id), 4),
                "انحراف على المستخدم $id: المحفظة {$this->walletOf($id)} "
                . "والدفتر {$this->ledgerBalanceOf($id)}");
        }
    }

    public function test_the_fee_split_lands_on_the_right_two_accounts(): void
    {
        // خطأ القسمة لا يُخلّ بالتوازن: يبقى المجموع صحيحاً وتصير حصّة
        // الوكيل في حساب المنصّة أو العكس.
        //
        // وأوّل صياغةٍ كتبتُها هنا فحصت **المجموع** — فمرّت وهي عمياء: قلبتُ
        // الحصّتين عمداً فلم تسقط، لأن المقلوب يجمع الرقم نفسه. والمجموع
        // مفحوصٌ أصلاً في اختبار التوازن، فتكراره هنا لا يضيف شيئاً.
        //
        // فتُمرَّر الحصّة صراحةً ويُقاس كلُّ حسابٍ برقمه المتوقَّع وحده.
        $customer = $this->makeUser('10000');
        $agent = $this->makeUser('0');

        $this->customer_cash_out_transaction(
            from_user_id: $customer->id, to_user_id: $agent->id,
            amount: '1000', charge: '50',
            agentCommissionOverride: '30',   // فحصّة المنصّة 20
        );

        // الوكيل: المبلغ 1000 + عمولته 30
        $this->assertSame(0, bccomp($this->ledgerBalanceOf($agent->id), '1030', 4),
            'حصّة الوكيل خاطئة — رصيده في الدفتر '
            . $this->ledgerBalanceOf($agent->id) . ' والمتوقَّع 1030');

        // المنصّة: 50 − 30
        $this->assertSame(0, bccomp($this->systemBalance('PLATFORM_FEE'), '20', 4),
            'حصّة المنصّة خاطئة — في الدفتر '
            . $this->systemBalance('PLATFORM_FEE') . ' والمتوقَّع 20');

        // والعميل يُخصم المبلغ والرسم كاملاً بصرف النظر عن القسمة.
        $this->assertSame(0, bccomp($this->ledgerBalanceOf($customer->id), '8950', 4));
    }

    public function test_a_cash_out_with_no_fee_still_balances(): void
    {
        // الرسم صفر يعني سطرين لا أربعة. وقيدٌ بسطرٍ بمبلغ صفر مرفوضٌ في
        // LedgerService، فلو لم تُحذف الأسطر الفارغة لسقط السحب المجّانيّ.
        $customer = $this->makeUser('5000');
        $agent = $this->makeUser('0');

        $this->customer_cash_out_transaction(
            from_user_id: $customer->id, to_user_id: $agent->id,
            amount: '750', charge: '0',
        );

        $this->assertSame(0, bccomp($this->walletOf($customer->id), '4250', 4));
        $this->assertSame(0,
            bccomp($this->walletOf($agent->id), $this->ledgerBalanceOf($agent->id), 4));
    }

    public function test_the_ledger_stays_balanced_overall(): void
    {
        $customer = $this->makeUser('10000');
        $agent = $this->makeUser('2000');

        $this->customer_cash_out_transaction(
            from_user_id: $customer->id, to_user_id: $agent->id,
            amount: '1500', charge: '75',
        );

        $debits = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $credits = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');

        $this->assertGreaterThan(0, bccomp($debits, '0', 4), 'لا قيود — الفحص على العدم');
        $this->assertSame(0, bccomp($debits, $credits, 4),
            "الدفتر غير متوازن بعد السحب: مدين $debits دائن $credits");
    }
}
