<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierShift extends Model
{
    protected $table = 'cashier_shifts';

    protected $fillable = [
        'merchant_user_id', 'pos_user_id', 'opening_float', 'expected_cash',
        'counted_cash', 'variance', 'cash_sales', 'sales_count', 'status',
        'notes', 'opened_by', 'opened_at', 'closed_at', 'zone_code',
    ];

    protected $casts = [
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'variance' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'sales_count' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
