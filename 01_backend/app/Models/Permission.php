<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $table = 'permissions';

    protected $fillable = ['code', 'label_ar', 'category', 'description'];

    // ============ Permission Categories ============
    public const CAT_SALES = 'sales';
    public const CAT_PRODUCTS = 'products';
    public const CAT_REPORTS = 'reports';
    public const CAT_BRANCHES = 'branches';
    public const CAT_EMPLOYEES = 'employees';
    public const CAT_CUSTOMERS = 'customers';
    public const CAT_SUPPLIERS = 'suppliers';
    public const CAT_FINANCE = 'finance';
    public const CAT_SETTINGS = 'settings';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
