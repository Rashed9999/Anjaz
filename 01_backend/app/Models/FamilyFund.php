<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-FUND-FAMILY-001
 */
class FamilyFund extends Model
{
    protected $table = 'family_funds';

    protected $fillable = [
        'fund_ulid',
        'name',
        'description',
        'owner_user_id',
        'balance',
        'held_balance',
        'zone_code',
        'status',
        'require_owner_approval_for_disbursement',
        'max_member_contribution_per_day',
    ];

    protected $casts = [
        'owner_user_id' => 'integer',
        'balance' => 'decimal:4',
        'held_balance' => 'decimal:4',
        'require_owner_approval_for_disbursement' => 'boolean',
        'max_member_contribution_per_day' => 'decimal:4',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(FamilyFundMember::class, 'fund_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FamilyFundTransaction::class, 'fund_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** فحص إن كان user عضو نشط */
    public function isMember(int $userId): bool
    {
        return $this->members()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();
    }

    public function memberRole(int $userId): ?string
    {
        return $this->members()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->value('role');
    }

    public function canContribute(int $userId): bool
    {
        return in_array($this->memberRole($userId), ['owner', 'admin', 'member'], true);
    }

    public function canDisburse(int $userId): bool
    {
        $role = $this->memberRole($userId);
        if ($role === 'owner') return true;
        // غير owner: يحتاج approval
        return in_array($role, ['admin', 'member'], true);
    }

    public function canApproveDisbursement(int $userId): bool
    {
        return $this->memberRole($userId) === 'owner';
    }
}
