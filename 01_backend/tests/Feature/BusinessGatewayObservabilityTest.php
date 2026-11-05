<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessLink;
use App\Saher\Collectors\GuardCoverageCollector;
use App\Saher\Findings\Finding;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AMIAL-BUSINESS-OBSERVE-001 — راصدٌ يرى القناة، وساهرٌ يحرس توقيعها.
 */
class BusinessGatewayObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial_business.webhook_url' => 'https://b.test/h', 'amial_business.webhook_secret' => 'w']);
    }

    private function check(): array
    {
        return app(SystemHealthService::class)->checkAll()['checks']['business_gateway'];
    }

    private function link(): int
    {
        return DB::table('business_links')->insertGetId(['link_ulid' => (string) Str::ulid(), 'merchant_user_id' => random_int(1000, 9999),
            'status' => 'active', 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function event(string $status, $createdAt): void
    {
        DB::table('business_outbox_events')->insert(['event_ulid' => (string) Str::ulid(), 'business_link_id' => null, 'event_type' => 'payment_intent.succeeded',
            'payload' => '{}', 'status' => $status, 'attempts' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }

    #[Test]
    public function no_links_is_unused_not_failing(): void
    {
        config(['amial_business.webhook_url' => '']);
        $c = $this->check();
        $this->assertSame('healthy', $c['status']);
        $this->assertStringContainsString('غير مستعملة', $c['message']);
    }

    #[Test]
    public function a_dead_event_brings_the_gateway_down(): void
    {
        $this->link();
        $this->event('delivered', now());
        $this->assertSame('healthy', $this->check()['status']);

        $this->event('dead', now());
        $c = $this->check();
        $this->assertSame('unhealthy', $c['status']);
        $this->assertSame(1, $c['dead']);
    }

    #[Test]
    public function linked_merchants_without_a_webhook_bring_it_down(): void
    {
        $this->link();
        config(['amial_business.webhook_url' => '']);
        $this->assertSame('unhealthy', $this->check()['status']);
    }

    #[Test]
    public function a_stale_pending_event_degrades_it(): void
    {
        $this->link();
        $this->event('pending', now()->subMinutes(5));
        $this->assertSame('healthy', $this->check()['status']);

        $this->event('pending', now()->subMinutes(20));
        $this->assertSame('warning', $this->check()['status']);
    }

    #[Test]
    public function the_health_check_raises_an_alert_for_a_dead_event(): void
    {
        $this->link();
        $this->event('dead', now());
        $this->artisan('amial:health-check');

        $this->assertSame('down', DB::table('system_health_checks')->where('component', 'business_gateway')->value('state'));
        // الإنذار أثرٌ في مركز الأعطال قبل أيّ قناة خارجيّة.
        $this->assertTrue(DB::table('system_errors')->where('fingerprint', hash('sha256', 'ops|health.down.business_gateway'))->exists());
    }

    #[Test]
    public function saher_catches_an_unsigned_business_route(): void
    {
        $clean = collect(app(GuardCoverageCollector::class)->collect()['findings'])
            ->filter(fn (Finding $f) => $f->ruleId === 'SAHER.GUARD.BUSINESS_GATEWAY_UNSIGNED');
        $this->assertCount(0, $clean, 'مسارٌ حقيقيٌّ في القناة بلا توقيع: '.$clean->pluck('assetKey')->implode(', '));

        Route::get('api/v1/business/saher-probe', fn () => 'x')->name('saher.probe.business');
        $found = collect(app(GuardCoverageCollector::class)->collect()['findings'])
            ->first(fn (Finding $f) => $f->assetKey === 'saher.probe.business');

        $this->assertNotNull($found, 'ساهرٌ لم يرَ مساراً بلا توقيع حُقن أمامه');
        $this->assertSame('CRITICAL', $found->severity);
    }
}
