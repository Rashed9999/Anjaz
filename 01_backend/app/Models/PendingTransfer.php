<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-TRANSFER-COOLDOWN-001 (v2.7)
 *
 * تحويل معلّق ضمن نافذة الإلغاء.
 */
class PendingTransfer extends Model
{
    protected $table = 'pending_transfers';

    protected $fillable = [
        'transfer_ulid', 'sender_user_id', 'recipient_user_id',
        'amount', 'fee', 'total_debited', 'note',
        'status', 'releasable_at', 'completed_at', 'cancelled_at',
        'cancellation_reason',
        'hold_transaction_id', 'release_transaction_id', 'idempotency_key',
        'zone_code',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'fee' => 'decimal:4',
        'total_debited' => 'decimal:4',
        'releasable_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_user_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_user_id'); }

    public function scopeHolding(Builder $q): Builder { return $q->where('status', 'holding'); }

    /** جاهز للتسليم (انتهت نافذة الإلغاء) */
    public function scopeReleasable(Builder $q): Builder
    {
        return $q->where('status', 'holding')->where('releasable_at', '<=', now());
    }

    public function isHolding(): bool { return $this->status === 'holding'; }

    /** هل ما زال ضمن نافذة الإلغاء؟ */
    public function isCancellable(): bool
    {
        return $this->status === 'holding' && $this->releasable_at->isFuture();
    }

    /** الثواني المتبقية على انتهاء النافذة */
    public function secondsRemaining(): int
    {
        if (!$this->isHolding()) return 0;
        return max(0, now()->diffInSeconds($this->releasable_at, false));
    }
}
