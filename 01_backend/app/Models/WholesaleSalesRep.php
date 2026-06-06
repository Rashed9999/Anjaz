<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleSalesRep extends Model
{
    protected $table = 'wholesale_sales_reps';
    protected $fillable = [
        'business_id', 'user_id', 'full_name', 'phone',
        'default_commission_rate',
        'total_sales', 'total_commission_earned', 'total_commission_paid',
        'is_active',
    ];
    protected $casts = [
        'business_id' => 'integer', 'user_id' => 'integer',
        'default_commission_rate' => 'decimal:2',
        'total_sales' => 'decimal:4',
        'total_commission_earned' => 'decimal:4',
        'total_commission_paid' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo { return $this->belongsTo(WholesaleBusiness::class, 'business_id'); }
    public function invoices(): HasMany { return $this->hasMany(WholesaleInvoice::class, 'sales_rep_id'); }

    /** العمولة المتبقّية لم تُدفع بعد. */
    public function pendingCommission(): float
    {
        return (float)$this->total_commission_earned - (float)$this->total_commission_paid;
    }
}
