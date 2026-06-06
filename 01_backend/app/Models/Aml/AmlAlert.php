<?php

namespace App\Models\Aml;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmlAlert extends Model
{
    protected $table = 'aml_alerts';

    protected $fillable = [
        'alert_ulid', 'alert_code', 'severity',
        'subject_type', 'subject_id',
        'title_ar', 'message_ar', 'context',
        'status',
        'assigned_to_admin_id', 'resolved_by_admin_id', 'resolved_at',
        'resolution_note',
    ];

    protected $casts = [
        'context' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to_admin_id'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by_admin_id'); }

    public function scopeOpen(Builder $q): Builder { return $q->where('status', 'open'); }
    public function scopeCritical(Builder $q): Builder { return $q->where('severity', 'critical'); }
}
