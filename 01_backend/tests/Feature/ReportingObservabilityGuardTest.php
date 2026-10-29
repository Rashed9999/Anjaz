<?php

namespace Tests\Feature;

use App\Services\Reporting\P1ObservabilityReportService;
use App\Services\Reporting\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-REPORTING-OBSERVABILITY-001
 *
 * يحرس الفرق بين «المتاح فعلاً من telemetry» وبين الأرقام التي يسهل
 * اختلاقها. التاريخ الداخلي ليس SLA تعاقدياً، وجدول queue لا يثبت عدد
 * المهام الناجحة لأن Laravel يحذفها بعد النجاح.
 */
class ReportingObservabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function health_history_counts_real_samples_and_never_claims_contractual_sla(): void
    {
        $now = now();
        DB::table('system_health_checks')->insert([
            [
                'component' => 'database', 'state' => 'up', 'latency_ms' => 12,
                'detail' => 'DB يعمل', 'checked_at' => $now->copy()->subMinutes(10),
            ],
            [
                'component' => 'database', 'state' => 'degraded', 'latency_ms' => 220,
                'detail' => 'DB بطيء', 'checked_at' => $now->copy()->subMinutes(5),
            ],
            [
                'component' => 'queue', 'state' => 'down', 'latency_ms' => null,
                'detail' => 'Queue متوقف', 'checked_at' => $now->copy()->subMinutes(5),
            ],
        ]);

        DB::table('system_errors')->insert([
            'fingerprint' => hash('sha256', 'reporting-health-test'),
            'exception' => 'RuntimeException',
            'message' => 'خطأ اختبار آمن',
            'occurrences' => 3,
            'first_seen_at' => $now->copy()->subHours(2),
            'last_seen_at' => $now->copy()->subHour(),
            'status_flag' => 'open',
            'created_at' => $now->copy()->subHours(2),
            'updated_at' => $now->copy()->subHour(),
        ]);

        $report = app(P1ObservabilityReportService::class)
            ->healthHistory($now->toDateString(), $now->toDateString());
        $components = collect($report['components'])->keyBy('component');

        $this->assertSame(2, $components['database']['samples']);
        $this->assertSame(1, $components['database']['up']);
        $this->assertSame(1, $components['database']['degraded']);
        $this->assertSame(0, $components['database']['down']);
        $this->assertSame('50.00', $components['database']['observed_up_ratio_pct']);
        $this->assertSame(1, $components['queue']['down']);
        $this->assertSame(1, $report['errors']['open']);
        $this->assertSame(1, $report['errors']['seen_in_period']);
        $this->assertSame(3, $report['errors']['occurrences_in_period_rows']);
        $this->assertFalse($report['formal_sla_available']);
        $this->assertStringContainsString('not contractual', $report['formal_sla_reason']);
        $this->assertSame(5, $report['heartbeat_expected_minutes']);
    }

    /** @test */
    public function queue_report_exposes_counts_and_wait_not_payload_or_fake_completion_history(): void
    {
        $now = now();
        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => 'SECRET_PAYLOAD_MUST_NOT_LEAVE_QUEUE_TABLE',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now->copy()->subMinute()->timestamp,
                'created_at' => $now->copy()->subMinutes(4)->timestamp,
            ],
            [
                'queue' => 'emails',
                'payload' => 'ANOTHER_PRIVATE_PAYLOAD',
                'attempts' => 1,
                'reserved_at' => $now->copy()->subMinute()->timestamp,
                'available_at' => $now->copy()->subMinutes(2)->timestamp,
                'created_at' => $now->copy()->subMinutes(3)->timestamp,
            ],
            [
                'queue' => 'emails',
                'payload' => 'DELAYED_PRIVATE_PAYLOAD',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now->copy()->addMinutes(10)->timestamp,
                'created_at' => $now->timestamp,
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => 'FAILED_SECRET_PAYLOAD',
            'exception' => 'Sensitive stack trace must not be exported by report',
            'failed_at' => $now->copy()->subMinutes(30),
        ]);

        $report = app(P1ObservabilityReportService::class)
            ->queueOperations($now->toDateString(), $now->toDateString());

        $this->assertSame(3, $report['pending']['total']);
        $this->assertSame(1, $report['pending']['ready']);
        $this->assertSame(1, $report['pending']['reserved']);
        $this->assertSame(1, $report['pending']['delayed']);
        $this->assertGreaterThanOrEqual(180, $report['pending']['oldest_ready_wait_seconds']);
        $this->assertSame(1, $report['failed']['total']);
        $this->assertSame(1, $report['failed']['in_period']);
        $this->assertFalse($report['completion_history_available']);

        $serialized = json_encode($report, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SECRET_PAYLOAD', $serialized);
        $this->assertStringNotContainsString('Sensitive stack trace', $serialized);
    }

    /** @test */
    public function observability_routes_are_report_permission_guarded_and_catalog_is_honest(): void
    {
        foreach ([
            'admin.amial.reporting-center.system-health-history',
            'admin.amial.reporting-center.queue-operations',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('platform:platform.reports.view', $route->gatherMiddleware(), $name);
        }

        $operations = collect(app(ReportCatalogService::class)->catalog()['operations']['reports'])
            ->keyBy('code');

        $this->assertSame('ready', $operations['system_health_history']['status']);
        $this->assertSame('ready', $operations['jobs_queues']['status']);
        $this->assertSame('partial', $operations['support_sla']['status']);
        $this->assertStringContainsString('لا يُسمى SLA', $operations['system_health_history']['source']);
    }
}
