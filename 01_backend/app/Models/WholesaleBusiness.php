<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleBusiness extends Model
{
    protected $table = 'wholesale_businesses';
    protected $fillable = [
        'merchant_user_id', 'business_name', 'commercial_register', 'tax_number',
        'city', 'address', 'phone', 'email',
        'default_tax_rate', 'invoice_prefix', 'next_invoice_number', 'default_payment_terms_days',
        'is_active', 'zone_code',
    ];
    protected $casts = [
        'merchant_user_id' => 'integer',
        'default_tax_rate' => 'decimal:2',
        'next_invoice_number' => 'integer',
        'default_payment_terms_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function priceTiers(): HasMany {
        return $this->hasMany(WholesalePriceTier::class, 'business_id')->orderBy('sort_order');
    }
    public function products(): HasMany { return $this->hasMany(WholesaleProduct::class, 'business_id'); }
    public function customers(): HasMany { return $this->hasMany(WholesaleCustomer::class, 'business_id'); }
    public function invoices(): HasMany { return $this->hasMany(WholesaleInvoice::class, 'business_id'); }

    /** يولّد رقم فاتورة جديد بشكل atomic (يجب استخدامه داخل DB transaction). */
    public function nextInvoiceNumber(): string
    {
        $num = $this->next_invoice_number;
        $this->increment('next_invoice_number');
        return sprintf('%s-%s-%05d', $this->invoice_prefix, date('Y'), $num);
    }
}
