<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRITICAL-001-SUBS — سجل تغييرات الاشتراك (immutable audit log).
 * ملاحظة: لا يُحدّث ولا يُحذف. كل تغيير = صفّ جديد.
 */
class SubscriptionChange extends Model
{
    protected $table = 'subscription_changes';
    public $timestamps = true;

    protected $fillable = [
        'merchant_user_id', 'actor_user_id', 'actor_role',
        'action', 'old_plan', 'old_expires_at',
        'new_plan', 'new_expires_at',
        'price_paid_sar', 'payment_method', 'payment_reference',
        'notes', 'metadata',
    ];

    protected $casts = [
        'old_expires_at' => 'datetime',
        'new_expires_at' => 'datetime',
        'price_paid_sar' => 'decimal:2',
        'metadata' => 'array',
    ];

    // ============ Action Types ============
    public const ACTION_UPGRADE     = 'upgrade';      // FREE→STARTER, BUSINESS→PRO, ...
    public const ACTION_DOWNGRADE   = 'downgrade';    // PRO→BUSINESS, ...
    public const ACTION_RENEW       = 'renew';        // نفس الخطّة + مدّة جديدة
    public const ACTION_EXTEND      = 'extend';       // إضافة أيام (دون تغيير خطّة)
    public const ACTION_CHANGE_PLAN = 'change_plan';  // تغيير عام (يدوي من أدمن)
    public const ACTION_CANCEL      = 'cancel';       // إلغاء قبل الانتهاء
    public const ACTION_EXPIRE_AUTO = 'expire_auto';  // انتهاء تلقائي (cron job)

    public const ALL_ACTIONS = [
        self::ACTION_UPGRADE, self::ACTION_DOWNGRADE, self::ACTION_RENEW,
        self::ACTION_EXTEND, self::ACTION_CHANGE_PLAN, self::ACTION_CANCEL,
        self::ACTION_EXPIRE_AUTO,
    ];

    public const ACTOR_ADMIN  = 'admin';
    public const ACTOR_SYSTEM = 'system';

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** label للعرض في UI. */
    public function actionLabel(): string
    {
        return match($this->action) {
            self::ACTION_UPGRADE => 'ترقية',
            self::ACTION_DOWNGRADE => 'تخفيض',
            self::ACTION_RENEW => 'تجديد',
            self::ACTION_EXTEND => 'تمديد',
            self::ACTION_CHANGE_PLAN => 'تغيير خطّة',
            self::ACTION_CANCEL => 'إلغاء',
            self::ACTION_EXPIRE_AUTO => 'انتهاء تلقائي',
            default => $this->action,
        };
    }
}
