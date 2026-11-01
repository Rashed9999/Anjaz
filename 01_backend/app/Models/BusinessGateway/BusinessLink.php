<?php

namespace App\Models\BusinessGateway;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — ربط تاجر أميال باي بمنشأته في «أميال أعمال».
 *
 * رابطٌ واحدٌ لكل تاجر. المعرّف الخارجي `link_ulid` هو ما تحمله أميال أعمال؛
 * رقم المستخدم الداخلي لا يغادر أميال باي.
 */
class BusinessLink extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    protected $table = 'business_links';

    protected $fillable = [
        'link_ulid', 'merchant_user_id', 'status', 'status_reason',
        'created_by_admin_id', 'linked_at', 'status_changed_at',
        'credit_handed_over_at', 'credit_handover_reference', 'credit_handover_fingerprint',
        'refund_auto_limit', 'refund_daily_limit', 'refund_mandate_set_at',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'created_by_admin_id' => 'integer',
        'linked_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'credit_handed_over_at' => 'datetime',
        'refund_mandate_set_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    /** صارت أميال أعمال مصدر الحقيقة الوحيد لديون هذا التاجر. */
    public function creditHandedOver(): bool
    {
        return $this->credit_handed_over_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
