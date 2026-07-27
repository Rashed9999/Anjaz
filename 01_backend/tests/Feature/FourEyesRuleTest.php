<?php

namespace Tests\Feature;

use App\Models\AuditDecision;
use App\Models\User;
use App\Services\FourEyesService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FOUR-EYES-002 — من نفّذ لا يراجع.
 *
 * **ما كان ناقصاً:** ApprovalService يمنع صاحب الطلب من اعتماد طلبه، أمّا
 * النزاعات فبلا ضابط إطلاقاً — ومن عالج الأدلّة يفصل، ومن فصل يُعيد الفصل.
 * وهو أخطر موضع: الفصل في النزاع يُحرّك مالاً بين طرفين.
 *
 * **ولماذا سجلّ التدقيق مصدرَ الحكم لا حقلٌ في الصفّ:** `admin_resolved_by`
 * يُكتب فوقه عند أوّل قرار ثانٍ فيضيع التاريخ. وسجلّ التدقيق لا يُحذف ولا
 * يُعدَّل — يمنعهما النموذج نفسه — فذاكرةُ من فعل ماذا لا تُمحى.
 */
class FourEyesRuleTest extends TestCase
{
    use RefreshDatabase;

    private FourEyesService $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = app(FourEyesService::class);
    }

    private function operator(): User
    {
        return User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
    }

    private function act(User $who, string $action, string $subjectId = '77'): void
    {
        AuditDecision::create([
            'decision_id' => (string) \Illuminate\Support\Str::ulid(),
            'actor_type' => 'admin',
            'actor_user_id' => $who->id,
            'subject_type' => 'safe_payment',
            'subject_id' => $subjectId,
            'action' => $action,
            'decision_code' => 'TEST',
            'reason' => 'اختبار',
            'severity' => 'critical',
            'zone_code' => 'SOUTH',
        ]);
    }

    public function test_someone_who_already_acted_cannot_review_it(): void
    {
        $maker = $this->operator();
        $this->act($maker, 'evidence_reviewed');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FOUR_EYES_VIOLATION');

        $this->rule->assertNotPreviousActor('safe_payment', 77, $maker);
    }

    public function test_a_second_operator_may_review_it(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();
        $this->act($maker, 'evidence_reviewed');

        $this->rule->assertNotPreviousActor('safe_payment', 77, $checker);

        $this->assertTrue(true, 'المراجع الثاني مُنع — القاعدة تُعطّل العمل بدل أن تضبطه');
    }

    /** الفعل على معاملة أخرى لا يمنع — القاعدة على الموضوع لا على الشخص. */
    public function test_acting_on_a_different_subject_does_not_block(): void
    {
        $operator = $this->operator();
        $this->act($operator, 'evidence_reviewed', subjectId: '99');

        $this->rule->assertNotPreviousActor('safe_payment', 77, $operator);
        $this->assertTrue(true);
    }

    /** ونوعٌ آخر من المواضيع لا يخلط بأرقامه — 77 نزاعاً غير 77 اعتماداً. */
    public function test_the_same_id_in_another_subject_type_does_not_block(): void
    {
        $operator = $this->operator();
        AuditDecision::create([
            'decision_id' => (string) \Illuminate\Support\Str::ulid(),
            'actor_type' => 'admin', 'actor_user_id' => $operator->id,
            'subject_type' => 'approval_request', 'subject_id' => '77',
            'action' => 'approved', 'decision_code' => 'TEST',
            'reason' => 'اختبار', 'severity' => 'critical', 'zone_code' => 'SOUTH',
        ]);

        $this->rule->assertNotPreviousActor('safe_payment', 77, $operator);
        $this->assertTrue(true);
    }

    /**
     * تحديد الأفعال المانعة: القراءة لا تمنع، والقرار يمنع.
     *
     * وإلّا صار فتحُ الصفحة للاطّلاع مانعاً من الفصل فيها — وهو ما يدفع
     * الموظّفين إلى التحايل: يفتحها زميل ثم يقرّر الأوّل.
     */
    public function test_only_the_listed_actions_block(): void
    {
        $operator = $this->operator();
        $this->act($operator, 'viewed');

        // القراءة وحدها لا تمنع
        $this->rule->assertNotPreviousActor(
            'safe_payment', 77, $operator, ['resolved_release', 'resolved_refund']);

        // والقرار يمنع
        $this->act($operator, 'resolved_release');
        $this->expectException(DomainException::class);
        $this->rule->assertNotPreviousActor(
            'safe_payment', 77, $operator, ['resolved_release', 'resolved_refund']);
    }

    /** اللوحة تعرض من تعامل معها قبل فتح القرار — منعٌ مسبق خير من رفض لاحق. */
    public function test_the_panel_can_show_who_touched_it_first(): void
    {
        $a = $this->operator();
        $b = $this->operator();
        $this->act($a, 'evidence_reviewed');
        $this->act($a, 'note_added');
        $this->act($b, 'viewed');

        $actors = $this->rule->actorsOn('safe_payment', 77);

        $this->assertCount(2, $actors);
        $byId = collect($actors)->keyBy('user_id');
        $this->assertSame(2, $byId[$a->id]['actions']);
        $this->assertSame(1, $byId[$b->id]['actions']);
        $this->assertNotSame('—', $byId[$a->id]['name'], 'الاسم لا يظهر فلا تُفيد اللوحة');
    }

    /** موضوع لم يمسّه أحد: لا مانع ولا فاعلين. */
    public function test_an_untouched_subject_blocks_nobody(): void
    {
        $this->rule->assertNotPreviousActor('safe_payment', 12345, $this->operator());
        $this->assertSame([], $this->rule->actorsOn('safe_payment', 12345));
    }

    /**
     * الاختبار الحاسم: المسار الحقيقي لا الخدمة وحدها.
     *
     * قاعدةٌ صحيحة في خدمة لا تُنادى لا تمنع شيئاً — وقد مرّ عليّ في هذه
     * الجولة حقلٌ صحيح لا يُنادى، ومسارٌ محصَّن له نظير مكشوف. فيُفحص ما
     * يصل إليه المشغّل: الطلب نفسه.
     */
    public function test_the_admin_who_handled_the_dispute_is_refused_at_the_endpoint(): void
    {
        [$payment, $admin] = $this->disputedPaymentTouchedBy();

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/safe-payments/{$payment->payment_ulid}/release",
                ['reason' => 'سببٌ مفصّل يتجاوز الحدّ الأدنى للطول'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FOUR_EYES_VIOLATION');
    }

    /** ولا يبقى المال محبوساً: مشرفٌ آخر يفصل فيها. */
    public function test_another_admin_can_still_resolve_it(): void
    {
        [$payment] = $this->disputedPaymentTouchedBy();
        $other = $this->operator();

        $response = $this->actingAs($other, 'user')
            ->postJson("/admin/amial/safe-payments/{$payment->payment_ulid}/release",
                ['reason' => 'سببٌ مفصّل يتجاوز الحدّ الأدنى للطول']);

        $this->assertNotSame(403, $response->getStatusCode(),
            'القاعدة تمنع الجميع — وهذا يحبس مال الطرفين بدل أن يحميه');
    }

    /**
     * نزاعٌ سبق أن تعامل معه مشرف.
     *
     * @return array{0: \App\Models\SafePayment, 1: User}
     */
    private function disputedPaymentTouchedBy(): array
    {
        $payment = $this->realDisputedPayment();
        $admin = $this->operator();
        $this->act($admin, 'evidence_reviewed', (string) $payment->id);

        return [$payment, $admin];
    }

    /** نزاع حقيقي عبر الخدمة — لا صفّ مصنوع بيد. */
    private function realDisputedPayment(): \App\Models\SafePayment
    {
        $party = function (string $balance) {
            $u = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
            \App\Models\EMoney::create([
                'user_id' => $u->id, 'current_balance' => $balance,
                'held_balance' => '0.0000', 'pending_balance' => '0.0000',
                'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
            ]);
            return $u;
        };

        $buyer = $party('100000.0000');
        $seller = $party('0.0000');
        $svc = app(\App\Services\SafePaymentService::class);

        $payment = $svc->createAndFund(
            buyer: $buyer, seller: $seller,
            title: 'جهاز مستعمل',
            description: 'هاتف بحالة ممتازة مع الشاحن الأصلي',
            amount: '20000',
        );
        $svc->sellerAccept($payment->fresh(), $seller);
        $svc->sellerMarkInDelivery($payment->fresh(), $seller);
        $svc->sellerMarkDelivered($payment->fresh(), $seller);
        $svc->buyerDispute($payment->fresh(), $buyer, 'وصلني الجهاز مكسور الشاشة', null, 'damaged');

        return $payment->fresh();
    }
}
