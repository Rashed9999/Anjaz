<?php

namespace App\Models\Aml;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmlRuleEvaluation extends Model
{
    protected $table = 'aml_rule_evaluations';
    public $timestamps = false;

    protected $fillable = [
        'transaction_ulid', 'transaction_type',
        'actor_user_id', 'counterparty_user_id', 'amount',
        'rule_id', 'rule_code',
        'matched', 'contributed_risk_score',
        'evaluation_context', 'created_at',
    ];

    protected $casts = [
        'matched' => 'boolean',
        'contributed_risk_score' => 'decimal:2',
        'amount' => 'decimal:4',
        'evaluation_context' => 'array',
        'created_at' => 'datetime',
    ];

    public function rule(): BelongsTo { return $this->belongsTo(AmlRule::class, 'rule_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
