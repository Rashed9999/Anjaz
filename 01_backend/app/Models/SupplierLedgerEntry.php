<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AMIAL-SUPPLIERS-001 — حركة على حساب مورد. */
class SupplierLedgerEntry extends Model
{
    protected $table = 'supplier_ledger';

    protected $fillable = [
        'supplier_id', 'merchant_user_id', 'entry_type',
        'amount', 'debt_after', 'reference', 'note',
    ];
}
