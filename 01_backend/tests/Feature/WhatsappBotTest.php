<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Models\WhatsappLinkedDevice;
use App\Services\Whatsapp\IntentParser;
use App\Services\Whatsapp\WhatsappAccountLinkingService;
use App\Services\Whatsapp\WhatsappApiClient;
use App\Services\Whatsapp\WhatsappBotService;
use App\Services\Whatsapp\WhatsappSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * AMIAL-WA-001 — اختبارات WhatsApp Bot.
 *
 * AMIAL-WA-002 — مُحدَّث: كل اختبار يربط الحساب أوّلاً في setUp()
 * (linkUserDirectly) لأنّ البوّابة الجديدة تمنع أيّ أمر بلا جلسة موثوقة.
 * راجع WhatsappAccountLinkingTest.php لاختبارات بوّابة الربط نفسها.
 */
class WhatsappBotTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappApiClient $mockClient;
    private WhatsappBotService $bot;
    private User $user;
    private const WA_NUMBER = '967777123456';

    protected function setUp(): void
    {
        parent::setUp();

        // Mock الـ Client (لا نرسل رسائل حقيقية في الاختبارات)
        $this->mockClient = Mockery::mock(WhatsappApiClient::class);
        $this->app->instance(WhatsappApiClient::class, $this->mockClient);

        $this->bot = app(WhatsappBotService::class);

        // مستخدم تجريبي
        $this->user = User::factory()->create([
            'phone' => '967777123456',
            'f_name' => 'أحمد',
            'l_name' => 'سالم',
            'type' => 3,
        ]);

        EMoney::create([
            'user_id' => $this->user->id,
            'current_balance' => 50000,
            'pending_balance' => 0,
        ]);

        // AMIAL-WA-002 — ربط مباشر (bypass OTP) لاختبارات v1 القديمة
        // التي تفترض جلسة موثوقة جاهزة مسبقاً
        $this->linkUserDirectly();
    }

    /** AMIAL-WA-002 helper — يربط رقم الواتساب التجريبي بالمستخدم مباشرة. */
    private function linkUserDirectly(): void
    {
        WhatsappLinkedDevice::create([
            'user_id'            => $this->user->id,
            'whatsapp_number'    => self::WA_NUMBER,
            'status'             => WhatsappLinkedDevice::STATUS_ACTIVE,
            'device_fingerprint' => hash('sha256', self::WA_NUMBER),
            'otp_verified_at'    => now(),
            'risk_score'         => 0,
        ]);
    }

    // =========================================================
    // IntentParser Tests
    // =========================================================

    /** @test */
    public function parser_detects_balance_intent(): void
    {
        $parser = app(IntentParser::class);
        $this->assertEquals('balance', $parser->parse('رصيدي')['type']);
        $this->assertEquals('balance', $parser->parse('1')['type']);
        $this->assertEquals('balance', $parser->parse('كم رصيدي')['type']);
    }

    /** @test */
    public function parser_detects_transfer_intent(): void
    {
        $parser = app(IntentParser::class);
        $this->assertEquals('transfer', $parser->parse('حول')['type']);
        $this->assertEquals('transfer', $parser->parse('تحويل')['type']);
        $this->assertEquals('transfer', $parser->parse('2')['type']);
    }

    /** @test */
    public function parser_extracts_phone_number(): void
    {
        $parser = app(IntentParser::class);
        $this->assertEquals('967777123456', $parser->extractPhone('777123456'));
        $this->assertEquals('967777123456', $parser->extractPhone('0777123456'));
        $this->assertEquals('967777123456', $parser->extractPhone('+967777123456'));
        $this->assertEquals('967777123456', $parser->extractPhone('967777123456'));
    }

    /** @test */
    public function parser_extracts_amount(): void
    {
        $parser = app(IntentParser::class);
        $this->assertEquals('5000', $parser->extractAmount('5000'));
        $this->assertEquals('5000', $parser->extractAmount('5000 ريال'));
        $this->assertNull($parser->extractAmount('50')); // أقل من 100
        $this->assertNull($parser->extractAmount('abc'));
    }

    /** @test */
    public function parser_detects_cancel(): void
    {
        $parser = app(IntentParser::class);
        $this->assertEquals('cancel', $parser->parse('إلغاء')['type']);
        $this->assertEquals('cancel', $parser->parse('الغاء')['type']);
        $this->assertEquals('reject', $parser->parse('لا')['type']);
    }

    /** @test */
    public function parser_handles_inline_transfer(): void
    {
        $parser = app(IntentParser::class);
        $result = $parser->parse('حول 5000 لـ 777654321');
        $this->assertEquals('transfer', $result['type']);
        $this->assertEquals('5000', $result['params']['amount']);
        $this->assertStringContainsString('777654321', $result['params']['to_phone']);
    }

    // =========================================================
    // Session Manager Tests
    // =========================================================

    /** @test */
    public function session_manager_creates_and_retrieves(): void
    {
        $mgr = app(WhatsappSessionManager::class);
        $phone = '967777999888';

        $session = $mgr->getOrCreate($phone);
        $this->assertEquals(WhatsappSessionManager::STEP_MENU, $session['step']);

        $mgr->advance($phone, WhatsappSessionManager::STEP_TRANSFER_PHONE, ['test' => 'val']);
        $this->assertTrue($mgr->isAt($phone, WhatsappSessionManager::STEP_TRANSFER_PHONE));
        $this->assertEquals('val', $mgr->getData($phone, 'test'));
    }

    /** @test */
    public function session_clears_correctly(): void
    {
        $mgr = app(WhatsappSessionManager::class);
        $phone = '967777111222';

        $mgr->advance($phone, WhatsappSessionManager::STEP_TRANSFER_PHONE);
        $mgr->clear($phone);
        $this->assertEquals(WhatsappSessionManager::STEP_MENU, $mgr->currentStep($phone));
    }

    // =========================================================
    // Bot Integration Tests
    // =========================================================

    /** @test */
    public function bot_shows_menu_on_greeting(): void
    {
        $this->mockClient
            ->shouldReceive('sendText')
            ->once()
            ->withArgs(fn($phone, $msg) =>
                str_contains($msg, 'أميال باي') &&
                str_contains($msg, 'رصيدي')
            );

        $this->bot->handle('967777123456', 'مرحبا');
    }

    /** @test */
    public function bot_shows_balance(): void
    {
        $this->mockClient
            ->shouldReceive('sendText')
            ->once()
            ->withArgs(fn($phone, $msg) =>
                str_contains($msg, '50,000') ||
                str_contains($msg, '50000')
            );

        $this->bot->handle('967777123456', 'رصيدي');
    }

    /** @test */
    public function bot_shows_unknown_for_unrecognised(): void
    {
        $this->mockClient
            ->shouldReceive('sendText')
            ->once()
            ->withArgs(fn($phone, $msg) =>
                str_contains($msg, 'لم أفهم') ||
                str_contains($msg, 'قائمة')
            );

        $this->bot->handle('967777123456', 'كلام لا معنى له xyz123');
    }

    /** @test */
    public function bot_blocks_unlinked_number_with_link_prompt(): void
    {
        // AMIAL-WA-002 — رقم غير مرتبط أصلاً (لا حساب ولا ربط)
        // يجب أن يُحظَر بنفس رسالة "ربط الحساب"، لا برسالة "user not found"
        // القديمة — لأنّ البوّابة الآن تسبق أيّ بحث عن مستخدم بالهاتف.
        $this->mockClient
            ->shouldReceive('sendText')
            ->once()
            ->withArgs(fn($phone, $msg) => str_contains($msg, 'ربط الحساب'));

        $this->bot->handle('967700000000', 'رصيدي'); // رقم غير مرتبط
    }

    /** @test */
    public function bot_blocks_linked_phone_in_database_but_not_linked_via_otp(): void
    {
        // AMIAL-WA-002 — تحقّق حاسم: مجرّد وجود رقم في users.phone
        // لا يعني وصولاً تلقائياً؛ يجب ربط واتساب صريحاً عبر OTP أوّلاً.
        $otherUser = User::factory()->create(['phone' => '967711222333', 'type' => 3]);

        $this->mockClient
            ->shouldReceive('sendText')
            ->once()
            ->withArgs(fn($phone, $msg) => str_contains($msg, 'ربط الحساب'));

        // واتساب 711222333 لم يُربَط أبداً — رغم أنّ صاحبه مستخدم حقيقي
        $this->bot->handle('967711222333', 'رصيدي');
    }

    /** @test */
    public function bot_cancel_clears_session(): void
    {
        $mgr = app(WhatsappSessionManager::class);
        $mgr->advance('967777123456', WhatsappSessionManager::STEP_TRANSFER_PHONE);

        $this->mockClient->shouldReceive('sendText')->once();

        $this->bot->handle('967777123456', 'إلغاء');

        $this->assertEquals(
            WhatsappSessionManager::STEP_MENU,
            $mgr->currentStep('967777123456')
        );
    }

    /** @test */
    public function bot_starts_transfer_flow(): void
    {
        $mgr = app(WhatsappSessionManager::class);

        $this->mockClient
            ->shouldReceive('sendText')
            ->once()
            ->withArgs(fn($phone, $msg) =>
                str_contains($msg, 'تحويل') ||
                str_contains($msg, 'رقم هاتف')
            );

        $this->bot->handle('967777123456', 'حول');

        $this->assertEquals(
            WhatsappSessionManager::STEP_TRANSFER_PHONE,
            $mgr->currentStep('967777123456')
        );
    }

    // =========================================================
    // Webhook Controller Tests
    // =========================================================

    /** @test */
    public function webhook_verification_succeeds_with_correct_token(): void
    {
        config(['services.whatsapp.verify_token' => 'test_token']);

        $response = $this->get('/wa/webhook?' . http_build_query([
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'test_token',
            'hub_challenge'    => 'test_challenge_123',
        ]));

        $response->assertStatus(200);
        $response->assertSeeText('test_challenge_123');
    }

    /** @test */
    public function webhook_verification_fails_with_wrong_token(): void
    {
        $response = $this->get('/wa/webhook?' . http_build_query([
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'wrong_token',
            'hub_challenge'    => 'test_challenge',
        ]));

        $response->assertStatus(403);
    }

    /** @test */
    public function webhook_post_returns_200_immediately(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry'  => [],
        ];

        // AMIAL-FIX: بدل تعطيل verifySignature (وهي private لا تُستبدَل)، نختبر المسار
        // الحقيقي: نضبط app_secret ونرسل توقيعاً صحيحاً — فيعبر التحقّق فعلياً.
        config(['services.whatsapp.app_secret' => 'test_secret_123']);
        $raw = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $raw, 'test_secret_123');

        $response = $this->call('POST', '/wa/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $sig,
            'CONTENT_TYPE'             => 'application/json',
        ], $raw);
        $response->assertStatus(200)->assertJson(['status' => 'ok']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
