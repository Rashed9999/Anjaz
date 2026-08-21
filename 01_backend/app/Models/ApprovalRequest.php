<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-INSIDER-001 — طلب موافقة (Maker-Checker).
 *
 * القاعدة المصرفية: الإجراءات التي "تعيد أو تمنح" وصولاً/مالاً لا يُنفّذها
 * موظف واحد — المُقدِّم (Maker) يطلب، ومشرف مختلف (Checker) يعتمد.
 */
class ApprovalRequest extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'expired', 'failed'];

    /** الإجراءات الخاضعة للموافقة الثنائية */
    public const ACTIONS = [
        'unfreeze_wallet',
        'reset_pin',
        'close_customer',
        'mark_customer_deceased',
    ];

    protected $fillable = [
        'request_number', 'action_type', 'subject_user_id',
        'maker_admin_id', 'checker_admin_id', 'reason', 'checker_note',
        'payload', 'status', 'expires_at', 'decided_at', 'executed_at',
    ];

    protected $casts = [
        'subject_user_id' => 'integer',
        'maker_admin_id' => 'integer',
        'checker_admin_id' => 'integer',
        'payload' => 'array',
        'expires_at' => 'datetime',
        'decided_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_admin_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_admin_id');
    }

    /** رقم طلب تسلسلي آمن ضد السباق (داخل معاملة). */
    public static function nextRequestNumber(): string
    {
        $max = (int) DB::table('approval_requests')
            ->selectRaw("MAX(CAST(SUBSTRING(request_number, 5) AS UNSIGNED)) AS m")
            ->lockForUpdate()
            ->value('m');

        return 'APR-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }
}
