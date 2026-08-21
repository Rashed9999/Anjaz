<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashHandoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-CASH-HANDOVER-001 — **قيدٌ في الدفتر ليس دليلاً على أنّ النقد تحرّك.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **المحورُ ٩ من وثيقة الوكيل نصّاً:**
 *
 *     لا تعتبر تغيير Ledger دليلاً على أنّ Cash انتقل في الواقع…
 *     **ولا تجعل Note نصّيّةً مثل «استلم النقد» هي الدليل المالي الوحيد.**
 *
 * وقِيس: `agent_settlements` تحمل من عناصر التسليم الأحد عشرَ **اثنين**
 * (`payment_reference` و`note`). فمن سأل «من سلّم؟ ومن استلم؟ وأين؟
 * ومتى؟» لم يجد إلّا سطراً نصّيّاً كتبه **طرفٌ واحد**.
 *
 * **والضابط: المستلِمُ يؤكّد، لا المُسلِّم.** وسائقٌ يقول «سلّمتُ» لا
 * يُغلق مبلغاً؛ يُغلقه من عدّ المالَ في يده.
 */
class CashHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): CashHandoverService
    {
        return app(CashHandoverService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['type' => ADMIN_TYPE, 'is_active' => 1]);
    }

    private function agent(): User
    {
        return User::factory()->create(['type' => AGENT_TYPE, 'zone_code' => 'SOUTH']);
    }

    /**
     * @test
     *
     * **① التسليمُ يُفتَح معلّقاً — لا مؤكَّداً بفعل من سلّم.**
     */
    public function a_handover_opens_pending_never_confirmed_by_its_sender(): void
    {
        $agent = $this->agent();
        $ops = $this->admin();

        $h = $this->svc()->open('agent_to_platform', '500000', $agent, null, $ops, [
            'location' => 'خزنة المكتب الرئيسيّ', 'reference' => 'CASH-2026-1',
        ]);

        $this->assertSame('pending', $h['status'],
            '**فُتح مؤكَّداً** — وتسليمٌ يُعلنه المُرسِلُ وحدَه دعوى لا إثبات');
        $this->assertSame($ops->id, $h['delivered_by_user_id']);
        $this->assertNull($h['received_by_user_id'], 'سُجّل مستلِمٌ لم يستلم بعد');
        $this->assertNotNull($h['delivered_at']);
    }

    /**
     * @test
     *
     * **② والعناصرُ الأحدَ عشرَ محفوظةٌ — لا سطرٌ نصّيّ.**
     */
    public function the_record_holds_the_traceable_fields_not_a_free_note(): void
    {
        $agent = $this->agent();
        $ops = $this->admin();

        $h = $this->svc()->open('agent_to_platform', '120000', $agent, null, $ops, [
            'location' => 'فرع كريتر', 'reference' => 'REF-9', 'note' => 'حقيبةٌ مختومة',
        ]);

        foreach (['direction', 'amount', 'from_user_id', 'location', 'status',
                  'reference', 'delivered_by_user_id', 'delivered_at'] as $field) {
            $this->assertArrayHasKey($field, $h, "عنصرُ التتبّع «{$field}» غيرُ محفوظ");
        }

        $this->assertSame('فرع كريتر', $h['location'],
            '**الموقعُ غيرُ محفوظ** — ومن سأل «أين سُلّم؟» لا يجد جواباً');
    }

    /**
     * @test
     *
     * **③ ومن سلّم لا يستلم — ولو كان مديراً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وتسليمٌ يؤكّده صاحبُه ليس إثباتاً — **هو الدعوى نفسُها مكتوبةً
     * مرّتين**. وهي أربعُ عيونٍ على النقد الورقيّ كما هي على الإلكترونيّ.
     */
    public function the_sender_cannot_confirm_their_own_handover(): void
    {
        $ops = $this->admin();
        $h = $this->svc()->open('platform_to_agent', '80000', null, $this->agent(), $ops);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('HANDOVER_SELF_CONFIRM_FORBIDDEN');

        $this->svc()->confirm($h['handover_ulid'], $ops);
    }

    /**
     * @test
     *
     * **④ والمستلِمُ يُغلقه — ويُسجَّل اسمُه ووقتُه.**
     */
    public function the_receiver_closes_it_and_is_recorded(): void
    {
        $ops = $this->admin();
        $receiver = $this->admin();

        $h = $this->svc()->open('agent_to_platform', '300000', $this->agent(), null, $ops);
        $done = $this->svc()->confirm($h['handover_ulid'], $receiver, 'عُدّ في الخزنة');

        $this->assertSame('confirmed', $done['status']);
        $this->assertSame($receiver->id, (int) $done['received_by_user_id'],
            'أُكّد الاستلامُ ولم يُسجَّل من استلم');
        $this->assertNotNull($done['received_at']);
    }

    /**
     * @test
     *
     * **⑤ وتأكيدٌ مكرّرٌ نجاحٌ صامتٌ لا خطأ.**
     *
     * فضغطةٌ ثانيةٌ على زرٍّ لا تُعلّق موظّفاً في شاشة.
     */
    public function confirming_twice_is_idempotent(): void
    {
        $ops = $this->admin();
        $receiver = $this->admin();

        $h = $this->svc()->open('agent_to_platform', '50000', $this->agent(), null, $ops);
        $this->svc()->confirm($h['handover_ulid'], $receiver);
        $again = $this->svc()->confirm($h['handover_ulid'], $receiver);

        $this->assertSame('confirmed', $again['status']);
        $this->assertSame(1, DB::table('cash_handovers')
            ->where('handover_ulid', $h['handover_ulid'])->count());
    }

    /**
     * @test
     *
     * **⑥ وخلافٌ في العدّ يُرفَع بسببه ولا يُحذَف.**
     *
     * فالحذفُ يمحو أثرَ ما وقع — **وسجلٌّ رقابيٌّ يُمحى ليس سجلّاً**.
     */
    public function a_dispute_is_raised_with_a_reason_never_deleted(): void
    {
        $ops = $this->admin();
        $h = $this->svc()->open('agent_to_platform', '700000', $this->agent(), null, $ops);

        $out = $this->svc()->dispute($h['handover_ulid'], $this->admin(), 'العدُّ ناقصٌ ٢٠٬٠٠٠');

        $this->assertSame('disputed', $out['status']);
        $this->assertStringContainsString('ناقص', (string) $out['dispute_reason']);
        $this->assertNotNull(DB::table('cash_handovers')
            ->where('handover_ulid', $h['handover_ulid'])->first(),
            '**حُذف السجلُّ** — ومُحي أثرُ ما وقع');
    }

    /** @test **⑦ وخلافٌ بلا سببٍ مرفوض.** */
    public function a_dispute_without_a_reason_is_refused(): void
    {
        $ops = $this->admin();
        $h = $this->svc()->open('agent_to_platform', '1000', $this->agent(), null, $ops);

        $this->expectException(\DomainException::class);
        $this->svc()->dispute($h['handover_ulid'], $this->admin(), '');
    }

    /**
     * @test
     *
     * **⑧ و«لم يُؤكَّد» ليس «مؤكَّداً».**
     *
     * (‏القاعدة السابعة.) وتسويةٌ بلا تسليمٍ مؤكَّدٍ تُقرأ تامّةً فيُغلق
     * اليومُ والمالُ ما زال في حقيبة.
     */
    public function an_unconfirmed_settlement_is_not_reported_as_confirmed(): void
    {
        $ops = $this->admin();
        $this->svc()->open('agent_to_platform', '9000', $this->agent(), null, $ops,
            ['settlement_ulid' => 'STL-XYZ']);

        $this->assertFalse($this->svc()->settlementIsPhysicallyConfirmed('STL-XYZ'),
            'تسليمٌ معلّقٌ قُرئ مؤكَّداً');
        $this->assertFalse($this->svc()->settlementIsPhysicallyConfirmed('STL-لا-وجود-له'),
            '**غيابُ السجلّ قُرئ تأكيداً** — و«غيرُ معروف» ليس «نعم»');
    }

    /**
     * @test
     *
     * **⑨ وغيرُ المؤكَّد يُعرَض مرتّباً بالأقدم — فالأقدمُ أخطر.**
     */
    public function unconfirmed_handovers_surface_oldest_first(): void
    {
        $ops = $this->admin();

        $a = $this->svc()->open('agent_to_platform', '111', $this->agent(), null, $ops);
        DB::table('cash_handovers')->where('id', $a['id'])
            ->update(['delivered_at' => now()->subDays(3)]);

        $this->svc()->open('agent_to_platform', '222', $this->agent(), null, $ops);

        $list = $this->svc()->unconfirmed();

        $this->assertCount(2, $list);
        $this->assertSame(0, bccomp((string) $list[0]['amount'], '111', 4),
            'لم يُرتَّب بالأقدم — والأقدمُ هو الذي يستحقّ السؤال أوّلاً');
        $this->assertGreaterThanOrEqual(70, $list[0]['age_hours'],
            'عمرُ التسليم غيرُ محسوب — والرقمُ وحدَه لا يقول كم انتظر');
    }
}
