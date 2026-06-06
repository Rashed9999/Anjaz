<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-REPORTS-001 (v2.11)
 *
 * طلب تصدير تقرير.
 */
class ReportExport extends Model
{
    protected $table = 'report_exports';

    protected $fillable = [
        'export_ulid', 'requested_by_user_id', 'requester_type',
        'report_type', 'format', 'parameters', 'status',
        'file_path', 'file_size', 'row_count', 'error_message',
        'expires_at', 'completed_at', 'download_count', 'zone_code',
    ];

    protected $casts = [
        'parameters' => 'array',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'file_size' => 'integer',
        'row_count' => 'integer',
        'download_count' => 'integer',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function scopeReady(Builder $q): Builder { return $q->where('status', 'ready'); }

    public function isReady(): bool
    {
        return $this->status === 'ready'
            && !empty($this->file_path)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
