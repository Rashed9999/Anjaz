<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1)
 *
 * sجل أحداث الـ safe payment ⇒ append-only.
 */
class SafePaymentEvent extends Model
{
    protected $table = 'safe_payment_events';
    public $timestamps = false;

    protected $fillable = [
        'safe_payment_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_type',
        'actor_user_id',
        'note',
        'attachments',
        'context',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'safe_payment_id' => 'integer',
        'actor_user_id' => 'integer',
        'attachments' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function safePayment(): BelongsTo
    {
        return $this->belongsTo(SafePayment::class, 'safe_payment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Enforce append-only: التعديل/الحذف ممنوع.
     */
    public function update(array $attributes = [], array $options = [])
    {
        if ($this->exists) {
            throw new \RuntimeException('SafePaymentEvent is append-only. Update not allowed.');
        }
        return parent::update($attributes, $options);
    }

    public function delete()
    {
        throw new \RuntimeException('SafePaymentEvent is append-only. Delete not allowed.');
    }

    /**
     * تسمية عربية للـ event type (للـ UI).
     */
    public function getEventLabelArAttribute(): string
    {
        return match ($this->event_type) {
            'created' => 'إنشاء الطلب',
            'seller_accepted' => 'قبول البائع',
            'seller_rejected' => 'رفض البائع',
            'in_delivery_marked' => 'بدء التسليم',
            'delivered_marked' => 'تأكيد التسليم',
            'buyer_confirmed' => 'تأكيد المشتري',
            'released_to_seller' => 'إفراج المبلغ للبائع',
            'buyer_disputed' => 'فتح نزاع',
            'buyer_cancelled' => 'إلغاء من المشتري',
            'admin_resolved_release' => 'الإدارة: إفراج للبائع',
            'admin_resolved_refund' => 'الإدارة: استرداد للمشتري',
            'admin_resolved_partial' => 'الإدارة: استرداد جزئي',
            'expired' => 'انتهت الصلاحية',
            'attachment_added' => 'إضافة مرفق',
            'note_added' => 'إضافة ملاحظة',
            default => $this->event_type,
        };
    }
}
