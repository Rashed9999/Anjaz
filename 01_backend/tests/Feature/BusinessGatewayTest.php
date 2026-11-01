<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessOutboxEvent;
use App\Models\BusinessGateway\BusinessPaymentIntent;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\BusinessGateway\BusinessPaymentIntentService;
use App\Services\BusinessGateway\BusinessWebhookDispatcher;
use App\Services\MerchantPaymentReferenceService;
use App\Services\MoneyService;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — عقد قناة «أميال أعمال».
 *
 * ما يحرسه: لا طلب بلا توقيع صالح ولا إعادة إرسال · لا نيّتان لمرجع واحد ·
 * المال يتحرّك مرةً واحدة وبالمبلغ نفسه · نيّةٌ ملغاة أو منتهية لا تقبل مالاً ·
 * دفعٌ استُهلك هنا لا يُستهلك في كاشير أميال باي · الأحداث تُكتب مع سببها
 * وتُسلَّم موقّعة وتُعاد عند الفشل.
 */
class BusinessGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const INBOUND_SECRET = 'test-inbound-secret-not-for-production';
    private const WEBHOOK_SECRET = 'test-webhook-secret-not-for-production';

    private User $merchant;
    private User $payer;
    private BusinessLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'amial_business.inbound_secret' => self::INBOUND_SECRET,
            'amial_business.webhook_secret' => self::WEBHOOK_SECRET,
            'amial_business.webhook_url' => 'https://business.test/api/amial-pay/webhooks',
        ]);

        $this->merchant = $this->makeMerchant('+967700100001');
        $this->payer = User::factory()->create([
            'type' => 2, 'phone' => '+967700100002', 'zone_code' => 'SOUTH',
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        $this->fund($this->merchant, '0');
        $this->fund($this->payer, '50000');

        $this->link = app(BusinessLinkService::class)->link($this->merchant);

        // لا اختبار هنا يخرج إلى الشبكة (كان `amial:business tick` يحاول التسليم إلى business.test فعلاً).
        // والموزّع يبتلع أيّ استثناء ليعيد المحاولة لاحقاً — وهو سلوكه الصحيح — فيبتلع رفضَ الطلب الشارد
        // أيضاً. فالحارس يقرأ الأثر في tearDown لا الاستثناء.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $stray = BusinessOutboxEvent::where('last_error', 'like', '%Attempted request to%')->count();
        parent::tearDown();
        $this->assertSame(0, $stray, 'اختبارٌ حاول طلب شبكةٍ حقيقيّاً بلا Http::fake');
    }

    // ── المصادقة ───────────────────────────────────────────────────────

    #[Test]
    public function unsigned_request_is_rejected(): void
    {
        $this->getJson('/api/v1/business/link', ['X-Amial-Business-Link' => $this->link->link_ulid])
            ->assertStatus(401)
            ->assertJsonPath('code', 'BUSINESS_SIGNATURE_EXPIRED');
    }

    #[Test]
    public function wrong_secret_is_rejected(): void
    {
        $this->signed('GET', '/api/v1/business/link', null, secret: 'another-secret')
            ->assertStatus(401)
            ->assertJsonPath('code', 'BUSINESS_SIGNATURE_INVALID');
    }

    #[Test]
    public function tampered_body_is_rejected(): void
    {
        $headers = $this->signHeaders('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-1', '100'));
        $tampered = json_encode($this->intentBody('INV-1', '999999'));

        $this->call('POST', '/api/v1/business/payment-intents', [], [], [], $this->server($headers), $tampered)
            ->assertStatus(401)
            ->assertJsonPath('code', 'BUSINESS_SIGNATURE_INVALID');

        $this->assertSame(0, BusinessPaymentIntent::count());
    }

    #[Test]
    public function stale_timestamp_is_rejected(): void
    {
        $this->signed('GET', '/api/v1/business/link', null, timestamp: (string) (time() - 3600))
            ->assertStatus(401)
            ->assertJsonPath('code', 'BUSINESS_SIGNATURE_EXPIRED');
    }

    #[Test]
    public function replayed_request_is_rejected(): void
    {
        $headers = $this->signHeaders('GET', '/api/v1/business/link', null);

        $this->call('GET', '/api/v1/business/link', [], [], [], $this->server($headers))->assertOk();
        $this->call('GET', '/api/v1/business/link', [], [], [], $this->server($headers))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BUSINESS_REQUEST_REPLAYED');
    }

    #[Test]
    public function gateway_is_closed_without_a_secret(): void
    {
        config(['amial_business.inbound_secret' => '']);

        $this->signed('GET', '/api/v1/business/link', null)
            ->assertStatus(503)
            ->assertJsonPath('code', 'BUSINESS_GATEWAY_NOT_CONFIGURED');
    }

    #[Test]
    public function unknown_link_is_rejected(): void
    {
        $this->signed('GET', '/api/v1/business/link', null, linkUlid: (string) Str::ulid())
            ->assertStatus(404)
            ->assertJsonPath('code', 'BUSINESS_LINK_NOT_FOUND');
    }

    #[Test]
    public function link_summary_exposes_no_balance(): void
    {
        $meta = $this->signed('GET', '/api/v1/business/link', null)->assertOk()->json('meta');

        $this->assertSame($this->link->link_ulid, $meta['link_id']);
        $this->assertTrue($meta['can_receive_payments']);
        $this->assertSame('YER', $meta['wallet_currency']);
        $this->assertArrayNotHasKey('balance', $meta);
        $this->assertArrayNotHasKey('merchant_user_id', $meta);
    }

    // ── إنشاء النيّة ───────────────────────────────────────────────────

    #[Test]
    public function creating_an_intent_makes_a_short_lived_qr_request(): void
    {
        $meta = $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-1', '1500.50'))
            ->assertStatus(201)
            ->assertJsonPath('code', 'INTENT_READY')
            ->json('meta');

        $this->assertSame('pending', $meta['status']);
        $this->assertSame('1500.5000', $meta['amount']);
        $this->assertNotEmpty($meta['short_code']);
        $this->assertStringContainsString('/req/', $meta['qr_payload']);

        $request = PaymentRequest::where('short_code', $meta['short_code'])->firstOrFail();
        $this->assertSame($this->merchant->id, $request->requester_user_id);
        $this->assertSame(PaymentRequest::SHARE_QR, $request->share_method);
        $this->assertTrue($request->expires_at->lte(now()->addSeconds(300)));
    }

    #[Test]
    public function the_same_reference_returns_the_same_intent(): void
    {
        $first = $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-2', '100'))->json('meta');
        $second = $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-2', '100'))->json('meta');

        $this->assertSame($first['intent_id'], $second['intent_id']);
        $this->assertSame(1, BusinessPaymentIntent::count());
        $this->assertSame(1, PaymentRequest::count());
    }

    #[Test]
    public function the_same_reference_with_another_amount_is_a_conflict(): void
    {
        $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-3', '100'))->assertStatus(201);

        $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-3', '101'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BUSINESS_REFERENCE_CONFLICT');

        $this->assertSame(1, PaymentRequest::count());
    }

    #[Test]
    public function amounts_with_more_than_two_decimals_are_rejected(): void
    {
        $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-4', '10.005'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION');
    }

    #[Test]
    public function only_the_wallet_currency_is_accepted(): void
    {
        $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-5', '100', 'SAR'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CURRENCY_NOT_SUPPORTED');
    }

    #[Test]
    public function an_unverified_merchant_cannot_request_payment(): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)->update(['verification_status' => 'unverified']);

        $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-6', '100'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'KYC_REQUIRED');
    }

    #[Test]
    public function a_suspended_link_cannot_request_payment(): void
    {
        $this->link->update(['status' => BusinessLink::STATUS_SUSPENDED]);

        $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody('INV-7', '100'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'BUSINESS_LINK_INACTIVE');
    }

    #[Test]
    public function one_link_cannot_read_another_links_intent(): void
    {
        $intentId = $this->createIntent('INV-8', '100')['intent_id'];

        $other = app(BusinessLinkService::class)->link($this->makeMerchant('+967700100009'));

        $this->signed('GET', "/api/v1/business/payment-intents/{$intentId}", null, linkUlid: $other->link_ulid)
            ->assertStatus(404)
            ->assertJsonPath('code', 'INTENT_NOT_FOUND');
    }

    // ── المال ──────────────────────────────────────────────────────────

    #[Test]
    public function paying_the_qr_moves_the_exact_amount_once_and_records_the_event(): void
    {
        $intent = $this->createIntent('INV-9', '2500');
        $request = PaymentRequest::where('short_code', $intent['short_code'])->firstOrFail();

        $result = app(PaymentRequestService::class)->pay($this->payer, $request);

        $this->assertSame(MoneyService::normalize('2500'), $this->balance($this->merchant));
        $this->assertSame(MoneyService::normalize('47500'), $this->balance($this->payer));

        $meta = $this->signed('GET', "/api/v1/business/payment-intents/{$intent['intent_id']}", null)->json('meta');
        $this->assertSame('succeeded', $meta['status']);
        $this->assertSame($result['transaction_id'], $meta['paid_transaction_id']);

        $events = BusinessOutboxEvent::where('event_type', 'payment_intent.succeeded')->get();
        $this->assertCount(1, $events);
        $this->assertSame($intent['intent_id'], $events[0]->payload['data']['intent_id']);
        $this->assertSame('INV-9', $events[0]->payload['data']['business_reference']);
        // AMIAL-BUSINESS-FEES-001: الحدث يحمل الرسم والصافي، فلا تحسبهما أميال أعمال.
        $this->assertSame('0.0000', $events[0]->payload['data']['fee']);
        $this->assertSame('2500.0000', $events[0]->payload['data']['net_amount']);
    }

    #[Test]
    public function both_receipts_carry_the_amial_business_invoice_number(): void
    {
        $intent = $this->createIntent('INV-77', '1200');
        $request = PaymentRequest::where('short_code', $intent['short_code'])->firstOrFail();
        $tx = app(PaymentRequestService::class)->pay($this->payer, $request)['transaction_id'];

        $receipts = \App\Models\Receipt::where('reference_transaction_id', $tx)->get();
        $this->assertCount(2, $receipts);
        foreach ($receipts as $receipt) {
            $this->assertSame('amial_business_intent', $receipt->reference_type);
            $this->assertSame('INV-77', $receipt->metadata['business_reference']);

            $doc = app(\App\Services\ReceiptDocumentService::class)->build($receipt);
            $this->assertSame('سند دفع فاتورة عبر أميال باي', $doc['title']);
            $this->assertContains(['label' => 'رقم فاتورة أميال أعمال', 'value' => 'INV-77'], $doc['context_fields']);
        }
    }

    #[Test]
    public function the_fee_quote_comes_from_the_fee_engine(): void
    {
        \App\Models\FeeScheme::create(['code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'merchant', 'fee_type' => 'percent', 'percent_rate' => '1.0000',
            'fixed_amount' => '0', 'agent_commission_percent' => '50.0000', 'agent_commission_fixed' => '0', 'bearer' => 'sender', 'version' => 1,
            'is_active' => true, 'effective_from' => now()]);

        $meta = $this->signed('GET', '/api/v1/business/fees/quote?operation=merchant_cash_out&amount=100000', null)->assertOk()->json('meta');
        $this->assertSame(['100000.0000', '1000.0000', '100000.0000', '101000.0000'], [$meta['amount'], $meta['fee'], $meta['net_amount'], $meta['total_debit']]);

        $wallet = $this->signed('GET', '/api/v1/business/fees/quote?operation=wallet_payment&amount=500', null)->assertOk()->json('meta');
        $this->assertSame(['0.0000', '500.0000'], [$wallet['fee'], $wallet['net_amount']]);

        $this->signed('GET', '/api/v1/business/fees/quote?operation=transfer&amount=500', null)->assertStatus(422);
    }

    #[Test]
    public function a_second_payment_of_the_same_qr_is_rejected(): void
    {
        $intent = $this->createIntent('INV-10', '1000');
        $request = PaymentRequest::where('short_code', $intent['short_code'])->firstOrFail();

        app(PaymentRequestService::class)->pay($this->payer, $request);

        try {
            app(PaymentRequestService::class)->pay($this->payer, $request->fresh());
            $this->fail('الدفع الثاني كان يجب أن يُرفض');
        } catch (\RuntimeException) {
        }

        $this->assertSame(MoneyService::normalize('1000'), $this->balance($this->merchant));
        $this->assertSame(MoneyService::normalize('49000'), $this->balance($this->payer));
        $this->assertSame(1, BusinessOutboxEvent::where('event_type', 'payment_intent.succeeded')->count());
    }

    #[Test]
    public function a_cancelled_intent_cannot_be_paid(): void
    {
        $intent = $this->createIntent('INV-11', '1000');

        $this->signed('POST', "/api/v1/business/payment-intents/{$intent['intent_id']}/cancel", null)
            ->assertOk()
            ->assertJsonPath('meta.status', 'cancelled');

        $request = PaymentRequest::where('short_code', $intent['short_code'])->firstOrFail();
        $this->assertSame('cancelled', $request->status);

        $this->expectException(\RuntimeException::class);
        try {
            app(PaymentRequestService::class)->pay($this->payer, $request);
        } finally {
            $this->assertSame(MoneyService::normalize('50000'), $this->balance($this->payer));
        }
    }

    #[Test]
    public function a_paid_intent_cannot_be_cancelled(): void
    {
        $intent = $this->createIntent('INV-12', '1000');
        app(PaymentRequestService::class)->pay(
            $this->payer,
            PaymentRequest::where('short_code', $intent['short_code'])->firstOrFail(),
        );

        $this->signed('POST', "/api/v1/business/payment-intents/{$intent['intent_id']}/cancel", null)
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTENT_ALREADY_SUCCEEDED');
    }

    #[Test]
    public function an_expired_intent_is_closed_and_cannot_be_paid(): void
    {
        $intent = $this->createIntent('INV-13', '1000');
        $this->travel(301)->seconds();

        Http::fake(['business.test/*' => Http::response(['ok' => true], 200)]);
        $this->artisan('amial:business tick')->assertSuccessful();

        $request = PaymentRequest::where('short_code', $intent['short_code'])->firstOrFail();
        $this->assertSame('expired', $request->status);
        $this->assertSame('expired', BusinessPaymentIntent::firstOrFail()->status);
        $this->assertSame(1, BusinessOutboxEvent::where('event_type', 'payment_intent.expired')->count());

        $this->expectException(\RuntimeException::class);
        try {
            app(PaymentRequestService::class)->pay($this->payer, $request);
        } finally {
            $this->assertSame(MoneyService::normalize('50000'), $this->balance($this->payer));
        }
    }

    #[Test]
    public function an_intent_that_is_no_longer_pending_rolls_the_whole_payment_back(): void
    {
        // حالةٌ لا يُفترض وقوعها: النيّة أُغلقت وطلب الدفع بقي قابلاً للدفع.
        // المطلوب ألّا يُقبل المال لفاتورةٍ مغلقة — لا رصيد ولا قيد ولا حدث.
        $intent = $this->createIntent('INV-14', '1000');
        BusinessPaymentIntent::where('intent_ulid', $intent['intent_id'])
            ->update(['status' => BusinessPaymentIntent::STATUS_CANCELLED]);
        $request = PaymentRequest::where('short_code', $intent['short_code'])->firstOrFail();

        try {
            app(PaymentRequestService::class)->pay($this->payer, $request);
            $this->fail('كان يجب أن يُرفض الدفع');
        } catch (\RuntimeException) {
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(MoneyService::normalize('50000'), $this->balance($this->payer));
        $this->assertSame(MoneyService::normalize('0'), $this->balance($this->merchant));
        $this->assertSame(0, BusinessOutboxEvent::where('event_type', 'payment_intent.succeeded')->count());
    }

    #[Test]
    public function a_business_payment_cannot_be_reused_by_the_amial_pay_cashier(): void
    {
        $intent = $this->createIntent('INV-15', '1000');
        $result = app(PaymentRequestService::class)->pay(
            $this->payer,
            PaymentRequest::where('short_code', $intent['short_code'])->firstOrFail(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('تم استخدام رمز الدفع');
        app(MerchantPaymentReferenceService::class)
            ->assertPaidForMerchant($this->merchant, $result['transaction_id'], '1000');
    }

    // ── تسليم الأحداث ──────────────────────────────────────────────────

    #[Test]
    public function events_are_delivered_signed(): void
    {
        Http::fake(['business.test/*' => Http::response(['ok' => true], 200)]);

        $result = app(BusinessWebhookDispatcher::class)->dispatchDue();

        $this->assertSame(1, $result['delivered']); // business_link.created
        Http::assertSent(function (HttpRequest $request) {
            $timestamp = $request->header('X-Amial-Pay-Timestamp')[0];
            $expected = BusinessGatewaySignature::outbound($timestamp, $request->body(), self::WEBHOOK_SECRET);

            return $request->header('X-Amial-Pay-Signature')[0] === $expected
                && $request->header('X-Amial-Pay-Event-Type')[0] === 'business_link.created';
        });
        $this->assertSame('delivered', BusinessOutboxEvent::firstOrFail()->status);
    }

    #[Test]
    public function failed_deliveries_back_off_then_die_without_being_deleted(): void
    {
        Http::fake(['business.test/*' => Http::response('down', 500)]);
        $dispatcher = app(BusinessWebhookDispatcher::class);

        $dispatcher->dispatchDue();
        $event = BusinessOutboxEvent::firstOrFail();
        $this->assertSame('pending', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertTrue($event->next_attempt_at->isFuture());

        $steps = count(config('amial_business.webhook_backoff_seconds'));
        for ($i = 0; $i < $steps; $i++) {
            $this->travel(13)->hours();
            $dispatcher->dispatchDue();
        }

        $event->refresh();
        $this->assertSame('dead', $event->status);
        $this->assertSame($steps + 1, $event->attempts);
    }

    #[Test]
    public function nothing_is_sent_or_lost_while_the_webhook_is_unconfigured(): void
    {
        Http::fake();
        config(['amial_business.webhook_url' => '']);

        $result = app(BusinessWebhookDispatcher::class)->dispatchDue();

        $this->assertSame('BUSINESS_WEBHOOK_NOT_CONFIGURED', $result['skipped']);
        Http::assertNothingSent();
        $this->assertSame('pending', BusinessOutboxEvent::firstOrFail()->status);
    }

    // ── أدوات ──────────────────────────────────────────────────────────

    private function createIntent(string $reference, string $amount): array
    {
        return $this->signed('POST', '/api/v1/business/payment-intents', $this->intentBody($reference, $amount))
            ->assertStatus(201)
            ->json('meta');
    }

    private function intentBody(string $reference, string $amount, string $currency = 'YER'): array
    {
        return ['business_reference' => $reference, 'amount' => $amount, 'currency' => $currency];
    }

    private function signed(
        string $method,
        string $path,
        ?array $body,
        ?string $secret = null,
        ?string $timestamp = null,
        ?string $linkUlid = null,
    ): TestResponse {
        $headers = $this->signHeaders($method, $path, $body, $secret, $timestamp, $linkUlid);
        $content = $body === null ? '' : json_encode($body);

        return $this->call($method, $path, [], [], [], $this->server($headers), $content);
    }

    private function signHeaders(
        string $method,
        string $path,
        ?array $body,
        ?string $secret = null,
        ?string $timestamp = null,
        ?string $linkUlid = null,
    ): array {
        $timestamp ??= (string) time();
        $nonce = Str::random(32);
        $content = $body === null ? '' : json_encode($body);
        [$pathOnly, $query] = array_pad(explode('?', $path, 2), 2, '');
        $canonical = BusinessGatewaySignature::inboundCanonical($timestamp, $nonce, $method, $pathOnly, \Symfony\Component\HttpFoundation\Request::normalizeQueryString($query), $content);

        return [
            'X-Amial-Business-Timestamp' => $timestamp,
            'X-Amial-Business-Nonce' => $nonce,
            'X-Amial-Business-Signature' => BusinessGatewaySignature::sign($canonical, $secret ?? self::INBOUND_SECRET),
            'X-Amial-Business-Link' => $linkUlid ?? $this->link->link_ulid,
        ];
    }

    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private function makeMerchant(string $phone): User
    {
        $merchant = User::factory()->create([
            'type' => 3, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        MerchantProfile::create(['user_id' => $merchant->id, 'verification_status' => 'verified']);

        return $merchant;
    }

    private function fund(User $user, string $amount): void
    {
        EMoney::create([
            'user_id' => $user->id, 'current_balance' => MoneyService::normalize($amount),
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    private function balance(User $user): string
    {
        return (string) EMoney::where('user_id', $user->id)->value('current_balance');
    }
}
