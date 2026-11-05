<?php

namespace App\Models\BusinessGateway;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-BUSINESS-CREDIT-001 — مرآة دين عميل لدى تاجر في «أميال أعمال».
 *
 * للقراءة فقط: الدفتر في أميال أعمال. الصف يُستبدل بنسخة أحدث (`version`)
 * ولا يُعدَّل هنا أبداً، فلا يصير لأميال باي رأيٌ في الرصيد.
 */
class BusinessCustomerDebt extends Model
{
    protected $table = 'business_customer_debts';

    protected $fillable = [
        'business_link_id', 'contact_ref', 'customer_user_id', 'customer_phone', 'customer_name',
        'balance', 'overdue_amount', 'next_due_date', 'currency', 'reminders_enabled',
        'version', 'as_of', 'last_reminded_at',
    ];

    protected $casts = [
        'business_link_id' => 'integer',
        'customer_user_id' => 'integer',
        'version' => 'integer',
        'reminders_enabled' => 'boolean',
        'next_due_date' => 'date',
        'as_of' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(BusinessLink::class, 'business_link_id');
    }
}
