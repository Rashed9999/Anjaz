<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentDailySettlement;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-DAILY-SETTLEMENT-001 — إقفالُ يوم الوكيل، وتحويلُ الورق إلى رصيد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **المسألة كلّها في جملةٍ واحدة: أين غطاءُ الرصيد الإلكترونيّ الليلة؟**
 *
 * عميلٌ أودع ألفاً في فرعٍ بالمكلا: سلّم ورقاً للوكيل، وخرج من رصيد
 * الوكيل ألفٌ إلى محفظة العميل. فصار في الشبكة ألفٌ إلكترونيٌّ غطاؤه
 * **ورقةٌ في درج رجلٍ في المكلا** — لا في خزينة المنصّة.
 *
 * وذلك مقبولٌ ساعاتٍ، لا أسابيع. فآخر اليوم يُسلّم الوكيل الورق ويستلم
 * رصيداً، فيعود الغطاء إلى مكانه. وهذا هو التحوّل: **الريال الحقيقيّ
 * يصير إلكترونيّاً**.
 *
 * والعكس بالعكس: وكيلٌ خدم سحوباتٍ أكثر امتلأ رصيدُه وفرغ درجُه، فيعيد
 * الرصيد ويستلم ورقاً — **الإلكترونيّ يصير حقيقيّاً**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا نافذةٌ زمنيّة، ولمَ لا يُترك الرفع مفتوحاً؟**
 *
 * لأنّ يوماً لا يُقفل ليس يوماً. ولو رفع كلّ وكيلٍ متى شاء لوصلت تسويات
 * الشبكة متفرّقةً على أربعٍ وعشرين ساعة، ولما وُجدت لحظةٌ يُقال فيها
 * «أمسُ مغلقٌ ومتوازن». والنافذة ليلاً بعد إغلاق الفروع.
 *
 * ومن تأخّر: **لا يُغتفر بصمت.** مالٌ بلا غطاءٍ مقابلٍ ليلةً كاملة يُسجَّل
 * ويحتاج فكّاً من إدارة أميال باسم من أذن وسببه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وكلّ رقمٍ هنا من مصدره:** الحركة من `agent_cash_movements`، والفروق
 * من جرد الورديّات، والرسوم من قيود الدفتر. ولا يُقرأ رقمٌ من عمودٍ
 * تجميعيٍّ يُحدَّث بيد.
 */
class AgentDailySettlementService
{
    public function __construct(
        private readonly AgentNetworkService $network,
        private readonly AuditService $audit,
        private readonly \App\Services\Whatsapp\AgentAlertService $alerts,
    ) {
    }

    // ══════════════════════════════════════════════════════════════════
    // النافذة
    // ══════════════════════════════════════════════════════════════════

    /**
     * حالةُ النافذة الآن ليومٍ ما.
     *
     * @return array{open: bool, state: string, message: string, opens_at: string, closes_at: string}
     */
    public function windowState(string $date, ?Carbon $now = null): array
    {
        $now ??= now();
        $start = (int) config('amial.daily_settlement.window_start_hour', 22);
        $end = (int) config('amial.daily_settlement.window_end_hour', 24);

        $day = Carbon::parse($date)->startOfDay();
        $opensAt = $day->copy()->addHours($start);
        // ٢٤ تعني منتصف ليل اليوم التالي — لا الساعة صفر من اليوم نفسه.
        $closesAt = $day->copy()->addHours($end);

        $fmt = fn (Carbon $c) => $c->format('Y-m-d H:i');

        if ($now->lt($opensAt)) {
            return [
                'open' => false, 'state' => 'not_yet',
                'message' => "لم تُفتح نافذة الرفع بعد — تُفتح {$fmt($opensAt)} وتُغلق {$fmt($closesAt)}.",
                'opens_at' => $fmt($opensAt), 'closes_at' => $fmt($closesAt),
            ];
        }

        if ($now->lte($closesAt)) {
            return [
                'open' => true, 'state' => 'on_time',
                'message' => "النافذة مفتوحة حتى {$fmt($closesAt)} — ارفع تسوية اليوم الآن.",
                'opens_at' => $fmt($opensAt), 'closes_at' => $fmt($closesAt),
            ];
        }

        $grace = (int) config('amial.daily_settlement.grace_minutes', 0);

        if ($grace > 0 && $now->lte($closesAt->copy()->addMinutes($grace))) {
            return [
                'open' => true, 'state' => 'late',
                'message' => 'انقضت النافذة — هذه مهلةُ سماحٍ، وسيُسجَّل الرفع متأخّراً.',
                'opens_at' => $fmt($opensAt), 'closes_at' => $fmt($closesAt),
            ];
        }

        return [
            'open' => false, 'state' => 'closed',
            'message' => "أُغلقت نافذة {$date} في {$fmt($closesAt)}. "
                . 'الرفع بعدها يحتاج فكّاً من إدارة أميال — راجعهم بسبب التأخير.',
            'opens_at' => $fmt($opensAt), 'closes_at' => $fmt($closesAt),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // حساب اليوم
    // ══════════════════════════════════════════════════════════════════

    /**
     * ما جرى في يومٍ لوكيل — محسوباً من المصدر، بلا حفظ.
     */
    public function computeDay(User $agent, string $date): array
    {
        $from = Carbon::parse($date)->startOfDay();
        $to = Carbon::parse($date)->endOfDay();

        $branchIds = AgentBranch::where('agent_user_id', $agent->id)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        if ($branchIds === []) {
            return $this->emptyDay($date);
        }

        // ── الحركة النقديّة في الأدراج ─────────────────────────────────
        $rows = AgentCashMovement::whereIn('branch_id', $branchIds)
            ->where('is_drawer', true)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->selectRaw('reason, count(*) as n, sum(amount) as total')
            ->groupBy('reason')->get()->keyBy('reason');

        $dep = bcadd((string) ($rows['customer_deposit']->total ?? '0'), '0', 4);
        $wdr = bcadd((string) ($rows['customer_withdraw']->total ?? '0'), '0', 4);

        // ── الورديّات وفروقها ─────────────────────────────────────────
        $shifts = AgentShift::whereIn('branch_id', $branchIds)
            ->whereBetween('opened_at', [$from, $to])->get();

        $closed = $shifts->where('status', AgentShift::STATUS_CLOSED);
        $short = $closed->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) < 0);
        $over = $closed->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) > 0);

        $absSum = fn ($c) => (string) $c->reduce(
            fn ($a, $s) => bcadd($a, ltrim((string) $s->variance, '-'), 4), '0');

        $shortTotal = $absSum($short);
        $overTotal = $absSum($over);

        // ── الرسوم والعمولة من الدفتر ─────────────────────────────────
        $refs = AgentCashMovement::whereIn('branch_id', $branchIds)
            ->where('is_drawer', true)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->pluck('reference')->filter()->unique()->all();

        [$fees, $commission] = $this->feesFrom($refs);

        // ── التحوّل ───────────────────────────────────────────────────
        //
        // `net_cash` موجبٌ = ورقٌ زائدٌ في يد الوكيل (أودع الناس أكثر
        // ممّا سحبوا). ورصيدُه الإلكترونيّ نقص بالمقدار نفسه.
        $netCash = bcsub($dep, $wdr, 4);
        $netFloat = bcsub('0', $netCash, 4);

        $conversion = 'none';
        if (bccomp($netCash, '0', 4) > 0) {
            $conversion = 'topup';        // يسلّم ورقاً ويستلم رصيداً
        } elseif (bccomp($netCash, '0', 4) < 0) {
            $conversion = 'payout';       // يعيد رصيداً ويستلم ورقاً
        }

        // ── ما يُرفع للإدارة صراحةً ────────────────────────────────────
        //
        // «مشبوه» هنا ليس حكماً — هو **ما يستحقّ نظرةً**. والحدّ مضبوط.
        $limit = (string) config('amial.daily_settlement.suspicious_variance', '50000');
        $suspicious = 0;
        $flags = [];

        if (bccomp($shortTotal, $limit, 4) > 0) {
            $suspicious++;
            $flags[] = "عجزُ اليوم {$shortTotal} تجاوز الحدّ {$limit}";
        }
        if (bccomp($overTotal, $limit, 4) > 0) {
            $suspicious++;
            $flags[] = "فائضُ اليوم {$overTotal} تجاوز الحدّ {$limit} — والفائض ليس خبراً سارّاً";
        }

        $unclosed = $shifts->where('status', AgentShift::STATUS_OPEN)->count();
        if ($unclosed > 0) {
            $suspicious++;
            $flags[] = "{$unclosed} ورديّة لم تُغلق — درجٌ بلا شهادة إنسان";
        }

        $pending = $closed->where('review_status', AgentShift::REVIEW_PENDING)->count();
        if ($pending > 0) {
            $flags[] = "{$pending} فرقاً لم تراجعه إدارة شركتك بعد";
        }

        return [
            'date' => $date,
            'deposits_count' => (int) ($rows['customer_deposit']->n ?? 0),
            'deposits_total' => $dep,
            'withdrawals_count' => (int) ($rows['customer_withdraw']->n ?? 0),
            'withdrawals_total' => $wdr,
            'fees_collected' => $fees,
            'agent_commission' => $commission,

            'shortage_count' => $short->count(),
            'shortage_total' => $shortTotal,
            'overage_count' => $over->count(),
            'overage_total' => $overTotal,
            'unclosed_shifts' => $unclosed,
            'pending_review' => $pending,
            'suspicious_count' => $suspicious,
            'flags' => $flags,

            'net_cash' => $netCash,
            'net_float' => $netFloat,
            'conversion' => $conversion,
            'conversion_amount' => ltrim($netCash, '-'),
            'conversion_label' => AgentDailySettlement::CONVERSION_LABELS[$conversion],

            'shifts_total' => $shifts->count(),
            'shifts_closed' => $closed->count(),
            'branches' => count($branchIds),
        ];
    }

    private function emptyDay(string $date): array
    {
        return [
            'date' => $date,
            'deposits_count' => 0, 'deposits_total' => '0.0000',
            'withdrawals_count' => 0, 'withdrawals_total' => '0.0000',
            'fees_collected' => '0.0000', 'agent_commission' => '0.0000',
            'shortage_count' => 0, 'shortage_total' => '0.0000',
            'overage_count' => 0, 'overage_total' => '0.0000',
            'unclosed_shifts' => 0, 'pending_review' => 0,
            'suspicious_count' => 0, 'flags' => [],
            'net_cash' => '0.0000', 'net_float' => '0.0000',
            'conversion' => 'none', 'conversion_amount' => '0.0000',
            'conversion_label' => AgentDailySettlement::CONVERSION_LABELS['none'],
            'shifts_total' => 0, 'shifts_closed' => 0, 'branches' => 0,
        ];
    }

    /** @param array<string> $references */
    private function feesFrom(array $references): array
    {
        if ($references === []) {
            return ['0.0000', '0.0000'];
        }

        $entries = DB::table('ledger_journal_entries')
            ->whereIn('source_id', $references)
            ->whereIn('source_type', ['agent_deposit', 'agent_withdraw'])
            ->pluck('metadata');

        $fees = '0.0000';
        $commission = '0.0000';

        foreach ($entries as $m) {
            $meta = json_decode((string) $m, true) ?: [];
            $fees = bcadd($fees, (string) ($meta['fee'] ?? '0'), 4);
            $commission = bcadd($commission, (string) ($meta['commission'] ?? '0'), 4);
        }

        return [$fees, $commission];
    }

    // ══════════════════════════════════════════════════════════════════
    // الرفع
    // ══════════════════════════════════════════════════════════════════

    public function submit(User $agent, AgentStaff $by, string $date, ?Carbon $now = null): AgentDailySettlement
    {
        $now ??= now();

        if (!$by->isHeadOffice()) {
            throw new DomainException('رفعُ تسوية اليوم من صلاحية الإدارة العامّة للشركة');
        }

        $existing = AgentDailySettlement::where('agent_user_id', $agent->id)
            ->whereDate('settlement_date', $date)->first();

        if ($existing && in_array($existing->status, [
            AgentDailySettlement::STATUS_SUBMITTED, AgentDailySettlement::STATUS_ACCEPTED,
        ], true)) {
            throw new DomainException('تسوية هذا اليوم مرفوعةٌ بالفعل');
        }

        $window = $this->windowState($date, $now);
        $unlocked = $existing && $existing->unlocked_at !== null;

        // الفكُّ من إدارة أميال يفتح الباب مرّةً واحدة، ولا يُلغي أنّه تأخّر.
        if (!$window['open'] && !$unlocked) {
            throw new DomainException($window['message']);
        }

        $day = $this->computeDay($agent, $date);

        return DB::transaction(function () use ($agent, $by, $date, $day, $window, $unlocked, $existing, $now) {
            $row = $existing ?: new AgentDailySettlement([
                'settlement_ulid' => (string) Str::ulid(),
                'agent_user_id' => $agent->id,
                'settlement_date' => $date,
            ]);

            $row->fill([
                'deposits_count' => $day['deposits_count'],
                'deposits_total' => $day['deposits_total'],
                'withdrawals_count' => $day['withdrawals_count'],
                'withdrawals_total' => $day['withdrawals_total'],
                'fees_collected' => $day['fees_collected'],
                'agent_commission' => $day['agent_commission'],
                'shortage_count' => $day['shortage_count'],
                'shortage_total' => $day['shortage_total'],
                'overage_count' => $day['overage_count'],
                'overage_total' => $day['overage_total'],
                'unclosed_shifts' => $day['unclosed_shifts'],
                'suspicious_count' => $day['suspicious_count'],
                'net_cash' => $day['net_cash'],
                'net_float' => $day['net_float'],
                'conversion' => $day['conversion'],
                'conversion_amount' => $day['conversion_amount'],
                'status' => AgentDailySettlement::STATUS_SUBMITTED,
                'window_state' => $unlocked ? 'unlocked' : $window['state'],
                'submitted_at' => $now,
                'submitted_by_staff_id' => $by->id,
                'detail' => $day,
            ]);

            $row->save();

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $agent->id,
                'action' => 'agent.daily_settlement.submit',
                'severity' => $row->window_state === 'on_time' ? 'info' : 'warning',
                'subject_type' => 'agent_daily_settlement',
                'subject_id' => $row->settlement_ulid,
                'metadata' => [
                    'date' => $date, 'conversion' => $day['conversion'],
                    'amount' => $day['conversion_amount'], 'window' => $row->window_state,
                ],
            ]);

            return $row->fresh();
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // قرار أميال — وهنا يقع التحوّل فعلاً
    // ══════════════════════════════════════════════════════════════════

    /**
     * قبولُ التسوية: يُنفَّذ التحويل بين الورق والرصيد.
     *
     * **ولا يُعلَّم شيءٌ «مقبولاً» قبل أن ينتقل المال.** فترتيبُ السطور
     * هنا مقصود: التحويل أوّلاً، والحالة بعده — ولو انعكس لبقيت تسويةٌ
     * «مقبولة» بلا ريالٍ تحرّك، وهو ما وقع في هذا المشروع من قبل.
     */
    public function accept(AgentDailySettlement $row, User $admin, string $note = ''): AgentDailySettlement
    {
        if ($row->status !== AgentDailySettlement::STATUS_SUBMITTED) {
            throw new DomainException('هذه التسوية ليست بانتظار القرار');
        }

        $agent = User::findOrFail($row->agent_user_id);
        $amount = (string) $row->conversion_amount;

        $decided = DB::transaction(function () use ($row, $agent, $admin, $amount, $note) {
            $linked = null;

            if ($row->conversion === 'topup' && bccomp($amount, '0', 4) > 0) {
                // سلّم ورقاً ⇒ يستلم رصيداً.
                $s = $this->network->adminCreditAgent(
                    $agent, $amount, $admin, 'تسوية يوم ' . $row->settlement_date->toDateString(),
                );
                $linked = $s->settlement_ulid;
            } elseif ($row->conversion === 'payout' && bccomp($amount, '0', 4) > 0) {
                // امتلأ رصيدُه ⇒ يعيده ويستلم ورقاً.
                $s = $this->network->requestPayout(
                    $agent, $amount, 'cash', 'تسوية يوم ' . $row->settlement_date->toDateString(),
                );
                $s = $this->network->approveSettlement($s, $admin);
                $linked = $s->settlement_ulid;
            }

            $row->fill([
                'status' => AgentDailySettlement::STATUS_ACCEPTED,
                'decided_by_user_id' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note ?: null,
                'linked_settlement_ulid' => $linked,
            ])->save();

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $admin->id,
                'action' => 'agent.daily_settlement.accept',
                'severity' => 'critical',
                'subject_type' => 'agent_daily_settlement',
                'subject_id' => $row->settlement_ulid,
                'metadata' => [
                    'agent_user_id' => $agent->id,
                    'conversion' => $row->conversion, 'amount' => $amount,
                    'linked_settlement' => $linked,
                ],
            ]);

            return $row->fresh();
        });

        // القرار يصل صاحبه — لا ينتظر أن يفتح البوّابة صباحاً.
        $this->alerts->settlementDecided($decided);

        return $decided;
    }

    public function reject(AgentDailySettlement $row, User $admin, string $note): AgentDailySettlement
    {
        if ($row->status !== AgentDailySettlement::STATUS_SUBMITTED) {
            throw new DomainException('هذه التسوية ليست بانتظار القرار');
        }

        if (mb_strlen(trim($note)) < 10) {
            throw new DomainException('سببُ الرفض إلزاميّ — عشرة أحرف فأكثر');
        }

        $row->fill([
            'status' => AgentDailySettlement::STATUS_REJECTED,
            'decided_by_user_id' => $admin->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'action' => 'agent.daily_settlement.reject',
            'severity' => 'critical',
            'subject_type' => 'agent_daily_settlement',
            'subject_id' => $row->settlement_ulid,
            'metadata' => ['reason' => $note],
        ]);

        $fresh = $row->fresh();

        $this->alerts->settlementDecided($fresh);

        return $fresh;
    }

    /**
     * فكُّ يومٍ انقضت نافذته — تدخّلُ إدارة أميال.
     *
     * ولا يُمحى أثرُ التأخير: تُرفع بعده بحالة `unlocked` لا `on_time`.
     */
    public function unlock(User $agent, string $date, User $admin, string $reason): AgentDailySettlement
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainException('سببُ الفكّ إلزاميّ — عشرة أحرف فأكثر');
        }

        $row = AgentDailySettlement::firstOrNew([
            'agent_user_id' => $agent->id,
            'settlement_date' => $date,
        ]);

        if ($row->status === AgentDailySettlement::STATUS_ACCEPTED) {
            throw new DomainException('تسوية هذا اليوم مقبولةٌ بالفعل');
        }

        $row->settlement_ulid = $row->settlement_ulid ?: (string) Str::ulid();
        $row->status = $row->status ?: AgentDailySettlement::STATUS_DRAFT;
        $row->unlocked_by_user_id = $admin->id;
        $row->unlocked_at = now();
        $row->unlock_reason = $reason;
        $row->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'action' => 'agent.daily_settlement.unlock',
            'severity' => 'critical',
            'subject_type' => 'agent_daily_settlement',
            'subject_id' => $row->settlement_ulid,
            'metadata' => ['agent_user_id' => $agent->id, 'date' => $date, 'reason' => $reason],
        ]);

        return $row->fresh();
    }

    // ══════════════════════════════════════════════════════════════════
    // شاشة أميال
    // ══════════════════════════════════════════════════════════════════

    /**
     * لوحةُ يومٍ للشبكة كلّها — ومعها **من لم يرفع**.
     *
     * ووكيلٌ لم يرفع لا يظهر صفراً ولا يغيب: هو الحالة التي تُبحث أوّلاً.
     * فقائمةٌ تعرض المرفوع وحده تُخفي بالضبط ما يجب أن يُرى.
     */
    public function networkDay(string $date): array
    {
        $branchAccounts = AgentBranch::pluck('branch_user_id')->all();

        $agents = User::where('type', AGENT_TYPE)
            ->whereNotIn('id', $branchAccounts ?: [0])
            ->where('is_active', 1)
            ->get(['id', 'f_name', 'l_name', 'phone']);

        $rows = AgentDailySettlement::whereDate('settlement_date', $date)
            ->get()->keyBy('agent_user_id');

        $out = [];

        foreach ($agents as $a) {
            $r = $rows[$a->id] ?? null;
            $name = trim(($a->f_name ?? '') . ' ' . ($a->l_name ?? '')) ?: ('#' . $a->id);

            $out[] = [
                'agent_user_id' => (int) $a->id,
                'agent' => $name,
                'phone' => $a->phone,
                'ulid' => $r?->settlement_ulid,
                'status' => $r?->status ?? 'not_submitted',
                'status_label' => $r
                    ? (AgentDailySettlement::STATUS_LABELS[$r->status] ?? $r->status)
                    : 'لم يرفع تسوية اليوم',
                'window_state' => $r?->window_state,
                'window_label' => $r?->window_state
                    ? (AgentDailySettlement::WINDOW_LABELS[$r->window_state] ?? $r->window_state) : null,
                'submitted_at' => $r?->submitted_at?->toDateTimeString(),
                'deposits_total' => (string) ($r->deposits_total ?? '0'),
                'withdrawals_total' => (string) ($r->withdrawals_total ?? '0'),
                'shortage_total' => (string) ($r->shortage_total ?? '0'),
                'overage_total' => (string) ($r->overage_total ?? '0'),
                'suspicious_count' => (int) ($r->suspicious_count ?? 0),
                'conversion' => $r?->conversion,
                'conversion_label' => $r ? (AgentDailySettlement::CONVERSION_LABELS[$r->conversion] ?? '') : null,
                'conversion_amount' => (string) ($r->conversion_amount ?? '0'),
                'unlocked' => $r?->unlocked_at !== null,
            ];
        }

        // من لم يرفع أوّلاً، ثمّ المرفوع بانتظار القرار، ثمّ المحسوم.
        $rank = fn (array $r) => match ($r['status']) {
            'not_submitted' => 0,
            AgentDailySettlement::STATUS_SUBMITTED => 1,
            AgentDailySettlement::STATUS_REJECTED => 2,
            default => 3,
        };
        usort($out, fn ($a, $b) => $rank($a) <=> $rank($b));

        $totals = [
            'agents' => count($out),
            'not_submitted' => count(array_filter($out, fn ($r) => $r['status'] === 'not_submitted')),
            'awaiting' => count(array_filter($out, fn ($r) => $r['status'] === AgentDailySettlement::STATUS_SUBMITTED)),
            'accepted' => count(array_filter($out, fn ($r) => $r['status'] === AgentDailySettlement::STATUS_ACCEPTED)),
            'late' => count(array_filter($out, fn ($r) => in_array($r['window_state'], ['late', 'unlocked'], true))),
            'suspicious' => array_sum(array_column($out, 'suspicious_count')),
        ];

        return ['date' => $date, 'rows' => $out, 'totals' => $totals];
    }
}
