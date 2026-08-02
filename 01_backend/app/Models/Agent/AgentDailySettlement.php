<?php

namespace App\Models\Agent;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-DAILY-SETTLEMENT-001 — يومُ الوكيل مُقفَلاً.
 */
class AgentDailySettlement extends Model
{
    protected $table = 'agent_daily_settlements';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'لم تُرفع بعد',
        self::STATUS_SUBMITTED => 'مرفوعة — بانتظار أميال',
        self::STATUS_ACCEPTED => 'قُبلت ونُفّذ التحويل',
        self::STATUS_REJECTED => 'مرفوضة',
    ];

    /**
     * اتّجاه التحوّل بين الورق والإلكترونيّ.
     *
     * `topup`  — الوكيل يسلّم ورقاً ويستلم رصيداً (إيداعاتُه أكثر).
     * `payout` — الوكيل يعيد رصيداً ويستلم ورقاً (سحوباتُه أكثر).
     */
    public const CONVERSION_LABELS = [
        'topup' => 'يسلّم نقداً ويستلم رصيداً إلكترونيّاً',
        'payout' => 'يعيد رصيداً إلكترونيّاً ويستلم نقداً',
        'none' => 'لا تحويل — اليوم متعادل',
    ];

    public const WINDOW_LABELS = [
        'on_time' => 'رُفعت في وقتها',
        'late' => 'رُفعت متأخّرة',
        'unlocked' => 'رُفعت بفكٍّ من إدارة أميال',
    ];

    protected $guarded = [];

    protected $casts = [
        'settlement_date' => 'date',
        'deposits_total' => 'decimal:4',
        'withdrawals_total' => 'decimal:4',
        'fees_collected' => 'decimal:4',
        'agent_commission' => 'decimal:4',
        'shortage_total' => 'decimal:4',
        'overage_total' => 'decimal:4',
        'net_cash' => 'decimal:4',
        'net_float' => 'decimal:4',
        'conversion_amount' => 'decimal:4',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'unlocked_at' => 'datetime',
        'detail' => 'array',
    ];
}
