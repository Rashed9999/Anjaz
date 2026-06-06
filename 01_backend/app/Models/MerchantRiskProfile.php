<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * AMIAL-MERCHANT-RISK-001 (v2.9)
 *
 * ملف مخاطر التاجر المتجدد.
 */
class MerchantRiskProfile extends Model
{
    protected $table = 'merchant_risk_profiles';

    protected $fillable = [
        'merchant_user_id', 'current_risk_score', 'risk_level',
        'avg_daily_volume', 'peak_daily_volume', 'avg_daily_customers',
        'total_received_lifetime', 'total_transferred_out',
        'aml_flags_count', 'volume_anomaly_count',
        'last_flagged_at', 'last_reviewed_at',
    ];

    protected $casts = [
        'current_risk_score' => 'decimal:2',
        'avg_daily_volume' => 'decimal:4',
        'peak_daily_volume' => 'decimal:4',
        'avg_daily_customers' => 'integer',
        'total_received_lifetime' => 'decimal:4',
        'total_transferred_out' => 'decimal:4',
        'aml_flags_count' => 'integer',
        'volume_anomaly_count' => 'integer',
        'last_flagged_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
    ];

    public function merchant(): BelongsTo { return $this->belongsTo(User::class, 'merchant_user_id'); }

    /** نسبة pass-through (مؤشر غسيل: استلام ثم تحويل فوري) */
    public function passThroughRatio(): float
    {
        $received = (float)$this->total_received_lifetime;
        if ($received <= 0) return 0.0;
        return (float)$this->total_transferred_out / $received;
    }
}

/**
 * حدث مخاطر التاجر (APPEND-ONLY).
 */
class MerchantRiskEvent extends Model
{
    protected $table = 'merchant_risk_events';
    public $timestamps = false;

    protected $fillable = [
        'merchant_user_id', 'event_type', 'risk_contribution',
        'description', 'context', 'transaction_ulid', 'created_at',
    ];

    protected $casts = [
        'risk_contribution' => 'decimal:2',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn() => throw new RuntimeException('Merchant risk events are immutable.'));
        static::deleting(fn() => throw new RuntimeException('Merchant risk events cannot be deleted.'));
    }
}
