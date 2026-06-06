<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-DONATIONS-001 (v1.2)
 */
class Donation extends Model
{
    protected $table = 'donations';

    protected $fillable = [
        'donation_ulid',
        'campaign_id', 'org_id', 'donor_user_id',
        'is_anonymous',
        'amount', 'platform_fee', 'net_to_charity',
        'wallet_transaction_id', 'receipt_id',
        'donor_message',
        'status', 'settlement_id',
        'donated_at', 'refunded_at', 'refund_reason',
        'zone_code',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'amount' => 'decimal:4',
        'platform_fee' => 'decimal:4',
        'net_to_charity' => 'decimal:4',
        'donated_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CharityCampaign::class, 'campaign_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(CharityOrganization::class, 'org_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_user_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CharitySettlement::class, 'settlement_id');
    }

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->whereIn('status', ['completed', 'settled']);
    }

    public function scopePendingSettlement(Builder $q): Builder
    {
        return $q->where('status', 'completed')->whereNull('settlement_id');
    }

    /**
     * للعرض العام (لا يظهر اسم المتبرع المجهول).
     */
    public function getPublicDonorNameAttribute(): string
    {
        if ($this->is_anonymous || !$this->donor) {
            return 'متبرع مجهول';
        }
        $names = array_filter([$this->donor->f_name, $this->donor->l_name]);
        return !empty($names) ? implode(' ', $names) : 'متبرع كريم';
    }
}
