<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلب صرف مستحقات مالك المنشأة.
 *
 * لا يمثل هذا الصف بيعاً ولا سحبَ عميلٍ عند وكيل ولا سيولةَ تشغيل الوكيل.
 * الرصيد يُحجز عند الطلب، وتبقى خطوة الاعتماد منفصلة عن تسليم النقد.
 */
class MerchantPayoutRequest extends Model
{
    protected $fillable = [
        'payout_ulid', 'merchant_user_id', 'amount', 'currency', 'status',
        'request_note', 'collection_instructions', 'handover_ulid',
        'approved_by_id', 'approved_at', 'paid_by_id', 'paid_at',
        'rejected_by_id', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }
}
