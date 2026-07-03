<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Models\WhatsappAuditLog;
use App\Models\WhatsappLinkedDevice;
use App\Services\Whatsapp\WhatsappAccountLinkingService;
use App\Services\Whatsapp\WhatsappApiClient;
use App\Services\Whatsapp\WhatsappBotService;
use App\Services\Whatsapp\WhatsappSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * AMIAL-WA-002 — اختبارات ربط الحساب + بوّابة الجلسة الموثوقة.
 */
class WhatsappAccountLinkingTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappAccountLinkingService $linking;
    private WhatsappApiClient $mockClient;
    private WhatsappBotService $bot;
    private User $user;
    private const WA_NUMBER = '967777999888';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(WhatsappApiClient::class);
        $this->app->instance(WhatsappApiClient::class, $this->mockClient);

        $this->linking = app(WhatsappAccountLinkingService::class);
        $this->bot     = app(WhatsappBotService::class);

        $this->user = User::factory()->create([
            'phone' => '967777123456', 'f_name' => 'أحمد', 'l_name' => 'سالم', 'type' => 3,
        ]);
        EMoney::create(['user_id' => $this->user->id, 'current_balance' => 50000, 'pending_balance' => 0]);
    }

    // ══════════════════════════════════════════════════════════════
    // بوّابة الربط — لا أمر بلا جلسة موثوقة (Section 4)
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function unlinked_number_cannot_check_balance(): void
    {
        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'ربط الحساب'));

        $this->bot->handle(self::WA_NUMBER, 'رصيدي');

        $this->assertFalse($this->linking->isLinked(self::WA_NUMBER));
    }

    /** @test */
    public function unlinked_number_cannot_transfer(): void
    {
        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'ربط الحساب'));

        $this->bot->handle(self::WA_NUMBER, 'حول 5000 لـ 777123456');
    }

    /** @test */
    public function blocked_attempt_is_audited(): void
    {
        $this->mockClient->shouldReceive('sendText')->once();
        $this->bot->handle(self::WA_NUMBER, 'رصيدي');

        $log = WhatsappAuditLog::where('whatsapp_number', self::WA_NUMBER)
            ->where('outcome', WhatsappAuditLog::OUTCOME_BLOCKED)->first();
        $this->assertNotNull($log);
    }

    // ══════════════════════════════════════════════════════════════
    // تدفّق الربط الكامل
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function full_linking_flow_succeeds(): void
    {
        $this->mockClient->shouldReceive('sendText')->times(2); // طلب الرقم + رسالة OTP أُرسل

        $this->bot->handle(self::WA_NUMBER, 'ربط الحساب');
        $this->bot->handle(self::WA_NUMBER, '967777123456'); // رقم الحساب الصحيح

        $cached = \Illuminate\Support\Facades\Cache::get('wa_link_otp:' . self::WA_NUMBER);
        $this->assertNotNull($cached);
        $otp = $cached['otp'];

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, 'تمّ ربط حسابك'));

        $this->bot->handle(self::WA_NUMBER, $otp);

        $this->assertTrue($this->linking->isLinked(self::WA_NUMBER));
        $link = WhatsappLinkedDevice::where('whatsapp_number', self::WA_NUMBER)->first();
        $this->assertEquals($this->user->id, $link->user_id);
        $this->assertEquals(WhatsappLinkedDevice::STATUS_ACTIVE, $link->status);
    }

    /** @test */
    public function linking_fails_for_unknown_phone(): void
    {
        $result = $this->linking->startLinking(self::WA_NUMBER, '777000000');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('لم يُعثَر', $result['message']);
    }

    /** @test */
    public function wrong_otp_decrements_attempts(): void
    {
        $this->linking->startLinking(self::WA_NUMBER, '967777123456');
        $r1 = $this->linking->verifyOtp(self::WA_NUMBER, '000000');
        $this->assertFalse($r1['success']);
        $this->assertStringContainsString('محاولة متبقّية', $r1['message']);
    }

    /** @test */
    public function exceeding_max_attempts_locks_out(): void
    {
        $this->linking->startLinking(self::WA_NUMBER, '967777123456');
        $this->linking->verifyOtp(self::WA_NUMBER, '000000');
        $this->linking->verifyOtp(self::WA_NUMBER, '000000');
        $result = $this->linking->verifyOtp(self::WA_NUMBER, '000000');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('حُظر', $result['message']);

        // محاولة جديدة بعد القفل — تُرفَض فوراً
        $blocked = $this->linking->startLinking(self::WA_NUMBER, '967777123456');
        $this->assertFalse($blocked['success']);
    }

    /** @test */
    public function correct_otp_creates_active_link(): void
    {
        $this->linking->startLinking(self::WA_NUMBER, '967777123456');
        $cached = \Illuminate\Support\Facades\Cache::get('wa_link_otp:' . self::WA_NUMBER);

        $result = $this->linking->verifyOtp(self::WA_NUMBER, $cached['otp']);
        $this->assertTrue($result['success']);
        $this->assertEquals($this->user->id, $result['user']->id);
    }

    /** @test */
    public function cannot_link_same_whatsapp_to_two_active_accounts(): void
    {
        $otherUser = User::factory()->create(['phone' => '967700111222', 'type' => 3]);

        $this->linking->startLinking(self::WA_NUMBER, '967777123456');
        $cached = \Illuminate\Support\Facades\Cache::get('wa_link_otp:' . self::WA_NUMBER);
        $this->linking->verifyOtp(self::WA_NUMBER, $cached['otp']);

        $result = $this->linking->startLinking(self::WA_NUMBER, '967700111222');
        $this->assertFalse($result['success']);
    }

    // ══════════════════════════════════════════════════════════════
    // ما بعد الربط — الأوامر تعمل بشكل طبيعي
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function linked_user_can_check_balance(): void
    {
        $this->linkUserDirectly();

        $this->mockClient->shouldReceive('sendText')->once()
            ->withArgs(fn($p, $msg) => str_contains($msg, '50,000') || str_contains($msg, '50000'));

        $this->bot->handle(self::WA_NUMBER, 'رصيدي');
    }

    /** @test */
    public function linked_session_updates_last_activity(): void
    {
        $this->linkUserDirectly();
        $this->mockClient->shouldReceive('sendText')->once();

        $before = WhatsappLinkedDevice::where('whatsapp_number', self::WA_NUMBER)->first();
        $this->assertNull($before->last_activity_at);

        $this->bot->handle(self::WA_NUMBER, 'رصيدي');

        $after = $before->fresh();
        $this->assertNotNull($after->last_activity_at);
    }

    // ══════════════════════════════════════════════════════════════
    // إلغاء الربط + Risk Score
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function revoke_disables_access(): void
    {
        $this->linkUserDirectly();
        $this->assertTrue($this->linking->isLinked(self::WA_NUMBER));

        $this->linking->revoke(self::WA_NUMBER, null, 'test_revoke');

        $this->assertFalse($this->linking->isLinked(self::WA_NUMBER));
    }

    /** @test */
    public function bump_risk_increases_score_and_is_capped_at_100(): void
    {
        $this->linkUserDirectly();
        $this->linking->bumpRisk(self::WA_NUMBER, 50, 'test');
        $this->linking->bumpRisk(self::WA_NUMBER, 70, 'test'); // يتجاوز 100

        $link = WhatsappLinkedDevice::where('whatsapp_number', self::WA_NUMBER)->first();
        $this->assertEquals(100, $link->risk_score);
    }

    /** @test */
    public function high_risk_triggers_needs_extra_verification(): void
    {
        $this->linkUserDirectly();
        $this->linking->bumpRisk(self::WA_NUMBER, 65, 'suspicious');

        $link = $this->linking->getLink(self::WA_NUMBER);
        $this->assertTrue($link->needsExtraVerification());
    }

    // ══════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════

    private function linkUserDirectly(): void
    {
        WhatsappLinkedDevice::create([
            'user_id'           => $this->user->id,
            'whatsapp_number'   => self::WA_NUMBER,
            'status'            => WhatsappLinkedDevice::STATUS_ACTIVE,
            'device_fingerprint'=> hash('sha256', self::WA_NUMBER),
            'otp_verified_at'   => now(),
            'risk_score'        => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
