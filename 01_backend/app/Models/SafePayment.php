<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1)
 */
class SafePayment extends Model
{
    protected $table = 'safe_payments';

    protected $fillable = [
        'payment_ulid',
        'buyer_user_id',
        'seller_user_id',
        'title',
        'description',
        'delivery_terms',
        'attachments',
        'amount',
        'platform_fee',
        'fee_scheme_id',
        'fee_scheme_version',
        'held_amount',
        'status',
        'buyer_debit_tx_id',
        'seller_credit_tx_id',
        'buyer_refund_tx_id',
        'refunded_to_buyer_amount',
        'released_to_seller_amount',
        'seller_response_deadline',
        'seller_accepted_at',
        'seller_rejected_at',
        'in_delivery_at',
        'delivered_at',
        'buyer_confirmed_at',
        'released_at',
        'refunded_at',
        'cancelled_at',
        'expired_at',
        'is_disputed',
        'disputed_at',
        'admin_resolved_by',
        'admin_resolved_at',
        'admin_resolution_note',
        'zone_code',
        'metadata',
    ];

    protected $casts = [
        'buyer_user_id' => 'integer',
        'seller_user_id' => 'integer',
        'amount' => 'decimal:4',
        'platform_fee' => 'decimal:4',
        'held_amount' => 'decimal:4',
        'refunded_to_buyer_amount' => 'decimal:4',
        'released_to_seller_amount' => 'decimal:4',
        'attachments' => 'array',
        'metadata' => 'array',
        'is_disputed' => 'boolean',
        'seller_response_deadline' => 'datetime',
        'seller_accepted_at' => 'datetime',
        'seller_rejected_at' => 'datetime',
        'in_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'buyer_confirmed_at' => 'datetime',
        'released_at' => 'datetime',
        'refunded_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
        'disputed_at' => 'datetime',
        'admin_resolved_at' => 'datetime',
        'admin_resolved_by' => 'integer',
    ];

    // ============================================================
    // Relationships
    // ============================================================

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function adminResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_resolved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SafePaymentEvent::class, 'safe_payment_id')
            ->orderBy('created_at');
    }

    // ============================================================
    // Scopes
    // ============================================================

    public function scopeForUser(Builder $q, int $userId): Builder
    {
        return $q->where(function ($q) use ($userId) {
            $q->where('buyer_user_id', $userId)
              ->orWhere('seller_user_id', $userId);
        });
    }

    public function scopeAsBuyer(Builder $q, int $userId): Builder
    {
        return $q->where('buyer_user_id', $userId);
    }

    public function scopeAsSeller(Builder $q, int $userId): Builder
    {
        return $q->where('seller_user_id', $userId);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', [
            'pending_seller_acceptance',
            'funded',
            'in_delivery',
            'delivered',
            'disputed',
        ]);
    }

    public function scopeTerminal(Builder $q): Builder
    {
        return $q->whereIn('status', [
            'released_to_seller',
            'seller_rejected',
            'refunded_to_buyer',
            'partially_refunded',
            'cancelled',
            'expired',
        ]);
    }

    public function scopeNeedingAdminReview(Builder $q): Builder
    {
        return $q->where('is_disputed', true)
            ->whereNull('admin_resolved_at');
    }

    public function scopeExpiredPendingAcceptance(Builder $q): Builder
    {
        return $q->where('status', 'pending_seller_acceptance')
            ->where('seller_response_deadline', '<', now());
    }

    // ============================================================
    // State predicates
    // ============================================================

    public function isPendingSellerAcceptance(): bool { return $this->status === 'pending_seller_acceptance'; }
    public function isFunded(): bool { return $this->status === 'funded'; }
    public function isInDelivery(): bool { return $this->status === 'in_delivery'; }
    public function isDelivered(): bool { return $this->status === 'delivered'; }
    public function isDisputed(): bool { return $this->status === 'disputed'; }
    public function isTerminal(): bool {
        return in_array($this->status, [
            'released_to_seller', 'seller_rejected', 'refunded_to_buyer',
            'partially_refunded', 'cancelled', 'expired',
        ], true);
    }

    /**
     * هل المشتري يستطيع إلغاء؟ (فقط قبل in_delivery، إلا لو disputed)
     */
    public function canBuyerCancel(): bool
    {
        return in_array($this->status, ['pending_seller_acceptance', 'funded'], true);
    }

    /**
     * هل المشتري يستطيع فتح نزاع؟
     */
    public function canBuyerDispute(): bool
    {
        return in_array($this->status, ['funded', 'in_delivery', 'delivered'], true)
            && !$this->is_disputed;
    }

    /**
     * هل المشتري يستطيع تأكيد الاستلام؟
     */
    public function canBuyerConfirm(): bool
    {
        return $this->status === 'delivered' && !$this->is_disputed;
    }

    /**
     * هل البائع يستطيع تأكيد in_delivery؟
     */
    public function canSellerMarkInDelivery(): bool
    {
        return $this->status === 'funded';
    }

    /**
     * هل البائع يستطيع تأكيد التسليم؟
     */
    public function canSellerMarkDelivered(): bool
    {
        return $this->status === 'in_delivery';
    }

    /**
     * هل البائع يستطيع قبول/رفض؟
     */
    public function canSellerRespond(): bool
    {
        return $this->status === 'pending_seller_acceptance';
    }
}
