<?php

namespace Tests\Feature;

use App\Services\Reporting\P2IdentityCommunicationReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class P2IdentityCommunicationReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function authentication_security_report_is_aggregate_only_and_never_exposes_identity_or_network_pii(): void
    {
        $now = now();

        DB::table('unified_login_attempts')->insert([
            [
                'role' => 'customer',
                'identifier' => 'secret.customer@example.com',
                'identifier_masked' => 's***@example.com',
                'success' => 1,
                'failure_reason' => null,
                'ip_address' => '10.9.8.7',
                'user_agent' => 'Sensitive-UA/1.0',
                'user_id' => null,
                'attempted_at' => $now->copy()->subMinutes(10),
            ],
            [
                'role' => 'merchant',
                'identifier' => '777777777',
                'identifier_masked' => '77***77',
                'success' => 1,
                'failure_reason' => null,
                'ip_address' => '10.1.1.1',
                'user_agent' => 'Merchant-Private-UA',
                'user_id' => null,
                'attempted_at' => $now->copy()->subMinutes(9),
            ],
        ]);

        for ($i = 0; $i < 5; $i++) {
            DB::table('unified_login_attempts')->insert([
                'role' => 'customer',
                'identifier' => 'secret.customer@example.com',
                'identifier_masked' => 's***@example.com',
                'success' => 0,
                'failure_reason' => 'INVALID_CREDENTIALS',
                'ip_address' => '10.9.8.7',
                'user_agent' => 'Sensitive-UA/1.0',
                'user_id' => null,
                'attempted_at' => $now->copy()->subMinutes(8 - $i),
            ]);
        }

        $report = app(P2IdentityCommunicationReportService::class)
            ->authenticationSecurity($now->toDateString(), $now->toDateString());

        $this->assertSame(7, $report['attempts']);
        $this->assertSame(2, $report['successful']);
        $this->assertSame(5, $report['failed']);
        $this->assertSame('28.57', $report['success_rate_pct']);
        $this->assertSame('71.42', $report['failure_rate_pct']);
        $this->assertSame(1, $report['repeated_failure_sources']);
        $this->assertFalse($report['privacy']['identifier_included']);
        $this->assertFalse($report['privacy']['ip_address_included']);
        $this->assertFalse($report['privacy']['user_agent_included']);

        $roles = collect($report['by_role'])->keyBy('value');
        $this->assertSame(6, $roles['customer']['total']);
        $this->assertSame(5, $roles['customer']['failure']);
        $this->assertSame(1, $roles['merchant']['success']);

        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret.customer@example.com', $encoded);
        $this->assertStringNotContainsString('777777777', $encoded);
        $this->assertStringNotContainsString('10.9.8.7', $encoded);
        $this->assertStringNotContainsString('10.1.1.1', $encoded);
        $this->assertStringNotContainsString('Sensitive-UA/1.0', $encoded);
        $this->assertStringNotContainsString('Merchant-Private-UA', $encoded);
    }
}
