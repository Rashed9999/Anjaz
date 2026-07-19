<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-CORPORATE-ACCOUNTS-001 — عضو مخوّل بالشراء على حساب الشركة.
 */
class CorporateAccountMember extends Model
{
    protected $fillable = [
        'corporate_account_id', 'member_name', 'identifier',
        'per_txn_limit', 'is_active', 'last_used_at',
    ];

    protected $casts = [
        'corporate_account_id' => 'integer',
        'per_txn_limit' => 'decimal:4',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function account(): BelongsTo { return $this->belongsTo(CorporateAccount::class, 'corporate_account_id'); }
}
