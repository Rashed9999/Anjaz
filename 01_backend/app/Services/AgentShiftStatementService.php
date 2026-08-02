<?php

namespace App\Services;

use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-SHIFT-STATEMENT-001 — كشفُ تسوية الورديّة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الورديّة المغلقة ليست رقماً واحداً — هي كشفٌ يرفعه الصرّاف إلى إدارة
 * شركته.**
 *
 * وكان الصرّاف يُغلق درجه فيُسجَّل «الفرق −٤٠٠٠» ثمّ ينتهي الأمر: لا يرى
 * هو ما رفعه، ولا ترى الإدارة كيف وصل الرقم إلى ما وصل. ورقمٌ بلا ما
 * يفسّره لا يُبنى عليه اتّهامٌ ولا براءة.
 *
 * فهذا الكشف يُظهر **السلسلة كاملة**:
 *
 *     العهدة الافتتاحيّة
 *   + نقدُ إيداعات العملاء
 *   − نقدُ سحوباتهم
 *   + ما ورَّدته الخزنة إلى الدرج أثناء الورديّة
 *   − ما سلّمه الدرج إلى الخزنة
 *   ────────────────────────────
 *   = المتوقَّع        ← يُحسب من سجلّ الحركة
 *     المعدود          ← ما شهد به الصرّاف بيده
 *   ────────────────────────────
 *   = الفرق            ← وهو المنتَج
 *
 * ومعه الرصيد الإلكترونيّ المقابل، والرسوم وعمولة الشركة، وكلُّ عمليّةٍ
 * باسم عميلها ومرجعها ووقتها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وفيه سطرٌ لا يوجد في أيّ كشفٍ عاديّ: فحصُ سلامة النظام.**
 *
 * المتوقَّع يُحسب هنا من سجلّ الحركة، و`cash_on_hand` عمودٌ يُحدَّث مع كلّ
 * عملية. وهما طريقان إلى الرقم نفسه. فإن اختلفا فالخلل في النظام لا في
 * الصرّاف — ويجب أن يُقال، لا أن يُحمَّل على من عدّ الدرج.
 */
class AgentShiftStatementService
{
    /** أسبابُ حركة الدرج التي يظهر لها سطرٌ في السلسلة. */
    private const LADDER = [
        'customer_deposit' => ['in', 'نقدُ إيداعات العملاء'],
        'customer_withdraw' => ['out', 'نقدُ سحوبات العملاء'],
        'treasury_in' => ['in', 'توريدٌ من خزنة الفرع إلى الدرج'],
        'treasury_out' => ['out', 'تسليمٌ من الدرج إلى خزنة الفرع'],
    ];

    public function build(AgentShift $shift): array
    {
        $staff = AgentStaff::find($shift->staff_id);
        $closedBy = $shift->closed_by ? AgentStaff::find($shift->closed_by) : null;
        $reviewer = $shift->reviewed_by ? AgentStaff::find($shift->reviewed_by) : null;

        $moves = AgentCashMovement::where('shift_id', $shift->id)
            ->orderBy('id')->get();

        $drawer = $moves->where('is_drawer', true);

        // ── السلسلة النقديّة ────────────────────────────────────────────
        $lines = [];
        $running = bcadd((string) $shift->opening_float, '0', 4);

        $lines[] = [
            'label' => 'العهدة الافتتاحيّة (من خزنة الفرع)',
            'direction' => 'in', 'count' => null,
            'amount' => bcadd((string) $shift->opening_float, '0', 4),
            'running' => $running,
        ];

        foreach (self::LADDER as $reason => [$dir, $label]) {
            $rows = $drawer->where('reason', $reason)->where('direction', $dir);

            if ($rows->isEmpty()) {
                continue;
            }

            $total = (string) $rows->reduce(fn ($a, $m) => bcadd($a, (string) $m->amount, 4), '0');
            $running = $dir === 'in' ? bcadd($running, $total, 4) : bcsub($running, $total, 4);

            $lines[] = [
                'label' => $label,
                'direction' => $dir,
                'count' => $rows->count(),
                'amount' => $total,
                'running' => $running,
            ];
        }

        $expected = $shift->expectedCash();
        $counted = $shift->counted_cash === null ? null : bcadd((string) $shift->counted_cash, '0', 4);
        $variance = $counted === null ? null : bcsub($counted, $expected, 4);

        // ── فحص السلامة: طريقان إلى الرقم نفسه ─────────────────────────
        //
        // للورديّة المفتوحة يُقارَن `cash_on_hand` الجاري بالمحسوب. وللمغلقة
        // صُفِّر العمود عند التسليم فلا مقارنة — وتُقال «غير قابلٍ للفحص»
        // ولا يُدّعى تطابق.
        $integrity = ['checkable' => false, 'matches' => null, 'stored' => null, 'computed' => $expected];

        if ($shift->isOpen()) {
            $stored = bcadd((string) $shift->cash_on_hand, '0', 4);
            $integrity = [
                'checkable' => true,
                'matches' => bccomp($stored, $expected, 4) === 0,
                'stored' => $stored,
                'computed' => $expected,
            ];
        }

        // ── الرصيد الإلكترونيّ والرسوم — من الدفتر ──────────────────────
        $money = $this->ledgerSide($drawer->pluck('reference')->filter()->unique()->all());

        // ── حركاتٌ لا يُعرف جانبُها ─────────────────────────────────────
        //
        // صفوفٌ سابقةٌ لتمييز الدرج من الخزنة. تُذكر صراحةً: كشفٌ ينقصه
        // سطرٌ ويسكت عنه أسوأ من كشفٍ يقول إنّه ناقص.
        $unknown = $moves->whereNull('is_drawer');

        return [
            'statement_no' => 'SHIFT-' . $shift->id,
            'shift_id' => (int) $shift->id,
            'status' => $shift->status,

            'staff' => [
                'id' => $staff?->id,
                'name' => $staff?->name ?? '—',
                'username' => $staff?->username,
            ],
            'branch' => [
                'id' => (int) $shift->branch_id,
                'name' => $shift->branch?->name ?? '—',
            ],

            'opened_at' => $shift->opened_at?->toDateTimeString(),
            'closed_at' => $shift->closed_at?->toDateTimeString(),
            'duration_minutes' => $shift->opened_at && $shift->closed_at
                ? $shift->opened_at->diffInMinutes($shift->closed_at) : null,
            'closed_by' => $closedBy?->name,
            'closed_by_self' => $closedBy && (int) $closedBy->id === (int) $shift->staff_id,

            'cash' => [
                'lines' => $lines,
                'expected' => $expected,
                'counted' => $counted,
                'variance' => $variance,
                // العجز والفائض يُسمَّيان ولا يُدمجان في «فرق».
                'variance_kind' => $variance === null ? null
                    : (bccomp($variance, '0', 4) === 0 ? 'balanced'
                        : (bccomp($variance, '0', 4) < 0 ? 'shortage' : 'overage')),
                'integrity' => $integrity,
            ],

            'emoney' => $money['emoney'],
            'fees' => $money['fees'],

            'operations' => $this->operations($drawer),

            'unknown_side' => [
                'count' => $unknown->count(),
                'note' => $unknown->count() > 0
                    ? 'حركاتٌ سُجّلت قبل تمييز الدرج من الخزنة — لم تُحسب في السلسلة أعلاه.'
                    : null,
            ],

            'review' => [
                'status' => $shift->review_status,
                'label' => AgentShift::REVIEW_LABELS[$shift->review_status] ?? $shift->review_status,
                'note' => $shift->review_note,
                'reviewed_by' => $reviewer?->name,
                'reviewed_at' => $shift->reviewed_at?->toDateTimeString(),
                'teller_note' => $shift->close_note,
            ],
        ];
    }

    /**
     * الجانب الإلكترونيّ: ما خرج من محفظة الفرع وما دخلها، والرسوم.
     *
     * يُقرأ من قيود الدفتر بمراجع العمليّات نفسها — فالرسم لا يُخزَّن في
     * سطر النقد، وحسابُه بإعادة تطبيق التسعيرة يُنتج رقماً قد يخالف ما
     * حُصّل فعلاً لو تغيّرت التسعيرة بعدها.
     *
     * @param  array<string>  $references
     */
    private function ledgerSide(array $references): array
    {
        $zero = [
            'emoney' => ['out_on_deposits' => '0.0000', 'in_on_withdrawals' => '0.0000'],
            'fees' => ['collected' => '0.0000', 'agent_commission' => '0.0000', 'known' => false],
        ];

        if ($references === []) {
            return $zero;
        }

        $entries = DB::table('ledger_journal_entries')
            ->whereIn('source_id', $references)
            ->whereIn('source_type', ['agent_deposit', 'agent_withdraw'])
            ->get(['source_type', 'total_amount', 'metadata']);

        if ($entries->isEmpty()) {
            return $zero;
        }

        $out = '0.0000';
        $in = '0.0000';
        $fees = '0.0000';
        $commission = '0.0000';

        foreach ($entries as $e) {
            $amt = bcadd((string) $e->total_amount, '0', 4);

            if ($e->source_type === 'agent_deposit') {
                $out = bcadd($out, $amt, 4);
            } else {
                $in = bcadd($in, $amt, 4);
            }

            $meta = json_decode((string) $e->metadata, true) ?: [];
            $fees = bcadd($fees, (string) ($meta['fee'] ?? '0'), 4);
            $commission = bcadd($commission, (string) ($meta['commission'] ?? '0'), 4);
        }

        return [
            'emoney' => ['out_on_deposits' => $out, 'in_on_withdrawals' => $in],
            'fees' => ['collected' => $fees, 'agent_commission' => $commission, 'known' => true],
        ];
    }

    /** كلُّ عمليّةٍ باسم عميلها — لا أرقاماً يُستعلَم عنها بعد ذلك. */
    private function operations($drawer): array
    {
        $ops = $drawer->whereIn('reason', ['customer_deposit', 'customer_withdraw']);

        $names = User::whereIn('id', $ops->pluck('customer_user_id')->filter()->unique())
            ->get(['id', 'f_name', 'l_name', 'phone'])->keyBy('id');

        return $ops->map(function (AgentCashMovement $m) use ($names) {
            $c = $names[$m->customer_user_id] ?? null;

            return [
                'at' => (string) $m->created_at,
                'reason' => $m->reason,
                'label' => AgentCashMovement::REASON_LABELS[$m->reason] ?? $m->reason,
                'amount' => bcadd((string) $m->amount, '0', 4),
                'drawer_after' => bcadd((string) $m->balance_after, '0', 4),
                'reference' => $m->reference,
                'customer' => $c ? (trim(($c->f_name ?? '') . ' ' . ($c->l_name ?? '')) ?: $c->phone) : null,
                'customer_phone' => $c?->phone,
            ];
        })->values()->all();
    }
}
