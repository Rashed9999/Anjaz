<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-AGENT-NETWORK-001 (v2.3)
 *
 * ملف الوكيل (الصرّاف) الموسّع.
 */
class AgentProfile extends Model
{
    protected $table = 'agent_profiles';

    protected $fillable = [
        'user_id', 'parent_agent_id', 'agent_level',
        'business_name', 'license_number',
        'location_city', 'location_address', 'location_lat', 'location_lng',
        'daily_cash_in_limit', 'daily_cash_out_limit',
        'single_transaction_limit', 'min_float_balance',
        'commission_rate', 'status', 'zone_code',
    ];

    protected $casts = [
        'daily_cash_in_limit' => 'decimal:4',
        'daily_cash_out_limit' => 'decimal:4',
        'single_transaction_limit' => 'decimal:4',
        'min_float_balance' => 'decimal:4',
        'commission_rate' => 'decimal:2',
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(AgentProfile::class, 'parent_agent_id'); }
    public function subAgents(): HasMany { return $this->hasMany(AgentProfile::class, 'parent_agent_id'); }

    public function scopeActive(Builder $q): Builder { return $q->where('status', 'active'); }
    public function scopeDistributors(Builder $q): Builder { return $q->where('agent_level', 'distributor'); }

    public function isActive(): bool { return $this->status === 'active'; }
    public function isDistributor(): bool { return $this->agent_level === 'distributor'; }
}
