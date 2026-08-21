<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PlatformTreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-TREASURY-MAKERCHECKER-001 — **«لا زرَّ واحدٌ يخلق مليارات».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **المحورُ ٣٤ من وثيقة المركز الماليّ** يطلب مساراً لا ضغطة:
 *
 *     Maker → إثباتُ التمويل → Pending Verification → Checker يتحقّق
 *           → معاينةٌ محاسبيّة → اعتماد → ترحيلٌ ذرّيّ → اكتمال
 *
 * **وإصدارُ الرصيد هو الإجراءُ الوحيدُ الذي يخلق مالاً من العدم.** سواه
 * يُحرّك موجوداً أو يفتح باباً. وموظّفٌ واحدٌ يملك زرَّه يملك المنصّةَ
 * كلَّها — لا سرقةً بل خطأً: رقمٌ زائدٌ في خانةٍ يضخّ مليوناً.
 */
class TreasuryMakerCheckerTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maker = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'is_active' => 1,
        ]);

        EMoney::where('user_id', $this->maker->id)->delete();
        EMoney::create([
            'user_id' => $this->maker->id, 'current_balance' => '0.0000',
            'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
            'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0,
        ]);
    }

    private function checker(): User
    {
        return User::factory()->create(['type' => ADMIN_TYPE, 'is_active' => 1]);
    }

    private function treasury(): PlatformTreasuryService
    {
        return app(PlatformTreasuryService::class);
    }

    private function adminBalance(): string
    {
        return (string) EMoney::where('user_id', \App\CentralLogics\Helpers::get_admin_id())
            ->value('current_balance');
    }

    /**
     * @test
     *
     * **① الطلبُ لا يُصدر ريالاً — المالُ يُخلَق لحظةَ الاعتماد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وإصدارٌ يقع عند الطلب ثمّ «يُعتمَد» بعده **ليس أربعَ عيون** — هو
     * توقيعٌ على أمرٍ واقع. والفرقُ بينهما هو كلُّ الحماية.
     */
    public function submitting_a_request_mints_nothing(): void
    {
        $this->checker();
        $before = $this->adminBalance();
        $entriesBefore = LedgerJournalEntry::where('source_type', 'treasury_issuance')->count();

        $out = $this->treasury()->requestIssuance(
            '500000', $this->maker, 'BANK-2026-77', 'إيداعٌ بنكيّ', 'bank_deposit');

        $this->assertSame('pending_approval', $out['mode']);
        $this->assertSame('pending', $out['request']->status);

        $this->assertSame($before, $this->adminBalance(),
            '**خُلق المالُ عند الطلب** — والاعتمادُ بعده توقيعٌ على أمرٍ واقع');
        $this->assertSame($entriesBefore,
            LedgerJournalEntry::where('source_type', 'treasury_issuance')->count(),
            'كُتب قيدُ إصدارٍ قبل أن يعتمده أحد');
    }

    /**
     * @test
     *
     * **② والاعتمادُ من مشرفٍ مختلفٍ هو ما يُصدر.**
     */
    public function approval_by_a_different_admin_is_what_mints(): void
    {
        $checker = $this->checker();

        $out = $this->treasury()->requestIssuance(
            '250000', $this->maker, 'TR-2026-9', 'توريدُ نقد', 'treasury_supply');

        app(ApprovalService::class)->approve($checker, (int) $out['request']->id, 'راجعتُ السند');

        $this->assertSame(0, bccomp($this->adminBalance(), '250000', 4),
            'اعتُمد الطلبُ ولم يقع الإصدار');
        $this->assertSame('approved', ApprovalRequest::find($out['request']->id)->status);
    }

    /**
     * @test
     *
     * **③ ولا يعتمد المُقدِّمُ طلبَه — ولو كان مديرَ المنصّة.**
     */
    public function the_maker_cannot_approve_their_own_issuance(): void
    {
        $this->checker();

        $out = $this->treasury()->requestIssuance(
            '900000', $this->maker, 'SELF-1', 'محاولةُ اعتمادٍ ذاتيّ');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SELF_APPROVAL_FORBIDDEN');

        app(ApprovalService::class)->approve($this->maker, (int) $out['request']->id);
    }

    /**
     * @test
     *
     * **④ والمُراجِعُ يرى القيدَ قبل أن يوقّع — لا رقماً مجرَّداً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * (‏المحورُ ٣٦: `Adjustment Preview`.) مُراجِعٌ يوقّع على «إصدار
     * ٥٠٠٬٠٠٠» لا يعرف أين ستقع. **ورقمٌ بلا قيدٍ يُوقَّع عليه بالثقة لا
     * بالمراجعة** — والثقةُ ليست ضابطاً.
     */
    public function the_checker_sees_the_accounting_preview_before_signing(): void
    {
        $this->checker();

        $out = $this->treasury()->requestIssuance(
            '400000', $this->maker, 'CAP-1', 'ضخُّ رأس مال', 'capital');

        $preview = $out['request']->payload['preview'] ?? null;

        $this->assertNotNull($preview, '**لا معاينة** — يُوقَّع على رقمٍ بلا قيد');
        $this->assertSame('capital', $preview['funding_source']);
        $this->assertCount(2, $preview['lines']);
        $this->assertSame('PLATFORM_CAPITAL', $preview['lines'][0]['account'],
            'المعاينةُ تُظهر حساباً غيرَ الذي سيقع فيه القيد — **وهي أسوأ من غيابها**');
        $this->assertStringContainsString('رأس مال', $preview['effect']);
    }

    /**
     * @test
     *
     * **⑤ ومنصّةٌ بمشرفٍ واحدٍ تُخبَر بالاسم — لا تُحجَب بصمت.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا العطلُ وقع في هذا المشروع من قبل:** أمرُ ترحيل المحافظ اشترط
     * قضيّةً معتمَدةً لا يمكن أن توجد لحظةَ النشر، **فصار حاجزاً يجعل
     * الميزةَ التي يحرسها مستحيلة**. فلا يُترك الطلبُ معلّقاً ينتظر من لا
     * وجودَ له، ويُقال ما العمل.
     */
    public function a_platform_with_a_single_admin_is_told_why_not_silently_blocked(): void
    {
        // لا مشرفَ آخرَ إطلاقاً.
        try {
            $this->treasury()->requestIssuance('1000', $this->maker, 'SOLO-1', 'وحدي');
            $this->fail('قُبل طلبٌ لا يستطيع أحدٌ اعتمادَه');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('لا مشرفَ ثانٍ', $e->getMessage(),
                'الرسالةُ لا تقول السبب — فيُبحث عن عطلٍ في مكانٍ آخر');
            $this->assertStringContainsString('AMIAL_TREASURY_APPROVAL_THRESHOLD', $e->getMessage(),
                'الرسالةُ لا تقول ما العمل');
        }

        $this->assertSame(0, ApprovalRequest::where('action_type', 'treasury_issuance')->count(),
            'أُنشئ طلبٌ معلّقٌ ينتظر من لا وجودَ له');
    }

    /**
     * @test
     *
     * **⑥ والحدُّ استثناءٌ يُضبَط بقرار — والافتراضُ صفرٌ أي «دائماً».**
     */
    public function a_configured_threshold_lets_small_amounts_through(): void
    {
        config(['amial.treasury.approval_threshold' => '10000']);
        $this->checker();

        $small = $this->treasury()->requestIssuance(
            '5000', $this->maker, 'SMALL-1', 'مبلغٌ دون الحدّ');
        $this->assertSame('issued', $small['mode']);
        $this->assertSame(0, bccomp($this->adminBalance(), '5000', 4));

        $big = $this->treasury()->requestIssuance(
            '50000', $this->maker, 'BIG-1', 'مبلغٌ فوق الحدّ');
        $this->assertSame('pending_approval', $big['mode'],
            'مبلغٌ فوق الحدّ مرّ بلا اعتماد — **والحدُّ زينةٌ لا ضابط**');
    }

    /**
     * @test
     *
     * **⑦ والافتراضُ آمن: صفرٌ يعني أنّ كلَّ إصدارٍ يحتاج عينين ثانيتين.**
     *
     * فإعدادٌ افتراضيٌّ متساهلٌ يجعل الحمايةَ تعتمد على من يتذكّر ضبطَها.
     */
    public function the_shipped_default_requires_approval_for_everything(): void
    {
        $this->assertSame(0, bccomp(
            (string) config('amial.treasury.approval_threshold'), '0', 4),
            '**الافتراضُ متساهل** — والحمايةُ صارت تعتمد على من يتذكّر ضبطَها');
    }
}
