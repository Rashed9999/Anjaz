<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallmentSchedule extends Model
{
    protected $table = 'installment_schedules';

    protected $fillable = [
        'contract_id', 'seq', 'due_date', 'amount', 'paid_amount', 'late_fee', 'status', 'paid_at',
    ];

    protected $casts = [
        'seq' => 'integer', 'due_date' => 'date',
        'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'late_fee' => 'decimal:2',
        'paid_at' => 'datetime',
    ];
}
