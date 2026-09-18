<?php

namespace Tests\Feature;

use App\Http\Middleware\CorrelationContext;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AMIAL-TRACE-001 — contract tests for the Injaz-derived trace boundary. */
class CorrelationTraceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function accepts_a_safe_incoming_id_and_returns_it_on_the_response(): void
    {
        $request = Request::create('/api/v1/amial/health', 'GET', [], [], [], [
            'HTTP_X_CORRELATION_ID' => 'support-case-2026.09.18',
        ]);

        $response = (new CorrelationContext())->handle(
            $request,
            static fn (Request $request) => new Response('ok'),
        );

        $this->assertSame('support-case-2026.09.18', $request->attributes->get('amial.correlation_id'));
        $this->assertSame('support-case-2026.09.18', $response->headers->get('X-Correlation-Id'));
    }

    /** @test */
    public function audit_records inherit_the_request_correlation_id(): void
    {
        $request = Request::create('/api/v1/amial/health', 'GET', [], [], [], [
            'HTTP_X_CORRELATION_ID' => 'case-otp-001',
        ]);
        app()->instance('request', $request);
        $request->attributes->set('amial.correlation_id', 'case-otp-001');

        $id = app(AuditService::class)->record([
            'action' => 'TRACE_TEST',
            'decision_code' => 'TRACE_INHERITED',
            'subject_type' => 'system',
        ]);

        $this->assertNotNull($id);
        $this->assertSame('case-otp-001', DB::table('audit_decisions')->where('decision_id', $id)->value('correlation_id'));
    }
}
