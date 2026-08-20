<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CLI ليس منفذاً خلفياً يتجاوز قرار المناطق أو هوية الموظف. */
class ZoneCommandGuardTest extends TestCase
{
    use RefreshDatabase;

    private function approver(): User
    {
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'is_active' => 1]);
        app(PlatformRoleService::class)->assign($admin, PlatformRoleService::ADMIN);

        return $admin->fresh();
    }

    public function test_the_cli_requires_a_real_authorized_actor_and_reason(): void
    {
        $user = User::factory()->create(['zone_code' => 'UNKNOWN']);

        $this->artisan('amial:zone', [
            'action' => 'set', 'user_id' => $user->id, 'zone' => 'SOUTH',
            '--reason' => 'قرار تشغيلي موثق لإسناد المنطقة',
        ])->assertExitCode(1);

        $this->assertSame('UNKNOWN', $user->fresh()->zone_code);
    }

    public function test_the_cli_uses_the_same_assignment_service_and_audit_trail(): void
    {
        $admin = $this->approver();
        $user = User::factory()->create(['zone_code' => 'UNKNOWN']);

        $this->artisan('amial:zone', [
            'action' => 'set', 'user_id' => $user->id, 'zone' => 'SOUTH',
            '--admin-id' => $admin->id,
            '--reason' => 'قرار تشغيلي موثق لإسناد المنطقة',
        ])->assertExitCode(0);

        $this->assertSame('SOUTH', $user->fresh()->zone_code);
        $this->assertDatabaseHas('zone_assignment_logs', [
            'user_id' => $user->id, 'assigned_zone' => 'SOUTH', 'method' => 'admin_decision',
        ]);
        $this->assertDatabaseHas('audit_decisions', [
            'actor_user_id' => $admin->id, 'subject_id' => (string) $user->id,
            'decision_code' => 'ZONE_SET',
        ]);
    }
}
