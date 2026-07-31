<?php

namespace Tests\Feature;

use App\Models\Ledger\LedgerAccount;
use App\Models\Settlement;
use App\Models\SettlementAuditLog;
use App\Models\SettlementPartner;
use App\Models\User;
use App\Services\UniversalSettlementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FOUR-EYES-002 — فصل المهام في التسويات.
 *
 * **ما كان قائماً:** شرطٌ واحد — من أنشأ التسوية لا يعتمدها.
 *
 * **الثغرتان اللتان يسدّهما هذا الملفّ:**
 *
 * 1. **من قدّم يعتمد.** يُنشئ موظّفٌ التسوية ويقدّمها موظّفٌ ثانٍ ثم يعتمدها
 *    هو نفسه. المنشئ مختلف فيمرّ شرط `created_by`، بينما صار القرار كلّه
 *    بيد شخصٍ واحد فعلياً. وفحصُ حقلٍ واحد يرى الاسم لا يرى المسار.
 *
 * 2. **من اعتمد ينفّذ.** التنفيذ هو ما يُخرج المال؛ فمن جمعه مع الاعتماد
 *    استطاع أن يعتمد ثم يُخرج المال بلا أن يمرّ على أحد. والاعتماد بلا
 *    تنفيذٍ منفصل توقيعٌ على ورقة لا حارسٌ على خزنة.
 *
 * ويُقرأ التاريخ من `settlement_audit_logs` لا من أعمدة `approved_by` وأخواتها:
 * العمود يُكتب فوقه عند القرار التالي فيضيع التاريخ، والسجلّ لا يُعدَّل.
 */
class SettlementFourEyesTest extends TestCase
{
    use RefreshDatabase;

    private UniversalSettlementService $service;
    private SettlementPartner $partner;
    private LedgerAccount $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UniversalSettlementService::class);

        $this->source = LedgerAccount::create([
            'account_code'    => UniversalSettlementService::REVENUE_WALLET_CODE,
            'account_type'    => 'liability',
            'name_ar'         => 'محفظة إيرادات أميال',
            'owner_type'      => 'platform',
            'normal_balance'  => 'credit',
            'current_balance' => '10000000',
        ]);

        $this->partner = SettlementPartner::create([
            'code' => 'FOUR_EYES_PARTNER', 'name_ar' => 'صرافة تجريبية',
            'type' => SettlementPartner::TYPE_EXCHANGE, 'currency' => 'YER',
            'is_active' => true,
        ]);
    }

    /** المدير المالي = type 0 أو دور إداريّ (assertIsFinanceManager). */
    private function financeManager(string $name): User
    {
        return User::factory()->create([
            'type' => 0, 'role' => 'super_admin', 'f_name' => $name,
        ]);
    }

    private function draftBy(User $creator): Settlement
    {
        return $this->service->create(
            $this->source->id, $this->partner->id, '5000', $creator,
        );
    }

    // ── الثغرة الأولى: من قدّم لا يعتمد ─────────────────────────────────

    public function test_the_submitter_cannot_approve_even_when_someone_else_created_it(): void
    {
        $creator = $this->financeManager('منشئ');
        $submitter = $this->financeManager('مقدِّم');

        $s = $this->draftBy($creator);
        $s = $this->service->submit($s, $submitter);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FOUR_EYES_VIOLATION');

        $this->service->approve($s, $submitter);
    }

    public function test_a_third_person_may_approve(): void
    {
        // الحدّ يُختبر من جهتيه: ضابطٌ يمنع الجميع يوقف العمل لا يحرسه.
        $creator = $this->financeManager('منشئ');
        $submitter = $this->financeManager('مقدِّم');
        $approver = $this->financeManager('معتمِد');

        $s = $this->draftBy($creator);
        $s = $this->service->submit($s, $submitter);
        $s = $this->service->approve($s, $approver);

        $this->assertSame(Settlement::STATUS_APPROVED, $s->status);
        $this->assertSame($approver->id, (int) $s->approved_by);
    }

    // ── الثغرة الثانية: من اعتمد لا ينفّذ ───────────────────────────────

    public function test_the_approver_cannot_start_processing(): void
    {
        $creator = $this->financeManager('منشئ');
        $submitter = $this->financeManager('مقدِّم');
        $approver = $this->financeManager('معتمِد');

        $s = $this->draftBy($creator);
        $s = $this->service->submit($s, $submitter);
        $s = $this->service->approve($s, $approver);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FOUR_EYES_VIOLATION');

        $this->service->startProcessing($s, $approver);
    }

    public function test_a_different_officer_may_start_processing(): void
    {
        $creator = $this->financeManager('منشئ');
        $submitter = $this->financeManager('مقدِّم');
        $approver = $this->financeManager('معتمِد');
        $executor = $this->financeManager('منفِّذ');

        $s = $this->draftBy($creator);
        $s = $this->service->submit($s, $submitter);
        $s = $this->service->approve($s, $approver);
        $s = $this->service->startProcessing($s, $executor);

        $this->assertSame(Settlement::STATUS_PROCESSING, $s->status);
    }

    // ── الضابط القديم ما زال قائماً ────────────────────────────────────

    public function test_the_creator_still_cannot_approve(): void
    {
        $creator = $this->financeManager('منشئ');

        $s = $this->draftBy($creator);
        $s = $this->service->submit($s, $creator);

        // يُرمى إمّا RuntimeException (شرط created_by) وإمّا DomainException
        // (ضابط الأربع أعين) — والمهمّ أن يُمنع، لا أيّهما سبق.
        $this->expectException(\Throwable::class);
        $this->service->approve($s, $creator);
    }

    // ── الأساس الذي يقوم عليه كل ما سبق ────────────────────────────────

    public function test_the_audit_trail_actually_records_who_did_what(): void
    {
        // كل الضوابط أعلاه تقرأ `settlement_audit_logs`. فإن لم يُسجَّل
        // الفاعل، مرّت كلّها على قائمةٍ فارغة ولم تحرس شيئاً — وهو صنف
        // «اختبارٍ ينجح لسببٍ خاطئ» الذي يجب أن يُسدّ صراحةً.
        $creator = $this->financeManager('منشئ');
        $submitter = $this->financeManager('مقدِّم');

        $s = $this->draftBy($creator);
        $this->service->submit($s, $submitter);

        $actors = SettlementAuditLog::where('settlement_id', $s->id)
            ->where('action', SettlementAuditLog::ACTION_SUBMITTED)
            ->pluck('actor_user_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->assertContains($submitter->id, $actors,
            'لم يُسجَّل المقدِّم في سجلّ التسوية — كل ضوابط فصل المهام '
            . 'تقرأ من هنا، فبلا تسجيلٍ تمرّ جميعها على العدم');
    }
}
