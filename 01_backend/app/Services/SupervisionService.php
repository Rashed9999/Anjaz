<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditDecision;
use App\Models\SafePayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-SUPERVISION-001 — ما يحتاجه المشرف ليراقب فريقاً لا ليقرأ سجلّاً.
 *
 * **الفرق بين هذه الشاشة وسجلّ التدقيق:** السجلّ يجيب عن «ماذا حدث لهذه
 * المعاملة؟» — سؤال تحقيقٍ بعد وقوع شكوى. والإشراف يجيب عن «هل يعمل الفريق
 * كما ينبغي **الآن**؟» — سؤالٌ يُطرح قبل الشكوى لا بعدها.
 *
 * ولذلك المقاييس هنا ليست أحداثاً بل **أنماطاً**:
 *
 * 1. **عمر ما ينتظر** لا عدده. طلبٌ ينتظر ثلاثة أيام مشكلةُ فريق؛ وعشرون
 *    طلباً عمرها ساعة يومُ عملٍ عاديّ. وهذا الدرس نفسه تعلّمناه في الطوابير:
 *    العدد يُطمئن والعمر يفضح.
 *
 * 2. **توزيع القرارات على الموظّفين.** موظّفٌ يقرّر ضعف زملائه إمّا يحمل
 *    عبئاً غير عادل وإمّا لا يتأنّى. والرقمان يبدوان سواءً حتى تُقارن.
 *
 * 3. **سلامة سلسلة السجلّ.** كل ما سبق يُبنى على أن السجلّ لم يُعبث به،
 *    فيُفحص أوّلاً. ورقابةٌ تثق بمصدرها بلا فحص ليست رقابة.
 */
class SupervisionService
{
    /** فوقها يُعدّ الانتظار تأخّراً — يوم عمل كامل. */
    private const SLA_HOURS = 24;

    public function snapshot(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->subDays(7);
        $to ??= now();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'chain' => $this->chainStatus(),
            'waiting' => $this->waiting(),
            'operators' => $this->operatorActivity($from, $to),
            'critical' => $this->criticalDecisions(),
            'pii' => $this->piiAccess(),
        ];
    }

    /**
     * سلامة سلسلة التلبيد — أوّل ما يُفحص.
     *
     * كل رقم في هذه الشاشة مأخوذ من `audit_decisions`. فإن كان قد عُبث به
     * صارت الشاشة تطمئن المشرف إلى ما يجب أن يُقلقه. ويُفحص آخر ألف سجلّ لا
     * الكلّ: الفحص الكامل ثقيل، والعبث الحديث هو المهمّ.
     */
    public function chainStatus(): array
    {
        $rows = AuditDecision::orderByDesc('id')->limit(1000)
            ->get(['id', 'decision_id', 'prev_hash', 'entry_hash'])
            ->reverse()->values();

        if ($rows->isEmpty()) {
            return ['checked' => 0, 'intact' => true, 'broken_at' => null];
        }

        $broken = null;
        for ($i = 1; $i < $rows->count(); $i++) {
            if ($rows[$i]->prev_hash !== $rows[$i - 1]->entry_hash) {
                $broken = $rows[$i]->decision_id;
                break;
            }
        }

        return [
            'checked' => $rows->count(),
            'intact' => $broken === null,
            'broken_at' => $broken,
        ];
    }

    /**
     * ما ينتظر قراراً، وأقدمه منذ متى.
     *
     * @return array<int, array{kind:string, label:string, count:int,
     *                          oldest_hours:int, breaching:int}>
     */
    public function waiting(): array
    {
        $out = [];

        $approvals = ApprovalRequest::where('status', 'pending')
            ->get(['id', 'created_at']);
        $out[] = $this->bucket('approvals', 'طلبات اعتماد', $approvals->pluck('created_at'));

        $disputes = SafePayment::where('status', 'disputed')
            ->get(['id', 'disputed_at', 'updated_at']);
        $out[] = $this->bucket('disputes', 'نزاعات مفتوحة',
            $disputes->map(fn ($d) => $d->disputed_at ?? $d->updated_at));

        return $out;
    }

    /** توزيع القرارات على الموظّفين في الفترة. */
    public function operatorActivity(Carbon $from, Carbon $to): array
    {
        $rows = AuditDecision::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('actor_user_id')
            ->selectRaw('actor_user_id,
                         COUNT(*) as total,
                         SUM(severity = "critical") as critical,
                         MAX(created_at) as last_at')
            ->groupBy('actor_user_id')
            ->orderByDesc('total')
            ->get();

        $names = User::whereIn('id', $rows->pluck('actor_user_id'))
            ->get(['id', 'f_name', 'l_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? ''))]);

        $totals = $rows->pluck('total')->map(fn ($v) => (int) $v)->all();

        return $rows->map(fn ($r) => [
            'user_id' => (int) $r->actor_user_id,
            'name' => $names[$r->actor_user_id] ?? '—',
            'roles' => $this->rolesOf((int) $r->actor_user_id),
            'total' => (int) $r->total,
            'critical' => (int) $r->critical,
            'last_at' => (string) $r->last_at,
            // يُقارَن بوسيط **الآخرين** لا بوسيط الجميع: في فريق من اثنين
            // يقع وسيط الجميع بين الرقمين، فلا يبلغ أحدٌ ضعفه مهما تفاوتا —
            // ومقياسٌ لا يعمل إلا مع فريق كبير لا يعمل مع فريق هذا المشروع.
            'outlier' => $this->isOutlier((int) $r->total, $totals),
        ])->all();
    }

    /** القرارات الحسّاسة — ما يقرؤه المشرف سطراً سطراً. */
    private function criticalDecisions(int $limit = 50): array
    {
        return AuditDecision::where('severity', 'critical')
            ->orderByDesc('id')->limit($limit)
            ->get(['decision_id', 'actor_user_id', 'subject_type', 'subject_id',
                   'action', 'decision_code', 'reason', 'created_at'])
            ->map(fn ($d) => [
                'decision_id' => $d->decision_id,
                'actor' => $this->nameOf($d->actor_user_id),
                'subject' => $d->subject_type . '#' . $d->subject_id,
                'action' => $d->action,
                'code' => $d->decision_code,
                'reason' => mb_substr((string) $d->reason, 0, 120),
                'at' => (string) $d->created_at,
            ])->all();
    }

    /**
     * من فتح ملفّ من — وهو ما لا يظهر في سجلّ القرارات.
     *
     * قراءة ملفّ عميل لا تُغيّر شيئاً فلا تُسجَّل قراراً، لكنها أكثر ما
     * يُساء استعماله: يُفتح ملفّ جارٍ أو قريب بلا سبب. والنمط يُرى في
     * التكرار لا في الحدث الواحد.
     */
    public function piiAccess(int $limit = 50): array
    {
        try {
            return DB::table('pii_access_logs')
                ->selectRaw('actor_user_id, subject_id, COUNT(*) as views, MAX(created_at) as last_at')
                ->where('created_at', '>=', now()->subDays(7))
                ->groupBy('actor_user_id', 'subject_id')
                ->orderByDesc('views')
                ->limit($limit)
                ->get()
                ->map(fn ($r) => [
                    'actor' => $this->nameOf((int) $r->actor_user_id),
                    'subject_id' => (int) $r->subject_id,
                    'views' => (int) $r->views,
                    'last_at' => (string) $r->last_at,
                    // فتحُ ملفّ واحد عشر مرّات في أسبوع ليس عملَ دعم.
                    'suspicious' => (int) $r->views >= 10,
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ── مساعدات ────────────────────────────────────────────────────────

    private function bucket(string $kind, string $label, $timestamps): array
    {
        $ages = collect($timestamps)->filter()
            ->map(fn ($t) => Carbon::parse($t)->diffInHours(now()));

        return [
            'kind' => $kind,
            'label' => $label,
            'count' => $ages->count(),
            'oldest_hours' => (int) ($ages->max() ?? 0),
            'breaching' => $ages->filter(fn ($h) => $h >= self::SLA_HOURS)->count(),
        ];
    }

    private function rolesOf(int $userId): array
    {
        return DB::table('admin_user_roles')
            ->join('roles', 'roles.id', '=', 'admin_user_roles.role_id')
            ->where('admin_user_roles.user_id', $userId)
            ->pluck('roles.label_ar')->all();
    }

    private function nameOf(?int $userId): string
    {
        if (!$userId) return 'النظام';
        $u = User::find($userId);
        return $u ? (trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')) ?: "#$userId") : "#$userId";
    }

    /** أكبر من ضعف وسيط زملائه — والمقارنة تستبعده هو. */
    private function isOutlier(int $value, array $allTotals): bool
    {
        if (count($allTotals) < 2) {
            return false; // موظّفٌ واحد لا يُقارن بأحد
        }

        $others = $allTotals;
        unset($others[array_search($value, $others, true)]);
        $median = $this->median(array_values($others));

        return $median > 0 && $value >= $median * 2;
    }

    private function median(array $values): float
    {
        if ($values === []) return 0;
        sort($values);
        $mid = intdiv(count($values), 2);
        return count($values) % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
