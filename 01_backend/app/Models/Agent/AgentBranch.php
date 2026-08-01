<?php

namespace App\Models\Agent;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * AMIAL-AGENT-PORTAL-001 — فرع وكيل.
 *
 * الفرع **حسابُ وكيلٍ ابن** لا كيانٌ مستقلّ — انظر شرح الهجرة. وهذا النموذج
 * يحمل ما لا يحمله الحساب: الاسم والعنوان والمدير وخزنة النقد.
 */
class AgentBranch extends Model
{
    protected $table = 'agent_branches';

    protected $fillable = [
        'agent_user_id', 'branch_user_id', 'name', 'code',
        'city', 'address', 'phone', 'lat', 'lng',
        'manager_user_id', 'is_active', 'working_hours',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'working_hours' => 'array',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    /** حساب الفرع — هو الذي يحمل المحفظة والحدود والتسويات. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'branch_user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function till(): HasOne
    {
        return $this->hasOne(AgentCashTill::class, 'branch_id');
    }
}
