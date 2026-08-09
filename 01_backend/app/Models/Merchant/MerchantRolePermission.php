<?php

namespace App\Models\Merchant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صلاحيّةٌ **ثلاثيّةٌ لا مفردة**: فعلٌ ونطاقٌ وحدٌّ واعتماد.
 *
 * «يلغي» وحدها تعطي الكاشيرَ ما تعطيه المدير. والفرقُ ليس تسمية:
 *
 *     fuel.sale.cancel · Scope: BRANCH-001 · Limit: ≤100,000 · Approval: manager
 */
class MerchantRolePermission extends Model
{
    protected $table = 'merchant_role_permissions';

    protected $fillable = [
        'merchant_role_id', 'permission_code',
        'scope_type', 'scope_id', 'max_amount', 'approval',
    ];

    protected $casts = [
        'merchant_role_id' => 'integer',
        'scope_id' => 'integer',
        'max_amount' => 'decimal:4',
    ];

    public const SCOPES = ['merchant', 'station', 'branch', 'shift', 'own'];
    public const APPROVALS = ['none', 'supervisor', 'manager', 'owner'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(MerchantRole::class, 'merchant_role_id');
    }
}
