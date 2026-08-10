<?php

namespace App\Models\Retail;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٧ — حركةُ نقدٍ في وردية، **عامّةً**.
 *
 * تعميمٌ لـ`fuel_shift_cash_movements`: الورديّةُ نوعٌ ومعرّف، والمنطقُ
 * واحدٌ لمحطّةٍ وكاشير. **والمفرداتُ في `MerchantShiftCashService`** —
 * مصدرٌ واحدٌ للأسباب واتّجاهاتها، لا قائمتان تنحرفان.
 */
class ShiftCashMovement extends Model
{
    protected $table = 'merchant_shift_cash_movements';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'shift_type', 'shift_id', 'direction',
        'reason', 'amount', 'reference', 'note', 'actor_user_id', 'approved_by_user_id',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'shift_id' => 'integer',
        'amount' => 'decimal:4',
    ];

    public const FUEL = 'fuel';
    public const CASHIER = 'cashier';

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
