<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentShift;
use App\Models\AgentSettlement;
use App\Models\EMoney;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-SETTLEMENT-ENGINE-001 — محرّك تسوية الوكلاء.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **مركز التسويات ليس تسويةً بنكيّة. هو نظامُ إدارة سيولة.**
 *
 * لكلّ وكيلٍ رصيدان يتحرّكان في اتّجاهين متعاكسين:
 *
 *   • **الرصيد الإلكترونيّ** — داخل النظام. يحدّ الإيداع.
 *   • **النقد الورقيّ**      — خارج النظام، في الواقع. يحدّ السحب.
 *
 * ووظيفة هذا المحرّك أن يحسب العلاقة بينهما **باستمرار**، ويكشف كلّ فرقٍ
 * بينهما وبين ما يقوله النظام.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القاعدة الحاكمة: كلّ رقمٍ يُحسب من مصدره، ولا يُقرأ من عمودٍ مخزَّن.**
 *
 * وهذه ليست أناقةً هندسيّة — هي الفرق بين تسويةٍ وتصديقٍ على النفس:
 *
 *   • «المتوقَّع» يُحسب من **سطور الدفتر** و**سجلّ حركة النقد**.
 *   • «الفعليّ» يُقرأ من الأعمدة المخزَّنة (`current_balance`، `cash_on_hand`).
 *   • **والفرق بينهما هو المنتَج.**
 *
 * ولو حُسب المتوقَّع من العمود نفسه لخرج الفرق صفراً دائماً، ولبدا كلّ شيءٍ
 * متوازناً حتى وهو منهار. وقد وقع هذا الخطأ في هذا المشروع مرّتين — في
 * ميزان المراجعة وفي جرد الورديّة — فكُتب هنا صريحاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **المعادلة التي يقوم عليها كلّ شيء:**
 *
 *   الرصيد الإلكترونيّ المتوقَّع =
 *         + ما اشتراه الوكيل        (topup معتمَد)
 *         − ما أعاده                 (payout معتمَد)
 *         + ما دخله من سحوبات العملاء (العميل يدفع رصيداً ويأخذ نقداً)
 *         − ما خرج في إيداعات العملاء (العميل يدفع نقداً ويأخذ رصيداً)
 *
 *   النقد الورقيّ المتوقَّع =
 *         + نقدُ إيداعات العملاء
 *         − نقدُ سحوباتهم
 *         + التوريد إلى الفروع
 *         − التوريد منها
 *         ± تسويات الجرد
 *
 * والاثنان معاً يجب أن يظلّا متوازنين: كلّ ريالٍ إلكترونيٍّ صادرٍ يقابله ريالٌ
 * حقيقيّ — إمّا في خزينة المنصّة، وإمّا في درج الوكيل.
 */
class AgentSettlementEngine
{
    /**
     * حاسبةُ فروق الورديّات — **مصدرٌ واحدٌ يشترك فيه هذا المحرّك
     * و`AgentDailySettlementService`.** (AMIAL-TRUTH-001)
     */
    public function __construct(
        private readonly \App\Services\Agent\ShiftVarianceCalculator $variance =
            new \App\Services\Agent\ShiftVarianceCalculator(),
    ) {
    }

    /** لا فرق يُتجاهَل تحت هذا الحدّ — التسامح صفر عمداً في المال. */
    private const TOLERANCE = '0.0001';

    public const STATUS_BALANCED = 'balanced';
    public const STATUS_SHORT = 'short';
    public const STATUS_OVER = 'over';
    public const STATUS_NO_DATA = 'no_data';

    public const STATUS_LABELS = [
        self::STATUS_BALANCED => 'متوازن',
        self::STATUS_SHORT => 'عجز',
        self::STATUS_OVER => 'فائض',
        self::STATUS_NO_DATA => 'لا حركة بعد',
    ];

    /**
     * الوضع الكامل لوكيلٍ واحد — الشاشة التي تُقرأ قبل أيّ قرار.
     */
    public function position(User $agent): array
    {
        $branchIds = AgentBranch::where('agent_user_id', $agent->id)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        $walletIds = array_merge(
            [(int) $agent->id],
            AgentBranch::where('agent_user_id', $agent->id)
                ->pluck('branch_user_id')->map(fn ($v) => (int) $v)->all(),
        );

        $float = $this->floatPosition($agent, $walletIds);
        $cash = $this->cashPosition($branchIds);

        // ── الوضع الصافي ────────────────────────────────────────────────
        //
        // الرصيد الإلكترونيّ **التزامٌ على المنصّة**: مالٌ نَدين به للوكيل
        // ويستطيع صرفه متى شاء. والنقد في درجه **ماله هو** لا يخصّنا.
        //
        // فلا يُجمعان. و«الوضع الصافي» هنا هو ما تدين به المنصّة، لا مجموع
        // ما لدى الوكيل.
        $companyLiability = $float['actual'];

        return [
            'agent' => [
                'id' => (int) $agent->id,
                'name' => trim(($agent->f_name ?? '') . ' ' . ($agent->l_name ?? '')) ?: ('#' . $agent->id),
                'phone' => $agent->phone,
                'branches' => count($branchIds),
            ],

            'float' => $float,
            'cash' => $cash,

            'net' => [
                // ما تدين به المنصّة للوكيل الآن.
                'company_liability' => $companyLiability,
                // وما يدين به الوكيل للمنصّة — ولا يكون إلّا برصيدٍ سالب،
                // وهو حالةٌ لا يجوز أن تقع أصلاً فتُرصَد صراحةً.
                'agent_liability' => bccomp($companyLiability, '0', 4) < 0
                    ? ltrim($companyLiability, '-') : '0.0000',
                'pending_topup' => $this->pendingAmount($agent, 'topup'),
                'pending_payout' => $this->pendingAmount($agent, 'payout'),
            ],

            'status' => $this->statusOf($float['difference'], $cash['difference'], $float, $cash),
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * الرصيد الإلكترونيّ: متوقَّعٌ من الدفتر، وفعليٌّ من المحفظة.
     *
     * @param  array<int>  $walletIds
     */
    private function floatPosition(User $agent, array $walletIds): array
    {
        // ── المتوقَّع: من سطور الدفتر لا من `current_balance` ────────────
        $bought = $this->settledAmount($agent, 'topup');
        $returned = $this->settledAmount($agent, 'payout');

        // سحبُ العميل يُدخل رصيداً إلى الفرع، وإيداعُه يُخرجه.
        $fromWithdrawals = $this->ledgerFlow($walletIds, 'agent_withdraw', 'in');
        $toDeposits = $this->ledgerFlow($walletIds, 'agent_deposit', 'out');

        $expected = bcsub(
            bcadd(bcsub($bought, $returned, 4), $fromWithdrawals, 4),
            $toDeposits,
            4,
        );

        // ── الفعليّ: من الأعمدة المخزَّنة ───────────────────────────────
        $actual = (string) (EMoney::whereIn('user_id', $walletIds)->sum('current_balance') ?: '0');
        $actual = bcadd($actual, '0', 4);

        $held = (string) (EMoney::whereIn('user_id', $walletIds)->sum('held_balance') ?: '0');

        return [
            'expected' => $expected,
            'actual' => $actual,
            // الفرق هو المنتَج: موجبٌ = رصيدٌ أكثر ممّا يفسّره الدفتر.
            'difference' => bcsub($actual, $expected, 4),
            'held' => bcadd($held, '0', 4),
            'breakdown' => [
                'bought' => $bought,
                'returned' => $returned,
                'from_withdrawals' => $fromWithdrawals,
                'to_deposits' => $toDeposits,
            ],
        ];
    }

    /**
     * النقد الورقيّ: متوقَّعٌ من سجلّ الحركة، وفعليٌّ من الخزائن والأدراج.
     *
     * @param  array<int>  $branchIds
     */
    private function cashPosition(array $branchIds): array
    {
        if ($branchIds === []) {
            return [
                'expected' => '0.0000', 'actual' => '0.0000', 'difference' => '0.0000',
                'in_safes' => '0.0000', 'in_drawers' => '0.0000',
                'breakdown' => [], 'has_movements' => false,
            ];
        }

        $sum = function (string $direction, array $reasons) use ($branchIds): string {
            $v = AgentCashMovement::whereIn('branch_id', $branchIds)
                ->where('direction', $direction)
                ->whereIn('reason', $reasons)
                ->sum('amount');

            return bcadd((string) ($v ?: '0'), '0', 4);
        };

        // **المتوقَّع يُبنى من الحركات لا من `cash_on_hand`.**
        //
        // وحركاتُ الورديّة (`shift_open`/`shift_close`) لا تُحسب: هي نقلٌ
        // **داخليّ** بين الخزنة والدرج، والاثنان معاً في «الفعليّ». وحسابُها
        // يُنقص المتوقَّع بمقدار كلّ عهدةٍ صُرفت.
        $depositsIn = $sum('in', ['customer_deposit']);
        $withdrawalsOut = $sum('out', ['customer_withdraw']);
        $treasuryIn = $sum('in', ['treasury_in', 'opening']);
        $treasuryOut = $sum('out', ['treasury_out']);
        $adjustIn = $sum('in', ['count_adjustment']);
        $adjustOut = $sum('out', ['count_adjustment']);

        $expected = bcsub(
            bcadd(bcadd($depositsIn, $treasuryIn, 4), $adjustIn, 4),
            bcadd(bcadd($withdrawalsOut, $treasuryOut, 4), $adjustOut, 4),
            4,
        );

        // ── الفعليّ: الخزائن + الأدراج المفتوحة ─────────────────────────
        //
        // والأدراج جزءٌ من نقد الفرع: قراءةُ الخزائن وحدها تُنقص الإجماليّ
        // بكلّ ما في أيدي الصرّافين، فيبدو فرعٌ نشِطٌ فارغاً كلّما زاد عملُه.
        $inSafes = bcadd((string) (DB::table('agent_cash_tills')
            ->whereIn('branch_id', $branchIds)->sum('cash_on_hand') ?: '0'), '0', 4);

        $inDrawers = bcadd((string) (AgentShift::whereIn('branch_id', $branchIds)
            ->where('status', AgentShift::STATUS_OPEN)->sum('cash_on_hand') ?: '0'), '0', 4);

        $actual = bcadd($inSafes, $inDrawers, 4);

        return [
            'expected' => $expected,
            'actual' => $actual,
            'difference' => bcsub($actual, $expected, 4),
            'in_safes' => $inSafes,
            'in_drawers' => $inDrawers,
            'breakdown' => [
                'deposits_in' => $depositsIn,
                'withdrawals_out' => $withdrawalsOut,
                'treasury_in' => $treasuryIn,
                'treasury_out' => $treasuryOut,
                'adjustments' => bcsub($adjustIn, $adjustOut, 4),
            ],
            'has_movements' => AgentCashMovement::whereIn('branch_id', $branchIds)->exists(),
        ];
    }

    /**
     * تسويةٌ يوميّة لوكيل — ما يُغلق به يومه.
     */
    public function dailySettlement(User $agent, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        $branchIds = AgentBranch::where('agent_user_id', $agent->id)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        // AMIAL-TRUTH-001 — **الحسابُ من مصدرٍ واحد.**
        //
        // كانت أسطرُ العجز والفائض منسوخةً حرفاً بحرف في
        // `AgentDailySettlementService::computeDay()`. وقِيس المحرّكان
        // على البيانات نفسِها فتطابقا — **والخطرُ في الغد**: من يُصلح
        // إشارةً أو شرطَ إغلاقٍ في واحدةٍ يترك الأخرى، فيرى المشرفُ
        // عجزاً في «ملفّ الوكيل» وعجزاً آخرَ في «لوحة اليوم» ولا يعرف
        // أيّهما الحقّ. (`amial-financial-truth`: لا تُكرَّر حساباتُ
        // التسوية.)
        //
        // والتعليلُ الذي كان هنا محفوظٌ في الحاسبة: المغلقةُ وحدها
        // تُحسب، والمجموعُ بالقيمة المطلقة، ولا مقاصّةَ بين نقصٍ وزيادة.
        $v = $this->variance->forDay($branchIds, $date);

        return [
            'date' => $date,
            'shifts_total' => $v['shifts_total'],
            'shifts_closed' => $v['shifts_closed'],
            'shifts_open' => $v['shifts_open'],

            'shortage_count' => $v['shortage_count'],
            'shortage_total' => $v['shortage_total'],
            'overage_count' => $v['overage_count'],
            'overage_total' => $v['overage_total'],

            // ورديّةٌ لم تُغلق تعني درجاً بلا شهادةِ إنسان — وهي حالةٌ
            // تُذكر، لا تُترك ضمن «لا فروق».
            'unclosed_drawers' => $v['shifts_open'],

            'position' => $this->position($agent),
        ];
    }

    /**
     * مسحٌ للشبكة — أيّ وكيلٍ خرج عن التوازن.
     *
     * @return array<int, array>
     */
    public function networkScan(int $limit = 200): array
    {
        $branchAccountIds = AgentBranch::pluck('branch_user_id')->all();

        $agents = User::where('type', AGENT_TYPE)
            ->whereNotIn('id', $branchAccountIds ?: [0])
            ->limit($limit)->get();

        $out = [];

        foreach ($agents as $agent) {
            $p = $this->position($agent);

            $out[] = [
                'agent' => $p['agent'],
                'status' => $p['status'],
                'status_label' => self::STATUS_LABELS[$p['status']] ?? $p['status'],
                'float_actual' => $p['float']['actual'],
                'float_difference' => $p['float']['difference'],
                'cash_actual' => $p['cash']['actual'],
                'cash_difference' => $p['cash']['difference'],
                'company_liability' => $p['net']['company_liability'],
                'pending_payout' => $p['net']['pending_payout'],
            ];
        }

        // غير المتوازن أوّلاً: من يقرأ الشاشة يبحث عن المشكلة لا عن السلامة.
        usort($out, function ($a, $b) {
            $rank = fn ($s) => $s === self::STATUS_BALANCED ? 2 : ($s === self::STATUS_NO_DATA ? 1 : 0);

            return $rank($a['status']) <=> $rank($b['status']);
        });

        return $out;
    }

    // ── مساعدات ─────────────────────────────────────────────────────────

    /** مجموع تسويةٍ معتمَدةٍ من نوعٍ ما. */
    private function settledAmount(User $agent, string $type): string
    {
        $v = AgentSettlement::where('agent_user_id', $agent->id)
            ->where('settlement_type', $type)
            ->where('status', 'completed')
            ->sum('amount');

        return bcadd((string) ($v ?: '0'), '0', 4);
    }

    private function pendingAmount(User $agent, string $type): string
    {
        $v = AgentSettlement::where('agent_user_id', $agent->id)
            ->where('settlement_type', $type)
            ->where('status', 'pending')
            ->sum('amount');

        return bcadd((string) ($v ?: '0'), '0', 4);
    }

    /**
     * تدفّقٌ من سطور الدفتر — لا من عمودٍ مخزَّن.
     *
     * @param  array<int>  $walletIds
     */
    private function ledgerFlow(array $walletIds, string $sourceType, string $direction): string
    {
        if ($walletIds === []) {
            return '0.0000';
        }

        $codes = DB::table('ledger_accounts')
            ->whereIn('owner_user_id', $walletIds)
            ->pluck('id');

        if ($codes->isEmpty()) {
            return '0.0000';
        }

        // «داخل» إلى محفظة الوكيل = قيدٌ دائن عليها (المحافظ التزاماتٌ دائنة).
        $ledgerDirection = $direction === 'in' ? 'credit' : 'debit';

        $v = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $codes)
            ->where('e.source_type', $sourceType)
            ->where('l.direction', $ledgerDirection)
            ->sum('l.amount');

        return bcadd((string) ($v ?: '0'), '0', 4);
    }

    /**
     * الحالة — وتُحسب من الفرقين معاً لا من أحدهما.
     *
     * و«لا حركة بعد» حالةٌ مستقلّة: وكيلٌ لم يعمل ليس «متوازناً»، هو غير
     * مُقيَّم. وعرضُه أخضرَ يجعل المدير يظنّ الشبكة سليمةً وهي ساكنة.
     */
    private function statusOf(string $floatDiff, string $cashDiff, array $float, array $cash): string
    {
        $noFloat = bccomp($float['actual'], '0', 4) === 0
            && bccomp($float['breakdown']['bought'], '0', 4) === 0;

        if ($noFloat && !$cash['has_movements']) {
            return self::STATUS_NO_DATA;
        }

        $tot = bcadd($floatDiff, $cashDiff, 4);

        if (bccomp(ltrim($tot, '-'), self::TOLERANCE, 4) <= 0) {
            return self::STATUS_BALANCED;
        }

        return bccomp($tot, '0', 4) < 0 ? self::STATUS_SHORT : self::STATUS_OVER;
    }
}
