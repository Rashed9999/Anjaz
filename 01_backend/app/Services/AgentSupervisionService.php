<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentShift;
use App\Models\EMoney;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AGENT-SUPERVISION-001 — ما تراه الإدارة عن شبكة شركات الصرافة.
 *
 * بوّابة الوكيل تُري الوكيلَ فرعَه. وهذه الخدمة تُري الإدارةَ الشبكةَ كلّها،
 * وهما سؤالان مختلفان: الوكيل يسأل «هل أستطيع الدفع الآن؟»، والإدارة تسأل
 * «أيّ فرعٍ سيعجز غداً؟».
 *
 * **قاعدةٌ حاكمة: النقد الورقيّ لا يُجمَع مع الرصيد الإلكترونيّ.**
 * الرصيد الإلكترونيّ التزامٌ على المنصّة تجاه الوكيل — مالٌ نَدين به.
 * والنقد الورقيّ في درج الفرع مالُ شركة الصرافة نفسها، لا يمرّ بدفترنا ولا
 * نضمنه. وجمعُهما في رقمٍ واحد يُنتج «إجماليّ سيولة» لا يقابله شيء في
 * الميزانية، ويقود إلى قرارٍ خاطئ: مديرٌ يرى الرقم كبيراً فيسمح بمزيدٍ من
 * الشحن، والمنصّة في الحقيقة مكشوفة.
 *
 * ولذلك تُعاد الأرقام هنا **مفصولةً دائماً**، ولا توجد دالّةٌ تجمعهما.
 */
class AgentSupervisionService
{
    /** ملخّص الشبكة — الأرقام التي تُقرأ قبل أيّ شيء آخر. */
    public function network(): array
    {
        $branches = AgentBranch::query()
            ->with('till')
            ->get();

        $parentIds = User::where('type', AGENT_TYPE)
            ->whereNotIn('id', $this->branchAccountIds())
            ->pluck('id');

        $cash = '0';
        $low = 0;
        $overloaded = 0;
        $stale = 0;
        $today = now()->toDateString();

        // نقدُ الأدراج المفتوحة جزءٌ من نقد الفرع.
        //
        // بعد فصل الدرج عن الخزنة صار في الفرع موضعان للورق. وقراءةُ الخزنة
        // وحدها تُنقص الإجماليّ بكلّ ما في أيدي الصرّافين — فيبدو فرعٌ نشِطٌ
        // فارغاً كلّما زاد عملُه، وهو أسوأ اتّجاهٍ ممكنٍ للخطأ.
        $drawers = AgentShift::where('status', AgentShift::STATUS_OPEN)
            ->selectRaw('branch_id, SUM(cash_on_hand) as total, COUNT(*) as n')
            ->groupBy('branch_id')->get()->keyBy('branch_id');

        $openShifts = 0;

        foreach ($branches as $b) {
            $drawer = $drawers[$b->id] ?? null;
            $openShifts += (int) ($drawer->n ?? 0);
            $cash = bcadd($cash, (string) ($drawer->total ?? '0'), 4);

            $till = $b->till;
            if (!$till) {
                // فرعٌ بلا خزنة: لا يُعدّ صفراً — يُعدّ ناقص التهيئة.
                $stale++;
                continue;
            }
            $cash = bcadd($cash, (string) $till->cash_on_hand, 4);
            if ($till->isLow()) {
                $low++;
            }
            if ($till->isOverloaded()) {
                $overloaded++;
            }
            if ($till->last_counted_at === null
                || $till->last_counted_at->toDateString() !== $today) {
                $stale++;
            }
        }

        $emoneyParents = (string) (EMoney::whereIn('user_id', $parentIds)->sum('current_balance') ?: '0');
        $emoneyBranches = (string) (EMoney::whereIn('user_id', $branches->pluck('branch_user_id'))
            ->sum('current_balance') ?: '0');

        // وكيلٌ بلا فرعٍ نشِط لا يخدم أحداً — وهذا أهمّ من رصيده.
        $withBranches = $branches->where('is_active', true)->pluck('agent_user_id')->unique();

        return [
            'agents' => $parentIds->count(),
            'agents_without_branch' => $parentIds->reject(fn ($id) => $withBranches->contains($id))->count(),
            'branches' => $branches->count(),
            'branches_active' => $branches->where('is_active', true)->count(),

            // مفصولان عمداً — انظر شرح أعلى الملفّ.
            'emoney_parents' => $emoneyParents,
            'emoney_branches' => $emoneyBranches,
            'cash_on_hand' => $cash,

            'flags' => [
                'low_cash' => $low,
                'overloaded' => $overloaded,
                'not_counted_today' => $stale,
            ],
            // **مقابلُ الرصيد الإلكترونيّ المُصدَر — من الدفتر لا من محفظة.**
            //
            // شحنُ الوكيل ليس تحويلاً من محفظة الإدارة: هو إصدارُ رصيدٍ
            // إلكترونيّ مقابل نقدٍ دخل خزينة المنصّة. فالقيد
            // «احتياطي النقد مدين ← محفظة الوكيل دائن»، والرقم الذي يجب أن
            // يراه من يشحن هو **الاحتياطي**.
            //
            // (وقد عرضتُ أوّلاً محفظة الإدارة — وكانت `NULL` في قاعدة
            // البيانات، فتظهر صفراً وتُوحي بأنّ المنصّة مفلسة وهي ليست
            // المصدر أصلاً.)
            'cash_reserve' => $this->cashReserveBalance(),

            // شبابيك مفتوحة الآن — أدراجٌ في أيدي صرّافين لم تُسلَّم بعد.
            'open_shifts' => $openShifts,
            'movements_today' => AgentCashMovement::whereBetween(
                'created_at',
                [$today . ' 00:00:00', $today . ' 23:59:59']
            )->count(),
        ];
    }

    /**
     * إثراء صفوف قائمة الوكلاء بما يخصّ الوكيل وحده.
     *
     * تُستدعى بمعرّفات الصفحة المعروضة فقط — لا بالشبكة كلّها — كي لا تتحوّل
     * القائمة إلى استعلامٍ يزحف مع كلّ وكيلٍ جديد.
     *
     * @param  array<int>  $userIds
     * @return array<int, array>
     */
    public function summariesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $today = now()->toDateString();

        $branches = AgentBranch::whereIn('agent_user_id', $userIds)
            ->with('till')->get()->groupBy('agent_user_id');

        $drawers = AgentShift::where('status', AgentShift::STATUS_OPEN)
            ->selectRaw('branch_id, SUM(cash_on_hand) as total')
            ->groupBy('branch_id')->pluck('total', 'branch_id');

        // حسابات الفروع تظهر في قائمة الوكلاء لأنّها **وكلاءُ أبناء** فعلاً.
        // وإخفاؤها يجعل الإدارة تعدّ الوكلاء خطأً؛ فتُوسَم بأمّها بدل ذلك.
        $parents = [];
        if (Schema::hasTable('agent_profiles')) {
            $rows = DB::table('agent_profiles')
                ->whereIn('user_id', $userIds)
                ->whereNotNull('parent_agent_id')
                ->pluck('parent_agent_id', 'user_id');

            $names = User::whereIn('id', $rows->values())->get()
                ->mapWithKeys(fn (User $u) => [
                    $u->id => trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')) ?: ('#' . $u->id),
                ]);

            foreach ($rows as $userId => $parentId) {
                $parents[(int) $userId] = [
                    'id' => (int) $parentId,
                    'name' => $names[$parentId] ?? ('#' . $parentId),
                ];
            }
        }

        $out = [];

        foreach ($userIds as $id) {
            $mine = $branches[$id] ?? collect();
            $cash = '0';
            $flags = [];

            foreach ($mine as $b) {
                // النقد في الأدراج المفتوحة نقدُ الفرع أيضاً — انظر `network()`.
                $cash = bcadd($cash, (string) ($drawers[$b->id] ?? '0'), 4);

                $till = $b->till;
                if (!$till) {
                    $flags['no_till'] = true;
                    continue;
                }
                $cash = bcadd($cash, (string) $till->cash_on_hand, 4);
                if ($till->isLow()) {
                    $flags['low_cash'] = true;
                }
                if ($till->isOverloaded()) {
                    $flags['overloaded'] = true;
                }
                if ($till->last_counted_at === null
                    || $till->last_counted_at->toDateString() !== $today) {
                    $flags['not_counted'] = true;
                }
            }

            $out[$id] = [
                'is_branch_account' => isset($parents[$id]),
                'parent' => $parents[$id] ?? null,
                'branches' => $mine->count(),
                'branches_active' => $mine->where('is_active', true)->count(),
                'cash_on_hand' => $cash,
                // فرعٌ ليس له فروع ليس نقصاً؛ الوكيل الأمّ بلا فروعٍ نقص.
                'no_branches' => !isset($parents[$id]) && $mine->isEmpty(),
                'flags' => array_keys($flags),
            ];
        }

        return $out;
    }

    /**
     * كلّ الفروع بحالة خزائنها — الشاشة التي يُدار منها التوريد.
     *
     * @param  array{agent_id?:int|null, flag?:string|null, search?:string|null}  $filters
     */
    public function branches(array $filters = []): array
    {
        $q = AgentBranch::query()->with(['till', 'agent']);

        if (!empty($filters['agent_id'])) {
            $q->where('agent_user_id', (int) $filters['agent_id']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $rows = $q->orderBy('agent_user_id')->orderBy('code')->limit(300)->get();

        $emoney = EMoney::whereIn('user_id', $rows->pluck('branch_user_id'))
            ->pluck('current_balance', 'user_id');

        $today = now()->toDateString();
        $flag = (string) ($filters['flag'] ?? '');

        $drawers = AgentShift::where('status', AgentShift::STATUS_OPEN)
            ->whereIn('branch_id', $rows->pluck('id'))
            ->selectRaw('branch_id, SUM(cash_on_hand) as total, COUNT(*) as n')
            ->groupBy('branch_id')->get()->keyBy('branch_id');

        $out = [];

        foreach ($rows as $b) {
            $till = $b->till;
            $counted = $till?->last_counted_at;
            $notCounted = $counted === null || $counted->toDateString() !== $today;

            $item = [
                'id' => (int) $b->id,
                'name' => (string) $b->name,
                'code' => (string) $b->code,
                'city' => $b->city,
                'phone' => $b->phone,
                'is_active' => (bool) $b->is_active,
                'agent' => [
                    'id' => (int) $b->agent_user_id,
                    'name' => $b->agent
                        ? (trim(($b->agent->f_name ?? '') . ' ' . ($b->agent->l_name ?? '')) ?: ('#' . $b->agent_user_id))
                        : ('#' . $b->agent_user_id),
                ],
                // مالُ المنصّة الذي بيد الفرع…
                'emoney' => (string) ($emoney[$b->branch_user_id] ?? '0'),
                // …ومالُ شركة الصرافة الذي في درجه. لا يُجمعان.
                'cash_on_hand' => $till ? (string) $till->cash_on_hand : null,
                // خزنةٌ ودرجٌ يُعرضان منفصلَين: خزنةٌ فارغةٌ وفرعٌ يعمل ليست
                // إنذاراً — النقد في أيدي الصرّافين.
                'drawers_cash' => (string) ($drawers[$b->id]->total ?? '0'),
                'open_shifts' => (int) ($drawers[$b->id]->n ?? 0),
                'min_cash_alert' => $till ? (string) $till->min_cash_alert : null,
                'max_cash_on_hand' => $till ? (string) $till->max_cash_on_hand : null,
                'low_cash' => (bool) $till?->isLow(),
                'overloaded' => (bool) $till?->isOverloaded(),
                // «لم يُجرَد» حالةٌ لا صفر: خزنةٌ لم يعدّها إنسانٌ اليوم رقمُها
                // ادّعاءُ النظام لا شهادةُ موظّف.
                'last_counted_at' => $counted?->toDateTimeString(),
                'not_counted_today' => $notCounted,
                'has_till' => $till !== null,
            ];

            $keep = match ($flag) {
                'low_cash' => $item['low_cash'],
                'overloaded' => $item['overloaded'],
                'not_counted' => $item['not_counted_today'],
                'inactive' => !$item['is_active'],
                default => true,
            };

            if ($keep) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * حركة النقد الورقيّ عبر الشبكة — سجلٌّ يُلحَق ولا يُعدَّل.
     *
     * @param  array{branch_id?:int|null, reason?:string|null, date?:string|null, limit?:int}  $filters
     */
    public function movements(array $filters = []): array
    {
        $q = AgentCashMovement::query()->with(['branch', 'actor']);

        if (!empty($filters['branch_id'])) {
            $q->where('branch_id', (int) $filters['branch_id']);
        }
        if (!empty($filters['reason'])) {
            $q->where('reason', (string) $filters['reason']);
        }
        if (!empty($filters['date'])) {
            $d = (string) $filters['date'];
            $q->whereBetween('created_at', [$d . ' 00:00:00', $d . ' 23:59:59']);
        }

        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 500);

        return $q->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AgentCashMovement $m) => [
                'id' => (int) $m->id,
                'branch' => $m->branch?->name ?? ('#' . $m->branch_id),
                'branch_code' => $m->branch?->code,
                'direction' => (string) $m->direction,
                'reason' => (string) $m->reason,
                'reason_label' => AgentCashMovement::REASON_LABELS[$m->reason] ?? (string) $m->reason,
                'amount' => (string) $m->amount,
                'balance_before' => (string) $m->balance_before,
                'balance_after' => (string) $m->balance_after,
                'reference' => $m->reference,
                'actor' => $m->actor
                    ? (trim(($m->actor->f_name ?? '') . ' ' . ($m->actor->l_name ?? '')) ?: ('#' . $m->actor_user_id))
                    : null,
                'note' => $m->note,
                'created_at' => optional($m->created_at)->toDateTimeString(),
            ])->all();
    }


    /**
     * رصيد حساب «احتياطي النقد» محسوباً من سطور الدفتر.
     *
     * من السطور لا من عمودٍ مخزَّن: عمودٌ يساوي نفسه لا يُثبت شيئاً.
     */
    private function cashReserveBalance(): string
    {
        $code = 'CASH_RESERVE';

        $row = DB::table('ledger_entry_lines as l')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.account_code', $code)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN l.direction = 'debit'  THEN l.amount ELSE 0 END), 0) AS d,
                COALESCE(SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END), 0) AS c
            ")->first();

        // حسابُ أصلٍ رصيدُه الطبيعيّ مدين.
        return bcsub((string) ($row->d ?? '0'), (string) ($row->c ?? '0'), 4);
    }

    /** معرّفات الحسابات التي هي فروع — كي لا تُعدّ وكلاءَ مستقلّين. */
    private function branchAccountIds(): array
    {
        return AgentBranch::pluck('branch_user_id')->map(fn ($v) => (int) $v)->all();
    }
}
