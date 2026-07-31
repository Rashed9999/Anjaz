<?php

namespace Tests\Feature;

use App\Models\Ledger\LedgerAccount;
use App\Models\Settlement;
use App\Models\SettlementPartner;
use App\Models\User;
use App\Services\UniversalSettlementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-DUAL-APPROVAL-001 — موافقتان فوق الحدّ الماليّ.
 *
 * **الفرق عن فصل المهام، وهو الذي يُخلط دائماً:**
 * فصلُ المهام يمنع أن يقوم **شخصٌ واحد بخطوتين** (ينشئ ثم يعتمد). والموافقة
 * المزدوجة تشترط أن يقرّر **شخصان في الخطوة الواحدة**.
 *
 * فمنصّةٌ فيها فصلُ مهامٍ كامل تُخرج تسويةً بمليون ريال بتوقيع موظّفٍ واحد —
 * ما دام غيرُه أنشأها. والتواطؤ يحتاج اثنين، والخطأ الفرديّ يلتقطه ثانٍ.
 *
 * **وحدٌّ ماليّ لا اشتراطٌ دائم:** موافقتان على كل تسوية صغيرة تُعطّل العمل،
 * فيُعتمد بالجملة بلا قراءة ويصير الضابط طقساً.
 */
class SettlementDualApprovalTest extends TestCase
{
    use RefreshDatabase;

    private UniversalSettlementService $service;
    private SettlementPartner $partner;
    private LedgerAccount $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UniversalSettlementService::class);
        config(['amial.settlement.dual_approval_threshold' => '100000']);

        $this->source = LedgerAccount::create([
            'account_code' => UniversalSettlementService::REVENUE_WALLET_CODE,
            'account_type' => 'liability',
            'name_ar' => 'محفظة إيرادات أميال',
            'owner_type' => 'platform',
            'normal_balance' => 'credit',
            'current_balance' => '10000000',
        ]);

        $this->partner = SettlementPartner::create([
            'code' => 'DUAL_PARTNER', 'name_ar' => 'صرافة تجريبية',
            'type' => SettlementPartner::TYPE_EXCHANGE, 'currency' => 'YER',
            'is_active' => true,
        ]);
    }

    private function officer(string $name): User
    {
        return User::factory()->create([
            'type' => 0, 'role' => 'super_admin', 'f_name' => $name,
        ]);
    }

    /** ينشئ تسويةً ويقدّمها، ويُرجعها جاهزةً للاعتماد. */
    private function submitted(string $amount, User $creator, User $submitter): Settlement
    {
        $s = $this->service->create($this->source->id, $this->partner->id, $amount, $creator);

        return $this->service->submit($s, $submitter);
    }

    // ── تحت الحدّ: موافقة واحدة تكفي ───────────────────────────────────

    public function test_a_small_settlement_is_approved_by_one_officer(): void
    {
        // ضابطٌ يشترط اثنين على كل شيء يُعطّل العمل فيُلتفّ عليه.
        $s = $this->submitted('50000', $this->officer('منشئ'), $this->officer('مقدِّم'));

        $s = $this->service->approve($s, $this->officer('معتمِد'));

        $this->assertSame(Settlement::STATUS_APPROVED, $s->status,
            'تسويةٌ تحت الحدّ لم تُعتمد بموافقةٍ واحدة');
        $this->assertSame(1, (int) $s->approvals_required);
    }

    // ── فوق الحدّ: واحدة لا تكفي ───────────────────────────────────────

    public function test_a_large_settlement_is_not_approved_by_one_officer(): void
    {
        // هذا هو العطل بعينه: كانت تخرج بتوقيعٍ واحد.
        $s = $this->submitted('500000', $this->officer('منشئ'), $this->officer('مقدِّم'));

        $s = $this->service->approve($s, $this->officer('معتمِد أوّل'));

        $this->assertSame(Settlement::STATUS_PENDING_APPROVAL, $s->status,
            'اعتُمدت تسويةٌ فوق الحدّ بموافقةٍ واحدة — الضابط لا يعمل');
        $this->assertSame(2, (int) $s->approvals_required);
        $this->assertNotNull($s->approved_by, 'لم تُسجَّل الموافقة الأولى');
        $this->assertNull($s->second_approved_by);
        $this->assertTrue($this->service->awaitingSecondApproval($s));
    }

    public function test_a_second_distinct_officer_completes_the_approval(): void
    {
        $s = $this->submitted('500000', $this->officer('منشئ'), $this->officer('مقدِّم'));
        $first = $this->officer('معتمِد أوّل');
        $second = $this->officer('معتمِد ثانٍ');

        $s = $this->service->approve($s, $first);
        $s = $this->service->approve($s, $second);

        $this->assertSame(Settlement::STATUS_APPROVED, $s->status,
            'لم تُعتمد بعد موافقتين — الضابط يمنع الجميع فلا يحرس بل يعطّل');
        $this->assertSame($first->id, (int) $s->approved_by);
        $this->assertSame($second->id, (int) $s->second_approved_by);
        $this->assertFalse($this->service->awaitingSecondApproval($s));
    }

    // ── الالتفاف الأسهل: ضغطُ الزرّ مرّتين ─────────────────────────────

    public function test_the_same_officer_cannot_supply_both_approvals(): void
    {
        // لولا هذا لكفى ضغطُ الزرّ مرّتين، فيصير «موافقتان» عدّاداً لا رقابة.
        // وهو أوّل ما يُجرَّب حين يستعجل الموظّف.
        $s = $this->submitted('500000', $this->officer('منشئ'), $this->officer('مقدِّم'));
        $officer = $this->officer('معتمِد');

        $s = $this->service->approve($s, $officer);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FOUR_EYES_VIOLATION');

        $this->service->approve($s, $officer);
    }

    // ── الالتفاف الأخطر: تغيير الحدّ بعد الإنشاء ───────────────────────

    public function test_raising_the_threshold_midway_does_not_downgrade_a_pending_settlement(): void
    {
        // تسويةٌ أُنشئت وهي تحتاج اثنين، ثم رُفع الحدّ في الإعدادات. لو حُسب
        // الشرط عند الاعتماد لصارت تحتاج واحداً — فتخرج بتوقيعٍ واحد بتغييرِ
        // إعدادٍ لا بقرار. ولذلك يُثبَّت العدد على الصفّ عند الإنشاء.
        $s = $this->submitted('500000', $this->officer('منشئ'), $this->officer('مقدِّم'));

        config(['amial.settlement.dual_approval_threshold' => '9000000']);

        $s = $this->service->approve($s, $this->officer('معتمِد أوّل'));

        $this->assertSame(Settlement::STATUS_PENDING_APPROVAL, $s->status,
            'خرجت التسوية بموافقةٍ واحدة بعد رفع الحدّ — الشرط يُحسب عند '
            . 'الاعتماد لا يُثبَّت عند الإنشاء');
    }

    // ── الإعداد الناقص يُشدّد لا يُرخي ─────────────────────────────────

    public function test_a_zero_threshold_means_always_two_not_always_one(): void
    {
        // حدٌّ غير مضبوط (0) يقرأه المرء «لا حدّ» فيُفهم «واحدة تكفي دائماً».
        // والصواب عكسه: من نسي الضبط لا يُعاقَب بتسويةٍ خرجت بتوقيعٍ واحد.
        config(['amial.settlement.dual_approval_threshold' => '0']);

        $s = $this->submitted('100', $this->officer('منشئ'), $this->officer('مقدِّم'));

        $this->assertSame(2, (int) $s->approvals_required,
            'حدٌّ صفر جعل الموافقة واحدة — الإعداد الناقص أرخى ولم يُشدّد');
    }

    // ── والتنفيذ يبقى لثالث ────────────────────────────────────────────

    public function test_neither_approver_may_execute_the_settlement(): void
    {
        // الموافقة المزدوجة بلا فصلٍ عن التنفيذ نصفُ ضابط: من وافق ثانياً
        // يستطيع إخراج المال بيده.
        $s = $this->submitted('500000', $this->officer('منشئ'), $this->officer('مقدِّم'));
        $first = $this->officer('معتمِد أوّل');
        $second = $this->officer('معتمِد ثانٍ');

        $s = $this->service->approve($s, $first);
        $s = $this->service->approve($s, $second);

        foreach ([$first, $second] as $approver) {
            try {
                $this->service->startProcessing($s, $approver);
                $this->fail('نفّذ التسويةَ من اعتمدها');
            } catch (DomainException $e) {
                $this->assertSame('FOUR_EYES_VIOLATION', $e->getMessage());
            }
        }
    }
}
