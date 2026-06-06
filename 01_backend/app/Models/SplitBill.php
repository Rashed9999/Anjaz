<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-SPLIT-BILL-001 — فاتورة مقسّمة ينشئها التاجر/POS.
 */
class SplitBill extends Model
{
    protected $table = 'split_bills';

    protected $fillable = [
        'split_ulid', 'merchant_user_id', 'pos_user_id',
        'total_amount', 'participant_count', 'channel',
        'status', 'zone_code', 'note',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'pos_user_id' => 'integer',
        'participant_count' => 'integer',
    ];

    public const STATUSES = ['open', 'partially_paid', 'completed', 'cancelled'];

    public function participants(): HasMany
    {
        return $this->hasMany(SplitBillParticipant::class, 'split_bill_id');
    }

    public function hasAnyPayment(): bool
    {
        return $this->participants()->where('status', 'paid')->exists();
    }
}
