<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-CASHIER-REFUND-001 — نموذج المرتجعات.
 */
class MerchantRefund extends Model
{
    protected $table = 'merchant_refunds';

    protected $fillable = [
        'refund_ulid',
        'merchant_user_id', 'customer_user_id', 'pos_user_id',
        'original_transaction_id', 'original_sale_ulid', 'original_amount',
        'customer_phone', 'customer_name',
        'refund_amount', 'refund_method', 'credit_account_id',
        'items', 'reason',
        'status', 'approved_by_admin_id', 'approved_at',
        'ledger_entry_ulid', 'receipt_id',
        'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'customer_user_id' => 'integer',
        'pos_user_id' => 'integer',
        'credit_account_id' => 'integer',
        'approved_by_admin_id' => 'integer',
        'receipt_id' => 'integer',
        'items' => 'array',
        'approved_at' => 'datetime',
    ];

    /** طرق الاسترداد المدعومة */
    public const METHODS = ['cash', 'wallet', 'credit_account'];

    /** حالات المرتجع */
    public const STATUSES = ['pending_approval', 'completed', 'rejected'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditAccount::class, 'credit_account_id');
    }
}
