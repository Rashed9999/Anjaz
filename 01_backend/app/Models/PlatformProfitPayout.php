<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دورة صرف أرباح أميال: طلب → اعتماد ثانٍ → دفع موثّق → تأكيد نقدي إن لزم.
 *
 * لا يعبّر الصف عن رصيد محفظة؛ الحقيقة المالية تبقى في دفتر القيود.
 */
class PlatformProfitPayout extends Model
{
    public const STATUS_PENDING = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    public const METHOD_CASH = 'cash';
    public const METHOD_BANK = 'bank';

    protected $fillable = [
        'payout_ulid', 'requested_by_id', 'approval_request_id', 'idempotency_key',
        'amount', 'currency', 'status', 'payout_method', 'beneficiary_name',
        'destination_reference', 'request_note', 'approval_ledger_entry_ulid',
        'payment_ledger_entry_ulid', 'handover_ulid', 'payment_reference',
        'proof_reference', 'approved_by_id', 'approved_at', 'paid_by_id', 'paid_at',
        'rejected_by_id', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
