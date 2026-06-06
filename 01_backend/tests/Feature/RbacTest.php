<?php

namespace Tests\Feature;

use App\Models\Rbac\Permission;
use App\Models\Rbac\Role;
use App\Models\Rbac\UserRole;
use App\Models\User;
use Database\Seeders\RbacDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RBAC-001 (v1.0-A) — اختبارات.
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacDefaultSeeder::class);
    }

    /** @test */
    public function seeder_creates_5_system_roles()
    {
        $this->assertEquals(5, Role::count());
        $this->assertTrue(Role::where('code', 'super_admin')->exists());
        $this->assertTrue(Role::where('code', 'finance_manager')->exists());
        $this->assertTrue(Role::where('code', 'compliance_officer')->exists());
        $this->assertTrue(Role::where('code', 'support_agent')->exists());
        $this->assertTrue(Role::where('code', 'read_only_auditor')->exists());

        // كل system roles
        $this->assertEquals(5, Role::where('is_system', true)->count());
    }

    /** @test */
    public function super_admin_has_all_permissions()
    {
        $totalPerms = Permission::count();
        $role = Role::where('code', 'super_admin')->first();

        $this->assertEquals($totalPerms, $role->permissions()->count());
        $this->assertGreaterThanOrEqual(40, $totalPerms); // عدد محدد من الـ seeder
    }

    /** @test */
    public function support_agent_only_has_view_permissions()
    {
        $role = Role::where('code', 'support_agent')->first();
        $perms = $role->permissions->pluck('code')->toArray();

        foreach ($perms as $perm) {
            $this->assertStringEndsWith('.view', $perm,
                "support_agent should only have view permissions, but has: {$perm}");
        }
    }

    /** @test */
    public function read_only_auditor_has_view_and_export_only()
    {
        $role = Role::where('code', 'read_only_auditor')->first();
        $perms = $role->permissions->pluck('code')->toArray();

        foreach ($perms as $perm) {
            $this->assertTrue(
                str_ends_with($perm, '.view') || str_ends_with($perm, '.export'),
                "auditor should only have view/export, but has: {$perm}",
            );
        }
    }

    /** @test */
    public function user_assign_role_creates_active_user_role_record()
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'support_agent')->first();

        $user->assignRole($role);

        $this->assertDatabaseHas('rbac_user_roles', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'revoked_at' => null,
        ]);
        $this->assertTrue($user->hasRole('support_agent'));
    }

    /** @test */
    public function assigning_role_twice_does_not_duplicate()
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'support_agent')->first();

        $user->assignRole($role);
        $user->assignRole($role);

        $this->assertEquals(1, UserRole::where('user_id', $user->id)->count());
    }

    /** @test */
    public function revoking_role_sets_revoked_at_and_removes_from_active()
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'support_agent')->first();
        $user->assignRole($role);

        $user->revokeRole($role, 'No longer needs access');

        $this->assertFalse($user->hasRole('support_agent'));
        $this->assertDatabaseMissing('rbac_user_roles', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'revoked_at' => null,
        ]);
    }

    /** @test */
    public function user_has_permission_checks_via_roles()
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'finance_manager')->first();
        $user->assignRole($role);

        $this->assertTrue($user->hasPermission('transactions.view'));
        $this->assertTrue($user->hasPermission('transactions.refund'));
        $this->assertFalse($user->hasPermission('users.suspend'));
        $this->assertFalse($user->hasPermission('kyc.approve'));
    }

    /** @test */
    public function super_admin_check_is_role_based()
    {
        $user = User::factory()->create();
        $regular = User::factory()->create();

        $superRole = Role::where('code', 'super_admin')->first();
        $user->assignRole($superRole);

        $this->assertTrue($user->isSuperAdmin());
        $this->assertFalse($regular->isSuperAdmin());
    }

    /** @test */
    public function permissions_are_cached_per_user()
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'finance_manager')->first();
        $user->assignRole($role);

        // First call: hits DB and caches
        $perms1 = $user->getCachedPermissionCodes();
        $this->assertContains('transactions.refund', $perms1);

        // إضافة role جديد — قبل clear الـ cache
        $supportRole = Role::where('code', 'support_agent')->first();
        $user->assignRole($supportRole);

        // assignRole يمسح cache → فحص مباشر يجب أن يرى الـ permissions الجديدة
        $this->assertTrue($user->hasPermission('transactions.refund')); // من finance
        // (support_agent عنده users.view وهي موجودة أصلاً في finance)
    }

    /** @test */
    public function rbac_audit_log_records_assignments()
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'support_agent')->first();

        $user->assignRole($role);

        $this->assertDatabaseHas('rbac_audit_log', [
            'action' => 'user.role.assigned',
            'subject_type' => 'user',
            'subject_id' => $user->id,
        ]);
    }

    /** @test */
    public function has_any_role_returns_true_if_any_match()
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'support_agent')->first();
        $user->assignRole($role);

        $this->assertTrue($user->hasAnyRole(['support_agent', 'finance_manager']));
        $this->assertTrue($user->hasAnyRole(['compliance_officer', 'support_agent']));
        $this->assertFalse($user->hasAnyRole(['compliance_officer', 'finance_manager']));
    }

    /** @test */
    public function has_all_permissions_strict_AND_check()
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'finance_manager')->first();
        $user->assignRole($role);

        // كلاهما عند finance_manager
        $this->assertTrue($user->hasAllPermissions(['transactions.view', 'transactions.refund']));

        // أحدهما ناقص
        $this->assertFalse($user->hasAllPermissions(['transactions.view', 'kyc.approve']));
    }
}
