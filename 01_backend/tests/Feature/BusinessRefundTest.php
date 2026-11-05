<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessOutboxEvent;
use App\Models\BusinessGateway\BusinessRefund;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\BusinessGateway\BusinessRefundService;
use App\Services\MoneyService;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AMIAL-BUSINESS-REFUND-001 — استرداد دفعات أميال أعمال:
 *   - توقيع القناة يطلب ولا يُخرج مالاً؛ المال يخرج بموافقة التاجر (PIN) أو ضمن تفويضه.
 *   - المال يعود لمن دفع فقط، ولا يتجاوز المجموعُ الدفعَ (والمعلّق يحجز).
 *   - التنفيذ مرة واحدة، وكل نهاية تُبلَّغ لأميال أعمال.
 */
class BusinessRefundTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-inbound-secret-not-for-production';

    private User $merchant;
    private User $payer;
    private BusinessLink $link;
    private array $intent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial_business.inbound_secret' => self::SECRET, 'amial_business.webhook_secret' => 'w-secret', 'amial_business.webhook_url' => 'https://b.test/hook']);
        $this->merchant = User::factory()->create(['type' => 3, 'phone' => '+967700400001', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1, 'transaction_pin' => Hash::make('4321')]);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $this->payer = User::factory()->create(['type' => 2, 'phone' => '+967700400002', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1]);
        $this->fund($this->merchant, '0');
        $this->fund($this->payer, '50000');
        $this->link = app(BusinessLinkService::class)->link($this->merchant);

        $this->intent = $this->signed('POST', '/api/v1/business/payment-intents', ['business_reference' => 'INV-1', 'amount' => '10000', 'currency' => 'YER'])->json('meta');
        app(PaymentRequestService::class)->pay($this->payer, PaymentRequest::where('short_code', $this->intent['short_code'])->firstOrFail());
    }

    #[Test]
    public function a_refund_waits_for_the_merchants_pin_then_returns_the_money_once(): void
    {
        $meta = $this->refund('SR-1', '4000')->assertStatus(201)->assertJsonPath('code', 'REFUND_PENDING_APPROVAL')->json('meta');
        $this->assertSame('10000.0000', $this->balance($this->merchant)); // لم يتحرّك شيء
        $this->assertSame('40000.0000', $this->balance($this->payer));

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/merchant/business-refunds/{$meta['refund_id']}/approve", ['pin' => '0000'])->assertStatus(403);
        $this->assertSame('10000.0000', $this->balance($this->merchant));

        $this->postJson("/api/v1/amial/merchant/business-refunds/{$meta['refund_id']}/approve", ['pin' => '4321'])->assertOk()->assertJsonPath('meta.status', 'completed');
        $this->postJson("/api/v1/amial/merchant/business-refunds/{$meta['refund_id']}/approve", ['pin' => '4321'])->assertOk(); // مكرر بلا أثر

        $this->assertSame('6000.0000', $this->balance($this->merchant));
        $this->assertSame('44000.0000', $this->balance($this->payer));
        $refund = BusinessRefund::sole();
        $this->assertSame('merchant_pin', $refund->approval);
        $this->assertNotNull($refund->ledger_entry_ulid);
        $event = BusinessOutboxEvent::where('event_type', 'business_refund.completed')->sole();
        $this->assertSame('SR-1', $event->payload['data']['business_reference']);
    }

    #[Test]
    public function within_the_merchants_mandate_a_refund_completes_immediately_and_stops_at_the_daily_limit(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->putJson('/api/v1/amial/merchant/business-refunds/mandate', ['per_refund' => '3000', 'daily' => '5000', 'pin' => '0000'])->assertStatus(403);
        $this->putJson('/api/v1/amial/merchant/business-refunds/mandate', ['per_refund' => '6000', 'daily' => '5000', 'pin' => '4321'])->assertStatus(422);
        $this->putJson('/api/v1/amial/merchant/business-refunds/mandate', ['per_refund' => '3000', 'daily' => '5000', 'pin' => '4321'])->assertOk();

        $this->refund('SR-1', '3000')->assertJsonPath('code', 'REFUND_COMPLETED')->assertJsonPath('meta.approval', 'mandate');
        $this->refund('SR-2', '3500')->assertJsonPath('code', 'REFUND_PENDING_APPROVAL'); // فوق حدّ العملية
        $this->refund('SR-3', '2500')->assertJsonPath('code', 'REFUND_PENDING_APPROVAL'); // يتجاوز اليومي (3000+2500)
        $this->assertSame('7000.0000', $this->balance($this->merchant));
    }

    #[Test]
    public function refunds_never_exceed_the_payment_and_pending_ones_count(): void
    {
        $this->refund('SR-1', '7000')->assertStatus(201);
        $this->refund('SR-2', '3001')->assertStatus(409)->assertJsonPath('code', 'REFUND_EXCEEDS_PAYMENT');
        $this->refund('SR-3', '3000')->assertStatus(201);
        $this->assertSame(2, BusinessRefund::count());
    }

    #[Test]
    public function the_same_reference_replays_and_another_amount_conflicts(): void
    {
        $first = $this->refund('SR-1', '1000')->json('meta.refund_id');
        $this->assertSame($first, $this->refund('SR-1', '1000')->json('meta.refund_id'));
        $this->refund('SR-1', '1500')->assertStatus(409)->assertJsonPath('code', 'BUSINESS_REFERENCE_CONFLICT');
    }

    #[Test]
    public function another_merchant_cannot_see_or_approve_it(): void
    {
        $id = $this->refund('SR-1', '1000')->json('meta.refund_id');
        $other = User::factory()->create(['type' => 3, 'phone' => '+967700400009', 'is_active' => 1, 'transaction_pin' => Hash::make('4321')]);

        Passport::actingAs($other, [], 'api');
        $this->postJson("/api/v1/amial/merchant/business-refunds/{$id}/approve", ['pin' => '4321'])->assertStatus(404);
        $this->getJson('/api/v1/amial/merchant/business-refunds')->assertOk()->assertJsonPath('meta.linked', false);
        $this->assertSame('pending_approval', BusinessRefund::sole()->status);
    }

    #[Test]
    public function rejected_and_expired_refunds_move_nothing_and_are_reported(): void
    {
        $a = $this->refund('SR-1', '1000')->json('meta.refund_id');
        $this->refund('SR-2', '2000');
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/merchant/business-refunds/{$a}/reject", ['note' => 'المنتج غير مرتجع'])->assertOk()->assertJsonPath('meta.status', 'rejected');

        $this->travel(73)->hours();
        $this->assertSame(1, app(BusinessRefundService::class)->expireDue());

        $this->assertSame('10000.0000', $this->balance($this->merchant));
        $this->assertSame(['business_refund.expired', 'business_refund.rejected'], BusinessOutboxEvent::where('event_type', 'like', 'business_refund.%')->orderBy('event_type')->pluck('event_type')->all());
        // المرفوض والمنتهي لا يحجزان: المبلغ كله قابل للاسترداد مجدداً
        $this->refund('SR-3', '10000')->assertStatus(201);
    }

    #[Test]
    public function an_insufficient_merchant_balance_keeps_the_refund_pending(): void
    {
        EMoney::where('user_id', $this->merchant->id)->update(['current_balance' => '500']);
        $id = $this->refund('SR-1', '1000')->json('meta.refund_id');

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/merchant/business-refunds/{$id}/approve", ['pin' => '4321'])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_BALANCE');
        $this->assertSame('pending_approval', BusinessRefund::sole()->status);
        $this->assertSame('40000.0000', $this->balance($this->payer));
    }

    #[Test]
    public function an_unpaid_intent_cannot_be_refunded(): void
    {
        $unpaid = $this->signed('POST', '/api/v1/business/payment-intents', ['business_reference' => 'INV-2', 'amount' => '500', 'currency' => 'YER'])->json('meta');
        $this->signed('POST', '/api/v1/business/refunds', ['business_reference' => 'SR-9', 'intent_id' => $unpaid['intent_id'], 'amount' => '100'])
            ->assertStatus(409)->assertJsonPath('code', 'INTENT_NOT_PAID');
    }

    private function refund(string $reference, string $amount): TestResponse
    {
        return $this->signed('POST', '/api/v1/business/refunds', ['business_reference' => $reference, 'intent_id' => $this->intent['intent_id'], 'amount' => $amount, 'reason' => 'مرتجع']);
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

    private function fund(User $user, string $amount): void
    {
        EMoney::create(['user_id' => $user->id, 'current_balance' => MoneyService::normalize($amount), 'held_balance' => '0', 'pending_balance' => '0', 'charge_earned' => '0', 'zone_code' => 'SOUTH']);
    }

    private function balance(User $user): string
    {
        return (string) EMoney::where('user_id', $user->id)->value('current_balance');
    }
}
