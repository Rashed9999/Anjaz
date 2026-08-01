<?php

namespace App\Models\Agent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-AGENT-PORTAL-001 — خزنة النقد الورقيّ في الفرع.
 *
 * **القيد الذي كان غائباً.** الرصيد الإلكترونيّ يحدّ الإيداع، والنقد
 * الورقيّ يحدّ السحب — وهما يتحرّكان في اتّجاهين متعاكسين. وبتتبّع الأوّل
 * وحده يقبل النظام سحباً لا يستطيع الفرع دفعه.
 */
class AgentCashTill extends Model
{
    protected $table = 'agent_cash_tills';

    protected $fillable = [
        'branch_id', 'cash_on_hand', 'max_cash_on_hand',
        'min_cash_alert', 'last_counted_at', 'last_counted_amount',
    ];

    protected $casts = [
        'cash_on_hand' => 'decimal:4',
        'max_cash_on_hand' => 'decimal:4',
        'min_cash_alert' => 'decimal:4',
        'last_counted_amount' => 'decimal:4',
        'last_counted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AgentBranch::class, 'branch_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AgentCashMovement::class, 'branch_id', 'branch_id');
    }

    public function canPayOut(string $amount): bool
    {
        return bccomp((string) $this->cash_on_hand, $amount, 4) >= 0;
    }

    public function isLow(): bool
    {
        return bccomp((string) $this->min_cash_alert, '0', 4) > 0
            && bccomp((string) $this->cash_on_hand, (string) $this->min_cash_alert, 4) < 0;
    }

    /** فوق الحدّ الأعلى: خطرٌ أمنيّ لا ميزة سيولة. */
    public function isOverloaded(): bool
    {
        return bccomp((string) $this->max_cash_on_hand, '0', 4) > 0
            && bccomp((string) $this->cash_on_hand, (string) $this->max_cash_on_hand, 4) > 0;
    }
}
