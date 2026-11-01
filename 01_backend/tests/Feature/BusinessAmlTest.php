<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessRefund;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\MoneyService;
use App\Services\PaymentRequestService;
use Database\Seeders\AmlDefaultRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AMIAL-BUSINESS-AML-001 — فحص الغسل على ما أُضيف من مسارات مال:
 *   - سحب التاجر بقواعده هو (لا حدود الأفراد)، وسحب العميل صار يُفحص عند الطلب.
 *   - الاسترداد يُفحص: نسبةٌ كبيرة إلى المبيعات تُحجز، والتفويض لا يتجاوز الفحص.
 */
class BusinessAmlTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'aml-inbound-secret';

    private User $merchant;
    private User $payer;
    private BusinessLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial.aml.enabled' => true, 'amial_business.inbound_secret' => self::SECRET, 'amial_business.webhook_secret' => 'w', 'amial_business.webhook_url' => 'https://b.test/h',
            'amial.merchant_cash_out.max_per_request' => '1000000', 'amial.merchant_cash_out.daily_limit' => '5000000']);
        $this->seed(AmlDefaultRulesSeeder::class);
        $this->merchant = User::factory()->create(['type' => 3, 'phone' => '+967700700001', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1, 'transaction_pin' => Hash::make('4321')]);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $this->payer = User::factory()->create(['type' => 2, 'phone' => '+967700700002', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1, 'transaction_pin' => Hash::make('1234')]);
        $this->wallet(User::factory()->create(['type' => 0])->id);
        $this->wallet($this->merchant->id, '900000');
        $this->wallet($this->payer->id, '50000');
        $this->link = app(BusinessLinkService::class)->link($this->merchant);
    }

    #[Test]
    public function a_merchant_cash_out_follows_merchant_rules_not_individual_limits(): void
    {
        Passport::actingAs($this->merchant, [], 'api');
        // 100,000 كانت ستُحجب بحدّ الأفراد (50,000)؛ للتاجر تمرّ.
        $this->postJson('/api/v1/amial/merchant/cash-out', ['amount' => '100000', 'pin' => '4321'])->assertStatus(201);
        // فوق 300,000 تنتظر مراجعة الامتثال: لا طلب ولا حجز.
        $this->postJson('/api/v1/amial/merchant/cash-out', ['amount' => '350000', 'pin' => '4321'])->assertStatus(202);
        $this->assertSame(1, WithdrawalRequest::count());
        $this->assertSame('100000.0000', (string) EMoney::where('user_id', $this->merchant->id)->value('held_balance'));
    }

    #[Test]
    public function a_customer_withdraw_request_is_screened_now(): void
    {
        $rich = User::factory()->create(['type' => 2, 'phone' => '+967700700003', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1, 'kyc_tier' => 3]);
        $this->wallet($rich->id, '200000');
        Passport::actingAs($rich, [], 'api');

        $this->postJson('/api/v1/amial/withdraw/request', ['amount' => '60000'])->assertStatus(403); // MAX_SINGLE_TX_HARD
        $this->assertSame(0, WithdrawalRequest::count());
    }

    #[Test]
    public function refunds_far_above_sales_wait_for_compliance_even_within_the_mandate(): void
    {
        $intent = $this->paidIntent('INV-1', '40000');
        $this->paidIntent('INV-2', '50000');
        $this->link->update(['refund_auto_limit' => '100000', 'refund_daily_limit' => '100000']);

        // استرداد صغير ضمن النسبة: يمرّ فوراً بالتفويض
        $this->refund('SR-1', $intent, '5000')->assertJsonPath('code', 'REFUND_COMPLETED');
        // 5,000 + 35,000 = 40,000 من 90,000 (44%) لكن دون 50,000: لا حكم على مبالغ صغيرة
        $this->refund('SR-2', $intent, '35000')->assertJsonPath('code', 'REFUND_COMPLETED');

        $second = $this->paidIntent('INV-3', '30000');
        // 40,000 + 20,000 = 60,000 من 120,000 (50%) ⇒ يُحجز رغم التفويض
        $id = $this->refund('SR-3', $second, '20000')->assertJsonPath('code', 'REFUND_PENDING_APPROVAL')->json('meta.refund_id');
        $this->assertSame('pending_approval', BusinessRefund::where('refund_ulid', $id)->value('status'));

        // وموافقة التاجر برمزه لا تتجاوز الفحص أيضاً
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/merchant/business-refunds/{$id}/approve", ['pin' => '4321'])->assertStatus(202);
        $this->assertSame('pending_approval', BusinessRefund::where('refund_ulid', $id)->value('status'));
    }

    #[Test]
    public function a_merchant_under_security_hold_cannot_refund(): void
    {
        $intent = $this->paidIntent('INV-1', '10000');
        $this->merchant->forceFill(['security_hold_until' => now()->addDay()])->save();
        $id = $this->refund('SR-1', $intent, '1000')->json('meta.refund_id');

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/merchant/business-refunds/{$id}/approve", ['pin' => '4321'])->assertStatus(422);
        $this->assertSame('pending_approval', BusinessRefund::where('refund_ulid', $id)->value('status'));
        $this->assertSame('910000.0000', $this->balance($this->merchant->id)); // 900,000 + 10,000 ولم يخرج شيء
    }

    private function paidIntent(string $ref, string $amount): string
    {
        $p = User::factory()->create(['type' => 2, 'phone' => '+96770080'.random_int(1000, 9999), 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1, 'kyc_tier' => 3]);
        $this->wallet($p->id, $amount);
        $meta = $this->signed('POST', '/api/v1/business/payment-intents', ['business_reference' => $ref, 'amount' => $amount, 'currency' => 'YER'])->json('meta');
        app(PaymentRequestService::class)->pay($p, PaymentRequest::where('short_code', $meta['short_code'])->firstOrFail());

        return $meta['intent_id'];
    }

    private function refund(string $ref, string $intent, string $amount): TestResponse
    {
        return $this->signed('POST', '/api/v1/business/refunds', ['business_reference' => $ref, 'intent_id' => $intent, 'amount' => $amount]);
    }

    private function signed(string $method, string $path, ?array $body): TestResponse
    {
        $ts = (string) time();
        $nonce = Str::random(32);
        $content = $body === null ? '' : json_encode($body);
        $sig = BusinessGatewaySignature::sign(BusinessGatewaySignature::inboundCanonical($ts, $nonce, $method, $path, '', $content), self::SECRET);

        return $this->call($method, $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $ts, 'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce, 'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => $sig,
            'HTTP_X_AMIAL_BUSINESS_LINK' => $this->link->link_ulid], $content);
    }

    private function wallet(int $id, string $balance = '0'): void
    {
        EMoney::create(['user_id' => $id, 'current_balance' => MoneyService::normalize($balance), 'held_balance' => '0', 'pending_balance' => '0', 'charge_earned' => '0', 'zone_code' => 'SOUTH']);
    }

    private function balance(int $id): string
    {
        return (string) EMoney::where('user_id', $id)->value('current_balance');
    }
}
