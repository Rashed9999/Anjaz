<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditDecision;
use Illuminate\Http\Request;

/**
 * AMIAL-AUDIT-001 (v0.8)
 *
 * عرض سجل audit_decisions — صفحة قراءة فقط، لا تعديل/حذف.
 *
 * Filters:
 *   - decision_code  (TX_ZONE_BLOCKED, TX_OK, TX_INSUFFICIENT_BALANCE, ...)
 *   - severity       (info, notice, warning, critical)
 *   - actor_user_id  (لاستعراض قرارات مستخدم معين)
 *   - action         (SEND_MONEY_COMPLETED, ZONE_POLICY_DENIED, ...)
 *   - date range
 */
class AuditDecisionsController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditDecision::query()->orderByDesc('id'); // الترتيب بالـ id أسرع من created_at

        if ($code = $request->query('decision_code')) {
            $query->where('decision_code', $code);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }
        if ($actor = $request->query('actor_user_id')) {
            $query->where('actor_user_id', (int)$actor);
        }
        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
        }
        // AMIAL-SAFEPAY-AUDIT-001: «أرني كل ما جرى على هذا الشيء بعينه».
        // بدونهما كان السجلّ يُقرأ بالفاعل أو بالنوع فقط — فمن أراد تتبّع
        // نزاع واحد من فتحه إلى حسمه لم يكن له سبيل.
        if ($subjectType = $request->query('subject_type')) {
            $query->where('subject_type', $subjectType);
        }
        if ($subjectId = $request->query('subject_id')) {
            $query->where('subject_id', (string) $subjectId);
        }
        if ($from = $request->query('date_from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        $decisions = $query->paginate(50)->withQueryString();

        // إحصاءات سريعة (آخر 24h)
        $stats24h = AuditDecision::where('created_at', '>=', now()->subHours(24))
            ->selectRaw('severity, COUNT(*) as cnt')
            ->groupBy('severity')
            ->pluck('cnt', 'severity')
            ->toArray();

        return view('admin-views.amial.audit.index', [
            'decisions' => $decisions,
            'stats_24h' => $stats24h,
            'filters' => $request->only([
                'decision_code', 'severity', 'actor_user_id', 'action',
                'subject_type', 'subject_id', 'date_from', 'date_to',
            ]),
        ]);
    }
}
