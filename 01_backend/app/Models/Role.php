<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'code', 'label_ar', 'is_system', 'merchant_user_id', 'description',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'merchant_user_id' => 'integer',
    ];

    // ============ System Role Codes ============
    public const SUPER_ADMIN = 'super_admin';           // كل الصلاحيات
    public const BRANCH_MANAGER = 'branch_manager';     // مدير فرع
    public const CASHIER = 'cashier';                   // كاشير عادي
    public const ACCOUNTANT = 'accountant';             // محاسب
    public const WAREHOUSE_KEEPER = 'warehouse_keeper'; // أمين مخزن
    public const SALES_REP = 'sales_rep';               // مندوب مبيعات

    public const ALL_SYSTEM_ROLES = [
        self::SUPER_ADMIN, self::BRANCH_MANAGER, self::CASHIER,
        self::ACCOUNTANT, self::WAREHOUSE_KEEPER, self::SALES_REP,
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function hasPermission(string $code): bool
    {
        return $this->permissions()->where('code', $code)->exists();
    }
}
