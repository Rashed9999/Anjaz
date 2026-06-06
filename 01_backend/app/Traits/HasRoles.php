<?php

namespace App\Traits;

use App\Models\Rbac\Permission;
use App\Models\Rbac\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

/**
 * AMIAL-RBAC-001 (v1.0-A)
 *
 * HasRoles — يُضاف لـ User model للحصول على RBAC capabilities.
 *
 * **استخدام:**
 *   $user->hasRole('finance_manager')
 *   $user->hasPermission('transactions.refund')
 *   $user->assignRole($role, $grantedBy)
 *   $user->revokeRole($role, $reason)
 *
 * **الـ caching:**
 *   permissions تُكاش لـ 5 دقائق لكل user (يقلل DB queries بشدة).
 *   عند تغيير الـ role أو permission، الـ cache يُمسح تلقائياً.
 */
trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'rbac_user_roles',
            'user_id',
            'role_id',
        )
            ->whereNull('rbac_user_roles.revoked_at')
            ->withPivot('assigned_at', 'assigned_by_user_id');
    }

    /**
     * هل المستخدم له هذا الدور (active)؟
     */
    public function hasRole(string $roleCode): bool
    {
        return $this->roles()->where('code', $roleCode)->exists();
    }

    /**
     * هل المستخدم له **أي** من هذه الأدوار؟
     */
    public function hasAnyRole(array $roleCodes): bool
    {
        return $this->roles()->whereIn('code', $roleCodes)->exists();
    }

    /**
     * هل عنده هذه الصلاحية (عبر أي من أدواره)؟
     *
     * يستخدم cache لتجنب N+1 queries.
     */
    public function hasPermission(string $permissionCode): bool
    {
        return in_array($permissionCode, $this->getCachedPermissionCodes(), true);
    }

    /**
     * Has any of these permissions.
     */
    public function hasAnyPermission(array $permissionCodes): bool
    {
        $userPerms = $this->getCachedPermissionCodes();
        foreach ($permissionCodes as $p) {
            if (in_array($p, $userPerms, true)) return true;
        }
        return false;
    }

    /**
     * Has all of these permissions.
     */
    public function hasAllPermissions(array $permissionCodes): bool
    {
        $userPerms = $this->getCachedPermissionCodes();
        foreach ($permissionCodes as $p) {
            if (!in_array($p, $userPerms, true)) return false;
        }
        return true;
    }

    /**
     * Super admin shortcut — يتجاوز كل فحص.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * إسناد role للمستخدم.
     */
    public function assignRole(Role $role, ?int $assignedByUserId = null): void
    {
        // فحص duplicate
        $existing = \App\Models\Rbac\UserRole::where('user_id', $this->id)
            ->where('role_id', $role->id)
            ->first();

        if ($existing && $existing->isActive()) {
            return; // already active
        }

        if ($existing) {
            // كان revoked، نُفعّله
            $existing->update([
                'revoked_at' => null,
                'revoke_reason' => null,
                'assigned_at' => now(),
                'assigned_by_user_id' => $assignedByUserId,
            ]);
        } else {
            \App\Models\Rbac\UserRole::create([
                'user_id' => $this->id,
                'role_id' => $role->id,
                'assigned_by_user_id' => $assignedByUserId,
                'assigned_at' => now(),
            ]);
        }

        $this->clearPermissionsCache();

        $this->logRbacAction('user.role.assigned', 'user', $this->id, [
            'role_code' => $role->code,
            'assigned_by' => $assignedByUserId,
        ]);
    }

    /**
     * إلغاء role.
     */
    public function revokeRole(Role $role, string $reason, ?int $revokedByUserId = null): void
    {
        \App\Models\Rbac\UserRole::where('user_id', $this->id)
            ->where('role_id', $role->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => mb_substr($reason, 0, 500),
            ]);

        $this->clearPermissionsCache();

        $this->logRbacAction('user.role.revoked', 'user', $this->id, [
            'role_code' => $role->code,
            'revoke_reason' => $reason,
            'revoked_by' => $revokedByUserId,
        ]);
    }

    /**
     * يجمع كل صلاحيات المستخدم من كل أدواره (cached).
     */
    public function getCachedPermissionCodes(): array
    {
        return Cache::remember(
            $this->permissionsCacheKey(),
            now()->addMinutes(5),
            fn() => $this->roles()
                ->with('permissions:id,code')
                ->get()
                ->flatMap(fn($role) => $role->permissions->pluck('code'))
                ->unique()
                ->values()
                ->toArray(),
        );
    }

    public function clearPermissionsCache(): void
    {
        Cache::forget($this->permissionsCacheKey());
    }

    private function permissionsCacheKey(): string
    {
        return "rbac:user_perms:{$this->id}";
    }

    private function logRbacAction(string $action, ?string $subjectType = null, ?int $subjectId = null, array $context = []): void
    {
        try {
            \DB::table('rbac_audit_log')->insert([
                'actor_user_id' => auth()->id(),
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'after_state' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'ip_address' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('RBAC audit log failed', ['error' => $e->getMessage()]);
        }
    }
}
