<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-INSIDER-001 — تنبيه أمني عن سلوك موظف شاذ.
 */
class SecurityAlert extends Model
{
    protected $fillable = [
        'admin_id', 'alert_type', 'severity', 'details',
        'status', 'acknowledged_by', 'acknowledged_at', 'alert_date',
    ];

    protected $casts = [
        'admin_id' => 'integer',
        'details' => 'array',
        'acknowledged_by' => 'integer',
        'acknowledged_at' => 'datetime',
        'alert_date' => 'date',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
