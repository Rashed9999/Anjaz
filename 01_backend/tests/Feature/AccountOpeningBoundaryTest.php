<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every retired Pay route must stop before parsing or persisting registration
 * data. This is the boundary that keeps KYC evidence and approval ownership
 * in Muwathaq while preserving a clear compatibility response to old clients.
 */
class AccountOpeningBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_public_creation_routes_identify_muwathaq_as_owner_without_creating_users(): void
    {
        config(['muwathaq.base_url' => 'https://muwathaq.example.test/']);

        $cases = [
            ['/api/v1/customer/auth/register', ['account_type' => 'customer'], 'customer', '/open-account?type=customer'],
            ['/api/v1/customer/auth/register', ['account_type' => 'merchant'], 'merchant', '/open-account?type=merchant'],
            ['/api/v1/agent/auth/register', [], 'agent', '/accounts/new-agent'],
            ['/api/v1/auth/register/quick', [], 'customer', '/open-account?type=customer'],
            ['/api/v1/auth/register/merchant', [], 'merchant', '/open-account?type=merchant'],
            ['/api/v1/auth/register/email', [], 'customer', '/open-account?type=customer'],
        ];

        foreach ($cases as [$url, $payload, $subject, $path]) {
            $this->postJson($url, $payload)
                ->assertStatus(410)
                ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ')
                ->assertJsonPath('account_opening_owner', 'muwathaq')
                ->assertJsonPath('subject_type', $subject)
                ->assertJsonPath('muwathaq_url', 'https://muwathaq.example.test'.$path);
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_operator_account_creation_routes_are_boundary_only_and_agent_is_staff_only(): void
    {
        config(['muwathaq.base_url' => 'https://muwathaq.example.test']);
        $operator = User::factory()->create(['type' => ADMIN_TYPE]);
        app(\App\Services\PlatformRoleService::class)->ensureHasSomeRole($operator);

        foreach ([
            ['customers', 'customer', '/open-account?type=customer'],
            ['merchants', 'merchant', '/open-account?type=merchant'],
            ['agents', 'agent', '/accounts/new-agent'],
        ] as [$slug, $subject, $path]) {
            $this->actingAs($operator, 'user')
                ->postJson('/admin/amial/hub/'.$slug.'/users', ['phone' => '777123456'])
                ->assertStatus(410)
                ->assertJsonPath('subject_type', $subject)
                ->assertJsonPath('muwathaq_url', 'https://muwathaq.example.test'.$path);
        }

        $this->assertSame(1, User::query()->count());
    }
}
