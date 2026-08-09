<?php

namespace App\Models\Fuel;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركةُ نقدٍ داخل الوردية.
 *
 * **وبلا هذا الجدول كان كلُّ ريالٍ يخرج للمصروفات يظهر عجزاً** في وجه
 * الكاشير: المتوقَّع يُحسب من الافتتاح والمبيعات وحدَها، والدرجُ فيه أقلّ.
 */
class FuelShiftCashMovement extends Model
{
    protected $table = 'fuel_shift_cash_movements';

    protected $fillable = [
        'shift_id', 'direction', 'reason', 'amount',
        'reference', 'note', 'actor_user_id', 'approved_by_user_id',
    ];

    protected $casts = [
        'shift_id' => 'integer',
        'amount' => 'decimal:4',
    ];

    public const DIRECTIONS = ['in', 'out'];
    public const REASONS = ['expense', 'cash_in', 'cash_drop', 'change_fund', 'refund'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
