<?php

namespace App\Models\Aml;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-AML-REGREPORT-001 — بلاغ تنظيميّ (اشتباه أو عملة).
 */
class AmlRegulatoryReport extends Model
{
    protected $table = 'aml_regulatory_reports';

    public const TYPE_STR = 'STR';   // تقديريّ — يحتاج تحقيقاً خلفه
    public const TYPE_CTR = 'CTR';   // غير تقديريّ — كلّ عملية فوق الحدّ

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending_submission';
    public const STATUS_SUBMITTED = 'submitted';

    public const TYPE_LABELS = [
        self::TYPE_STR => 'بلاغ اشتباه (STR)',
        self::TYPE_CTR => 'بلاغ عملة (CTR)',
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'مسودّة',
        self::STATUS_PENDING => 'بانتظار الإرسال',
        self::STATUS_SUBMITTED => 'أُرسل',
    ];

    protected $fillable = [
        'report_number', 'report_type', 'status',
        'subject_user_id', 'investigation_id', 'transaction_ulid',
        'amount', 'currency', 'period_start', 'period_end', 'content',
        'generated_by', 'generated_at',
        'submitted_by', 'submitted_at', 'external_reference', 'submission_note',
        'supersedes_report_id',
    ];

    protected $casts = [
        'content' => 'array',
        'amount' => 'decimal:4',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'generated_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(AmlInvestigation::class, 'investigation_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** المُرسَل لا يُعدَّل — لدى المنظّم نسخةٌ منه. */
    public function isLocked(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }
}
