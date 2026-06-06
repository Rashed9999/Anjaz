<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-FUND-FAMILY-001
 */
class FamilyFundMember extends Model
{
    protected $table = 'family_fund_members';

    protected $fillable = [
        'fund_id',
        'user_id',
        'role',
        'status',
        'total_contributed',
        'total_disbursed',
        'invited_at',
        'joined_at',
        'removed_at',
        'invited_by_user_id',
    ];

    protected $casts = [
        'fund_id' => 'integer',
        'user_id' => 'integer',
        'total_contributed' => 'decimal:4',
        'total_disbursed' => 'decimal:4',
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
        'removed_at' => 'datetime',
        'invited_by_user_id' => 'integer',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FamilyFund::class, 'fund_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
