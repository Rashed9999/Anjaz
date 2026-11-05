<?php

namespace App\Models\BusinessGateway;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-BUSINESS-REFUND-001 — استرداد دفعة أميال أعمال إلى محفظة من دفعها.
 * المال يتحرّك مرة واحدة عند الانتقال إلى completed، بقيد مزدوج.
 */
class BusinessRefund extends Model
{
    public const PENDING = 'pending_approval';
    public const COMPLETED = 'completed';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';

    /** ما يحجز من المبلغ القابل للاسترداد. */
    public const COUNTED = [self::PENDING, self::COMPLETED];

    protected $table = 'business_refunds';

    protected $fillable = [
        'refund_ulid', 'business_link_id', 'business_payment_intent_id', 'business_reference', 'amount', 'currency',
        'request_fingerprint', 'customer_user_id', 'status', 'approval', 'reason', 'decision_note',
        'decided_by_user_id', 'decided_at', 'ledger_entry_ulid', 'expires_at',
    ];

    protected $casts = [
        'business_link_id' => 'integer',
        'business_payment_intent_id' => 'integer',
        'customer_user_id' => 'integer',
        'decided_by_user_id' => 'integer',
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(BusinessLink::class, 'business_link_id');
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(BusinessPaymentIntent::class, 'business_payment_intent_id');
    }
}
