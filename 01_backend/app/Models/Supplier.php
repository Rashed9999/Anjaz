<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AMIAL-SUPPLIERS-001 */
class Supplier extends Model
{
    protected $fillable = [
        'merchant_user_id', 'name', 'contact_person', 'phone', 'email',
        'address', 'category', 'current_debt', 'is_active',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function ledger(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class)->orderByDesc('id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)->orderByDesc('id');
    }
}
