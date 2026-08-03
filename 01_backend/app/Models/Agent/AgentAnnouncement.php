<?php

namespace App\Models\Agent;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-TELLER-WS-001 — تعميمٌ يظهر في الشبّاك.
 *
 * ومستواه يحدّد من يقرأه: `agent_user_id = null` تعميمٌ من أميال إلى كلّ
 * الشبابيك، وغيرُه تعميمُ شركةٍ إلى موظّفيها وحدهم. ومن غير هذا الفصل
 * تُقرأ رسالةُ شركةٍ لصرّافيها في شركةٍ منافسة.
 */
class AgentAnnouncement extends Model
{
    protected $table = 'agent_announcements';

    protected $fillable = [
        'agent_user_id', 'branch_id', 'audience', 'severity', 'title', 'body',
        'is_active', 'starts_at', 'ends_at', 'created_by_user_id', 'created_by_staff_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public const SEVERITY_ICONS = [
        'info' => 'ℹ️',
        'warning' => '⚠️',
        'critical' => '🚨',
    ];
}
