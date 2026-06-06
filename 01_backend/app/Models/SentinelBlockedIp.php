<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-SENTINEL-001 — عنوان IP محظور (تلقائي أو يدوي).
 */
class SentinelBlockedIp extends Model
{
    protected $table = 'sentinel_blocked_ips';

    protected $fillable = [
        'ip_address',
        'reason',
        'hits',
        'blocked_until',
        'created_by',
    ];

    protected $casts = [
        'hits' => 'integer',
        'blocked_until' => 'datetime',
    ];

    /** الحظر ما زال فعّالاً؟ (دائم أو لم تنتهِ مدّته). */
    public function isActive(): bool
    {
        return $this->blocked_until === null || $this->blocked_until->isFuture();
    }

    /** Scope: المحظورون الفعّالون فقط. */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('blocked_until')->orWhere('blocked_until', '>', now());
        });
    }
}
