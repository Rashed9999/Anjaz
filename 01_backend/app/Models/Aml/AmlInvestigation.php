<?php

namespace App\Models\Aml;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-AML-INVESTIGATION-001 — قضيّة تحقيق.
 */
class AmlInvestigation extends Model
{
    protected $table = 'aml_investigations';

    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_PENDING_DECISION = 'pending_decision';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_REOPENED = 'reopened';

    public const DECISION_NO_ACTION = 'no_action';
    public const DECISION_WARNING = 'warning_issued';
    public const DECISION_FROZEN = 'account_frozen';
    public const DECISION_BLACKLISTED = 'blacklisted';
    public const DECISION_STR_FILED = 'str_filed';

    public const DECISIONS = [
        self::DECISION_NO_ACTION => 'لا إجراء — نشاط مشروع',
        self::DECISION_WARNING => 'تنبيه العميل',
        self::DECISION_FROZEN => 'تجميد الحساب',
        self::DECISION_BLACKLISTED => 'إدراج في القائمة السوداء',
        self::DECISION_STR_FILED => 'رُفع بلاغ اشتباه',
    ];

    protected $fillable = [
        'case_number', 'subject_user_id', 'opened_from', 'source_ulid',
        'priority', 'status', 'assigned_officer_id', 'assigned_at',
        'risk_score_at_open', 'opened_by', 'opened_at',
        'decision', 'closed_by', 'closed_at', 'closure_reason',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'risk_score_at_open' => 'decimal:2',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** الخطّ الزمنيّ — يُضاف إليه ولا يُعدَّل. */
    public function events(): HasMany
    {
        return $this->hasMany(AmlInvestigationEvent::class, 'investigation_id')
            ->orderBy('created_at');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(AmlRegulatoryReport::class, 'investigation_id');
    }

    public function isOpen(): bool
    {
        return !in_array($this->status, [self::STATUS_CLOSED], true);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', '!=', self::STATUS_CLOSED);
    }

    /**
     * كم بقيت مفتوحة — بالساعات.
     *
     * متوسّط زمن إغلاق التحقيق مؤشّرٌ تنفيذيّ في الوثيقة، وهو أيضاً ما يسأل
     * عنه المنظّم: قضيّةٌ مفتوحة منذ ستّة أشهر ليست تحقيقاً بل إهمالاً.
     */
    public function ageHours(): int
    {
        $end = $this->closed_at ?? now();

        return (int) $this->opened_at?->diffInHours($end);
    }
}
