<?php

namespace App\Models\Aml;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmlUserRiskProfile extends Model
{
    protected $table = 'aml_user_risk_profiles';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'current_risk_score', 'risk_level',
        'total_transactions', 'total_flagged', 'total_blocked', 'total_held',
        'avg_transaction_amount', 'max_transaction_amount', 'lifetime_volume',
        'last_evaluation_at', 'last_flagged_at', 'last_review_at',
        'manual_override', 'override_reason', 'override_admin_id',
    ];

    protected $casts = [
        'current_risk_score' => 'decimal:2',
        'total_transactions' => 'integer',
        'total_flagged' => 'integer',
        'total_blocked' => 'integer',
        'total_held' => 'integer',
        'avg_transaction_amount' => 'decimal:4',
        'max_transaction_amount' => 'decimal:4',
        'lifetime_volume' => 'decimal:4',
        'last_evaluation_at' => 'datetime',
        'last_flagged_at' => 'datetime',
        'last_review_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function overrideAdmin(): BelongsTo { return $this->belongsTo(User::class, 'override_admin_id'); }

    public function isWhitelisted(): bool { return $this->manual_override === 'whitelist'; }
    public function isBlacklisted(): bool { return $this->manual_override === 'blacklist'; }
}
