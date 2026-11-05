<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationCaseStep extends Model
{
    protected $fillable = [
        'verification_case_id', 'step_key', 'step_order', 'status', 'evidence_snapshot',
        'completed_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'evidence_snapshot' => 'array',
        'completed_at' => 'datetime',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function verificationCase(): BelongsTo
    {
        return $this->belongsTo(VerificationCase::class, 'verification_case_id');
    }
}
