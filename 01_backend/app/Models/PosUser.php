<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-UNIFIED-AUTH-001 (v1.5)
 *
 * موظف نقطة بيع (POS user) — تابع لتاجر رئيسي.
 *
 * لاحظ:
 *   - PosUser يملك User account مستقل (للـ authentication)
 *   - لكنه لا يملك محفظة (كل المبالغ تذهب لحساب التاجر الرئيسي)
 *   - يتسجل في كل معاملة بـ pos_user_id (وفقاً للوثيقة Section 11)
 */
class PosUser extends Model
{
    protected $table = 'pos_users';

    protected $fillable = [
        'user_id',
        'merchant_user_id',
        'branch_id',
        'pos_number',
        'display_name',
        'is_active',
        'permissions',
        'last_login_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'merchant_user_id' => 'integer',
        'branch_id' => 'integer',
        'is_active' => 'boolean',
        'permissions' => 'array',
        'last_login_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function merchant(): BelongsTo { return $this->belongsTo(User::class, 'merchant_user_id'); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class, 'branch_id'); }

    /**
     * P1-RBAC — الأدوار المُسنَدة لهذا POS user.
     * كل دور قد يكون له branch_scope_id (للأدوار المحدّدة بفرع).
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'pos_user_roles')
            ->withPivot('branch_scope_id', 'granted_by_user_id')
            ->withTimestamps();
    }

    /**
     * P1-RBAC — هل يملك صلاحية معيّنة؟
     *
     * @param string $code رمز الصلاحية (e.g. 'sales.create')
     * @param int|null $branchId الفرع المعنيّ (للتحقّق من branch_scope)
     */
    public function hasPermission(string $code, ?int $branchId = null): bool
    {
        $roleIds = $this->roles()
            ->when($branchId !== null, function ($q) use ($branchId) {
                // إمّا الدور بدون scope (يطبّق على كل الفروع)
                // أو scope مطابق للفرع
                $q->where(function ($w) use ($branchId) {
                    $w->whereNull('pos_user_roles.branch_scope_id')
                      ->orWhere('pos_user_roles.branch_scope_id', $branchId);
                });
            })
            ->pluck('roles.id');
        if ($roleIds->isEmpty()) return false;

        return \App\Models\Permission::query()
            ->whereIn('id', function ($q) use ($roleIds) {
                $q->select('permission_id')->from('role_permissions')
                    ->whereIn('role_id', $roleIds);
            })
            ->where('code', $code)
            ->exists();
    }

    public function scopeActive(Builder $q): Builder { return $q->where('is_active', true); }

    public function can(string $permission): bool
    {
        $perms = $this->permissions ?? [];
        return in_array($permission, $perms, true) || in_array('*', $perms, true);
    }
}
