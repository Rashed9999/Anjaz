<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CustomerWithdrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-CASHOUT-001 — **الطريقُ الوحيدُ الذي يخرج به المالُ ورقاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * السحبُ النقديُّ عند الوكيل كان **بقعةً عمياء مُعلَنة**: يُحرّك المالَ في
 * أربع نقاطٍ ولا يكتب قيداً واحداً، وتعتذر عنه المصالحةُ الليليّةُ في كلّ
 * ليلة. ودفترٌ يرى الأموالَ داخلةً ولا يراها خارجة **يبقى متوازناً** —
 * فميزانُ المراجعة لا يمسك هذا أبداً.
 *
 * والمقيسُ هنا ليس «أيُكتب قيد» بل **أتُغلَق الدورةُ كلُّها**: حجزٌ يُفتح،
 * وأربعةُ مخارجَ منه، ولا حجزٌ يبقى معلّقاً بعد أن رجع مالُه.
 */
class CashOutLedgerCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function fundedCustomer(string $balance = '50000'): User
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH']);
        EMoney::where('user_id', $u->id)->delete();
        EMoney::create([
            'user_id' => $u->id, 'current_balance' => $balance,
            'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
            'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0,
        ]);

        return $u;
    }

    private function balanceOf(string $code): string
    {
        $account = LedgerAccount::where('account_code', $code)->first();

        if (! $account) {
            return '0.0000';
        }

        $d = (string) LedgerEntryLine::where('account_id', $account->id)
            ->where('direction', 'debit')->sum('amount');
        $c = (string) LedgerEntryLine::where('account_id', $account->id)
            ->where('direction', 'credit')->sum('amount');

        return bcsub($c ?: '0', $d ?: '0', 4);
    }

    private function service(): CustomerWithdrawService
    {
        return app(CustomerWithdrawService::class);
    }

    /**
     * @test
     *
     * **① الطلبُ يُرحَّل ساعةَ يقع — لا عند الصرف.**
     *
     * ══════════════════════════════════════════════════════════════════
     * بين الطلب والصرف تمرّ دقائق. والمالُ فيها خرج من متاح العميل ولم
     * يصل الوكيل. فدفترٌ لا يكتب شيئاً حتّى الصرف **يقول إنّ الرصيدَ
     * متاحٌ للعميل طوال الانتظار** — ويُبنى عليه حدٌّ أو قرار.
     */
    public function requesting_a_cash_out_moves_the_money_into_a_hold_account(): void
    {
        $customer = $this->fundedCustomer('50000');
        $wallet = 'USER_WALLET_'.$customer->id;

        // **الفرقُ يُقاس لا الرصيدُ المطلق.** `EMoneyObserver` يفتح كلَّ
        // محفظةٍ تولد مموَّلةً في الدفتر، فرصيدُها هنا ٥٠٠٠٠ قبل أن يقع
        // شيء. ومقارنةُ المطلق تُسقط الاختبارَ على أمرٍ صحيح.
        $before = $this->balanceOf($wallet);

        $req = $this->service()->request($customer, '10000');

        // خرج من محفظة العميل بالكامل — المبلغُ والرسم.
        $this->assertSame(0, bccomp(
            bcsub($before, $this->balanceOf($wallet), 4),
            (string) $req->total_debit, 4),
            'رصيدُ العميل في الدفتر لم ينزل بمقدار المحجوز');

        // ووقف في حسابٍ معلّق — التزامٌ لا حقُّ ملكيّة.
        $this->assertSame(0, bccomp($this->balanceOf('CASH_OUT_HOLD'),
            (string) $req->total_debit, 4),
            'الحجزُ لم يُسجَّل في حسابٍ معلّق');

        $hold = LedgerAccount::where('account_code', 'CASH_OUT_HOLD')->first();
        $this->assertSame('liability', $hold->account_type,
            'الحجزُ صُنّف غيرَ التزام — ومالٌ للعميل يُقيَّد ملكاً للمنصّة يُقرأ ربحاً');
    }

    /**
     * @test
     *
     * **② والصرفُ يُخرجه من الحجز إلى الوكيل والمنصّة — لا يخصم العميلَ ثانية.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا الخطأُ بعينه **لا يمسكه فحصُ التوازن**: خصمٌ ثانٍ من العميل مع
     * دائنٍ يقابله يُبقي مدينَ الدفتر = دائنَه. ولا يظهر إلّا في رصيد
     * العميل، وبعد أن يشتكي.
     */
    public function executing_the_cash_out_empties_the_hold_and_pays_the_agent(): void
    {
        $customer = $this->fundedCustomer('50000');
        $agent = User::factory()->create(['type' => AGENT_TYPE, 'zone_code' => 'SOUTH']);

        $req = $this->service()->request($customer, '10000');
        $walletAfterRequest = $this->balanceOf('USER_WALLET_'.$customer->id);

        $this->service()->execute($agent, $req->op_code, '');

        $this->assertSame(0, bccomp($this->balanceOf('CASH_OUT_HOLD'), '0', 4),
            'بقي في الحجز رصيدٌ بعد الصرف — حجزٌ معلّقٌ إلى الأبد');

        $this->assertSame(0, bccomp(
            $this->balanceOf('USER_WALLET_'.$customer->id), $walletAfterRequest, 4),
            '**خُصم العميلُ مرّتين** — عند الطلب وعند الصرف. والتوازنُ لا يمسكه');

        $expectedAgent = bcadd((string) $req->amount, (string) $req->agent_commission, 4);
        $this->assertSame(0, bccomp(
            $this->balanceOf('USER_WALLET_'.$agent->id), $expectedAgent, 4),
            'الوكيلُ لم يُقيَّد له تعويضُ نقدِه وعمولتُه');

        if (bccomp((string) $req->platform_profit, '0', 4) > 0) {
            $this->assertSame(0, bccomp(
                $this->balanceOf('PLATFORM_FEE'), (string) $req->platform_profit, 4),
                'حصّةُ المنصّة لم تدخل حسابَ الإيراد');
        }
    }

    /**
     * @test
     *
     * **③ وكلُّ مخرجٍ من الحجز يُفرغه — أربعةٌ لا ثلاثة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * إلغاءٌ صريح · انتهاءٌ يكتشفه الوكيل · كنسٌ مجدول · وصرف. ومخرجٌ
     * واحدٌ بلا قيدٍ يترك الحجزَ في الدفتر إلى الأبد **بينما رجع المالُ
     * إلى المحفظة فعلاً**: انحرافٌ دائمٌ بمقداره، يظهر في مطابقة المحافظ
     * ولا يُعرف سببُه.
     *
     * @dataProvider exits
     */
    public function every_exit_from_the_hold_empties_it(string $exit): void
    {
        $customer = $this->fundedCustomer('50000');
        $wallet = 'USER_WALLET_'.$customer->id;
        $before = $this->balanceOf($wallet);

        $req = $this->service()->request($customer, '10000');

        $this->assertSame(0, bccomp($this->balanceOf('CASH_OUT_HOLD'),
            (string) $req->total_debit, 4), 'الحجزُ لم يُفتح أصلاً');

        match ($exit) {
            'cancel' => $this->service()->cancel($customer, $req->id),

            'agent_finds_it_expired' => (function () use ($req, $customer) {
                WithdrawalRequest::where('id', $req->id)
                    ->update(['expires_at' => now()->subMinute()]);
                $agent = User::factory()->create(['type' => AGENT_TYPE, 'zone_code' => 'SOUTH']);

                try {
                    $this->service()->execute($agent, $req->op_code, '');
                } catch (\RuntimeException $e) {
                    // متوقَّع: «العملية ملغاة أو منتهية»
                }
            })(),

            'scheduled_sweep' => (function () use ($req) {
                WithdrawalRequest::where('id', $req->id)
                    ->update(['expires_at' => now()->subMinute()]);
                $this->service()->expireStale();
            })(),
        };

        $this->assertSame(0, bccomp($this->balanceOf('CASH_OUT_HOLD'), '0', 4),
            "المخرجُ «{$exit}» ترك الحجزَ قائماً في الدفتر — والمالُ رجع إلى المحفظة");

        $this->assertSame(0, bccomp($this->balanceOf($wallet), $before, 4),
            "المخرجُ «{$exit}» لم يُرجع المالَ إلى العميل في الدفتر");
    }

    public static function exits(): array
    {
        return [
            'إلغاءٌ من العميل' => ['cancel'],
            'الوكيلُ يجدها منتهية' => ['agent_finds_it_expired'],
            'الكنسُ المجدول' => ['scheduled_sweep'],
        ];
    }

    /**
     * @test
     *
     * **④ وانتهاءُ الصلاحيّة عند الوكيل يُثبَّت — لا يُردّ مع الاستثناء.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **العطلُ الذي كُشف هنا:** كان الفكُّ والوسمُ داخلَ معاملة التنفيذ،
     * ثمّ يُرمى الاستثناءُ في السطر التالي فتُردّ المعاملةُ كلُّها. أي أنّ
     * الفرعَ لم يُنجز شيئاً منذ كُتب: يقرأ الوكيلُ «انتهت الصلاحيّة»
     * **ولا يتغيّر شيء** — يبقى الطلبُ `pending` ومالُ العميل محجوزاً.
     *
     * **ولا يمسكه اختبارٌ يتحقّق من الرسالة**: الرسالةُ كانت صحيحة.
     */
    public function an_expiry_found_by_the_agent_actually_persists(): void
    {
        $customer = $this->fundedCustomer('50000');
        $agent = User::factory()->create(['type' => AGENT_TYPE, 'zone_code' => 'SOUTH']);

        $req = $this->service()->request($customer, '10000');
        WithdrawalRequest::where('id', $req->id)->update(['expires_at' => now()->subMinute()]);

        try {
            $this->service()->execute($agent, $req->op_code, '');
            $this->fail('نُفّذ طلبٌ منتهي الصلاحيّة');
        } catch (\RuntimeException $e) {
            // متوقَّع
        }

        $this->assertSame('expired', WithdrawalRequest::find($req->id)->status,
            '**الوسمُ رُدّ مع الاستثناء** — الطلبُ ما زال pending بعد أن قيل للوكيل إنّه انتهى');

        $wallet = EMoney::where('user_id', $customer->id)->first();
        $this->assertSame(0, bccomp((string) $wallet->held_balance, '0', 4),
            '**الحجزُ رُدّ مع الاستثناء** — مالُ العميل ما زال محجوزاً');
        $this->assertSame(0, bccomp((string) $wallet->current_balance, '50000', 4),
            'لم يرجع المالُ إلى متاح العميل');
    }

    /**
     * @test
     *
     * **⑤ ولا يبقى السحبُ النقديُّ مُعلَناً بقعةً عمياء بعد أن سُدّ.**
     *
     * ══════════════════════════════════════════════════════════════════
     * القاعدةُ السابعة مقلوبةً: **اعتذارٌ باطلٌ عن ثغرةٍ أُغلقت يُخفي فرقاً
     * حقيقيّاً**. من قرأ «السحب لا يُرحَّل» في تقرير المصالحة بحث عن
     * الفرق في المكان الخطأ.
     */
    public function the_blind_spot_list_no_longer_apologises_for_this_flow(): void
    {
        $declared = array_keys((array) config('amial.reconciliation.blind_spots', []));

        $this->assertNotContains('CustomerWithdrawService', $declared,
            'السحبُ النقديُّ صار يُرحَّل — وبقاؤه في قائمة البقع العمياء '
            . 'يجعل المصالحةَ تعتذر عن ثغرةٍ أُغلقت، فيُبحث عن الفرق في غير موضعه');
    }

    /**
     * @test
     *
     * **⑥ والدفترُ يبقى متوازناً عبر الدورة كلِّها.**
     */
    public function the_ledger_stays_balanced_across_the_whole_cycle(): void
    {
        $customer = $this->fundedCustomer('50000');
        $agent = User::factory()->create(['type' => AGENT_TYPE, 'zone_code' => 'SOUTH']);

        $a = $this->service()->request($customer, '5000');
        $this->service()->cancel($customer, $a->id);

        $b = $this->service()->request($customer, '7000');
        $this->service()->execute($agent, $b->op_code, '');

        $debits = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $credits = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');

        $this->assertSame(0, bccomp($debits ?: '0', $credits ?: '0', 4),
            'الدفترُ اختلّ: مدينٌ ≠ دائن بعد دورة سحبٍ كاملة');

        $this->assertGreaterThanOrEqual(4,
            LedgerJournalEntry::whereIn('source_type', [
                'cash_out_requested', 'cash_out_released', 'cash_out_executed',
            ])->count(),
            'الدورةُ كتبت قيوداً أقلّ من مخارجها');
    }
}
