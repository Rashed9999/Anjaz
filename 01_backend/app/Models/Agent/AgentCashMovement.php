<?php

namespace App\Models\Agent;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-AGENT-PORTAL-001 — حركة نقدٍ ورقيّ.
 *
 * تُلحَق ولا تُعدَّل (`$timestamps = false` مع `created_at` وحده): حركةُ نقدٍ
 * يمكن تحريرها بعد وقوعها تُبطل الجرد كلّه — يصير كلّ فرقٍ قابلاً للتسوية
 * بتعديل التاريخ بدل تفسيره.
 */
class AgentCashMovement extends Model
{
    protected $table = 'agent_cash_movements';

    public $timestamps = false;

    public const REASON_LABELS = [
        'customer_deposit' => 'إيداع عميل (نقد داخل)',
        'customer_withdraw' => 'سحب عميل (نقد خارج)',
        'treasury_in' => 'توريد إلى الفرع',
        'treasury_out' => 'توريد من الفرع',
        'count_adjustment' => 'تسوية جرد',
        'opening' => 'رصيد افتتاحيّ',
        'shift_open' => 'عهدة ورديّة (من الخزنة إلى الدرج)',
        'shift_close' => 'تسليم ورديّة (من الدرج إلى الخزنة)',
    ];

    protected $fillable = [
        'branch_id', 'shift_id', 'staff_id', 'direction', 'reason', 'amount',
        'balance_before', 'balance_after', 'reference',
        'actor_user_id', 'customer_user_id', 'note', 'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AgentBranch::class, 'branch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
