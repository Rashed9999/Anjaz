<?php

namespace App\Models\Aml;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-AML-INVESTIGATION-001 — حدثٌ في الخطّ الزمنيّ للقضية.
 *
 * **يُكتب مرّةً ولا يُعدَّل.** `$timestamps = false` مع `created_at` وحده ليس
 * اختصاراً بل منعٌ مقصود: تحقيقٌ يمكن تحرير تاريخه لا يصلح دليلاً، ومن
 * يستطيع تغيير ما قيل بالأمس يستطيع أن يجعل كلّ قرارٍ يبدو صائباً.
 *
 * وتصحيحُ حدثٍ خاطئ يكون بحدثٍ جديد يقول إنّ السابق خطأ — كما تُصحَّح
 * القيود المحاسبية بقيدٍ عكسيّ لا بمحو الأوّل.
 */
class AmlInvestigationEvent extends Model
{
    protected $table = 'aml_investigation_events';

    public $timestamps = false;

    public const TYPE_OPENED = 'opened';
    public const TYPE_ASSIGNED = 'assigned';
    public const TYPE_EVIDENCE = 'evidence_added';
    public const TYPE_NOTE = 'note_added';
    public const TYPE_ACTION = 'action_taken';
    public const TYPE_ESCALATED = 'escalated';
    public const TYPE_DECISION = 'decision_made';
    public const TYPE_CLOSED = 'closed';
    public const TYPE_REOPENED = 'reopened';

    public const TYPE_LABELS = [
        self::TYPE_OPENED => 'فُتحت القضية',
        self::TYPE_ASSIGNED => 'أُسندت',
        self::TYPE_EVIDENCE => 'أُضيف دليل',
        self::TYPE_NOTE => 'ملاحظة',
        self::TYPE_ACTION => 'إجراء امتثال',
        self::TYPE_ESCALATED => 'صُعِّدت',
        self::TYPE_DECISION => 'اتُّخذ القرار',
        self::TYPE_CLOSED => 'أُغلقت',
        self::TYPE_REOPENED => 'أُعيد فتحها',
    ];

    protected $fillable = [
        'investigation_id', 'event_type', 'actor_user_id', 'note', 'metadata', 'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
