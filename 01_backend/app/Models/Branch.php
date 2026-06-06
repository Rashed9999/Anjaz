<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * P1-BRANCHES — Branch Model.
 */
class Branch extends Model
{
    use SoftDeletes;

    protected $table = 'branches';

    protected $fillable = [
        'merchant_user_id', 'name', 'code', 'address', 'city', 'phone',
        'manager_pos_user_id', 'is_active', 'is_default', 'settings',
        'cached_pos_users_count', 'cached_terminals_count', 'ulid',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'settings' => 'array',
        'cached_pos_users_count' => 'integer',
        'cached_terminals_count' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($branch) {
            if (empty($branch->ulid)) {
                $branch->ulid = (string) Str::ulid();
            }
        });
    }

    // ============ Relationships ============

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(PosUser::class, 'manager_pos_user_id');
    }

    /** الموظّفون المرتبطون بهذا الفرع (يضاف branch_id لـ pos_users في الجولة 2). */
    public function posUsers(): HasMany
    {
        return $this->hasMany(PosUser::class, 'branch_id');
    }

    // ============ Scopes ============

    public function scopeActive($q) { return $q->where('is_active', true); }
    public function scopeForMerchant($q, int $merchantId) {
        return $q->where('merchant_user_id', $merchantId);
    }

    // ============ Helpers ============

    /** عنوان كامل مُنسَّق. */
    public function fullAddressAttribute(): string
    {
        $parts = array_filter([$this->city, $this->address]);
        return implode(' — ', $parts);
    }
}
