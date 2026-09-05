<?php

namespace Tests\Feature;

use App\Models\BillPaymentOrder;
use App\Models\BillProvider;
use App\Models\BillService;
use App\Models\EMoney;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\WhatsappLinkedDevice;
use App\Services\BillPayService;
use App\Services\Whatsapp\IntentParser;
use App\Services\Whatsapp\WhatsappApiClient;
use App\Services\Whatsapp\WhatsappBotService;
use App\Services\Whatsapp\WhatsappMediaDownloader;
use App\Services\Whatsapp\WhatsappQrDecoderService;
use App\Services\Whatsapp\WhatsappSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * AMIAL-WA-003 — اختبارات دفع الفواتير (Section 22) وطلبات الدفع عبر
 * رابط/رمز/QR (Section 23).
 *
 * منهجية الاختبار:
 *   - تدفّق طلبات الدفع (Payment Request): خدمات حقيقية بالكامل (لا Mock)
 *     لأنّ PaymentRequestService::pay() حتمي تماماً (لا عشوائية) —
 *     نتحقّق من الأرصدة الفعلية قبل/بعد، لا فقط من رسائل النجاح.
 *   - تدفّق الفواتير: BillPayService مُموَّه (Mockery) عند نقطة
 *     createAndExecute() فقط، لأنّ StubProvider::pay() الحقيقي عشوائي
 *     بنسبة (crc32 % 100) — اختباره هنا بصدقية كاملة سيكون flaky.
 *     باقي التدفّق (inquire، اختيار الخدمة، التحقّقات) حقيقي 100% عبر
 *     StubProvider الفعلي الحتمي في inquire().
 */
class WhatsappBillPayAndPaymentRequestTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappApiClient $mockClient;
    private WhatsappBotService $bot;
    private User $payer;
    private const WA_NUMBER = '967777555444';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(WhatsappApiClient::class);
        $this->app->instance(WhatsappApiClient::class, $this->mockClient);

        $this->bot = app(WhatsappBotService::class);

        $this->payer = User::factory()->create([
            'phone' => '967777555444', 'f_name' => 'سالم', 'l_name' => 'ناصر',
            'type' => 3, 'zone_code' => 'SOUTH',
        ]);
        EMoney::create(['user_id' => $this->payer->id, 'current_balance' => 100000, 'pending_balance' => 0]);

        WhatsappLinkedDevice::create([
            'user_id' => $this->payer->id, 'whatsapp_number' => self::WA_NUMBER,
            'status' => WhatsappLinkedDevice::STATUS_ACTIVE,
            'device_fingerprint' => hash('sha256', self::WA_NUMBER),
            'otp_verified_at' => now(), 'risk_score' => 0,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // Section 22 — دفع الفواتير
    // ════════════════════════════════════════════════════════════

    private function seedBillService(): BillService
    {
        $provider = BillProvider::create([
            'code' => 'test_stub', 'name' => 'Test Stub', 'display_name_ar' => 'مزوّد تجريبي',
            'integration_type' => 'stub', 'is_active' => true, 'zone_code' => 'SOUTH',
        ]);
        return BillService::create([
            'provider_id' => $provider->id, 'code' => 'test_electricity',
            'name' => 'Electricity', 'display_name_ar' => 'فاتورة الكهرباء',
            'service_type' => 'postpaid', 'is_active' => true, 'requires_account_number' => true,
        ]);
    }

    /** @test */
    public function bill_pay_shows_service_list_from_menu(): void
    {
        $this->seedBillService();
        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'فاتورة الكهرباء'));

        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');
    }

    /** @test */
    public function bill_pay_empty_list_when_no_zone_match(): void
    {
        $provider = BillProvider::create([
            'code' => 'north_stub', 'name' => 'North', 'display_name_ar' => 'شمالي',
            'integration_type' => 'stub', 'is_active' => true, 'zone_code' => 'NORTH', // ← منطقة مختلفة
        ]);
        BillService::create([
            'provider_id' => $provider->id, 'code' => 'x', 'name' => 'X',
            'display_name_ar' => 'خدمة شمالية', 'service_type' => 'postpaid', 'is_active' => true,
        ]);

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'لا توجد خدمات'));

        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');
    }

    /** @test */
    public function bill_pay_picks_service_by_number(): void
    {
        $this->seedBillService();
        $this->mockClient->shouldReceive('sendText')->once(); // القائمة
        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'رقم الاشتراك'));
        $this->bot->handle(self::WA_NUMBER, '1');

        $mgr = app(WhatsappSessionManager::class);
        $this->assertEquals(
            WhatsappSessionManager::STEP_BILLPAY_ACCOUNT, $mgr->currentStep(self::WA_NUMBER)
        );
    }

    /** @test */
    public function bill_pay_picks_service_by_partial_name(): void
    {
        $this->seedBillService();
        $this->mockClient->shouldReceive('sendText')->once();
        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'رقم الاشتراك'));
        $this->bot->handle(self::WA_NUMBER, 'كهرباء');
    }

    /** @test */
    public function bill_pay_unmatched_name_shows_retry(): void
    {
        $this->seedBillService();
        $this->mockClient->shouldReceive('sendText')->once();
        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'لم أتعرّف'));
        $this->bot->handle(self::WA_NUMBER, 'خدمة-غير-موجودة-أبداً');
    }

    /** @test */
    public function bill_pay_account_ending_in_zero_fails_inquiry(): void
    {
        // StubProvider::inquire() الحقيقي: أرقام تنتهي بـ 0 = غير موجود (حتمي)
        $this->seedBillService();
        $this->mockClient->shouldReceive('sendText')->twice(); // القائمة + طلب الرقم
        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');
        $this->bot->handle(self::WA_NUMBER, 'كهرباء');

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'تعذّر التحقّق'));
        $this->bot->handle(self::WA_NUMBER, '12340'); // ينتهي بصفر
    }

    /** @test */
    public function bill_pay_valid_account_asks_for_amount(): void
    {
        // StubProvider الحقيقي: balance_due دائماً '0' → يُطلَب المبلغ (حتمي)
        $this->seedBillService();
        $this->mockClient->shouldReceive('sendText')->twice();
        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');
        $this->bot->handle(self::WA_NUMBER, 'كهرباء');

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'أدخل المبلغ'));
        $this->bot->handle(self::WA_NUMBER, '12345'); // لا ينتهي بصفر
    }

    /** @test */
    public function bill_pay_amount_exceeding_balance_blocks(): void
    {
        $this->seedBillService();
        $this->mockClient->shouldReceive('sendText')->times(3);
        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');
        $this->bot->handle(self::WA_NUMBER, 'كهرباء');
        $this->bot->handle(self::WA_NUMBER, '12345');

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'رصيد غير كافٍ'));
        $this->bot->handle(self::WA_NUMBER, '9999999'); // أكبر من الرصيد (100000)

        $mgr = app(WhatsappSessionManager::class);
        $this->assertEquals(WhatsappSessionManager::STEP_MENU, $mgr->currentStep(self::WA_NUMBER));
    }

    /** @test */
    public function bill_pay_valid_amount_sends_confirmation_and_pin_link(): void
    {
        $this->seedBillService();
        $this->mockClient->shouldReceive('sendText')->times(3);
        $this->bot->handle(self::WA_NUMBER, 'دفع فاتورة');
        $this->bot->handle(self::WA_NUMBER, 'كهرباء');
        $this->bot->handle(self::WA_NUMBER, '12345');

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'تأكيد دفع الفاتورة') && str_contains($msg, 'wa/pin/'));
        $this->bot->handle(self::WA_NUMBER, '5000');

        $mgr = app(WhatsappSessionManager::class);
        $this->assertEquals(WhatsappSessionManager::STEP_BILLPAY_PIN_SENT, $mgr->currentStep(self::WA_NUMBER));
    }

    /** @test */
    public function bill_pay_wrong_pin_rejected_and_bumps_risk(): void
    {
        $service = $this->seedBillService();
        $this->advanceToBillPayPinSent($service);

        $this->payer->update(['transaction_pin' => '1234']);

        $result = $this->bot->executeBillPayAfterPin(self::WA_NUMBER, $this->payer->fresh(), '0000');
        $this->assertFalse($result['success']);

        $link = app(\App\Services\Whatsapp\WhatsappAccountLinkingService::class)->getLink(self::WA_NUMBER);
        $this->assertGreaterThan(0, $link->risk_score);
    }

    /** @test */
    public function bill_pay_correct_pin_success_status_sends_success_message(): void
    {
        $service = $this->seedBillService();
        $this->advanceToBillPayPinSent($service);
        $this->payer->update(['transaction_pin' => '1234']);

        $fakeOrder = new BillPaymentOrder([
            'order_ulid' => (string) Str::ulid(), 'status' => 'success',
        ]);

        $mockSvc = Mockery::mock(BillPayService::class);
        $mockSvc->shouldReceive('createAndExecute')->once()->andReturn($fakeOrder);
        $this->app->instance(BillPayService::class, $mockSvc);
        $bot = app(WhatsappBotService::class); // إعادة بناء مع الـ mock

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'تمّ دفع الفاتورة بنجاح'));

        $result = $bot->executeBillPayAfterPin(self::WA_NUMBER, $this->payer->fresh(), '1234');
        $this->assertTrue($result['success']);
    }

    /** @test */
    public function bill_pay_pending_status_sends_pending_message(): void
    {
        $service = $this->seedBillService();
        $this->advanceToBillPayPinSent($service);
        $this->payer->update(['transaction_pin' => '1234']);

        $fakeOrder = new BillPaymentOrder([
            'order_ulid' => (string) Str::ulid(), 'status' => 'pending_provider_confirmation',
        ]);
        $mockSvc = Mockery::mock(BillPayService::class);
        $mockSvc->shouldReceive('createAndExecute')->once()->andReturn($fakeOrder);
        $this->app->instance(BillPayService::class, $mockSvc);
        $bot = app(WhatsappBotService::class);

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'قيد المعالجة'));

        $result = $bot->executeBillPayAfterPin(self::WA_NUMBER, $this->payer->fresh(), '1234');
        $this->assertTrue($result['success']); // pending ليست failed
    }

    /** @test */
    public function bill_pay_failed_status_sends_failure_message(): void
    {
        $service = $this->seedBillService();
        $this->advanceToBillPayPinSent($service);
        $this->payer->update(['transaction_pin' => '1234']);

        $fakeOrder = new BillPaymentOrder([
            'order_ulid' => (string) Str::ulid(), 'status' => 'failed',
            'provider_message' => 'رصيد المزوّد غير كافٍ',
        ]);
        $mockSvc = Mockery::mock(BillPayService::class);
        $mockSvc->shouldReceive('createAndExecute')->once()->andReturn($fakeOrder);
        $this->app->instance(BillPayService::class, $mockSvc);
        $bot = app(WhatsappBotService::class);

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'فشل دفع الفاتورة'));

        $result = $bot->executeBillPayAfterPin(self::WA_NUMBER, $this->payer->fresh(), '1234');
        $this->assertFalse($result['success']);
    }

    private function advanceToBillPayPinSent(BillService $service): void
    {
        $mgr = app(WhatsappSessionManager::class);
        $mgr->advance(self::WA_NUMBER, WhatsappSessionManager::STEP_BILLPAY_PIN_SENT, [
            'service_id' => $service->id, 'service_name' => $service->display_name_ar,
            'provider_id' => $service->provider_id, 'account' => '12345',
            'amount' => '5000', 'fee' => '0', 'total' => '5000',
        ]);
    }

    // ════════════════════════════════════════════════════════════
    // Section 23 — طلبات الدفع عبر رابط/رمز/QR
    // ════════════════════════════════════════════════════════════

    private function createPaymentRequest(string $amount = '3000'): PaymentRequest
    {
        $requester = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $requester->id, 'current_balance' => 0, 'pending_balance' => 0]);
        // AMIAL-MERCHANT-VERIFY-RECEIVE-001: التاجرُ المستلمُ للفاتورة موثّقٌ خادميّاً.
        \App\Models\MerchantProfile::create([
            'user_id' => $requester->id, 'tier' => 'small',
            'verification_status' => 'verified',
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);

        return PaymentRequest::create([
            'request_ulid' => (string) Str::ulid(),
            'short_code' => 'ABC234',
            'requester_user_id' => $requester->id,
            'amount' => $amount,
            'note' => 'فاتورة مشتريات',
            'share_method' => 'qr',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
            'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function payment_request_resolved_by_direct_code(): void
    {
        $req = $this->createPaymentRequest();

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'طلب دفع') && str_contains($msg, '3,000'));

        $this->bot->handle(self::WA_NUMBER, $req->short_code);
    }

    /** @test */
    public function payment_request_resolved_by_full_url(): void
    {
        $req = $this->createPaymentRequest();

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'طلب دفع'));

        $this->bot->handle(self::WA_NUMBER, "https://amial.pay/req/{$req->short_code}");
    }

    /** @test */
    public function payment_request_invalid_code_shows_not_found(): void
    {
        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'لم يُعثَر'));

        $this->bot->handle(self::WA_NUMBER, 'ZZ9988'); // رمز غير موجود لكنّه بالصيغة الصحيحة
    }

    /** @test */
    public function payment_request_self_pay_rejected(): void
    {
        EMoney::where('user_id', $this->payer->id)->update(['current_balance' => 0]);
        $req = PaymentRequest::create([
            'request_ulid' => (string) Str::ulid(), 'short_code' => 'SELF22',
            'requester_user_id' => $this->payer->id, // ← نفس الدافع
            'amount' => '1000', 'share_method' => 'qr', 'status' => 'pending',
            'expires_at' => now()->addDay(), 'zone_code' => 'SOUTH',
        ]);

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'أنشأته بنفسك'));

        $this->bot->handle(self::WA_NUMBER, $req->short_code);
    }

    /** @test */
    public function payment_request_insufficient_balance_blocks_before_pin(): void
    {
        $req = $this->createPaymentRequest('999999'); // أكبر من رصيد الدافع (100000)

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'رصيد غير كافٍ'));

        $this->bot->handle(self::WA_NUMBER, $req->short_code);

        $mgr = app(WhatsappSessionManager::class);
        $this->assertEquals(WhatsappSessionManager::STEP_MENU, $mgr->currentStep(self::WA_NUMBER));
    }

    /** @test */
    public function payment_request_full_real_flow_debits_and_credits_actual_wallets(): void
    {
        $req = $this->createPaymentRequest('3000');
        $requesterWalletBefore = (string) EMoney::where('user_id', $req->requester_user_id)->first()->current_balance;
        $payerWalletBefore     = (string) EMoney::where('user_id', $this->payer->id)->first()->current_balance;

        $this->mockClient->shouldReceive('sendText')->once(); // رسالة التأكيد
        $this->bot->handle(self::WA_NUMBER, $req->short_code);

        $this->payer->update(['transaction_pin' => '1234']);

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'تمّ الدفع بنجاح'));

        $result = $this->bot->executePaymentRequestAfterPin(self::WA_NUMBER, $this->payer->fresh(), '1234');
        $this->assertTrue($result['success']);

        // تحقّق حاسم: الأرصدة الفعلية تغيّرت بالضبط بالمبلغ، لا فقط رسالة النجاح
        $payerAfter     = (string) EMoney::where('user_id', $this->payer->id)->first()->current_balance;
        $requesterAfter = (string) EMoney::where('user_id', $req->requester_user_id)->first()->current_balance;

        $this->assertEquals(bcsub($payerWalletBefore, '3000', 4), $payerAfter);
        $this->assertEquals(bcadd($requesterWalletBefore, '3000', 4), $requesterAfter);
        $this->assertEquals('paid', $req->fresh()->status);
    }

    /** @test */
    public function payment_request_wrong_pin_does_not_pay(): void
    {
        $req = $this->createPaymentRequest('3000');

        $this->mockClient->shouldReceive('sendText')->once();
        $this->bot->handle(self::WA_NUMBER, $req->short_code);

        $this->payer->update(['transaction_pin' => '1234']);

        $result = $this->bot->executePaymentRequestAfterPin(self::WA_NUMBER, $this->payer->fresh(), '9999');
        $this->assertFalse($result['success']);
        $this->assertEquals('pending', $req->fresh()->status); // لم يُدفَع
    }

    /** @test */
    public function payment_request_already_paid_fails_gracefully_on_second_attempt(): void
    {
        $req = $this->createPaymentRequest('2000');
        $this->payer->update(['transaction_pin' => '1234']);

        $this->mockClient->shouldReceive('sendText')->once();
        $this->bot->handle(self::WA_NUMBER, $req->short_code);
        $this->mockClient->shouldReceive('sendText')->once();
        $this->bot->executePaymentRequestAfterPin(self::WA_NUMBER, $this->payer->fresh(), '1234');

        $this->assertEquals('paid', $req->fresh()->status);

        // محاولة ثانية على نفس الرمز
        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'لم يُعثَر'));
        $this->bot->handle(self::WA_NUMBER, $req->short_code);
    }

    // ════════════════════════════════════════════════════════════
    // وحدات: استخراج الرمز + فكّ QR + تحميل الوسائط
    // ════════════════════════════════════════════════════════════

    /** @test */
    public function intent_parser_extracts_direct_code(): void
    {
        $parser = app(IntentParser::class);
        $this->assertEquals('ABC234', $parser->extractPaymentRequestCode('abc234'));
        $this->assertEquals('XYZ789', $parser->extractPaymentRequestCode('XYZ789'));
    }

    /** @test */
    public function intent_parser_extracts_code_from_url(): void
    {
        $parser = app(IntentParser::class);
        $this->assertEquals('ABC234', $parser->extractPaymentRequestCode('https://amial.pay/req/ABC234'));
        $this->assertEquals('ABC234', $parser->extractPaymentRequestCode('شاهد طلبي: https://amial.pay/req/abc234 شكراً'));
    }

    /** @test */
    public function intent_parser_rejects_invalid_codes(): void
    {
        $parser = app(IntentParser::class);
        $this->assertNull($parser->extractPaymentRequestCode('abc')); // قصير جداً
        $this->assertNull($parser->extractPaymentRequestCode('abc23456')); // طويل جداً
        $this->assertNull($parser->extractPaymentRequestCode('hello!!')); // رموز غير صالحة
    }

    /** @test */
    public function intent_parser_pay_code_does_not_collide_with_known_keywords(): void
    {
        $parser = app(IntentParser::class);
        // "cancel" تُصنَّف كـ INTENT_CANCEL أوّلاً، لا كرمز دفع
        $this->assertEquals(IntentParser::INTENT_CANCEL, $parser->parse('cancel')['type']);
        $this->assertEquals(IntentParser::INTENT_HELP, $parser->parse('help')['type']);
    }

    /** @test */
    public function qr_decoder_returns_null_for_garbage_bytes(): void
    {
        $decoder = app(WhatsappQrDecoderService::class);
        $this->assertNull($decoder->decode('this is not a real image'));
    }

    /** @test */
    public function qr_decoder_returns_null_for_empty_input(): void
    {
        $decoder = app(WhatsappQrDecoderService::class);
        $this->assertNull($decoder->decode(''));
    }

    /** @test */
    public function media_downloader_returns_null_without_access_token(): void
    {
        putenv('META_WA_ACCESS_TOKEN=');
        $downloader = app(WhatsappMediaDownloader::class);
        $this->assertNull($downloader->download('fake_media_id_123'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
