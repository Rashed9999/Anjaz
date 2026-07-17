<?php

namespace App\Services;

use App\Models\AgentFloatLog;
use App\Models\AgentProfile;
use App\Models\AgentSettlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-AGENT-NETWORK-001 (v2.3)
 *
 * AgentNetworkService — إدارة شبكة الوكلاء (الصرّافين).
 *
 * **الوظائف:**
 *   1. assertCashInAllowed: فحص حدود الوكيل قبل cash-in
 *   2. recordFloatMovement: تتبع السيولة اليومية
 *   3. requestTopup: الوكيل يطلب شراء رصيد (settlement)
 *   4. approveSettlement: الإدارة/الموزّع تؤكد التسوية + قيد ledger
 *   5. getFloatDashboard: لوحة سيولة الوكيل
 *
 * **نموذج السيولة (M-Pesa-style):**
 *   الوكيل يملك "float" = رصيد رقمي يبيعه كاش-إن.
 *   عند نفاده → topup من الموزّع/الإدارة (يدفع كاش، يأخذ رصيد).
 */
class AgentNetworkService
{
    use \App\Traits\PostsToLedger;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly FinancialGuardService $guard,
        private readonly AuditService $audit,
    ) {}

    // ============================================================
    // 1. فحص الحدود قبل cash-in
    // ============================================================

    /**
     * يتحقق أن الوكيل لم يتجاوز حدوده اليومية.
     *
     * @throws RuntimeException
     */
    public function assertCashInAllowed(int $agentUserId, string $amount): void
    {
        $profile = AgentProfile::where('user_id', $agentUserId)->first();
        if (!$profile) {
            // SECURITY (AUDIT): سابقاً كانت تُرجِع بلا حدود (fail-open) — ثغرة إيداع
            // غير محدود لأي وكيل بلا profile. الآن fail-safe: نفرض سقفاً افتراضياً
            // من config (للتوافق مع الوكلاء القدامى ضمن حدّ آمن لا "بلا حدود").
            $defaultSingle = (string) config('amial.agent.default_single_transaction_limit', '100000');
            if (bccomp($amount, $defaultSingle, 4) > 0) {
                throw new RuntimeException(
                    "المبلغ يتجاوز الحد الافتراضي للعملية ({$defaultSingle} ر.ي). يلزم تفعيل ملف الوكيل."
                );
            }
            return;
        }

        if (!$profile->isActive()) {
            throw new RuntimeException('حساب الوكيل غير نشط');
        }

        // حد العملية الواحدة
        if (bccomp($amount, (string)$profile->single_transaction_limit, 4) > 0) {
            throw new RuntimeException(
                "المبلغ يتجاوز حد العملية الواحدة ({$profile->single_transaction_limit} ر.ي)"
            );
        }

        // الحد اليومي
        $todayLog = $this->getTodayFloatLog($agentUserId);
        $newDailyTotal = bcadd((string)$todayLog->cash_in_total, $amount, 4);
        if (bccomp($newDailyTotal, (string)$profile->daily_cash_in_limit, 4) > 0) {
            throw new RuntimeException(
                "هذه العملية ستتجاوز حدك اليومي للإيداع ({$profile->daily_cash_in_limit} ر.ي)"
            );
        }
    }

    // ============================================================
    // 2. تتبع السيولة
    // ============================================================

    /**
     * تسجيل حركة في سجل السيولة اليومي.
     * يُستدعى بعد كل cash-in/cash-out/topup.
     */
    public function recordFloatMovement(
        int $agentUserId,
        string $type, // cash_in, cash_out, topup
        string $amount,
        string $commission = '0',
    ): void {
        $log = $this->getTodayFloatLog($agentUserId);

        switch ($type) {
            case 'cash_in':
                $log->cash_in_total = bcadd((string)$log->cash_in_total, $amount, 4);
                break;
            case 'cash_out':
                $log->cash_out_total = bcadd((string)$log->cash_out_total, $amount, 4);
                break;
            case 'topup':
                $log->topup_total = bcadd((string)$log->topup_total, $amount, 4);
                break;
        }

        $log->commission_earned = bcadd((string)$log->commission_earned, $commission, 4);
        $log->transaction_count += 1;

        // closing float = الرصيد الحالي الفعلي
        $wallet = $this->guard->getWallet($agentUserId);
        $log->closing_float = (string)($wallet?->current_balance ?? '0');

        $log->save();
    }

    private function getTodayFloatLog(int $agentUserId): AgentFloatLog
    {
        $today = Carbon::today()->toDateString();

        $log = AgentFloatLog::firstOrNew([
            'agent_user_id' => $agentUserId,
            'log_date' => $today,
        ]);

        if (!$log->exists) {
            // opening = الرصيد الحالي
            $wallet = $this->guard->getWallet($agentUserId);
            $log->opening_float = (string)($wallet?->current_balance ?? '0');
            $log->closing_float = $log->opening_float;
            $log->save();
            $log->refresh(); // MERGE-FIX: حمّل defaults الأعمدة العشرية (تفادي null في bcmath)
        }

        return $log;
    }

    // ============================================================
    // 3. طلب topup (شراء رصيد)
    // ============================================================

    /**
     * الوكيل يطلب شراء رصيد رقمي (يدفع كاش للموزّع/الإدارة).
     */
    public function requestTopup(
        User $agent,
        string $amount,
        ?int $settledWithId = null,
        ?string $paymentMethod = 'cash',
        ?string $paymentReference = null,
    ): AgentSettlement {
        $normalized = MoneyService::normalize($amount);
        if (bccomp($normalized, '0', 4) <= 0) {
            throw new RuntimeException('المبلغ يجب أن يكون موجباً');
        }
        // AMIAL-FIX(H2): سقف عقلاني لمبلغ الشحن — يمنع الأخطاء الجسيمة/الإساءة
        // (خلق رصيد ضخم بضغطة). قابل للضبط عبر الإعداد.
        $maxTopup = (string) config('amial.agent.max_topup', '100000000');
        if (bccomp($normalized, $maxTopup, 4) > 0) {
            throw new RuntimeException("مبلغ الشحن يتجاوز الحدّ الأقصى المسموح ({$maxTopup})");
        }

        $profile = AgentProfile::where('user_id', $agent->id)->first();
        $settledWith = $settledWithId ?? ($profile?->parent_agent_id);

        return DB::transaction(function () use ($agent, $normalized, $settledWith, $paymentMethod, $paymentReference) {
            $settlement = AgentSettlement::create([
                'settlement_ulid' => (string) Str::ulid(),
                'agent_user_id' => $agent->id,
                'settled_with_id' => $settledWith ?? 0, // 0 = الإدارة
                'settlement_type' => 'topup',
                'amount' => $normalized,
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'zone_code' => 'SOUTH',
            ]);

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $agent->id,
                'subject_type' => 'agent_settlement',
                'subject_id' => $settlement->settlement_ulid,
                'action' => 'TOPUP_REQUESTED',
                'decision_code' => 'PENDING',
                'severity' => 'info',
                'context' => ['amount' => $normalized],
            ]);

            return $settlement;
        });
    }

    // ============================================================
    // 4. الموافقة على التسوية + إضافة الرصيد
    // ============================================================

    /**
     * الإدارة/الموزّع يؤكد topup → يُضاف الرصيد للوكيل + قيد ledger.
     */
    public function approveSettlement(AgentSettlement $settlement, User $approver): AgentSettlement
    {
        // AMIAL-FIX(H1): من يعتمد؟ الأدمن، أو الموزّع المُسوّى معه فقط — لا أيّ مستخدم.
        $this->assertCanApprove($settlement, $approver);

        return DB::transaction(function () use ($settlement, $approver) {
            // AMIAL-FIX(C4): أعد تحميل التسوية بقفل صفّي ثم افحص الحالة *داخل*
            // المعاملة — يمنع سباق الاعتماد المزدوج (طلبان يضيفان الرصيد مرّتين).
            $settlement = AgentSettlement::whereKey($settlement->getKey())
                ->lockForUpdate()->firstOrFail();

            if ($settlement->status !== 'pending') {
                throw new RuntimeException('التسوية ليست قيد الانتظار');
            }

            $amount = (string) $settlement->amount;

            if ($settlement->settlement_type === 'topup') {
                // الوكيل يستلم رصيد رقمي
                $this->guard->credit($settlement->agent_user_id, $amount,
                    "agent_topup:{$settlement->settlement_ulid}");

                // قيد ledger: CASH_RESERVE → agent wallet
                $reserve = $this->ledger->getOrCreateSystemAccount(
                    'CASH_RESERVE', 'asset', 'احتياطي النقد', 'debit'
                );
                $agentWallet = $this->ledger->getOrCreateUserWallet($settlement->agent_user_id);

                $journal = $this->ledger->post(
                    sourceType: 'agent_topup',
                    sourceId: $settlement->settlement_ulid,
                    description: 'شراء رصيد رقمي للوكيل',
                    lines: [
                        ['account' => $reserve->account_code, 'direction' => 'debit', 'amount' => $amount],
                        ['account' => $agentWallet->account_code, 'direction' => 'credit', 'amount' => $amount],
                    ],
                    idempotencyKey: "agent_topup_{$settlement->settlement_ulid}",
                    createdByUserId: $approver->id,
                    // AMIAL-FIX(H2): أُزيل allowNegative — قيد الشحن يزيد الاحتياطي
                    // (مدين لأصل) فلا يصير سالباً؛ إبقاؤه كان يسمح بخلق رصيد بلا غطاء.
                );

                $settlement->ledger_entry_ulid = $journal->entry_ulid;
                $this->recordFloatMovement($settlement->agent_user_id, 'topup', $amount);
            }

            $settlement->status = 'completed';
            $settlement->approved_by_id = $approver->id;
            $settlement->completed_at = now();
            $settlement->save();

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $approver->id,
                'subject_type' => 'agent_settlement',
                'subject_id' => $settlement->settlement_ulid,
                'action' => 'SETTLEMENT_APPROVED',
                'decision_code' => 'OK',
                'severity' => 'info',
                'context' => ['amount' => $amount, 'type' => $settlement->settlement_type],
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * AMIAL-FIX(H1): من يحقّ له اعتماد تسوية وكيل؟
     *   - الأدمن (النوع 0 أو دور admin/super_admin)، أو
     *   - الموزّع المُسوّى معه تحديداً (settled_with_id === المعتمِد).
     * أيّ مستخدم آخر (وكيل عادي، عميل) يُرفَض.
     */
    private function assertCanApprove(AgentSettlement $settlement, User $approver): void
    {
        $isAdmin = (int) ($approver->type ?? -1) === 0
            || in_array($approver->role ?? null, ['admin', 'super_admin'], true);

        $isSettledDistributor = (int) ($settlement->settled_with_id ?? 0) !== 0
            && (int) $settlement->settled_with_id === (int) $approver->id;

        if (!$isAdmin && !$isSettledDistributor) {
            throw new RuntimeException('لا تملك صلاحية اعتماد هذه التسوية');
        }
    }

    /**
     * AMIAL-ADMIN-AGENT-CREDIT-001 — رفض تسوية وكيل قيد الانتظار.
     * لا يُحرَّك أيّ رصيد؛ يُغلَق الطلب فقط مع سبب.
     */
    public function rejectSettlement(AgentSettlement $settlement, User $approver, ?string $reason = null): AgentSettlement
    {
        $this->assertCanApprove($settlement, $approver);

        return DB::transaction(function () use ($settlement, $approver, $reason) {
            $settlement = AgentSettlement::whereKey($settlement->getKey())
                ->lockForUpdate()->firstOrFail();

            if ($settlement->status !== 'pending') {
                throw new RuntimeException('التسوية ليست قيد الانتظار');
            }

            $settlement->status = 'rejected';
            $settlement->approved_by_id = $approver->id;
            $settlement->completed_at = now();
            $settlement->save();

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $approver->id,
                'subject_type' => 'agent_settlement',
                'subject_id' => $settlement->settlement_ulid,
                'action' => 'SETTLEMENT_REJECTED',
                'decision_code' => 'REJECTED',
                'severity' => 'warning',
                'context' => ['amount' => (string) $settlement->amount, 'reason' => $reason],
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * AMIAL-ADMIN-AGENT-CREDIT-001 — تحويل رصيد مباشر من الإدارة إلى الوكيل.
     * يُنشئ تسوية topup داخلية ويعتمدها فوراً (خطوة واحدة) → يُضاف الرصيد للوكيل
     * مع قيد ledger كامل. تُستخدم عندما تُموّل الإدارة الوكيل مباشرةً.
     */
    public function adminCreditAgent(User $agent, string $amount, User $admin, ?string $reference = null): AgentSettlement
    {
        $settlement = $this->requestTopup($agent, $amount, 0, 'internal', $reference);
        return $this->approveSettlement($settlement, $admin);
    }

    // ============================================================
    // 5. لوحة السيولة
    // ============================================================

    /**
     * AMIAL-AGENT-PORTAL-001 — كشف حركة الرصيد (السيولة) عبر فترة.
     *
     * يعيد صفوف agent_float_logs اليومية (افتتاحي/إيداعات/سحوبات/شحن/عمولة/ختامي/عدد)
     * + إجماليات الفترة. يصلح لجدول «كشف حركة الرصيد» في لوحة الوكيل.
     */
    public function getFloatStatement(int $agentUserId, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : Carbon::now()->subDays(30)->startOfDay();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();

        $logs = AgentFloatLog::where('agent_user_id', $agentUserId)
            ->whereBetween('log_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderByDesc('log_date')
            ->get();

        $rows = $logs->map(fn ($l) => [
            'date' => Carbon::parse($l->log_date)->toDateString(),
            'opening_float' => (string) $l->opening_float,
            'cash_in_total' => (string) $l->cash_in_total,       // إيداعات للعملاء (خصم سيولة)
            'cash_out_total' => (string) $l->cash_out_total,     // سحوبات من العملاء (إضافة سيولة)
            'topup_total' => (string) $l->topup_total,           // شحن رصيد
            'commission_earned' => (string) $l->commission_earned,
            'closing_float' => (string) $l->closing_float,
            'transaction_count' => (int) $l->transaction_count,
        ])->values()->all();

        $sum = fn (string $col) => (string) $logs->reduce(
            fn ($carry, $l) => bcadd($carry, (string) $l->{$col}, 4), '0'
        );

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'rows' => $rows,
            'totals' => [
                'cash_in_total' => $sum('cash_in_total'),
                'cash_out_total' => $sum('cash_out_total'),
                'topup_total' => $sum('topup_total'),
                'commission_earned' => $sum('commission_earned'),
                'transaction_count' => (int) $logs->sum('transaction_count'),
            ],
        ];
    }

    public function getFloatDashboard(int $agentUserId): array
    {
        $profile = AgentProfile::where('user_id', $agentUserId)->first();
        $todayLog = $this->getTodayFloatLog($agentUserId);
        $wallet = $this->guard->getWallet($agentUserId);
        $currentFloat = (string)($wallet?->current_balance ?? '0');

        // تنبيه السيولة المنخفضة
        $lowFloat = $profile && bccomp($currentFloat, (string)$profile->min_float_balance, 4) <= 0;

        return [
            'current_float' => $currentFloat,
            'today' => [
                'opening_float' => (string)$todayLog->opening_float,
                'cash_in_total' => (string)$todayLog->cash_in_total,
                'cash_out_total' => (string)$todayLog->cash_out_total,
                'topup_total' => (string)$todayLog->topup_total,
                'commission_earned' => (string)$todayLog->commission_earned,
                'transaction_count' => $todayLog->transaction_count,
            ],
            'limits' => $profile ? [
                'daily_cash_in_limit' => (string)$profile->daily_cash_in_limit,
                'daily_cash_in_remaining' => bcsub(
                    (string)$profile->daily_cash_in_limit,
                    (string)$todayLog->cash_in_total, 4
                ),
                'single_transaction_limit' => (string)$profile->single_transaction_limit,
            ] : null,
            'low_float_warning' => $lowFloat,
            'agent_level' => $profile?->agent_level,
        ];
    }

    /**
     * إحصاءات شبكة الموزّع (الصرّافون تحته).
     */
    public function getDistributorNetwork(int $distributorUserId): array
    {
        $distributor = AgentProfile::where('user_id', $distributorUserId)->first();
        if (!$distributor || !$distributor->isDistributor()) {
            throw new RuntimeException('ليس موزّعاً');
        }

        $subAgents = AgentProfile::where('parent_agent_id', $distributor->id)
            ->with('user:id,f_name,l_name,phone')
            ->get()
            ->map(fn($sub) => [
                'user_id' => $sub->user_id,
                'business_name' => $sub->business_name,
                'city' => $sub->location_city,
                'status' => $sub->status,
                'float' => (string)($this->guard->getWallet($sub->user_id)?->current_balance ?? '0'),
            ]);

        return [
            'distributor_id' => $distributorUserId,
            'sub_agents_count' => $subAgents->count(),
            'sub_agents' => $subAgents,
        ];
    }
}
