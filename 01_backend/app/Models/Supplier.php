<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AMIAL-SUPPLIERS-001 */
class Supplier extends Model
{
    protected $fillable = [
        'merchant_user_id', 'amial_user_id', 'name', 'contact_person', 'phone', 'email',
        'address', 'category', 'current_debt', 'current_credit', 'is_active',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'amial_user_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * AMIAL-BUSINESS-CREDIT-001 — بعد تسليم الآجل لأميال أعمال لا يتحرّك هنا
     * أي رصيد دين. الحارس على النموذج لا على الخدمة، فيمرّ به كل مسار.
     */
    protected static function booted(): void
    {
        static::saving(function (self $supplier) {
            if (\App\Services\BusinessGateway\CreditBalanceChange::changed($supplier, ['current_debt', 'current_credit'])) {
                app(\App\Services\BusinessGateway\CreditHandoverService::class)->assertOpen($supplier->merchant_user_id);
            }
        });
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class)->orderByDesc('id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)->orderByDesc('id');
    }
}
