<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-PAYMENT-REQUESTS-001 — طلب دفع.
 */
class PaymentRequest extends Model
{
    protected $table = 'payment_requests';

    protected $fillable = [
        'request_ulid', 'short_code',
        'requester_user_id', 'recipient_user_id', 'recipient_phone', 'recipient_name',
        'amount', 'note', 'share_method',
        'status', 'paid_by_user_id', 'paid_transaction_id', 'paid_at',
        'expires_at',
        'is_recurring', 'recurring_period', 'parent_request_id',
        'zone_code',
    ];

    protected $casts = [
        'requester_user_id' => 'integer',
        'recipient_user_id' => 'integer',
        'paid_by_user_id' => 'integer',
        'parent_request_id' => 'integer',
        'is_recurring' => 'boolean',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // AMIAL-REQUEST-DIRECT-001: 'declined' — المستلم يرفض. ويُفصل عن
    // 'cancelled' (الطالب يسحب طلبه): الطالب يحتاج أن يعرف أيّهما وقع،
    // فالمرفوض لا يُعاد إرساله والملغى قد يُعاد.
    public const STATUSES = ['pending', 'paid', 'cancelled', 'expired', 'declined'];
    public const PERIODS = ['daily', 'weekly', 'monthly'];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'pending' && $this->expires_at?->isFuture() === true;
    }

    /** الرابط الكامل المعروض للمشاركة. */
    public function publicUrl(): string
    {
        $base = config('app.public_url', 'https://amyal.pay');
        return "{$base}/req/{$this->short_code}";
    }
}
