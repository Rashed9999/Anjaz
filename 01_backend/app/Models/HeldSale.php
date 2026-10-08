<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-HELD-SALE-001 — تذكرةٌ مفتوحة: سلّةٌ عُلّقت ولم تُدفَع بعد.
 *
 * **وليست بيعة.** لا مالَ فيها ولا قيدَ ولا مخزونَ محجوز — تصير بيعةً
 * حين تُستأنَف ويُضغط الدفع، وحينها وحدَها تدخل `merchant_sales`.
 */
class HeldSale extends Model
{
    protected $table = 'held_sales';

    public const OPEN = 'open';
    public const RESUMED = 'resumed';
    public const VOIDED = 'voided';

    /**
     * **سقفُ التذاكر المفتوحة للمنشأة الواحدة.**
     *
     * لا لأنّ الجدولَ يضيق، **بل لأنّ قائمةً بلا حدٍّ لا تُقرأ**: كاشيرٌ
     * أمام أربعين تذكرةً لا يجد تذكرتَه فيفتح واحدةً جديدة، فتتراكم
     * وتصير الميزةُ عبئاً. والحدُّ يُجبر على الحسم: ادفعها أو ألغِها.
     */
    public const MAX_OPEN = 20;

    protected $fillable = [
        'ticket_ulid', 'merchant_user_id', 'pos_user_id', 'opened_by',
        'opened_by_name', 'shift_id', 'label', 'customer_name', 'customer_phone',
        'items', 'total', 'notes', 'status', 'resumed_at', 'voided_at',
        'void_reason', 'sale_ulid', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'pos_user_id' => 'integer',
        'shift_id' => 'integer',
        'items' => 'array',
        'resumed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];
}
