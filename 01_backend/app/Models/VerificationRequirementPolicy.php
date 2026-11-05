<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationRequirementPolicy extends Model
{
    protected $fillable = [
        'subject_kind', 'merchant_vertical', 'target_level', 'policy_version',
        'requirements', 'is_active',
    ];

    protected $casts = [
        'target_level' => 'integer',
        'requirements' => 'array',
        'is_active' => 'boolean',
    ];
}
