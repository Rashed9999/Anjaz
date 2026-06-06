<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-SENTINEL-001 — حدث رصده الحارس المخفي.
 */
class SentinelEvent extends Model
{
    protected $table = 'sentinel_events';

    public $timestamps = false; // created_at فقط (DB default)

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'method',
        'path',
        'threat_score',
        'severity',
        'signatures',
        'action',
        'request_id',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'threat_score' => 'integer',
        'signatures' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /** أحداث حرجة فقط. */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /** تجميع حسب IP خلال فترة (لكشف الهجمات المتكررة). */
    public function scopeFromIp($query, string $ip)
    {
        return $query->where('ip_address', $ip);
    }
}
