<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-SPLIT-BILL-001 — حصة مشارك في فاتورة مقسّمة.
 */
class SplitBillParticipant extends Model
{
    protected $table = 'split_bill_participants';

    protected $fillable = [
        'split_bill_id', 'customer_user_id', 'customer_phone',
        'share_amount', 'status', 'paid_transaction_id', 'paid_at',
    ];

    protected $casts = [
        'split_bill_id' => 'integer',
        'customer_user_id' => 'integer',
        'paid_at' => 'datetime',
    ];

    public const STATUSES = ['pending', 'paid', 'cancelled'];

    public function splitBill(): BelongsTo
    {
        return $this->belongsTo(SplitBill::class, 'split_bill_id');
    }
}
