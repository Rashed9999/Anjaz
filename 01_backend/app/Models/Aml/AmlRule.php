<?php

namespace App\Models\Aml;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-AML-001 (v1.4)
 */
class AmlRule extends Model
{
    protected $table = 'aml_rules';

    protected $fillable = [
        'code', 'name_ar', 'description_ar', 'rule_type',
        'applies_to', 'parameters',
        'action_on_match', 'risk_score_contribution',
        'priority', 'is_active', 'shadow_mode',
        'created_by_admin_id', 'updated_by_admin_id',
    ];

    protected $casts = [
        'parameters' => 'array',
        'risk_score_contribution' => 'decimal:2',
        'is_active' => 'boolean',
        'shadow_mode' => 'boolean',
        'priority' => 'integer',
    ];

    public function evaluations(): HasMany
    {
        return $this->hasMany(AmlRuleEvaluation::class, 'rule_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function appliesToType(string $type): bool
    {
        $types = array_map('trim', explode(',', $this->applies_to));
        return in_array($type, $types, true) || in_array('*', $types, true);
    }
}
