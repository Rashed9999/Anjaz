<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationCaseEvent extends Model
{
    protected $fillable = ['verification_case_id', 'actor_user_id', 'event_type', 'details'];

    protected $casts = ['actor_user_id' => 'integer', 'details' => 'array'];

    public function verificationCase(): BelongsTo
    {
        return $this->belongsTo(VerificationCase::class, 'verification_case_id');
    }
}
