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

    /**
     * أسُجِّل خروجُ مالٍ لهذا التاجر أصلاً؟ — **وبلا هذا يُقرأ المجهولُ صفراً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `total_transferred_out` كان له كاتبٌ واحدٌ بلا مُنادٍ
     * (`recordTransferOut`)، **فبقي صفراً على كلّ تاجرٍ منذ بُني الجدول**.
     * والنسبةُ تُقسَم عليه، فكانت `0.0` أبداً — وتُعرَض في لوحة مخاطر
     * التجّار «‎0.0%‎».
     *
     * **ومن يقرأ «صفر بالمئة» يفهم «فُحص فلم يوجد»**، والحقيقةُ «لم
     * يُعَدّ شيء». وهو الفرقُ الذي تقوم عليه القاعدةُ السابعة: الصفرُ
     * يُقرأ «فحصنا فلم نجد»، والغيابُ يُقال بسببه.
     *
     * والتوصيلُ قائمٌ الآن على ثلاثة مخارج، **لكنّ ملفَّات التجّار
     * السابقةَ لا يُعاد بناؤها بأثرٍ رجعيّ** — فصفرُها ما زال مجهولاً
     * لا نتيجة، ويبقى كذلك حتّى يخرج منها أوّلُ ريال.
     * ══════════════════════════════════════════════════════════════════
     */
    public function hasOutboundRecord(): bool
    {
        return (float)$this->total_transferred_out > 0;
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
