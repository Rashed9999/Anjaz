<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-WITHDRAW-001 — دورة السحب البنكيّ بحالتها الوسطى.
 *
 * **العطل:** المسار كلّه كان بلا ترحيل — ثلاث نقاط تُحرّك مالاً ولا قيد
 * لأيّها: الطلب (متحكّم العميل)، والقبول (`accept_withdraw_transaction`)،
 * والرفض (متحكّم الإدارة).
 *
 * **وما يميّز السحب عن بقيّة المسارات** أن فيه حالةً وسطى: المال يخرج من
 * الرصيد المتاح إلى المعلّق فور الطلب، ويبقى هناك أياماً حتى يُبتّ فيه.
 * فلو كُتب قيدٌ واحد عند القبول لبدا الدفترُ طوال تلك الأيام كأن الرصيد ما
 * زال متاحاً للعميل وهو محجوز — ودفترٌ يقول ذلك يُبنى عليه قرار.
 *
 * ولذلك حسابُ `WITHDRAW_PENDING` وسيطاً: الطلب يخرجه من محفظة العميل إليه،
 * ثمّ يخرج منه إمّا إلى الإدارة عند القبول وإمّا عائداً إلى العميل عند الرفض.
 *
 * **والخطأ الأخطر هنا لا يكشفه فحصُ التوازن:** لو خُصم من محفظة العميل مرّةً
 * عند الطلب وأخرى عند القبول، بقي كلُّ قيدٍ متوازناً في نفسه وصار العميل
 * مخصوماً ضعف المبلغ. فتُقاس الأرصدة لا التوازن.
 */
class LedgerWithdrawCycleTest extends TestCase
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

    private function balanceOf(string $code): string
    {
        $acc = LedgerAccount::where('account_code', $code)->first();
        if (!$acc) return '0.0000';

        $d = (string) LedgerEntryLine::where('account_id', $acc->id)
            ->where('direction', 'debit')->sum('amount');
        $c = (string) LedgerEntryLine::where('account_id', $acc->id)
            ->where('direction', 'credit')->sum('amount');

        return bcsub($c ?: '0', $d ?: '0', 4);
    }

    private function walletLedger(int $userId): string
    {
        return $this->balanceOf("USER_WALLET_{$userId}");
    }

    /**
     * محفظةٌ تولد مموَّلة يسجّل لها `EMoneyObserver` رصيداً افتتاحياً، فرصيدُها
     * في الدفتر يبدأ من 10000 لا من صفر. وأوّل صياغةٍ لهذا الملفّ توقّعت
     * -1050 فسقطت — والخطأ في التوقّع لا في الشيفرة.
     *
     * فتُقاس الفروق لا المطلقات حيث يكون المعنى في الحركة.
     */
    private const OPENING = '10000';

    /** يحاكي نقطة الطلب: المال ينتقل من المتاح إلى المعلّق. */
    private function request(User $u, string $total, string $ref): void
    {
        $w = EMoney::where('user_id', $u->id)->first();
        $w->current_balance = bcsub((string) $w->current_balance, $total, 4);
        $w->pending_balance = bcadd((string) $w->pending_balance, $total, 4);
        $w->save();

        $this->ledgerWithdrawRequested($u->id, $total, $ref);
    }

    // ── الحالة الوسطى ──────────────────────────────────────────────────

    public function test_requesting_moves_the_money_into_a_holding_account(): void
    {
        $u = $this->makeUser('10000');

        $this->request($u, '1050', 'REQ-1');

        $this->assertSame(0,
            bccomp($this->walletLedger($u->id), bcsub(self::OPENING, '1050', 4), 4),
            'محفظة العميل في الدفتر لم تُخصم عند الطلب');
        $this->assertSame(0, bccomp($this->balanceOf('WITHDRAW_PENDING'), '1050', 4),
            'المبلغ لم يدخل حساب الحجز — فيبدو الرصيد متاحاً وهو محجوز');
    }

    public function test_denying_returns_the_money_and_empties_the_holding(): void
    {
        $u = $this->makeUser('10000');

        $this->request($u, '1050', 'REQ-2');
        $this->ledgerWithdrawDenied($u->id, '1050', 'REQ-2');

        $this->assertSame(0, bccomp($this->walletLedger($u->id), self::OPENING, 4),
            'لم يعد المال إلى العميل في الدفتر بعد الرفض');
        $this->assertSame(0, bccomp($this->balanceOf('WITHDRAW_PENDING'), '0', 4),
            'بقي مالٌ في حساب الحجز بعد البتّ فيه');
    }

    public function test_approving_empties_the_holding_into_admin_and_fees(): void
    {
        $customer = $this->makeUser('10000');
        $admin = $this->makeUser('0');

        $this->request($customer, '1050', 'REQ-3');
        $this->ledgerWithdrawApproved(
            adminUserId: $admin->id, amount: '1000', charge: '50', sourceId: 'REQ-3',
        );

        $this->assertSame(0, bccomp($this->balanceOf('WITHDRAW_PENDING'), '0', 4),
            'بقي مالٌ في حساب الحجز بعد القبول');
        $this->assertSame(0, bccomp($this->walletLedger($admin->id), '1000', 4),
            'الإدارة لم تستلم المبلغ في الدفتر');
        $this->assertSame(0, bccomp($this->balanceOf('PLATFORM_FEE'), '50', 4),
            'الرسم لم يصل حساب إيراد المنصّة');
    }

    // ── الخطأ الذي لا يكشفه التوازن ────────────────────────────────────

    public function test_the_customer_is_debited_exactly_once_across_the_cycle(): void
    {
        // لو خُصم عند الطلب وعند القبول معاً، بقي كلُّ قيدٍ متوازناً في نفسه
        // وصار العميل مخصوماً 2100 من 1050. فحصُ التوازن يمرّ، والعميل يخسر.
        $customer = $this->makeUser('10000');
        $admin = $this->makeUser('0');

        $this->request($customer, '1050', 'REQ-4');
        $this->ledgerWithdrawApproved(
            adminUserId: $admin->id, amount: '1000', charge: '50', sourceId: 'REQ-4',
        );

        $expected = bcsub(self::OPENING, '1050', 4);
        $this->assertSame(0, bccomp($this->walletLedger($customer->id), $expected, 4),
            'رصيد العميل في الدفتر ' . $this->walletLedger($customer->id)
            . " والمتوقَّع $expected. خصمٌ مزدوج: القبول لا يمسّ محفظة "
            . 'العميل — خُصمت عند الطلب.');
    }

    public function test_the_ledger_balances_after_a_full_approved_cycle(): void
    {
        $customer = $this->makeUser('10000');
        $admin = $this->makeUser('0');

        $this->request($customer, '1050', 'REQ-5');
        $this->ledgerWithdrawApproved(
            adminUserId: $admin->id, amount: '1000', charge: '50', sourceId: 'REQ-5',
        );

        $d = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $c = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');

        $this->assertGreaterThan(0, bccomp($d, '0', 4), 'لا قيود — الفحص على العدم');
        $this->assertSame(0, bccomp($d, $c, 4), "غير متوازن: مدين $d دائن $c");
    }

    public function test_a_repeated_decision_does_not_post_twice(): void
    {
        // أزرار الإدارة تُضغط مرّتين، وطلبات HTTP تُعاد. ومفتاح التفرّد هو
        // ما يمنع أن يصير الرفضُ المكرّر إعادةَ المال مرّتين.
        $u = $this->makeUser('10000');

        $this->request($u, '500', 'REQ-6');
        $this->ledgerWithdrawDenied($u->id, '500', 'REQ-6');
        $this->ledgerWithdrawDenied($u->id, '500', 'REQ-6');

        $this->assertSame(0, bccomp($this->walletLedger($u->id), self::OPENING, 4),
            'رصيد العميل ' . $this->walletLedger($u->id) . ' والمتوقَّع '
            . self::OPENING . ' — أُعيد المال مرّتين، فمفتاح التفرّد لا يعمل');
    }
}
