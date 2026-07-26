<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ZonePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AMIAL-ZONE-001
 *
 * يغطي قسم 4 من الوثيقة:
 *   - أي حساب بدون zone واضح: لا عمليات
 *   - SOUTH فقط للعمليات المالية
 *   - read actions مسموحة خارج SOUTH
 *   - IP/VPN signals، ليست أحكاماً
 */
class ZonePolicyTest extends TestCase
{
    use RefreshDatabase;

    private ZonePolicyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ZonePolicyService::class);
    }

    /** @test */
    public function south_user_can_send_money(): void
    {
        $user = User::factory()->create(['zone_code' => 'SOUTH']);
        $result = $this->svc->authorize($user, 'send_money', Request::create('/'));

        $this->assertTrue($result['allowed']);
        // AMIAL-ZONE-BOUNDARY-001: التحويل عملية دفتر — رمز القرار تغيّر لا الإذن
        $this->assertSame('ALLOWED_LEDGER_ONLY', $result['decision_code']);
        $this->assertSame('SOUTH', $result['account_zone']);
    }

    /** @test */
    public function north_user_cannot_cash_out(): void
    {
        // AMIAL-ZONE-BOUNDARY-001: الحجب انتقل من الشخص إلى نقطة عبور النقد.
        // التحويل يبقى عاملاً؛ السحب لدى وكيل هو ما يُحجَب.
        $user = User::factory()->create(['zone_code' => 'NORTH']);
        $result = $this->svc->authorize($user, 'cash_out', Request::create('/'));

        $this->assertFalse($result['allowed']);
        $this->assertSame('TX_ZONE_BLOCKED', $result['decision_code']);
    }

    /** @test */
    public function north_user_can_still_send_money(): void
    {
        $user = User::factory()->create(['zone_code' => 'NORTH']);
        $result = $this->svc->authorize($user, 'send_money', Request::create('/'));

        $this->assertTrue($result['allowed']);
        $this->assertSame('ALLOWED_LEDGER_ONLY', $result['decision_code']);
    }

    /** @test */
    public function user_with_no_zone_cannot_transact(): void
    {
        $user = User::factory()->create(['zone_code' => 'UNKNOWN']);
        $result = $this->svc->authorize($user, 'send_money', Request::create('/'));

        $this->assertFalse($result['allowed']);
        $this->assertSame('ACCOUNT_ZONE_UNKNOWN', $result['decision_code']);
    }

    /** @test */
    public function north_user_can_view_balance_read_only(): void
    {
        $user = User::factory()->create(['zone_code' => 'NORTH']);
        $result = $this->svc->authorize($user, 'view_balance', Request::create('/'));

        $this->assertTrue($result['allowed']);
        $this->assertSame('ALLOWED_READ', $result['decision_code']);
    }

    /** @test */
    public function south_user_can_view_history(): void
    {
        $user = User::factory()->create(['zone_code' => 'SOUTH']);
        $result = $this->svc->authorize($user, 'view_history', Request::create('/'));

        $this->assertTrue($result['allowed']);
    }

    /** @test */
    public function unknown_action_is_denied(): void
    {
        $user = User::factory()->create(['zone_code' => 'SOUTH']);
        $result = $this->svc->authorize($user, 'eat_pizza', Request::create('/'));

        $this->assertFalse($result['allowed']);
        $this->assertSame('ACTION_UNKNOWN', $result['decision_code']);
    }

    /** @test */
    public function unauthenticated_user_cannot_do_financial_actions(): void
    {
        $result = $this->svc->authorize(null, 'send_money', Request::create('/'));
        $this->assertFalse($result['allowed']);
        $this->assertSame('AUTH_REQUIRED', $result['decision_code']);
    }

    /** @test */
    public function unauthenticated_user_can_view_public_actions(): void
    {
        $result = $this->svc->authorize(null, 'view_terms', Request::create('/'));
        $this->assertTrue($result['allowed']);
    }

    /** @test */
    public function session_policy_payload_contains_required_fields(): void
    {
        $user = User::factory()->create(['zone_code' => 'SOUTH']);
        $payload = $this->svc->buildSessionPolicy($user, Request::create('/'));

        $this->assertArrayHasKey('account_zone', $payload);
        $this->assertArrayHasKey('allowed_operational_zone', $payload);
        $this->assertArrayHasKey('can_transact', $payload);
        $this->assertArrayHasKey('read_only_mode', $payload);
        $this->assertArrayHasKey('available_actions', $payload);
        $this->assertArrayHasKey('policy_version', $payload);

        $this->assertTrue($payload['can_transact']);
        $this->assertFalse($payload['read_only_mode']);
        $this->assertNull($payload['banner_message']);
    }

    /** @test */
    public function session_policy_shows_banner_for_non_south_user(): void
    {
        $user = User::factory()->create(['zone_code' => 'MIDDLE']);
        $payload = $this->svc->buildSessionPolicy($user, Request::create('/'));

        $this->assertFalse($payload['can_transact']);
        $this->assertTrue($payload['read_only_mode']);
        $this->assertNotNull($payload['banner_message']);
        $this->assertSame([], $payload['available_actions']['financial']);
    }

    /** @test */
    public function request_zone_detected_from_header(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Amial-Zone', 'NORTH');

        $detected = $this->svc->detectRequestZone($request);
        $this->assertSame('NORTH', $detected);
    }

    /** @test */
    public function invalid_request_zone_falls_back_to_other(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Amial-Zone', 'ATLANTIS');

        $detected = $this->svc->detectRequestZone($request);
        $this->assertSame('OTHER', $detected);
    }
}
