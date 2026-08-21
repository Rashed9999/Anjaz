<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FamilyFund;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\User;
use App\Services\FamilyFundService;
use App\Services\InstallmentService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * AMIAL-LEDGER-BLINDSPOTS-002 — **البقعتان الباقيتان: الأقساط والحوض.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كلتاهما تُحرّك مالاً حقيقيّاً بين محافظ، **ولا تكتب قيداً**. وأخطرُهما
 * صندوقُ العائلة: المالُ يخرج من محفظة العضو ويدخل **عموداً في جدول**
 * (`family_funds.balance`) لا محفظةَ أحد. فالدفترُ يراه يغادر ولا يراه
 * يصل — ويبقى **متوازناً** لأنّه لا يعرف أنّ الحوضَ موجود.
 *
 * وهذا هو الفرقُ بين «الدفترُ متوازن» و«الدفترُ يرى كلَّ شيء»: الأوّلُ
 * يقول إنّ ما كُتب صحيح، ولا يقول شيئاً عمّا لم يُكتب.
 */
class PooledAndInstalmentLedgerCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function funded(int $type, string $balance = '100000'): User
    {
        $u = User::factory()->create([
            'type' => $type, 'zone_code' => 'SOUTH', 'is_kyc_verified' => 1, 'kyc_tier' => 2,
        ]);
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

    private function totals(): array
    {
        return [
            (string) LedgerEntryLine::where('direction', 'debit')->sum('amount') ?: '0',
            (string) LedgerEntryLine::where('direction', 'credit')->sum('amount') ?: '0',
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // الأقساط
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **① الدفعةُ الأولى وكلُّ قسطٍ بعدها يُرحَّلان.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وأخطرُ ما يُفحص هنا القسطُ الثاني.** مفتاحُ تفرّدٍ برقم العقد وحدَه
     * يجعل الترحيلَ يقع مرّةً ويُبتلع بعدها **صامتاً**: يتحرّك المالُ في
     * المحافظ ولا يُكتب قيد، **ويبدو المسارُ مغطّىً وليس كذلك** — وهو
     * أسوأ من ألّا يُرحَّل أصلاً.
     */
    public function every_instalment_payment_is_posted_not_just_the_first(): void
    {
        $merchant = $this->funded(MERCHANT_TYPE, '0');
        $customer = $this->funded(CUSTOMER_TYPE, '100000');

        $svc = app(InstallmentService::class);
        // التقسيطُ مُطفأٌ افتراضاً — يُفعَّل بمساره الحقيقيّ لا بكتابةٍ في الجدول.
        $svc->savePlan($merchant, [
            'is_active' => true, 'min_amount' => '0', 'max_amount' => '1000000',
            'down_payment_percent' => '20', 'durations' => [3, 6, 12],
            'markup_percent' => '5', 'require_kyc' => false, 'require_guarantor' => false,
        ]);

        $contract = $svc->createContract($merchant, $customer, 30000, 3);

        $merchantAccount = 'USER_WALLET_'.$merchant->id;
        $afterDown = $this->balanceOf($merchantAccount);

        $this->assertSame(0, bccomp($afterDown, (string) $contract->down_payment, 4),
            'الدفعةُ الأولى لم تُرحَّل');

        $svc->payInstallment($customer, $contract, '5000');
        $afterFirst = $this->balanceOf($merchantAccount);

        $svc->payInstallment($customer, $contract->fresh(), '5000');
        $afterSecond = $this->balanceOf($merchantAccount);

        $this->assertSame(0, bccomp(bcsub($afterFirst, $afterDown, 4), '5000', 4),
            'القسطُ الأوّل لم يُرحَّل');

        $this->assertSame(0, bccomp(bcsub($afterSecond, $afterFirst, 4), '5000', 4),
            '**القسطُ الثاني ابتُلع** — مفتاحُ التفرّد لا يميّز بين سدادين '
            . 'على العقد نفسِه، فالمالُ يتحرّك والدفترُ لا يراه');
    }

    /** @test **② ولا يختلّ الدفترُ بها.** */
    public function the_ledger_stays_balanced_through_an_instalment_contract(): void
    {
        $merchant = $this->funded(MERCHANT_TYPE, '0');
        $customer = $this->funded(CUSTOMER_TYPE, '100000');

        $svc = app(InstallmentService::class);
        // التقسيطُ مُطفأٌ افتراضاً — يُفعَّل بمساره الحقيقيّ لا بكتابةٍ في الجدول.
        $svc->savePlan($merchant, [
            'is_active' => true, 'min_amount' => '0', 'max_amount' => '1000000',
            'down_payment_percent' => '20', 'durations' => [3, 6, 12],
            'markup_percent' => '5', 'require_kyc' => false, 'require_guarantor' => false,
        ]);

        $contract = $svc->createContract($merchant, $customer, 30000, 3);
        $svc->payInstallment($customer, $contract, '4000');

        [$d, $c] = $this->totals();
        $this->assertSame(0, bccomp($d, $c, 4), 'مدينٌ ≠ دائن بعد عقد تقسيط');
    }

    // ══════════════════════════════════════════════════════════════════
    // حوض صندوق العائلة
    // ══════════════════════════════════════════════════════════════════

    private function fundWithMember(User $owner): FamilyFund
    {
        $fund = app(FamilyFundService::class)->create($owner, 'صندوق الاختبار');

        return $fund instanceof FamilyFund ? $fund : FamilyFund::latest('id')->first();
    }

    /**
     * @test
     *
     * **③ المساهمةُ تدخل حوضاً مُقيَّداً — لا تتبخّر.**
     *
     * ══════════════════════════════════════════════════════════════════
     * والحوضُ **التزامٌ** لا أصلٌ ولا حقوقُ ملكيّة: مالُ الأعضاء بعهدة
     * المنصّة، لا يصير ملكاً لها لحظةً واحدة. وتصنيفُه حقوقَ ملكيّةٍ يجعل
     * كلَّ ريالٍ يودعه الناسُ يُقرأ **ثروةً للمنصّة** في كلّ تقرير.
     */
    public function a_contribution_lands_in_a_liability_pool_account(): void
    {
        $owner = $this->funded(CUSTOMER_TYPE, '50000');
        $fund = $this->fundWithMember($owner);

        $walletBefore = $this->balanceOf('USER_WALLET_'.$owner->id);

        app(FamilyFundService::class)->contribute($fund, $owner, '8000');

        $pool = 'FAMILY_FUND_'.$fund->id;

        $this->assertSame(0, bccomp($this->balanceOf($pool), '8000', 4),
            'المساهمةُ لم تدخل حوضَ الصندوق في الدفتر — خرجت من المحفظة إلى العدم');

        $this->assertSame(0, bccomp(
            bcsub($walletBefore, $this->balanceOf('USER_WALLET_'.$owner->id), 4), '8000', 4),
            'محفظةُ العضو لم تنزل في الدفتر بمقدار المساهمة');

        $account = LedgerAccount::where('account_code', $pool)->first();
        $this->assertSame('liability', $account->account_type,
            '**الحوضُ صُنّف غيرَ التزام** — ومالُ الأعضاء يُقرأ عندئذٍ ثروةً للمنصّة');
    }

    /**
     * @test
     *
     * **④ وكلُّ مساهمةٍ تُرحَّل — لا الأولى وحدَها.**
     */
    public function repeated_contributions_are_each_posted(): void
    {
        $owner = $this->funded(CUSTOMER_TYPE, '50000');
        $fund = $this->fundWithMember($owner);
        $svc = app(FamilyFundService::class);

        $svc->contribute($fund->fresh(), $owner, '3000');
        $svc->contribute($fund->fresh(), $owner, '4000');

        $this->assertSame(0, bccomp($this->balanceOf('FAMILY_FUND_'.$fund->id), '7000', 4),
            '**المساهمةُ الثانية ابتُلعت** — مفتاحُ تفرّدٍ برقم الصندوق يبتلع '
            . 'كلَّ مساهمةٍ بعد الأولى، فيبدو الحوضُ مغطّىً وليس كذلك');
    }

    /**
     * @test
     *
     * **⑤ والدفترُ متوازنٌ والحوضُ يطابق الجدول.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذه المقارنةُ هي المقصود: `family_funds.balance` عمودٌ مخزَّن،
     * ورصيدُ الحوض في الدفتر **يُحسب من القيود**. وتطابقُهما هو ما يجعل
     * الرقمَ المعروضَ قابلاً للإثبات. (‏القاعدة السادسة.)
     */
    public function the_pool_ledger_balance_matches_the_stored_column(): void
    {
        $owner = $this->funded(CUSTOMER_TYPE, '50000');
        $fund = $this->fundWithMember($owner);
        $svc = app(FamilyFundService::class);

        $svc->contribute($fund->fresh(), $owner, '9000');
        $svc->contribute($fund->fresh(), $owner, '1500');

        $stored = (string) FamilyFund::find($fund->id)->balance;

        $this->assertSame(0, bccomp($this->balanceOf('FAMILY_FUND_'.$fund->id), $stored, 4),
            'رصيدُ الحوض في الدفتر لا يطابق العمودَ المخزَّن — **وأحدُهما يكذب**');

        [$d, $c] = $this->totals();
        $this->assertSame(0, bccomp($d, $c, 4), 'مدينٌ ≠ دائن بعد مساهمات الصندوق');
    }

    /**
     * @test
     *
     * **⑥ ولا تبقى القائمةُ تعتذر عمّا سُدّ.**
     */
    public function the_blind_spot_list_no_longer_names_these_flows(): void
    {
        $declared = array_keys((array) config('amial.reconciliation.blind_spots', []));

        foreach (['InstallmentService', 'FamilyFundService', 'SplitBillService'] as $service) {
            $this->assertNotContains($service, $declared,
                "«{$service}» صار يُرحَّل — وبقاؤه في القائمة يجعل المصالحةَ "
                . 'تعتذر عن ثغرةٍ أُغلقت، فيُبحث عن الفرق في غير موضعه');
        }
    }
}
