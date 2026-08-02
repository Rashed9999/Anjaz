<?php

namespace App\Models\Agent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-AGENT-STAFF-001 — ورديّة صرّاف: درجُه من فتحه إلى إغلاقه.
 *
 * **الدرج وحدة المسؤولية، والخزنة وحدة السيولة.** الصرّاف يأخذ عهدةً من
 * خزنة الفرع أوّل ورديّته، ويعمل عليها، ويردّ ما بقي آخرها. فإن نقص شيءٌ
 * عُرف صاحبه — وهذا ما لا تعطيه خزنةٌ واحدةٌ لعشرين شبّاكاً.
 *
 * **وقدرة الدفع تُقاس بالدرج لا بالخزنة**: خزنةٌ فيها خمسة ملايين لا تُعين
 * صرّافاً في درجه عشرة آلاف — لا يستطيع أن يعدّ ما ليس بيده.
 */
class AgentShift extends Model
{
    protected $table = 'agent_shifts';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    /**
     * حالةُ مراجعة ملفّ التسوية.
     *
     * `balanced` **ليست** `accepted`: الأولى «لا قرار مطلوب»، والثانية «نظر
     * فيها إنسانٌ وقبِل». وخلطُهما يجعل ورديّةً بعجزٍ خمسة آلاف تبدو كأنّ
     * أحداً راجعها وهي لم تُقرأ.
     */
    public const REVIEW_BALANCED = 'balanced';
    public const REVIEW_PENDING = 'pending';
    public const REVIEW_ACCEPTED = 'accepted';
    public const REVIEW_INVESTIGATING = 'investigating';
    public const REVIEW_RESOLVED = 'resolved';

    public const REVIEW_LABELS = [
        self::REVIEW_BALANCED => 'مطابقة — لا قرار مطلوب',
        self::REVIEW_PENDING => 'بانتظار مراجعة الإدارة',
        self::REVIEW_ACCEPTED => 'روجعت وقُبل الفرق',
        self::REVIEW_INVESTIGATING => 'قيد التحقيق',
        self::REVIEW_RESOLVED => 'أُغلقت بعد التحقيق',
    ];

    protected $fillable = [
        'branch_id', 'staff_id', 'opening_float', 'cash_on_hand',
        'deposits_total', 'withdrawals_total', 'deposits_count', 'withdrawals_count',
        'counted_cash', 'variance', 'close_note', 'status',
        'opened_at', 'closed_at', 'closed_by',
        'review_status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'opening_float' => 'decimal:4',
        'cash_on_hand' => 'decimal:4',
        'deposits_total' => 'decimal:4',
        'withdrawals_total' => 'decimal:4',
        'counted_cash' => 'decimal:4',
        'variance' => 'decimal:4',
        'deposits_count' => 'integer',
        'withdrawals_count' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AgentBranch::class, 'branch_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(AgentStaff::class, 'staff_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * الرصيد المتوقَّع في الدرج — يُحسب من العهدة والحركة.
     *
     * ولا يُقرأ من `cash_on_hand` عند الجرد: ذاك ما يقوله النظام، والجرد
     * يقارن قولَ النظام بما عدّه إنسان. ومقارنةُ العمود بنفسه تُنتج فرقاً
     * صفرياً دائماً وتُخفي كلّ عجز.
     */
    public function expectedCash(): string
    {
        return bcsub(
            bcadd((string) $this->opening_float, (string) $this->deposits_total, 4),
            (string) $this->withdrawals_total,
            4,
        );
    }

    public function canPayOut(string $amount): bool
    {
        return bccomp((string) $this->cash_on_hand, $amount, 4) >= 0;
    }
}
