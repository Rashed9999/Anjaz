<?php

namespace App\Models\Merchant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إسنادُ دورٍ إلى موظّف، بنطاقِ محطّةٍ أو فرعٍ إن لزم. */
class MerchantUserRole extends Model
{
    protected $table = 'merchant_user_roles';

    protected $fillable = [
        'merchant_user_id', 'user_id', 'merchant_role_id',
        'scope_station_id', 'scope_branch_id', 'is_active',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'user_id' => 'integer',
        'merchant_role_id' => 'integer',
        'scope_station_id' => 'integer',
        'scope_branch_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(MerchantRole::class, 'merchant_role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
