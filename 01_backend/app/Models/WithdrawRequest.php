<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawRequest extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        // AMIAL-WITHDRAW-DOOR-001 — **الرسمُ كان يُسقَط صامتاً.**
        //
        // العمودُ موجودٌ في الجدول والمتحكّمُ يقرؤه في كلّ حساب
        // (`amount + admin_charge`)، ولم يكن في `$fillable` — فأيُّ
        // `create()` يُمرّره **يتجاهله** بلا خطأ، ويبقى `null`.
        //
        // وأثرُه ماليّ: المحجوزُ يساوي المبلغ وحده، والمُعاد عند الرفض
        // يساوي المبلغ وحده، **والرسمُ يضيع بين الرقمين** — أو يُحسب
        // صفراً في كشوف الرسوم فتقلّ الإيرادات المعلنة بلا سبب ظاهر.
        'admin_charge',
        'request_status',
        'is_paid',
        'sender_note',
        'admin_note',
        'withdrawal_method_id',
        'withdrawal_method_fields',
    ];

    protected $casts = [
        'withdrawal_method_fields' => 'array',
    ];

    public function withdrawal_method(): BelongsTo
    {
        return $this->belongsTo(WithdrawalMethod::class, 'withdrawal_method_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
