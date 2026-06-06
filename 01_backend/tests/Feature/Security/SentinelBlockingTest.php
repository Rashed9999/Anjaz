<?php

namespace Tests\Feature\Security;

use App\Models\SentinelBlockedIp;
use App\Models\SentinelEvent;
use App\Services\Security\SecuritySentinelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-SENTINEL-001 — اختبارات الحظر التلقائي/اليدوي.
 */
class SentinelBlockingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SecuritySentinelService
    {
        return app(SecuritySentinelService::class);
    }

    /** @test */
    public function manual_block_and_unblock_work(): void
    {
        $svc = $this->service();
        $ip = '203.0.113.10';

        $this->assertFalse($svc->isBlocked($ip));

        $svc->blockIp($ip, 'manual test', 60, 'admin:1');
        $this->assertTrue($svc->isBlocked($ip));
        $this->assertDatabaseHas('sentinel_blocked_ips', ['ip_address' => $ip]);

        $svc->unblockIp($ip);
        $this->assertFalse($svc->isBlocked($ip));
        $this->assertDatabaseMissing('sentinel_blocked_ips', ['ip_address' => $ip]);
    }

    /** @test */
    public function expired_temporary_block_is_not_active(): void
    {
        $ip = '203.0.113.20';
        SentinelBlockedIp::create([
            'ip_address' => $ip,
            'reason' => 'expired',
            'blocked_until' => now()->subMinute(),
            'created_by' => 'auto',
        ]);

        $this->assertFalse($this->service()->isBlocked($ip));
    }

    /** @test */
    public function permanent_block_has_no_expiry(): void
    {
        $svc = $this->service();
        $ip = '203.0.113.30';

        $svc->blockIp($ip, 'permanent', null, 'admin:1');

        $row = SentinelBlockedIp::where('ip_address', $ip)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->blocked_until);
        $this->assertTrue($svc->isBlocked($ip));
    }

    /** @test */
    public function blocked_ips_active_scope_filters_expired(): void
    {
        SentinelBlockedIp::create(['ip_address' => '198.51.100.1', 'blocked_until' => now()->addHour(), 'created_by' => 'auto']);
        SentinelBlockedIp::create(['ip_address' => '198.51.100.2', 'blocked_until' => now()->subHour(), 'created_by' => 'auto']);
        SentinelBlockedIp::create(['ip_address' => '198.51.100.3', 'blocked_until' => null, 'created_by' => 'auto']);

        $active = SentinelBlockedIp::active()->pluck('ip_address')->all();

        $this->assertContains('198.51.100.1', $active);
        $this->assertContains('198.51.100.3', $active);
        $this->assertNotContains('198.51.100.2', $active);
    }

    /** @test */
    public function sentinel_events_persist_for_reporting(): void
    {
        SentinelEvent::create([
            'ip_address' => '203.0.113.40',
            'method' => 'GET',
            'path' => '.env',
            'threat_score' => 90,
            'severity' => 'critical',
            'signatures' => ['BAIT_PATH:.env'],
            'action' => 'block',
        ]);

        $this->assertDatabaseHas('sentinel_events', ['ip_address' => '203.0.113.40', 'severity' => 'critical']);
        $this->assertSame(1, SentinelEvent::critical()->count());
    }
}
