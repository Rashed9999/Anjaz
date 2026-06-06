<?php

namespace Tests\Feature\Security;

use App\Services\Security\SecuritySentinelService;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AMIAL-SENTINEL-001 — اختبارات منطق الكشف.
 *
 * تختبر دالة analyze() مباشرة (بلا DB) لإثبات أن التوقيعات تلتقط الهجمات
 * وتترك الطلبات الشرعية تمرّ بنقاط صفرية.
 */
class SecuritySentinelTest extends TestCase
{
    private function analyze(string $uri, string $ua = 'Mozilla/5.0', array $body = []): array
    {
        $request = Request::create($uri, empty($body) ? 'GET' : 'POST', $body, [], [], [
            'HTTP_USER_AGENT' => $ua,
        ]);

        return app(SecuritySentinelService::class)->analyze($request);
    }

    /** @test */
    public function legitimate_request_scores_zero(): void
    {
        $report = $this->analyze('/api/v1/customer/send-money?page=1', 'Mozilla/5.0 (iPhone)', [
            'amount' => '100',
            'to' => '+967700000001',
        ]);

        $this->assertSame(0, $report['score']);
        $this->assertSame('monitor', $report['action']);
        $this->assertSame([], $report['signatures']);
    }

    /** @test */
    public function detects_sql_injection_in_query(): void
    {
        $report = $this->analyze("/api/v1/users?id=1' UNION SELECT password FROM users--");

        $this->assertGreaterThanOrEqual(40, $report['score']);
        $this->assertContains('SQLI_UNION', $report['signatures']);
    }

    /** @test */
    public function detects_xss_in_body(): void
    {
        $report = $this->analyze('/api/v1/profile', 'Mozilla/5.0', [
            'bio' => '<script>document.cookie</script>',
        ]);

        $this->assertContains('XSS_SCRIPT', $report['signatures']);
    }

    /** @test */
    public function detects_path_traversal(): void
    {
        $report = $this->analyze('/api/v1/file?path=../../../../etc/passwd');

        $this->assertNotEmpty($report['signatures']);
        $this->assertContains('SENSITIVE_FILE', $report['signatures']);
    }

    /** @test */
    public function bait_path_triggers_high_score(): void
    {
        $report = $this->analyze('/.env');

        $this->assertGreaterThanOrEqual(60, $report['score']);
        $this->assertContains('BAIT_PATH:.env', $report['signatures']);
    }

    /** @test */
    public function scanner_user_agent_is_flagged(): void
    {
        $report = $this->analyze('/api/v1/health', 'sqlmap/1.7');

        $this->assertContains('SCANNER_UA:sqlmap', $report['signatures']);
    }

    /** @test */
    public function high_score_maps_to_block_action(): void
    {
        // طُعم (60) + UA فاحص (30) → >= 80 → block
        $report = $this->analyze('/wp-login.php', 'nikto');

        $this->assertGreaterThanOrEqual(80, $report['score']);
        $this->assertSame(SecuritySentinelService::ACTION_BLOCK, $report['action']);
        $this->assertSame('critical', $report['severity']);
    }
}
