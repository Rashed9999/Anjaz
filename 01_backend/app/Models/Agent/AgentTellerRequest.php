<?php

namespace App\Models\Agent;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-TELLER-WS-001 — طلبُ موافقةٍ من الصرّاف إلى مديره.
 *
 * **الرفض ليس علاجاً.** صرّافٌ أمامه عميلٌ يريد سحب مليونٍ وحدُّه نصف
 * مليون: إمّا أن يقف العميل، وإمّا أن يقسّمها الصرّاف عمليّتين — وهو
 * بالضبط ما تبحث عنه قواعد غسل الأموال.
 *
 * فالطريق الثالث أن يطلب موافقةً باسمه، فيقرّر إنسانٌ ويُسجَّل قراره.
 */
class AgentTellerRequest extends Model
{
    protected $table = 'agent_teller_requests';

    protected $fillable = [
        'request_number', 'agent_user_id', 'branch_id', 'staff_id', 'shift_id',
        'kind', 'operation', 'amount', 'customer_user_id', 'reason', 'limit_snapshot',
        'status', 'decided_by_staff_id', 'decision_note', 'decided_at', 'expires_at', 'used_at',
    ];

    protected $casts = [
        'limit_snapshot' => 'array',
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_USED = 'used';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'بانتظار قرار المدير',
        self::STATUS_APPROVED => 'وُوفق عليه — نفّذ العمليّة',
        self::STATUS_REJECTED => 'رُفض',
        self::STATUS_EXPIRED => 'انقضت مهلته',
        self::STATUS_USED => 'استُعمل ونُفّذت العمليّة',
    ];

    public const KIND_LABELS = [
        'over_limit' => 'تجاوز الحدّ',
        'restricted_op' => 'عمليّة خارج الصلاحية',
    ];

    /**
     * صالحٌ للاستعمال الآن؟
     *
     * **ومرّةً واحدة.** موافقةٌ تُستعمل مرّتين تعني أنّ مديراً وافق على
     * مليونٍ فمرّ مليونان.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->used_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function staff()
    {
        return $this->belongsTo(AgentStaff::class, 'staff_id');
    }

    public function branch()
    {
        return $this->belongsTo(AgentBranch::class, 'branch_id');
    }
}
