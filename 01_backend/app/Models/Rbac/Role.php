<?php

namespace App\Models\Rbac;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * AMIAL-RBAC-001
 */
class Role extends Model
{
    protected $table = 'rbac_roles';

    protected $fillable = [
        'code',
        'name_ar',
        'description_ar',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'rbac_role_permissions',
            'role_id',
            'permission_id',
        )->withPivot('granted_at', 'granted_by_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'rbac_user_roles', 'role_id', 'user_id')
            ->whereNull('rbac_user_roles.revoked_at');
    }

    public function hasPermission(string $permissionCode): bool
    {
        return $this->permissions()->where('code', $permissionCode)->exists();
    }

    public function grantPermission(Permission $permission, ?int $grantedByUserId = null): void
    {
        if (!$this->permissions()->where('permission_id', $permission->id)->exists()) {
            $this->permissions()->attach($permission->id, [
                'granted_by_user_id' => $grantedByUserId,
                'granted_at' => now(),
            ]);
        }
    }

    public function revokePermission(Permission $permission): void
    {
        $this->permissions()->detach($permission->id);
    }
}
