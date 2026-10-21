<?php

namespace Tests\Feature;

use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MuwathaqReviewerOperationsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function signed_reviewer_directory_exposes_lifecycle_lists_without_excluding_legacy_accounts(): void
    {
        config(['muwathaq.decision_secret' => 'reviewer-operations-test-secret']);

        $female = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'gender' => 'female',
            'is_active' => 1,
        ]);
        $newMerchant = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'is_active' => 0,
        ]);
        $legacyAgent = User::factory()->create([
            'type' => AGENT_TYPE,
            'is_active' => 1,
        ]);

        MuwathaqAccountOpeningApplication::query()->create([
            'application_ulid' => (string) Str::ulid(),
            'user_id' => $newMerchant->id,
            'subject_kind' => 'merchant',
            'verification_path' => 'merchant_sector',
            'business_type' => 'restaurant',
            'status' => MuwathaqAccountOpeningApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->signedGet('/api/v1/internal/muwathaq/account-directory?list=customer_female')
            ->assertOk()
            ->assertJsonPath('meta.list', 'customer_female')
            ->assertJsonFragment(['name' => $female->declared_legal_name ?: $female->f_name]);

        $this->signedGet('/api/v1/internal/muwathaq/account-directory?list=new')
            ->assertOk()
            ->assertJsonPath('data.0.account_type', 'merchant')
            ->assertJsonPath('data.0.opening_status', MuwathaqAccountOpeningApplication::STATUS_SUBMITTED);

        $this->signedGet('/api/v1/internal/muwathaq/account-directory?list=suspended')
            ->assertOk()
            ->assertJsonPath('data.0.operational_status', 'suspended');

        $this->signedGet('/api/v1/internal/muwathaq/account-directory?list=active')
            ->assertOk()
            ->assertJsonFragment(['account_type' => 'agent'])
            ->assertJsonFragment(['account_type' => 'customer']);

        $this->assertSame(1, $legacyAgent->fresh()->is_active);
    }

    /** @test */
    public function signed_reviewer_audit_event_is_allowlisted_and_idempotent(): void
    {
        config(['muwathaq.decision_secret' => 'reviewer-operations-test-secret']);

        $event = [
            'event_ulid' => (string) Str::ulid(),
            'reviewer_identity' => 'reviewer@example.test',
            'action' => 'directory_opened',
            'context' => [
                'list' => 'merchant',
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
            'action' => 'directory_opened',
        ]);
        $stored = json_decode((string) DB::table('muwathaq_reviewer_audit_events')
            ->where('event_ulid', $event['event_ulid'])
            ->value('context'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['list' => 'merchant'], $stored);
    }

    private function signedGet(string $uri)
    {
        $timestamp = (string) time();

        return $this->call('GET', $uri, [], [], [], [
            'HTTP_X_MUWATHAQ_TIMESTAMP' => $timestamp,
            'HTTP_X_MUWATHAQ_SIGNATURE' => hash_hmac('sha256', $timestamp.'.', 'reviewer-operations-test-secret'),
        ]);
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
