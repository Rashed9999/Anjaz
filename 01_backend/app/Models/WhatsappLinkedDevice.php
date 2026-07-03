<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-WA-002 — حساب واتساب مرتبط بمستخدم (Trusted Session طويلة الأمد).
 */
class WhatsappLinkedDevice extends Model
{
    protected $table = 'whatsapp_linked_devices';

    protected $fillable = [
        'user_id', 'whatsapp_number', 'device_fingerprint',
        'status', 'risk_score', 'otp_verified_at', 'last_activity_at',
        'revoked_at', 'revoked_by', 'revoke_reason',
    ];

    protected $casts = [
        'risk_score'        => 'integer',
        'otp_verified_at'   => 'datetime',
        'last_activity_at'  => 'datetime',
        'revoked_at'        => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_REVOKED = 'revoked';

    /** عتبة المخاطر التي تستوجب OTP إضافياً (Section 26). */
    public const RISK_THRESHOLD_EXTRA_OTP = 60;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function needsExtraVerification(): bool
    {
        return $this->risk_score >= self::RISK_THRESHOLD_EXTRA_OTP;
    }
}
