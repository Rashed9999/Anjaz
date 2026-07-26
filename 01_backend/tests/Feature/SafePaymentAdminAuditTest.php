<?php

namespace Tests\Feature;

use App\Models\AuditDecision;
use App\Models\EMoney;
use App\Models\SafePayment;
use App\Models\SafePaymentEvidence;
use App\Models\User;
use App\Services\SafePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-SAFEPAY-AUDIT-001 — قرار الموظّف في سجلّ لا يُحذف.
 *
 * حسم النزاع هو الموضع الوحيد الذي ينقل فيه موظّفٌ مالاً بين عميلين
 * بإرادته. كان يُكتب في `safe_payment_events` وحده — جدول عاديّ يُحذف منه
 * بلا أثر — بينما سلسلة `audit_decisions` المترابطة موجودة في النظام ولا
 * تصلها هذه القرارات. أي أن أخطر فعل في المنتج كان أقلّها توثيقاً.
 */
class SafePaymentAdminAuditTest extends TestCase
{
    use RefreshDatabase;

    private function party(string $balance = '100000.0000'): User
    {
        $u = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        EMoney::create([
            'user_id' => $u->id, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        return $u;
    }

    private function staff(): User
    {
        return User::factory()->create(['type' => ADMIN_TYPE]);
    }

    /** نزاع مفتوح جاهز للحسم، مع دليل من كل طرف. */
    private function disputedDeal(): array
    {
        $buyer = $this->party();
        $seller = $this->party('0.0000');
        $svc = app(SafePaymentService::class);

        $payment = $svc->createAndFund(
            buyer: $buyer, seller: $seller,
            title: 'جهاز مستعمل',
            description: 'هاتف بحالة ممتازة مع الشاحن الأصلي',
            amount: '20000',
        );

        $svc->sellerAccept($payment->fresh(), $seller);

        $this->actingAs($seller, 'api')->post(
            "/api/v1/amial/safe-payments/{$payment->payment_ulid}/evidence",
            ['stage' => 'in_delivery', 'files' => [UploadedFile::fake()->image('ship.jpg', 411, 307)]]
        )->assertSuccessful();

        $svc->sellerMarkInDelivery($payment->fresh(), $seller);
        $svc->sellerMarkDelivered($payment->fresh(), $seller);

        $this->actingAs($buyer, 'api')->post(
            "/api/v1/amial/safe-payments/{$payment->payment_ulid}/evidence",
            ['stage' => 'dispute', 'files' => [UploadedFile::fake()->image('damage.jpg', 523, 389)]]
        )->assertSuccessful();

        $svc->buyerDispute($payment->fresh(), $buyer, 'وصلني الجهاز مكسور الشاشة', null, 'damaged');

        return [$payment->fresh(), $buyer, $seller];
    }

    private function trail(SafePayment $p, string $action)
    {
        return AuditDecision::where('subject_type', 'safe_payment')
            ->where('subject_id', (string) $p->id)
            ->where('action', $action)
            ->first();
    }

    // ===================== القرار يُسجَّل =====================

    public function test_each_resolution_kind_lands_in_the_immutable_log(): void
    {
        $cases = [
            'release' => 'SAFE_PAYMENT_ADMIN_RELEASE',
            'refund' => 'SAFE_PAYMENT_ADMIN_REFUND',
            'partial' => 'SAFE_PAYMENT_ADMIN_PARTIAL',
        ];

        foreach ($cases as $kind => $action) {
            [$p] = $this->disputedDeal();
            $svc = app(SafePaymentService::class);
            $staff = $this->staff();
            $reason = "قرار مسبَّب بما يكفي من الشرح — {$kind}";

            match ($kind) {
                'release' => $svc->adminResolveRelease($p, $staff, $reason),
                'refund' => $svc->adminResolveRefund($p, $staff, $reason),
                'partial' => $svc->adminResolvePartial($p, $staff, '5000', $reason),
            };

            $row = $this->trail($p, $action);

            $this->assertNotNull($row, "قرار {$kind} لم يُسجَّل في السلسلة");
            $this->assertSame('admin', $row->actor_type);
            $this->assertSame($staff->id, $row->actor_user_id);
            // قرار يحرّك مال عميلين: أعلى درجة كي يظهر فوراً في اللوحة.
            $this->assertSame('critical', $row->severity);
            $this->assertSame($reason, $row->reason);
        }
    }

    /**
     * القرار مقيَّد بالأدلّة التي كانت أمامه لحظة اتّخاذه.
     *
     * بدون هذا يستطيع من أراد التحايل أن يقرّر أوّلاً ثم يرفع دليلاً
     * يُبرّر قراره، ولا يُظهر السجلّ الترتيب.
     */
    public function test_the_decision_is_bound_to_the_evidence_it_saw(): void
    {
        [$p] = $this->disputedDeal();

        app(SafePaymentService::class)->adminResolveRefund(
            $p, $this->staff(), 'الأدلّة تُظهر تلفاً واضحاً عند الاستلام'
        );

        $context = json_decode($this->trail($p, 'SAFE_PAYMENT_ADMIN_REFUND')->context, true);

        $this->assertSame(2, $context['evidence_count']);
        $this->assertCount(2, $context['evidence_at_decision']);

        $fingerprints = array_column($context['evidence_at_decision'], 'fingerprint');
        foreach (SafePaymentEvidence::where('safe_payment_id', $p->id)->get() as $e) {
            $this->assertContains(substr($e->sha256, 0, 12), $fingerprints);
        }

        // ومعه ما يشرح القرار بلا فتح الملفّ: سبب الشكوى وحالة رمز التسليم.
        $this->assertSame('damaged', $context['dispute_reason_code']);
        $this->assertFalse($context['delivery_code_verified']);
        $this->assertSame('20000.0000', $context['held_amount_before']);
    }

    public function test_partial_settlement_records_both_shares(): void
    {
        [$p] = $this->disputedDeal();

        app(SafePaymentService::class)->adminResolvePartial(
            $p, $this->staff(), '7500', 'تسوية: نصف القيمة لكل طرف بعد المراجعة'
        );

        $context = json_decode($this->trail($p, 'SAFE_PAYMENT_ADMIN_PARTIAL')->context, true);

        $this->assertSame('7500.0000', $context['to_buyer']);
        $this->assertSame('12500.0000', $context['to_seller']);
    }

    // ===================== القراءة تُسجَّل أيضاً =====================

    public function test_opening_a_dispute_file_is_recorded(): void
    {
        [$p] = $this->disputedDeal();
        $staff = $this->staff();

        $this->actingAs($staff, 'user')
            ->getJson("/admin/amial/safe-payments/{$p->payment_ulid}")->assertOk();

        $row = $this->trail($p, 'SAFE_PAYMENT_DISPUTE_VIEWED');

        $this->assertNotNull($row);
        $this->assertSame($staff->id, $row->actor_user_id);
    }

    /** سحب ملفّ دليل بمعرّفه — بضاعة الناس وفواتيرهم — لا يمرّ بلا أثر. */
    public function test_pulling_an_evidence_file_is_recorded(): void
    {
        [$p] = $this->disputedDeal();
        $evidence = SafePaymentEvidence::where('safe_payment_id', $p->id)->firstOrFail();
        $staff = $this->staff();

        $this->actingAs($staff, 'user')
            ->get("/admin/amial/safe-payments/evidence/{$evidence->id}/file")->assertOk();

        $row = $this->trail($p, 'SAFE_PAYMENT_EVIDENCE_VIEWED');

        $this->assertNotNull($row);
        $this->assertSame($staff->id, $row->actor_user_id);
        $this->assertStringContainsString(
            substr($evidence->sha256, 0, 12), (string) $row->context
        );
    }

    public function test_the_panel_shows_the_trail_to_the_reviewer(): void
    {
        // سجلٌّ لا يراه أحد لا يردع أحداً: يُعرض للموظّف وهو يقرّر.
        [$p] = $this->disputedDeal();

        $trail = $this->actingAs($this->staff(), 'user')
            ->getJson("/admin/amial/safe-payments/{$p->payment_ulid}")
            ->assertOk()->json('meta.audit_trail');

        $actions = array_column($trail, 'action');

        $this->assertContains('SAFE_PAYMENT_DISPUTED', $actions);
        $this->assertContains('SAFE_PAYMENT_DISPUTE_VIEWED', $actions);
    }

    public function test_the_audit_page_can_be_filtered_to_one_dispute(): void
    {
        [$p] = $this->disputedDeal();
        [$other] = $this->disputedDeal();

        $this->actingAs($this->staff(), 'user')
            ->get('/admin/amial/audit?subject_type=safe_payment&subject_id=' . $p->id)
            ->assertOk()
            ->assertDontSee($other->payment_ulid);
    }

    // ===================== السلسلة تكشف العبث =====================

    /**
     * الفائدة كلّها في هذا الاختبار: لو عُدِّل القرار بعد كتابته — في
     * القاعدة مباشرةً، متجاوزاً كل حراسة Eloquent — انكسرت السلسلة وظهر
     * ذلك في `amial:audit-verify`.
     */
    public function test_tampering_with_a_recorded_decision_breaks_the_chain(): void
    {
        [$p] = $this->disputedDeal();
        app(SafePaymentService::class)->adminResolveRelease(
            $p, $this->staff(), 'أدلّة البائع كافية والتسليم مؤكَّد'
        );

        $this->artisan('amial:audit-verify')->assertExitCode(0);

        // موظّف يغيّر سبب قراره بعد أن كُتب.
        DB::table('audit_decisions')
            ->where('action', 'SAFE_PAYMENT_ADMIN_RELEASE')
            ->update(['reason' => 'سبب مختلف تماماً كُتب لاحقاً']);

        $this->artisan('amial:audit-verify')->assertFailed();
    }

    public function test_eloquent_refuses_to_delete_or_edit_a_decision(): void
    {
        [$p] = $this->disputedDeal();
        app(SafePaymentService::class)->adminResolveRefund(
            $p, $this->staff(), 'الاسترداد للمشتري بعد مراجعة الأدلّة'
        );

        $row = $this->trail($p, 'SAFE_PAYMENT_ADMIN_REFUND');

        $this->expectException(\RuntimeException::class);
        $row->delete();
    }
}
