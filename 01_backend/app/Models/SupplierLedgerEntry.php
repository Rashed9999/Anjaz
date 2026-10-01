<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AMIAL-SUPPLIERS-001 — حركة على حساب مورد. */
class SupplierLedgerEntry extends Model
{
    protected $table = 'supplier_ledger';

    /**
     * AMIAL-DAILY-MOVEMENT-001 — **`cash_amount` هنا وإلّا سقط صامتاً.**
     *
     * `$fillable` يُسقط ما ليس فيه من `create()` **بلا خطأ ولا تحذير** —
     * وقد عضّ هذا المشروعَ ثلاث مرّات. فعمودٌ يُضاف في هجرةٍ ولا يُضاف
     * هنا يبقى `null` أبداً، ويُقرأ «استلامٌ قديمٌ آجل» في كلّ مشترىً
     * نقديّ.
     */
    protected $fillable = [
        'entry_ulid', 'supplier_id', 'merchant_user_id', 'entry_type',
        'amount', 'cash_amount', 'payment_method', 'debt_after', 'credit_after',
        'supplier_credit_applied', 'reference',
        'transaction_id', 'cashier_shift_id', 'idempotency_key', 'note',
    ];
}
