<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\ZonePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ZONE-001 — اختبار شامل: المشروع يدعم **جنوب اليمن فقط (SOUTH)**.
 *
 * يثبت أن مستخدمي وعمليات **الشمال (NORTH)** (وكذلك MIDDLE/OTHER/UNKNOWN) مرفوضة
 * في كل العمليات المالية، عبر ثلاث طبقات دفاع:
 *   1. ZonePolicyService::authorize() — قرار السياسة (يستخدمه middleware → HTTP 403)
 *   2. TransactionTrait::assertFinancialEligibility() — حارس القلب المالي (RuntimeException)
 *   3. الطرف المقابل: مستلم/تاجر شمالي يُفشل عملية مرسل جنوبي
 *
 * بينما القراءة فقط (الرصيد/السجل) مسموحة في كل المناطق.
 */
class NorthZoneOperationsBlockedTest extends TestCase
{
    use RefreshDatabase;

    private ZonePolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(ZonePolicyService::class);
    }

    /**
     * AMIAL-RESIDENCE-TEST-001 — المصنع الافتراضي مقيمٌ موثّقٌ في عدن، فـ
     * `zone_code` وحده لم يعد يصنع مستخدماً خارج التغطية: التغطية تُقرأ من
     * محافظة الإقامة الموثّقة. فالمنطقة تُبنى من الإقامة نفسها.
     */
    private function userInZone(string $zone, int $type = 2): User
    {
        if ($zone === 'SOUTH') {
            return User::factory()->create(['type' => $type, 'zone_code' => 'SOUTH']);
        }
        if ($zone === 'UNKNOWN') {
            return User::factory()->withoutVerifiedResidence()->create([
                'type' => $type, 'zone_code' => 'UNKNOWN', 'residence_governorate' => null,
            ]);
        }

        return User::factory()->outsideZone($zone)->create(['type' => $type]);
    }

    private function wallet(int $userId, string $balance = '0.0000', string $zone = 'SOUTH'): EMoney
    {
        return EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance,
            'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
            'held_balance' => '0.0000', 'zone_code' => $zone, 'version' => 0,
        ]);
    }

    private function txTrait(): object
    {
        return new class {
            use \App\Traits\TransactionTrait;
        };
    }

    // ============================================================
    // 1) طبقة السياسة: الشمال (وغير الجنوب) مرفوض في كل عملية مالية
    // ============================================================

    /** @test */
    public function north_user_is_blocked_for_every_cash_boundary_action(): void
    {
        // AMIAL-ZONE-BOUNDARY-001: الحجب انتقل من «كل عملية مالية» إلى
        // «كل عملية يعبر فيها نقد». عمليات الدفتر تُختبر أدناه بأنها مسموحة.
        $north = $this->userInZone('NORTH');

        foreach (ZonePolicyService::CASH_BOUNDARY_ACTIONS as $action) {
            $decision = $this->policy->authorize($north, $action);
            $this->assertFalse(
                $decision['allowed'],
                "العملية المالية '{$action}' يجب أن تُرفض لمستخدم شمالي"
            );
            $this->assertSame('NORTH', $decision['account_zone']);
        }
    }

    /** @test */
    public function middle_other_and_unknown_zones_are_also_blocked_from_cash(): void
    {
        foreach (['MIDDLE', 'OTHER', 'UNKNOWN'] as $zone) {
            $user = $this->userInZone($zone);
            $decision = $this->policy->authorize($user, 'cash_out');
            $this->assertFalse($decision['allowed'], "المنطقة {$zone} يجب أن تُرفض نقدياً");
        }
    }

    /** @test */
    public function north_user_keeps_ledger_actions(): void
    {
        $north = $this->userInZone('NORTH');

        foreach (ZonePolicyService::LEDGER_ACTIONS as $action) {
            $this->assertTrue(
                $this->policy->authorize($north, $action)['allowed'],
                "عملية الدفتر '{$action}' لا يعبر فيها نقد فلا تُحجَب"
            );
        }
    }

    /** @test */
    public function unknown_zone_is_blocked_even_from_ledger_actions(): void
    {
        // UNKNOWN شرط هوية لا شرط جغرافيا — يبقى حاجزاً شاملاً.
        $this->assertFalse(
            $this->policy->authorize($this->userInZone('UNKNOWN'), 'send_money')['allowed']
        );
    }

    /** @test */
    public function south_user_is_allowed_for_financial_actions(): void
    {
        $south = $this->userInZone('SOUTH');

        foreach (ZonePolicyService::FINANCIAL_ACTIONS as $action) {
            $decision = $this->policy->authorize($south, $action);
            $this->assertTrue(
                $decision['allowed'],
                "العملية '{$action}' يجب أن تُسمح لمستخدم جنوبي"
            );
        }
    }

    // ============================================================
    // 2) القراءة فقط مسموحة حتى في الشمال
    // ============================================================

    /** @test */
    public function north_user_can_still_do_read_only_actions(): void
    {
        $north = $this->userInZone('NORTH');

        foreach (ZonePolicyService::READ_ACTIONS as $action) {
            $decision = $this->policy->authorize($north, $action);
            $this->assertTrue(
                $decision['allowed'],
                "القراءة '{$action}' يجب أن تُسمح حتى في الشمال"
            );
        }
    }

    // ============================================================
    // 3) طبقة القلب المالي (defense-in-depth): trait يرفض الشمال فعلياً
    // ============================================================

    /** @test */
    public function trait_blocks_unverified_location_sender_from_sending_money(): void
    {
        // AMIAL-ZONE-BOUNDARY-001: التحويل دفتريّ فلا يُحجَب جغرافياً؛ ما
        // يُحجَب هو الحساب بلا إقامة موثّقة (شرط هويّة لا جغرافيا).
        $sender   = $this->userInZone('UNKNOWN');
        $receiver = $this->userInZone('SOUTH');
        $admin    = $this->userInZone('SOUTH', 0);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '1000.0000', 'NORTH');
        $this->wallet($receiver->id, '0.0000');

        $this->expectException(\RuntimeException::class);
        $this->txTrait()->customer_send_money_transaction($sender->id, $receiver->id, '100.0000', '2.0000');
    }

    /** @test */
    public function trait_blocks_north_user_from_cash_out(): void
    {
        $north = $this->userInZone('NORTH');
        $this->wallet($north->id, '1000.0000', 'NORTH');

        $this->expectException(\RuntimeException::class);
        // أي عملية مالية على مستخدم شمالي تُرفض من assertFinancialEligibility
        $this->txTrait()->customer_cash_out_transaction(
            from_user_id: $north->id,
            to_user_id: $north->id,
            amount: '100.0000',
            charge: '1.0000',
        );
    }

    /** @test */
    public function blocked_funds_are_not_moved(): void
    {
        $sender   = $this->userInZone('UNKNOWN');
        $receiver = $this->userInZone('SOUTH');
        $admin    = $this->userInZone('SOUTH', 0);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '1000.0000', 'NORTH');
        $this->wallet($receiver->id, '0.0000');

        try {
            $this->txTrait()->customer_send_money_transaction($sender->id, $receiver->id, '100.0000', '2.0000');
            $this->fail('كان يجب رفض العملية لحسابٍ بلا إقامة موثّقة');
        } catch (\RuntimeException $e) {
            // متوقع
        }

        // لا تتحرّك الأموال إطلاقاً
        $this->assertSame('1000.0000', (string) EMoney::where('user_id', $sender->id)->value('current_balance'));
        $this->assertSame('0.0000', (string) EMoney::where('user_id', $receiver->id)->value('current_balance'));
    }

    // ============================================================
    // 4) الطرف المقابل: مستلم شمالي يُفشل مرسلاً جنوبياً
    // ============================================================

    /**
     * السياسة مبنية على منطقة **الفاعل** (المنشئ للعملية): مرسل جنوبي مسموح له،
     * لكن المستلم الشمالي لا يستطيع بعدها التصرّف في المبلغ (يبقى محظوراً ماليّاً).
     *
     * @test
     */
    public function policy_is_actor_based_north_recipient_cannot_act_on_received_funds(): void
    {
        $sender   = $this->userInZone('SOUTH');
        $north    = $this->userInZone('NORTH');
        $other    = $this->userInZone('SOUTH');
        $admin    = $this->userInZone('SOUTH', 0);
        $this->wallet($admin->id);
        $this->wallet($sender->id, '1000.0000');
        $this->wallet($north->id, '0.0000', 'NORTH');
        $this->wallet($other->id, '0.0000');

        // مرسلٌ جنوبيّ → مستلمٌ مقيمٌ خارج النطاق: طبقةُ توثيق العميل تشترط
        // أن يكون المستلمُ قادراً على الاستلام (إقامة موثّقة داخل النطاق)،
        // فيُرفض التحويل كلُّه ولا يتحرّك قرش.
        try {
            $this->txTrait()->customer_send_money_transaction($sender->id, $north->id, '100.0000', '2.0000');
            $this->fail('كان يجب رفض التحويل إلى مستلمٍ خارج نطاق التشغيل');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RESIDENCE_OUTSIDE_OPERATIONAL_AREA', $e->getMessage());
        }
        $this->assertSame('1000.0000', (string) EMoney::where('user_id', $sender->id)->value('current_balance'));
        $this->assertSame('0.0000', (string) EMoney::where('user_id', $north->id)->value('current_balance'));
    }
}
