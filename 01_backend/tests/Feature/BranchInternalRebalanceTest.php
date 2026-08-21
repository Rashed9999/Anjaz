<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\User;
use App\Services\AgentDailySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-BRANCH-BALANCE-001 — **صفرُ الشركة لا يعني توازنَ الفروع.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **هذه حالةُ اختبارٍ تطلبها وثيقةُ الوكيل نصّاً (المحور ٧):**
 *
 *     فرع A: ‎+١٬٠٠٠٬٠٠٠ نقداً ويحتاج Float.
 *     فرع B: ‎−١٬٠٠٠٬٠٠٠ نقداً ولديه Float زائد.
 *     صافي الشركة: صفر. والتسويةُ مع أميال: صفر.
 *     **لكن يجب أن تظهر خطّةُ إعادة توازنٍ داخليّة.**
 *     لا تعرض «كلُّ شيءٍ متعادل» إذا كانت الفروعُ نفسُها غيرَ متوازنة.
 *
 * **وليس هذا نقصَ عرض.** فرعٌ بلا ورقٍ يردّ كلَّ ساحبٍ يقف أمامه، وفرعٌ
 * بلا رصيدٍ إلكترونيٍّ يردّ كلَّ مودِع — **واللوحةُ تقول إنّ اليومَ
 * متعادل**. والعميلُ الذي رُدّ لا يعرف أنّ الحلَّ على بعد شارع.
 */
class BranchInternalRebalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = User::factory()->create(['type' => AGENT_TYPE, 'zone_code' => 'SOUTH']);
    }

    /**
     * **بمساره الحقيقيّ لا بكتابةٍ في الجدول.** للفرع حسابُ مستخدمٍ
     * ومحفظةٌ ودرجٌ وملفُّ أبوّة — وصفٌّ يُكتب مباشرةً يُنتج فرعاً بلا
     * درج، فتُفحَص حالةٌ لا وجودَ لها في الإنتاج.
     */
    private function branch(string $name): AgentBranch
    {
        static $n = 0;
        $n++;

        return app(\App\Services\AgentBranchService::class)->create($this->agent, [
            'name' => $name,
            'code' => 'BR'.$n.substr(md5($name.microtime()), 0, 4),
            'phone' => '9677715'.str_pad((string) (10000 + $n), 5, '0', STR_PAD_LEFT),
            'password' => 'Passw0rd!2026',
        ]);
    }

    private function move(AgentBranch $b, string $reason, string $amount): void
    {
        AgentCashMovement::create([
            'branch_id' => $b->id,
            'is_drawer' => true,
            'direction' => $reason === 'customer_deposit' ? 'in' : 'out',
            'reason' => $reason,
            'amount' => $amount,
            'balance_before' => '0',
            'balance_after' => '0',
            'reference' => 'T-'.uniqid(),
            // كلُّ حركةِ نقدٍ لها فاعلٌ — لا حركةَ بلا من فعلها.
            'actor_user_id' => $b->branch_user_id,
            'created_at' => now(),
        ]);
    }

    private function day(): array
    {
        return app(AgentDailySettlementService::class)
            ->computeDay($this->agent, now()->toDateString());
    }

    /**
     * @test
     *
     * **① الحالةُ التي تطلبها الوثيقةُ حرفاً: صفرٌ للشركة وميلٌ في الفرعين.**
     */
    public function a_company_that_nets_zero_still_shows_an_internal_imbalance(): void
    {
        $a = $this->branch('فرع أ');
        $b = $this->branch('فرع ب');

        $this->move($a, 'customer_deposit', '1000000');   // ورقٌ زائد
        $this->move($b, 'customer_withdraw', '1000000');  // ورقٌ ناقص

        $day = $this->day();

        // التسويةُ الخارجيّةُ صفرٌ فعلاً — وهذا صحيح.
        $this->assertSame(0, bccomp($day['net_cash'], '0', 4));
        $this->assertSame('none', $day['conversion']);

        // **ومع ذلك الشبكةُ ليست متوازنة.**
        $this->assertTrue($day['internal_rebalance']['needed'],
            '**«كلُّ شيءٍ متعادل»** على شبكةٍ فرعُها الأوّلُ يعجز عن الصرف '
            . 'وفرعُها الثاني يعجز عن الإيداع — والعميلُ يُردّ في كليهما');

        $moves = $day['internal_rebalance']['moves'];
        $this->assertCount(1, $moves, 'خطّةٌ بأكثر من نقلةٍ لفرعين متقابلين');
        $this->assertSame('فرع أ', $moves[0]['from_branch'],
            'النقلُ من الفرع صاحبِ الورق الزائد لا إليه');
        $this->assertSame('فرع ب', $moves[0]['to_branch']);
        $this->assertSame(0, bccomp($moves[0]['amount'], '1000000', 4));
    }

    /**
     * @test
     *
     * **② وكلُّ فرعٍ يُقال ما ينقصه — لا رقمٌ مجرَّد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * «‎+١٬٠٠٠٬٠٠٠» وحدَه لا يقول للمدير ماذا يرسل. **والقيدان متعاكسان**:
     * الورقُ يحدّ السحبَ والإلكترونيُّ يحدّ الإيداع. فيُقال أيُّهما نقص.
     */
    public function each_branch_states_which_of_the_two_liquidities_it_lacks(): void
    {
        $a = $this->branch('فرع أ');
        $b = $this->branch('فرع ب');

        $this->move($a, 'customer_deposit', '400000');
        $this->move($b, 'customer_withdraw', '400000');

        $pos = collect($this->day()['branch_positions'])->keyBy('name');

        $this->assertSame('float', $pos['فرع أ']['need'],
            'فرعٌ ورقُه زائدٌ يحتاج رصيداً إلكترونيّاً — لا العكس');
        $this->assertSame('cash', $pos['فرع ب']['need'],
            'فرعٌ ورقُه ناقصٌ يحتاج نقداً ورقيّاً');
    }

    /**
     * @test
     *
     * **③ وميلٌ في اتّجاهٍ واحدٍ ليس مشكلةً داخليّة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فرعان كلاهما ورقُه زائدٌ لا يُصلحهما نقلٌ بينهما — **يُصلحهما
     * `topup` مع أميال**. واقتراحُ نقلٍ هنا يُرسل سائقاً بلا فائدة.
     */
    public function a_one_sided_tilt_is_settled_with_the_platform_not_between_branches(): void
    {
        $a = $this->branch('فرع أ');
        $b = $this->branch('فرع ب');

        $this->move($a, 'customer_deposit', '300000');
        $this->move($b, 'customer_deposit', '200000');

        $day = $this->day();

        $this->assertSame('topup', $day['conversion'],
            'ورقٌ زائدٌ في الشبكة كلِّها يُسوّى بـtopup');
        $this->assertFalse($day['internal_rebalance']['needed'],
            'اقتُرح نقلٌ بين فرعين كلاهما ورقُه زائد — سائقٌ يسير بلا فائدة');
        $this->assertStringContainsString('أميال', $day['internal_rebalance']['reason']);
    }

    /**
     * @test
     *
     * **④ وما لا يُغطّيه النقلُ الداخليُّ يُسمّى — لا يُسكت عنه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * بقيّةٌ مسكوتٌ عنها تُقرأ «اكتملت الخطّة» وهي لم تكتمل، فيُغلق
     * المديرُ اليومَ وفرعٌ ما زال عاجزاً.
     */
    public function whatever_the_internal_moves_cannot_cover_is_named(): void
    {
        $a = $this->branch('فرع أ');
        $b = $this->branch('فرع ب');

        $this->move($a, 'customer_deposit', '900000');   // ‎+٩٠٠٬٠٠٠
        $this->move($b, 'customer_withdraw', '200000');  // ‎−٢٠٠٬٠٠٠

        $r = $this->day()['internal_rebalance'];

        $this->assertTrue($r['needed']);
        $this->assertSame(0, bccomp($r['moves'][0]['amount'], '200000', 4),
            'النقلُ الداخليُّ يُغطّي أصغرَ الطرفين لا أكبرَهما');

        $this->assertNotEmpty($r['unmatched'],
            '**بقيّةٌ مسكوتٌ عنها**: ٧٠٠٬٠٠٠ لم يُغطّها النقلُ ولم تُذكر');
        $this->assertSame(0, bccomp($r['unmatched'][0]['amount'], '700000', 4));
    }

    /**
     * @test
     *
     * **⑤ وأقلُّ عددِ نقلاتٍ ممكن — فالنقلُ حركةُ نقدٍ لا سطرٌ في تقرير.**
     *
     * ══════════════════════════════════════════════════════════════════
     * لكلّ نقلةٍ سائقٌ ومخاطرةُ طريق. **فعشرُ نقلاتٍ صغيرةٍ أسوأ من
     * نقلتين كبيرتين** ولو تساوى المجموع.
     */
    public function the_plan_prefers_fewer_larger_moves(): void
    {
        $big = $this->branch('الكبير');
        $s1 = $this->branch('صغير ١');
        $s2 = $this->branch('صغير ٢');

        $this->move($big, 'customer_deposit', '1000000');
        $this->move($s1, 'customer_withdraw', '600000');
        $this->move($s2, 'customer_withdraw', '400000');

        $moves = $this->day()['internal_rebalance']['moves'];

        $this->assertCount(2, $moves, 'خطّةٌ مجزّأةٌ أكثر ممّا يلزم');
        $this->assertSame(0, bccomp($moves[0]['amount'], '600000', 4),
            'لم تُغلق أكبرُ حاجةٍ أوّلاً');
    }

    /**
     * @test
     *
     * **⑥ ويومٌ بلا فروعٍ ليس يوماً متوازناً.**
     *
     * (‏القاعدة السابعة: «غير معروف» ليس صفراً — ومفتاحٌ غائبٌ يُقرأ
     * `undefined` في الشاشة فيُعرَض «متوازن».)
     */
    public function a_day_with_no_branches_says_so_rather_than_reading_balanced(): void
    {
        $day = $this->day();

        $this->assertArrayHasKey('internal_rebalance', $day,
            'المفتاحُ غائبٌ في يومٍ فارغ — والشاشةُ تقرأ undefined فتعرض «متوازن»');
        $this->assertFalse($day['internal_rebalance']['needed']);
        $this->assertStringContainsString('لا فرعَ', $day['internal_rebalance']['reason']);
    }
}
