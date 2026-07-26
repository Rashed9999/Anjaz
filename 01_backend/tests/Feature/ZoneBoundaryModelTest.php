<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ZonePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-ZONE-BOUNDARY-001 — الحدّ عند النقد لا عند الشخص.
 *
 * السيناريو الحاكم: عميل من صنعاء استقرّ في عدن واستعمل التطبيق، ثم عاد
 * إلى صنعاء. ماذا يجب أن يحدث؟
 *
 * خطر العملتين ليس في مكان الشخص بل في المكان الذي يعبر فيه النقد بين
 * المحفظة والعالم. التحويل والاستقبال ينقلان قيمةً داخل دفتر واحد مقوَّم
 * بريال واحد: لا بنك يُلمَس ولا عملة تُصرَف. أمّا الإيداع والسحب والدفع
 * فيلمسها وكيل أو تاجر — وهما العقدتان اللتان تتحكّم بهما المنصّة.
 *
 * فالضبط على الوكيل والتاجر، والعميل لا يُحجَب بموقعه.
 */
class ZoneBoundaryModelTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): ZonePolicyService
    {
        return app(ZonePolicyService::class);
    }

    private function user(string $zone, int $type = 2): User
    {
        $u = User::factory()->create(['type' => $type, 'zone_code' => $zone]);
        EMoney::create([
            'user_id' => $u->id, 'current_balance' => '100000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => $zone,
        ]);

        return $u;
    }

    // ============ السيناريو: عميل انتقل شمالاً ============

    public function test_relocated_customer_can_still_transfer(): void
    {
        // القيمة تبقى داخل الدفتر — لا نقد يعبر، فلا مبرّر للحجب.
        $customer = $this->user('NORTH');

        $decision = $this->policy()->authorize($customer, 'send_money');

        $this->assertTrue($decision['allowed']);
        $this->assertSame('ALLOWED_LEDGER_ONLY', $decision['decision_code']);
    }

    /** @dataProvider ledgerActions */
    public function test_ledger_actions_are_not_geo_blocked(string $action): void
    {
        $this->assertTrue(
            $this->policy()->authorize($this->user('NORTH'), $action)['allowed'],
            "العملية {$action} لا يعبر فيها نقد فلا تُحجَب بالجغرافيا"
        );
    }

    public static function ledgerActions(): array
    {
        return [
            'تحويل' => ['send_money'],
            'طلب مال' => ['request_money'],
            'تقسيم فاتورة' => ['split_bill'],
            'تبرّع' => ['donate'],
            'صندوق عائلي' => ['family_fund_contribute'],
            'دفع آمن — حجز' => ['safe_payment_create'],
            'دفع آمن — تحرير' => ['safe_payment_release'],
            'استرجاع' => ['refund'],
        ];
    }

    /** @dataProvider cashActions */
    public function test_cash_boundary_actions_stay_blocked_outside_the_zone(string $action): void
    {
        $decision = $this->policy()->authorize($this->user('NORTH'), $action);

        $this->assertFalse($decision['allowed'], "العملية {$action} يعبر فيها نقد فتُحجَب");
        $this->assertSame('TX_ZONE_BLOCKED', $decision['decision_code']);
    }

    public static function cashActions(): array
    {
        return [
            'إيداع لدى وكيل' => ['cash_in'],
            'سحب نقدي' => ['cash_out'],
            'شحن رصيد' => ['add_money'],
            'طلب سحب' => ['withdraw'],
            'دفع لتاجر' => ['merchant_payment'],
            'دفع بمسح رمز' => ['qr_payment'],
            'سداد فاتورة' => ['pay_bill'],
        ];
    }

    public function test_unknown_zone_still_blocks_everything_financial(): void
    {
        // شرط هوية موثّقة لا شرط جغرافيا — يبقى كما هو.
        $decision = $this->policy()->authorize($this->user('UNKNOWN'), 'send_money');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('ACCOUNT_ZONE_UNKNOWN', $decision['decision_code']);
    }

    public function test_southern_customer_keeps_full_access(): void
    {
        foreach (['send_money', 'cash_out', 'merchant_payment'] as $action) {
            $this->assertTrue($this->policy()->authorize($this->user('SOUTH'), $action)['allowed']);
        }
    }

    // ============ الحدّ الحقيقي: موقع الوكيل ============

    private function agentCashRequest(User $agent, ?array $coords): \Illuminate\Testing\TestResponse
    {
        \App\Models\UserLogHistory::create([
            'user_id' => $agent->id, 'device_id' => 'dev', 'is_active' => 1,
        ]);

        $test = $this->actingAs($agent, 'api')->withHeader('device-id', 'dev');
        if ($coords !== null) {
            $test = $test->withHeaders([
                'X-Amial-Lat' => (string) $coords[0],
                'X-Amial-Lng' => (string) $coords[1],
            ]);
        }

        return $test->postJson('/api/v1/agent/send-money', []);
    }

    public function test_agent_operating_from_the_north_is_blocked(): void
    {
        // الثغرة الحقيقية: وكيل معتمد في عدن يأخذ هاتفه إلى صنعاء. منطقة
        // حسابه ما زالت SOUTH فيمرّ كل فحص — ويصرف بسعر خاطئ في كل عملية.
        $agent = $this->user('SOUTH', AGENT_TYPE);

        $response = $this->agentCashRequest($agent, [15.3694, 44.1910]); // صنعاء

        $response->assertStatus(403);
        $this->assertSame('AGENT_OUTSIDE_OPERATIONAL_ZONE', $response->json('code'));
    }

    public function test_agent_operating_from_within_the_zone_passes_the_location_gate(): void
    {
        $agent = $this->user('SOUTH', AGENT_TYPE);

        $response = $this->agentCashRequest($agent, [12.7855, 45.0187]); // عدن

        // ملاحظة: المسار القديم يردّ 403 لأخطاء التحقّق أيضاً، فلا يصلح
        // رمز الحالة وحده معياراً — نفحص سبب الرفض لا رقمه.
        $this->assertNotSame('AGENT_OUTSIDE_OPERATIONAL_ZONE', $response->json('code'));
        $this->assertNotSame('AGENT_LOCATION_REQUIRED', $response->json('code'));
    }

    public function test_soft_mode_lets_an_agent_without_location_through(): void
    {
        // نشرٌ آمن: الوكلاء الذين لم يحدّثوا التطبيق بعد لا يتعطّلون.
        config(['amial.agent_location_mode' => 'soft']);
        $agent = $this->user('SOUTH', AGENT_TYPE);

        $response = $this->agentCashRequest($agent, null);
        $this->assertNotSame('AGENT_LOCATION_REQUIRED', $response->json('code'));
        $this->assertNotSame('AGENT_OUTSIDE_OPERATIONAL_ZONE', $response->json('code'));
    }

    public function test_strict_mode_requires_a_location(): void
    {
        config(['amial.agent_location_mode' => 'strict']);
        $agent = $this->user('SOUTH', AGENT_TYPE);

        $response = $this->agentCashRequest($agent, null);

        $response->assertStatus(403);
        $this->assertSame('AGENT_LOCATION_REQUIRED', $response->json('code'));
    }

    public function test_customer_location_is_never_enforced(): void
    {
        // الحارس للوكيل وحده — العميل لا يُلزَم بموقعه إطلاقاً.
        $customer = $this->user('SOUTH');
        \App\Models\UserLogHistory::create([
            'user_id' => $customer->id, 'device_id' => 'dev', 'is_active' => 1,
        ]);

        $response = $this->actingAs($customer, 'api')
            ->withHeader('device-id', 'dev')
            ->withHeaders(['X-Amial-Lat' => '15.3694', 'X-Amial-Lng' => '44.1910'])
            ->postJson('/api/v1/customer/send-money', []);

        $this->assertNotSame('AGENT_OUTSIDE_OPERATIONAL_ZONE', $response->json('code'));
        $this->assertNotSame('AGENT_LOCATION_REQUIRED', $response->json('code'));
        $this->assertNotSame('TX_ZONE_BLOCKED', $response->json('code'));
    }

    // ============ الرسالة الصادقة ============

    public function test_coverage_reports_no_agents_without_calling_it_a_ban(): void
    {
        $customer = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'residence_governorate' => 'YE-SN',
        ]);

        $data = $this->actingAs($customer, 'api')
            ->getJson('/api/v1/amial/service-coverage')
            ->assertOk()->json('data');

        $this->assertSame('صنعاء', $data['governorate']);
        $this->assertSame(0, $data['agents']);
        $this->assertTrue($data['can_receive'], 'الاستقبال لا يتوقّف');
        $this->assertTrue($data['can_transfer'], 'التحويل لا يتوقّف');
        $this->assertFalse($data['can_cash_out']);
        $this->assertStringContainsString('لا يوجد وكلاء', $data['notice']);
        $this->assertStringNotContainsString('ممنوع', $data['notice']);
    }

    public function test_coverage_counts_real_agents(): void
    {
        User::factory()->count(3)->create([
            'type' => AGENT_TYPE, 'is_active' => 1, 'is_kyc_verified' => 1,
            'residence_governorate' => 'YE-AD',
        ]);

        $customer = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'residence_governorate' => 'YE-AD',
        ]);

        $data = $this->actingAs($customer, 'api')
            ->getJson('/api/v1/amial/service-coverage')->assertOk()->json('data');

        $this->assertSame(3, $data['agents']);
        $this->assertTrue($data['can_cash_out']);
    }

    // ============ رصد الحساب المصرف المغلق ============

    public function test_receive_only_account_is_flagged_not_blocked(): void
    {
        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH', 'residence_governorate' => 'YE-SN',
        ]);

        foreach ([60000, 80000] as $amount) {
            Transaction::create([
                'transaction_id' => (string) Str::ulid(),
                'ref_trans_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'transaction_type' => 'received_money',
                'debit' => '0', 'credit' => (string) $amount,
                'charge' => '0', 'balance' => (string) $amount,
                'zone_code' => 'SOUTH',
            ]);
        }

        $this->artisan('amial:detect-sink-accounts')->assertExitCode(0);

        $alert = \App\Models\Aml\AmlAlert::where('alert_code', 'SINK_ACCOUNT')
            ->where('subject_id', $user->id)->first();

        $this->assertNotNull($alert, 'النمط يستحقّ تنبيهاً');
        $this->assertSame('open', $alert->status);
        // والأهم: الحساب لم يُقيَّد — الرصد لا يعاقب سلوكاً مشروعاً.
        $this->assertSame(1, (int) $user->fresh()->is_active);
        $this->assertSame('SOUTH', $user->fresh()->zone_code);
    }

    public function test_account_that_withdraws_is_not_flagged(): void
    {
        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH']);

        Transaction::create([
            'transaction_id' => (string) Str::ulid(), 'ref_trans_id' => (string) Str::ulid(),
            'user_id' => $user->id, 'transaction_type' => 'received_money',
            'debit' => '0', 'credit' => '90000', 'charge' => '0', 'balance' => '90000',
            'zone_code' => 'SOUTH',
        ]);
        Transaction::create([
            'transaction_id' => (string) Str::ulid(), 'ref_trans_id' => (string) Str::ulid(),
            'user_id' => $user->id, 'transaction_type' => 'cash_out',
            'debit' => '20000', 'credit' => '0', 'charge' => '0', 'balance' => '70000',
            'zone_code' => 'SOUTH',
        ]);

        $this->artisan('amial:detect-sink-accounts');

        $this->assertNull(
            \App\Models\Aml\AmlAlert::where('alert_code', 'SINK_ACCOUNT')
                ->where('subject_id', $user->id)->first()
        );
    }
}
