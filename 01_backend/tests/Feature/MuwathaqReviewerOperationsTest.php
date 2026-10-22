<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MuwathaqReviewerOperationsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function signed_reviewer_audit_event_is_allowlisted_and_idempotent(): void
    {
        config(['muwathaq.decision_secret' => 'reviewer-operations-test-secret']);

        $event = [
            'event_ulid' => (string) Str::ulid(),
            'reviewer_identity' => 'reviewer@example.test',
            'action' => 'decision_recorded',
            'context' => [
                'decision' => 'approved',
                'delivery_status' => 'pending',
                'untrusted_identity_number' => 'must-not-be-stored',
            ],
            'occurred_at' => now()->toIso8601String(),
        ];

        $this->signedJson('POST', '/api/v1/internal/muwathaq/reviewer-audit-events', $event)
            ->assertOk()
            ->assertJsonPath('code', 'MUWATHAQ_AUDIT_EVENT_STORED');
        $this->signedJson('POST', '/api/v1/internal/muwathaq/reviewer-audit-events', $event)
            ->assertOk();

        $this->assertDatabaseCount('muwathaq_reviewer_audit_events', 1);
        $this->assertDatabaseHas('muwathaq_reviewer_audit_events', [
            'event_ulid' => $event['event_ulid'],
            'reviewer_identity' => 'reviewer@example.test',
            'action' => 'decision_recorded',
        ]);

        $stored = json_decode((string) DB::table('muwathaq_reviewer_audit_events')
            ->where('event_ulid', $event['event_ulid'])
            ->value('context'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'decision' => 'approved',
            'delivery_status' => 'pending',
        ], $stored);
    }

    private function signedJson(string $method, string $uri, array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MUWATHAQ_TIMESTAMP' => $timestamp,
            'HTTP_X_MUWATHAQ_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'reviewer-operations-test-secret'),
        ], $body);
    }
}
