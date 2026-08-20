<?php

namespace App\Models\Agent;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-TELLER-WS-001 — بلاغُ طوارئ من الشبّاك.
 *
 * **لا يُحذف ولا يُعدَّل.** بلاغُ إكراهٍ يستطيع صاحبُه إلغاءه تحت التهديد
 * يعني أنّ الزرّ لا يحمي أحداً — يضغطه الموظّف فيُجبَر على سحبه فيبقى
 * وحده كما كان. فالإلغاء حالةٌ تُضاف (`false_alarm`) بيد الإدارة، لا
 * محوٌ للصفّ.
 */
class AgentPanicAlert extends Model
{
    protected $table = 'agent_panic_alerts';

    protected $fillable = [
        'alert_number', 'agent_user_id', 'branch_id', 'staff_id', 'shift_id',
        'kind', 'note', 'lat', 'lng', 'geo_state', 'ip', 'user_agent',
        'status', 'acknowledged_by_user_id', 'acknowledged_at', 'resolution_note',
    ];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public const KIND_LABELS = [
        'duress' => '🚨 إكراه — أُجبِر الموظّف على عمليّة',
        'robbery' => '🚨 سطو على الفرع',
        'fraud' => '⚠️ محاولة احتيال',
        'threat' => '⚠️ تهديد',
    ];

    public const STATUS_LABELS = [
        'open' => 'مفتوح — لم يُستلم بعد',
        'acknowledged' => 'استلمته الإدارة',
        'resolved' => 'عولج',
        'false_alarm' => 'إنذارٌ خاطئ',
    ];

    /** «غير متاح» ليس «رفض الموظّف» — ولكلٍّ سببُه. */
    public const GEO_LABELS = [
        'ok' => 'الموقع مُرسَل',
        'denied' => 'الموظّف لم يأذن بالموقع',
        'unavailable' => 'الجهاز لم يُعطِ موقعاً',
        'insecure' => 'الاتّصال غير مشفَّر — المتصفّح يمنع الموقع',
    ];

    public function staff()
    {
        return $this->belongsTo(AgentStaff::class, 'staff_id');
    }

    public function branch()
    {
        return $this->belongsTo(AgentBranch::class, 'branch_id');
    }
}
