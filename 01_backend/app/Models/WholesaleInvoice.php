<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleInvoice extends Model
{
    protected $table = 'wholesale_invoices';
    protected $fillable = [
        'invoice_ulid', 'invoice_number', 'business_id', 'branch_id', 'customer_id',
        'sales_rep_id', 'created_by_user_id',
        'invoice_date', 'due_date',
        'subtotal', 'discount_amount', 'tax_rate', 'tax_amount',
        'total_amount', 'paid_amount', 'balance_due',
        'status', 'payment_type', 'paid_transaction_id',
        'sales_rep_commission_rate', 'sales_rep_commission_amount',
        'notes', 'zone_code',
    ];
    protected $casts = [
        'business_id' => 'integer', 'customer_id' => 'integer',
        'sales_rep_id' => 'integer', 'created_by_user_id' => 'integer',
        'invoice_date' => 'date', 'due_date' => 'date',
        'subtotal' => 'decimal:4', 'discount_amount' => 'decimal:4',
        'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4', 'paid_amount' => 'decimal:4', 'balance_due' => 'decimal:4',
        'sales_rep_commission_rate' => 'decimal:2',
        'sales_rep_commission_amount' => 'decimal:4',
    ];

    public const STATUSES = ['draft', 'issued', 'partial_paid', 'paid', 'overdue', 'voided'];
    /**
     * `amial_pay` لا يعني أن الموظف كتب "تم الدفع"؛ بل لا يصبح مدفوعاً
     * إلا بعد التحقق من طلب دفع مكتمل ومتجه إلى محفظة مالك التاجر.
     */
    public const PAYMENT_TYPES = ['cash', 'amial_pay', 'credit'];

    public function business(): BelongsTo { return $this->belongsTo(WholesaleBusiness::class, 'business_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(WholesaleCustomer::class, 'customer_id'); }
    public function salesRep(): BelongsTo { return $this->belongsTo(WholesaleSalesRep::class, 'sales_rep_id'); }
    public function items(): HasMany { return $this->hasMany(WholesaleInvoiceItem::class, 'invoice_id'); }
    public function collections(): HasMany { return $this->hasMany(WholesaleCollection::class, 'invoice_id'); }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['issued', 'partial_paid'], true)
            && $this->due_date !== null
            && $this->due_date->isPast()
            && (float)$this->balance_due > 0;
    }

    public function daysOverdue(): int
    {
        if (!$this->isOverdue()) return 0;
        return (int)now()->startOfDay()->diffInDays($this->due_date->startOfDay());
    }
}
