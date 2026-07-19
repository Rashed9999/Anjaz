<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-CORPORATE-ACCOUNTS-001 — حركة على حساب الشركة (شراء/سداد/تعديل).
 */
class CorporateAccountMovement extends Model
{
    public const TYPES = ['charge', 'payment', 'adjustment'];

    protected $fillable = [
        'movement_ulid', 'corporate_account_id', 'member_id', 'type',
        'amount', 'balance_after', 'due_date', 'reference_type',
        'reference_id', 'reference_number', 'note', 'created_by_user_id', 'zone_code',
    ];

    protected $casts = [
        'corporate_account_id' => 'integer',
        'member_id' => 'integer',
        'amount' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'due_date' => 'date',
        'created_by_user_id' => 'integer',
    ];

    public function account(): BelongsTo { return $this->belongsTo(CorporateAccount::class, 'corporate_account_id'); }
}
