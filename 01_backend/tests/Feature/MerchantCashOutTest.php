<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessOutboxEvent;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\CustomerWithdrawService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-CASHOUT-001 — سحب التاجر نقداً عبر الوكيل:
 *   - بآلية سحب العميل (حجز · رقم عملية · صرف الوكيل · عمولته)، بسياسة التاجر:
 *     PIN، حدود، ورسم التاجر إن وُجد.
 *   - الوكيل لا يصرف لتاجر دون التحقق من هاتفه أو رقم حسابه.
 *   - باب سحب العميل مغلق أمام حساب التاجر.
 *   - السحب المنفّذ يُبلَّغ لأميال أعمال لتقيّده.
 */
class MerchantCashOutTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial_business.inbound_secret' => 'in-secret', 'amial_business.webhook_secret' => 'w', 'amial_business.webhook_url' => 'https://b.test/h',
            'amial.merchant_cash_out.max_per_request' => '300000', 'amial.merchant_cash_out.daily_limit' => '400000']);
        $this->wallet(User::factory()->create(['type' => 0])->id);
        $this->merchant = User::factory()->create(['type' => 3, 'phone' => '+967700600001', 'zone_code' => 'SOUTH', 'is_active' => 1, 'transaction_pin' => Hash::make('4321')]);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $this->agent = User::factory()->create(['type' => 1, 'zone_code' => 'SOUTH']);
        $this->wallet($this->merchant->id, '1000000');
        $this->wallet($this->agent->id);
        foreach (['customer' => '5.0000'] as $appliesTo => $rate) {
            FeeScheme::create(['code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => $appliesTo, 'fee_type' => 'percent', 'percent_rate' => $rate,
                'fixed_amount' => '0', 'agent_commission_percent' => '40.0000', 'agent_commission_fixed' => '0', 'bearer' => 'sender', 'version' => 1,
                'is_active' => true, 'effective_from' => now()]);
        }
    }

    #[Test]
    public function the_owner_requests_with_pin_and_the_amount_plus_fee_is_held(): void
    {
        Passport::actingAs($this->merchant, [], 'api');
        $this->postJson('/api/v1/amial/merchant/cash-out', ['amount' => '100000', 'pin' => '0000'])->assertStatus(403);
        $meta = $this->postJson('/api/v1/amial/merchant/cash-out', ['amount' => '100000', 'pin' => '4321'])->assertStatus(201)->json('meta');

        $this->assertMatchesRegularExpression('/^\d{9}$/', $meta['op_code']);
        $this->assertSame('5000.0000', $meta['fee']); // لا رسم تاجر معرَّف ⇒ رسم العميل
        $w = EMoney::where('user_id', $this->merchant->id)->first();
        $this->assertSame('895000.0000', (string) $w->current_balance);
        $this->assertSame('105000.0000', (string) $w->held_balance);
        $this->assertSame('merchant', WithdrawalRequest::sole()->requester_type);
    }

    #[Test]
    public function a_merchant_fee_scheme_is_used_when_defined(): void
    {
        FeeScheme::create(['code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'merchant', 'fee_type' => 'percent', 'percent_rate' => '1.0000',
            'fixed_amount' => '0', 'agent_commission_percent' => '50.0000', 'agent_commission_fixed' => '0', 'bearer' => 'sender', 'version' => 1,
            'is_active' => true, 'effective_from' => now()]);
        $req = app(CustomerWithdrawService::class)->requestAsMerchant($this->merchant, '100000');

        $this->assertSame('1000.0000', (string) $req->fee);
    }

    #[Test]
    public function the_agent_must_verify_the_merchant_and_completion_is_reported(): void
    {
        $link = app(BusinessLinkService::class)->link($this->merchant);
        $req = app(CustomerWithdrawService::class)->requestAsMerchant($this->merchant, '100000');
        $svc = app(CustomerWithdrawService::class);

        foreach (['', '+967700699999'] as $identifier) {
            try {
                $svc->execute($this->agent, $req->op_code, $identifier);
                $this->fail('agent must verify the merchant');
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame('pending', $req->fresh()->status);

        $svc->execute($this->agent, $req->op_code, '700600001'); // صيغة محلية للهاتف نفسه

        $this->assertSame('completed', $req->fresh()->status);
        $this->assertSame('0.0000', (string) EMoney::where('user_id', $this->merchant->id)->value('held_balance'));
        $this->assertSame('102000.0000', (string) EMoney::where('user_id', $this->agent->id)->value('current_balance')); // المبلغ + 40% من الرسم
        $event = BusinessOutboxEvent::where('event_type', 'merchant_cash_out.completed')->sole();
        $this->assertSame($link->id, $event->business_link_id);
        $this->assertSame(['100000.0000', '5000.0000'], [$event->payload['data']['amount'], $event->payload['data']['fee']]);
        $this->assertArrayNotHasKey('op_code', $event->payload['data']);
    }

    #[Test]
    public function the_customer_withdraw_door_is_closed_to_merchant_accounts(): void
    {
        Passport::actingAs($this->merchant, [], 'api');
        $this->postJson('/api/v1/amial/withdraw/request', ['amount' => '100000'])->assertStatus(422);
        $this->assertSame(0, WithdrawalRequest::count());
    }

    #[Test]
    public function per_request_and_daily_limits_apply(): void
    {
        $svc = app(CustomerWithdrawService::class);
        try {
            $svc->requestAsMerchant($this->merchant, '300001');
            $this->fail('above per-request limit');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('حدّ السحب', $e->getMessage());
        }
        $svc->requestAsMerchant($this->merchant, '300000');
        $this->expectExceptionMessage('اليومي');
        $svc->requestAsMerchant($this->merchant, '100001');
    }

    #[Test]
    public function an_unverified_merchant_cannot_cash_out(): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)->update(['verification_status' => 'unverified']);

        $this->expectException(\RuntimeException::class);
        app(CustomerWithdrawService::class)->requestAsMerchant($this->merchant, '1000');
    }

    #[Test]
    public function the_agent_sees_a_merchant_request_and_amial_business_sees_only_balances(): void
    {
        $link = app(BusinessLinkService::class)->link($this->merchant);
        $req = app(CustomerWithdrawService::class)->requestAsMerchant($this->merchant, '20000');

        Passport::actingAs($this->agent, [], 'api');
        $this->postJson('/api/v1/amial/agent/withdraw/lookup', ['op_code' => $req->op_code])->assertOk()
            ->assertJsonPath('meta.requester_type', 'merchant')->assertJsonPath('meta.identity_required', true);

        $ts = (string) time();
        $nonce = Str::random(32);
        $sig = BusinessGatewaySignature::sign(BusinessGatewaySignature::inboundCanonical($ts, $nonce, 'GET', '/api/v1/business/wallet', '', ''), 'in-secret');
        $meta = $this->call('GET', '/api/v1/business/wallet', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $ts,
            'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce, 'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => $sig, 'HTTP_X_AMIAL_BUSINESS_LINK' => $link->link_ulid])->assertOk()->json('meta');

        $this->assertSame('979000.0000', $meta['available']);
        $this->assertSame('21000.0000', $meta['held']);
        $this->assertSame(1, $meta['pending_cash_outs']);
        $this->assertStringNotContainsString($req->op_code, json_encode($meta));
    }

    private function wallet(int $userId, string $balance = '0.0000'): void
    {
        EMoney::create(['user_id' => $userId, 'current_balance' => MoneyService::normalize($balance), 'charge_earned' => '0.0000',
            'pending_balance' => '0.0000', 'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0]);
    }
}
