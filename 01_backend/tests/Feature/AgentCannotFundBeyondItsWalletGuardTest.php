<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentNetworkService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-AGENT-FUND-CEILING-001 — **«يشحن فروعاً بلا نهاية» — قِيست.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الدعوى كما وصلت:** «يستطيع الوكيلُ شحنَ فروعٍ برصيدٍ إلكترونيٍّ ما
 * لا نهاية، وهذا خطرٌ كارثيّ».
 *
 * **وقياسُها ردّها:** `AgentBranchService::fundBranch` تقرأ رصيدَ الأمّ
 * وترفض إن لم يكفِ، **وتُعيد الفحصَ داخل القفل** — فلا يمرّ طلبان
 * متزامنان بفحصٍ واحد.
 *
 * **لكنّ الدعوى أصابت شيئاً أخطرَ من نفسِها: لا حارسَ يُثبت السقف.**
 * صفرُ اختبارٍ في المشروع كلِّه يحاول تجاوزَه — و`AgentFundingHierarchy
 * Test` يشحن مبالغَ ضمن الرصيد فحسب. فالسقفُ **قائمٌ بلا شاهد**: من
 * حذفه غداً لا يسقط عليه شيء.
 *
 * **ولمَ يهمّ أكثرَ من غيره:** رصيدٌ إلكترونيٌّ يُخلَق من العدم يعني
 * التزاماً على المنصّة بلا مقابلٍ في الخزانة، **ولا يظهر في أيّ شاشة**
 * — لأنّ محرّكَ التسوية يقرأ الوكيلَ وفروعَه كتلةً واحدة. (القاعدة
 * العاشرة.)
 */
class AgentCannotFundBeyondItsWalletGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $company;

    private AgentBranch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770008801',
        ]);

        $this->company = User::factory()->create([
            'type' => 2, 'role' => 'agent', 'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        $branchUser = User::factory()->create([
            'type' => 2, 'role' => 'agent', 'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        foreach ([$this->company, $branchUser] as $u) {
            EMoney::create([
                'user_id' => $u->id, 'current_balance' => '0',
                'pending_balance' => '0', 'zone_code' => 'SOUTH',
            ]);
        }

        $this->branch = AgentBranch::create([
            'agent_user_id' => $this->company->id,
            'branch_user_id' => $branchUser->id,
            'name' => 'فرعُ القياس',
            'code' => 'BR-CEIL',
            'is_active' => true,
        ]);
    }

    private function walletOf(User $u): string
    {
        return (string) (EMoney::where('user_id', $u->id)->value('current_balance') ?? '0');
    }

    /**
     * **الرصيدُ يُصنَع من مصدره لا يُكتب في العمود.**
     *
     * ══════════════════════════════════════════════════════════════════
     * أوّلُ صياغةٍ هنا ضبطت `EMoney.current_balance` بـ`update()` مباشرةً،
     * فسقطت الحالاتُ بـ«Insufficient balance in USER_WALLET». **والشيفرةُ
     * محقّةٌ لا المُثبَّت**: `current_balance` **إسقاطٌ** لا مصدر، والمصدرُ
     * الدفتر. فمن كتب العمودَ وحدَه صنع حالةً لا وجودَ لها في الإنتاج.
     *
     * **وهذا يخصّ الدعوى نفسَها**: للسقف **حاجزان لا واحد** —
     * فحصُ الخدمة على الرصيد، **وحارسُ الدفتر الذي يرفض حساباً سالباً**.
     * فحذفُ الأوّل غداً لا يفتح الباب: يردُّه الدفترُ من عمقه.
     * ══════════════════════════════════════════════════════════════════
     */
    private function giveCompany(string $amount): void
    {
        app(AgentNetworkService::class)
            ->adminCreditAgent($this->company, $amount, $this->admin);
    }

    private function fund(string $amount): void
    {
        app(AgentBranchService::class)
            ->fundBranch($this->branch, $this->company, $amount, 'قياسُ السقف');
    }

    /**
     * **① الحالةُ قبل الفحص — وإلّا فحصنا العدم.**
     */
    /** @test */
    public function the_company_really_has_the_balance_it_is_said_to_have(): void
    {
        $this->giveCompany('5000');

        $this->assertSame('5000.0000', $this->walletOf($this->company),
            'لم يُضبَط الرصيدُ — فالحالاتُ التالية تفحص فراغاً.');
    }

    /**
     * **② وشحنٌ يفوق الرصيدَ يُردّ.**
     */
    /** @test */
    public function funding_more_than_the_company_holds_is_refused(): void
    {
        $this->giveCompany('5000');

        try {
            $this->fund('5000.0001');

            $this->fail('**شُحن فرعٌ بأكثرَ ممّا تملك الشركة** — فالرصيدُ '
                .'الإلكترونيُّ يُخلَق من العدم، وهو التزامٌ على المنصّة بلا '
                .'مقابلٍ في الخزانة.');
        } catch (\Throwable $e) {
            // ══════════════════════════════════════════════════════════
            // **يُلتقَط `Throwable` لا `DomainException` — والسببُ مقيس.**
            //
            // جُرّب بالعكس فظهر أنّ للسقف **ثلاث طبقات**:
            //   ① فحصُ الخدمة قبل القفل
            //   ② وإعادتُه داخل القفل — ونزعُ ① وحدَه لا يُمرّر شيئاً
            //   ③ **وحارسُ الدفتر** يرفض حساب أصلٍ سالباً من عمقه
            //
            // فبنزع ① و② معاً **لا يُخلَق ريالٌ بعد** — يردُّه الدفتر.
            // **لكنّ الرسالةَ تصير إنجليزيّةً من `LedgerService`**، وهي
            // العطلُ الحقيقيُّ الباقي: حمايةٌ قائمةٌ ورسالةٌ مفقودة، ترى
            // الإدارةُ العامّة `Insufficient balance in USER_WALLET_1`.
            //
            // فالمحروسُ هنا **الرسالةُ** لا المنعُ وحدَه.
            // ══════════════════════════════════════════════════════════
            $this->assertStringContainsString('لا يكفي', $e->getMessage(), sprintf(
                "**رُدَّ الشحنُ برسالةٍ ليست للمدير:**\n  %s\n\n"
                ."والمنعُ وقع (لا ريالَ خُلق)، **والرسالةُ ضاعت** — فيقرأ "
                .'صاحبُ الشركة خطأً إنجليزيّاً من الدفتر ولا يعرف كم ينقصه.',
                $e->getMessage()));

            // **وتقول الرقمين** — «لا يكفي» وحدَها تجعله يُخمّن.
            $this->assertStringContainsString('5000', $e->getMessage());
        }
    }

    /**
     * **③ ولا يتحرّك ريالٌ عند الردّ.**
     *
     * فرفضٌ يترك خصماً نصفيّاً أسوأ من قبولٍ كامل: المالُ يختفي من
     * الأمّ ولا يصل الفرعَ، **ولا يظهر في أيّ شاشة**.
     */
    /** @test */
    public function nothing_moves_at_all_when_the_ceiling_refuses(): void
    {
        $this->giveCompany('5000');

        $before = [$this->walletOf($this->company),
            (string) (EMoney::where('user_id', $this->branch->branch_user_id)
                ->value('current_balance') ?? '0')];

        try { $this->fund('9000'); } catch (\Throwable) { /* متوقَّع */ }

        $after = [$this->walletOf($this->company),
            (string) (EMoney::where('user_id', $this->branch->branch_user_id)
                ->value('current_balance') ?? '0')];

        $this->assertSame($before, $after,
            '**تحرّك مالٌ في عمليّةٍ مرفوضة** — فالتراجعُ ناقص.');
    }

    /**
     * **④ وشحنٌ بكلّ الرصيد يمرّ — فالسقفُ سقفٌ لا قفل.**
     *
     * (حارسٌ يُثبت المنعَ ولا يُثبت السماحَ يقبل «امنع كلَّ شيء» علاجاً.)
     */
    /** @test */
    public function funding_exactly_the_whole_balance_is_allowed(): void
    {
        $this->giveCompany('5000');

        $this->fund('5000');

        $this->assertSame('0.0000', $this->walletOf($this->company));
        $this->assertSame('5000.0000',
            (string) EMoney::where('user_id', $this->branch->branch_user_id)
                ->value('current_balance'));
    }

    /**
     * **⑤ والمجموعُ محفوظ — لا يُخلَق ريالٌ ولا يُفقَد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا هو الثابتُ الحقيقيُّ خلف الدعوى: الشحنُ **نقلٌ** لا إصدار.
     * فحارسٌ يفحص الرصيدين منفصلَين يمرّ على خللٍ يزيد أحدَهما دون أن
     * ينقص الآخر.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_two_wallets_together_never_grow(): void
    {
        $this->giveCompany('5000');

        $sum = fn () => bcadd($this->walletOf($this->company),
            (string) (EMoney::where('user_id', $this->branch->branch_user_id)
                ->value('current_balance') ?? '0'), 4);

        $before = $sum();

        $this->fund('1200');
        $this->fund('800');

        $this->assertSame($before, $sum(),
            '**نما المجموعُ بعد شحنتين** — فالشحنُ يُصدر رصيداً لا ينقله.');
    }
}
