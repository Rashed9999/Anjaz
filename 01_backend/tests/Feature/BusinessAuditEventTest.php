<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessAuditEvent;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** AMIAL-BUSINESS-AUDIT-001 — أحداث التدقيق الحساسة من أميال أعمال: موقّعة، ومرة لكل مرجع. */
class BusinessAuditEventTest extends TestCase
{
    use RefreshDatabase;

    private string $link;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial_business.inbound_secret' => 'audit-secret']);
        $m = User::factory()->create(['type' => 3, 'phone' => '+967700800001', 'is_active' => 1]);
        MerchantProfile::create(['user_id' => $m->id, 'verification_status' => 'verified']);
        $this->link = app(BusinessLinkService::class)->link($m)->link_ulid;
    }

    #[Test]
    public function an_event_is_recorded_once_and_unsigned_ones_are_refused(): void
    {
        $body = ['event_ref' => '17', 'action' => 'staff.role_changed', 'occurred_at' => now()->toIso8601String(),
            'actor' => ['name' => 'خالد', 'role' => 'owner'], 'context' => ['from' => 'cashier', 'to' => 'manager'], 'hash' => str_repeat('a', 64)];

        $this->send($body)->assertOk()->assertJsonPath('meta.replayed', false);
        $this->send($body)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertEquals(['from' => 'cashier', 'to' => 'manager'], BusinessAuditEvent::sole()->context); // JSON في MySQL يعيد ترتيب المفاتيح

        $this->postJson('/api/v1/business/audit-events', $body, ['X-Amial-Business-Link' => $this->link])->assertStatus(401);
        $this->send([...$body, 'event_ref' => '18', 'action' => 'DROP TABLE'])->assertStatus(422);
        $this->assertSame(1, BusinessAuditEvent::count());
    }

    private function send(array $body): TestResponse
    {
        $ts = (string) time();
        $nonce = Str::random(32);
        $content = json_encode($body);
        $sig = BusinessGatewaySignature::sign(BusinessGatewaySignature::inboundCanonical($ts, $nonce, 'POST', '/api/v1/business/audit-events', '', $content), 'audit-secret');

        return $this->call('POST', '/api/v1/business/audit-events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $ts, 'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce, 'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => $sig,
            'HTTP_X_AMIAL_BUSINESS_LINK' => $this->link], $content);
    }
}
