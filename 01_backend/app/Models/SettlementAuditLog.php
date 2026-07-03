<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementAuditLog extends Model
{
    protected $table = 'settlement_audit_logs';
    public    $timestamps = false;

    protected $fillable = [
        'settlement_id', 'action', 'actor_user_id', 'actor_role',
        'ip_address', 'user_agent', 'external_reference',
        'balance_before', 'balance_after', 'metadata', 'notes',
        'created_at',
    ];

    protected $casts = [
        'balance_before' => 'decimal:4',
        'balance_after'  => 'decimal:4',
        'metadata'       => 'array',
        'created_at'     => 'datetime',
    ];

    public const ACTION_CREATED    = 'created';
    public const ACTION_SUBMITTED  = 'submitted';
    public const ACTION_APPROVED   = 'approved';
    public const ACTION_REJECTED   = 'rejected';
    public const ACTION_PROCESSING = 'processing';
    public const ACTION_COMPLETED  = 'completed';
    public const ACTION_CANCELLED  = 'cancelled';
    public const ACTION_ATTACHMENT = 'attachment_added';
    public const ACTION_NOTE       = 'note_added';

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'settlement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED    => 'إنشاء',
            self::ACTION_SUBMITTED  => 'إرسال للاعتماد',
            self::ACTION_APPROVED   => 'اعتماد',
            self::ACTION_REJECTED   => 'رفض',
            self::ACTION_PROCESSING => 'بدء التنفيذ',
            self::ACTION_COMPLETED  => 'اكتمال',
            self::ACTION_CANCELLED  => 'إلغاء',
            self::ACTION_ATTACHMENT => 'إضافة إيصال',
            self::ACTION_NOTE       => 'ملاحظة',
            default                 => $this->action,
        };
    }
}
