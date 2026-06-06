<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-PIN-SECURITY-001
 *
 * يسجل أحداث أمن الحساب — يُستخدم لاحقاً في شاشة "أمان الحساب" (قسم 20).
 */
class AccountSecurityEvent extends Model
{
    protected $table = 'account_security_events';

    public $timestamps = false; // created_at فقط (DB default)

    protected $fillable = [
        'user_id',
        'event_type',
        'ip_address',
        'user_agent',
        'device_id',
        'note',
        'metadata',
        'severity',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /** Scope: أحداث PIN فقط — يحاكي جدول account_pin_security_events المذكور في الوثيقة */
    public function scopePinEvents($query)
    {
        return $query->where('event_type', 'like', 'PIN_%');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
