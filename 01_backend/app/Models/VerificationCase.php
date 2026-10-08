<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The workflow record intentionally reuses the existing privacy/KYC case. */
class VerificationCase extends Model
{
    protected $table = 'kyc_verification_cases';

    protected $fillable = [
        'user_id', 'case_ulid', 'subject_kind', 'merchant_vertical', 'target_level',
        'policy_version', 'workflow_status', 'current_step', 'final_decided_by',
        'final_decided_at', 'final_reason',
    ];

    protected $casts = [
        'target_level' => 'integer',
        'final_decided_by' => 'integer',
        'final_decided_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(VerificationCaseStep::class, 'verification_case_id')->orderBy('step_order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VerificationCaseEvent::class, 'verification_case_id')->latest('id');
    }
}
