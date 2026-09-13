<?php

namespace App\Services\Reporting;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\AuditDecision;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\MerchantVerificationRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-CENTER-002 — تقارير P1 الرقابية والتشغيلية.
 *
 * قاعدة الخدمة: لا جدول تقارير موازٍ ولا أرقام واجهة. كل تقرير هنا قراءة
 * فقط من مصدر الحقيقة الأصلي، ولا ينشئ خزنةً أو يعدّل حالةً أثناء العرض.
 */
class P1ControlReportService
{
    public function merchantPortfolio(): array
    {
        if (! Schema::hasTable('merchant_profiles')) {
            return $this->unavailable('merchant_profiles');
        }

        $base = MerchantProfile::query();

        return [
            'report' => 'merchant_portfolio',
            'basis' => 'merchant_profiles',
            'total' => (clone $base)->count(),
            'verified' => (clone $base)->where('verification_status', 'verified')->count(),
            'expiring_30d' => (clone $base)
                ->whereNotNull('subscription_expires_at')
                ->whereBetween('subscription_expires_at', [now(), now()->addDays(30)])
                ->count(),
            'expired' => (clone $base)
                ->whereNotNull('subscription_expires_at')
                ->where('subscription_expires_at', '<', now())
                ->count(),
            'by_business_type' => $this->countBy('merchant_profiles', 'business_type'),
            'by_plan' => $this->countBy('merchant_profiles', 'subscription_plan'),
            'by_verification' => $this->countBy('merchant_profiles', 'verification_status'),
            'by_risk' => $this->countBy('merchant_profiles', 'risk_category'),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function kycPipeline(?string $from = null, ?string $to = null): array
    {
        $fromDate = Carbon::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay();
        $toDate = Carbon::parse($to ?: now()->toDateString())->endOfDay();

        $customer = [
            'total' => 0,
            'verified' => 0,
            'update_required' => 0,
            'by_tier' => [],
        ];

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_kyc_verified')) {
            $customers = User::query()->where('type', CUSTOMER_TYPE);
            $customer['total'] = (clone $customers)->count();
            $customer['verified'] = (clone $customers)->where('is_kyc_verified', 1)->count();
            if (Schema::hasColumn('users', 'kyc_update_required')) {
                $customer['update_required'] = (clone $customers)->where('kyc_update_required', 1)->count();
            }
            if (Schema::hasColumn('users', 'kyc_tier')) {
                $customer['by_tier'] = (clone $customers)
                    ->selectRaw('kyc_tier as value, COUNT(*) as total')
                    ->groupBy('kyc_tier')
                    ->orderBy('kyc_tier')
                    ->get()
                    ->map(fn ($row) => ['value' => (string) ($row->value ?? '0'), 'total' => (int) $row->total])
                    ->all();
            }
        }

        $merchant = [
            'submitted_in_period' => 0,
            'status_in_period' => [],
            'pending_backlog' => 0,
            'pending_aging' => ['0_1_days' => 0, '2_3_days' => 0, '4_7_days' => 0, '8_plus_days' => 0],
            'average_review_minutes' => null,
        ];

        if (Schema::hasTable('merchant_verification_requests')) {
            $period = MerchantVerificationRequest::query()
                ->whereBetween('created_at', [$fromDate, $toDate]);
            $merchant['submitted_in_period'] = (clone $period)->count();
            $merchant['status_in_period'] = (clone $period)
                ->selectRaw('status as value, COUNT(*) as total')
                ->groupBy('status')
                ->orderBy('status')
                ->get()
                ->map(fn ($row) => ['value' => (string) $row->value, 'total' => (int) $row->total])
                ->all();

            $pending = MerchantVerificationRequest::query()->where('status', 'pending_review');
            $merchant['pending_backlog'] = (clone $pending)->count();
            $merchant['pending_aging'] = [
                '0_1_days' => (clone $pending)->where('created_at', '>=', now()->subDay())->count(),
                '2_3_days' => (clone $pending)->whereBetween('created_at', [now()->subDays(3), now()->subDay()])->count(),
                '4_7_days' => (clone $pending)->whereBetween('created_at', [now()->subDays(7), now()->subDays(3)])->count(),
                '8_plus_days' => (clone $pending)->where('created_at', '<', now()->subDays(7))->count(),
            ];

            $reviewed = (clone $period)->whereNotNull('reviewed_at')->get(['created_at', 'reviewed_at']);
            if ($reviewed->isNotEmpty()) {
                $seconds = $reviewed->reduce(
                    fn (int $carry, MerchantVerificationRequest $row) => $carry
                        + max(0, $row->created_at->diffInSeconds($row->reviewed_at, false)),
                    0,
                );
                $merchant['average_review_minutes'] = (int) round(($seconds / $reviewed->count()) / 60);
            }
        }

        return [
            'report' => 'kyc_pipeline',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'basis' => 'users KYC state + merchant_verification_requests',
            'customers' => $customer,
            'merchants' => $merchant,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function agentLiquidity(?string $date = null): array
    {
        $date = $date ?: now()->toDateString();
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        if (! Schema::hasTable('agent_branches')) {
            return $this->unavailable('agent_branches') + ['date' => $date];
        }

        $branches = AgentBranch::query()->with('till')->orderBy('id')->get();
        $rows = [];
        $totals = [
            'branches' => 0,
            'active' => 0,
            'missing_till' => 0,
            'low_cash' => 0,
            'overloaded_cash' => 0,
            'unreconciled' => 0,
            'cash_on_hand' => '0.0000',
            'expected_cash' => '0.0000',
            'emoney_balance' => '0.0000',
            'difference' => '0.0000',
        ];

        foreach ($branches as $branch) {
            $totals['branches']++;
            if ($branch->is_active) $totals['active']++;

            $till = $branch->till;
            $cash = $till ? (string) $till->cash_on_hand : '0.0000';
            $emoney = (string) (EMoney::where('user_id', $branch->branch_user_id)->value('current_balance') ?? '0.0000');

            $moves = AgentCashMovement::query()
                ->where('branch_id', $branch->id)
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('id')
                ->get();

            $opening = $moves->isNotEmpty() ? (string) $moves->first()->balance_before : $cash;
            $cashIn = $moves->where('direction', 'in')
                ->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->amount, 4), '0.0000');
            $cashOut = $moves->where('direction', 'out')
                ->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->amount, 4), '0.0000');
            $expected = bcsub(bcadd($opening, $cashIn, 4), $cashOut, 4);
            $difference = bcsub($expected, $cash, 4);

            $missingTill = $till === null;
            $low = $till !== null && bccomp((string) $till->min_cash_alert, '0', 4) > 0
                && bccomp($cash, (string) $till->min_cash_alert, 4) < 0;
            $overloaded = $till !== null && bccomp((string) $till->max_cash_on_hand, '0', 4) > 0
                && bccomp($cash, (string) $till->max_cash_on_hand, 4) > 0;
            $reconciles = ! $missingTill && bccomp($difference, '0', 4) === 0;

            if ($missingTill) $totals['missing_till']++;
            if ($low) $totals['low_cash']++;
            if ($overloaded) $totals['overloaded_cash']++;
            if (! $reconciles) $totals['unreconciled']++;

            $totals['cash_on_hand'] = bcadd($totals['cash_on_hand'], $cash, 4);
            $totals['expected_cash'] = bcadd($totals['expected_cash'], $expected, 4);
            $totals['emoney_balance'] = bcadd($totals['emoney_balance'], $emoney, 4);
            $totals['difference'] = bcadd($totals['difference'], $difference, 4);

            $rows[] = [
                'branch_id' => (int) $branch->id,
                'code' => (string) $branch->code,
                'name' => (string) $branch->name,
                'active' => (bool) $branch->is_active,
                'cash_on_hand' => $cash,
                'expected_cash' => $expected,
                'difference' => $difference,
                'emoney_balance' => $emoney,
                'movements' => $moves->count(),
                'missing_till' => $missingTill,
                'low_cash' => $low,
                'overloaded_cash' => $overloaded,
                'reconciles' => $reconciles,
                'last_counted_at' => $till?->last_counted_at?->toIso8601String(),
            ];
        }

        return [
            'report' => 'agent_float',
            'date' => $date,
            'basis' => 'agent_cash_tills + append-only cash movements + e_money',
            'summary' => $totals,
            'branches' => $rows,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function auditSensitiveActions(?string $from = null, ?string $to = null, int $limit = 50): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);
        if (! Schema::hasTable('audit_decisions')) {
            return $this->unavailable('audit_decisions');
        }

        $base = AuditDecision::query()->whereBetween('created_at', [$fromDate, $toDate]);
        $actionGroups = (clone $base)
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->orderByDesc('total')
            ->get();

        $sensitiveActions = $actionGroups
            ->filter(fn ($row) => $this->isSensitiveAction((string) $row->action))
            ->pluck('action')
            ->values()
            ->all();

        $recent = empty($sensitiveActions)
            ? collect()
            : (clone $base)->whereIn('action', $sensitiveActions)
                ->orderByDesc('created_at')->limit($limit)
                ->get(['decision_id', 'actor_type', 'actor_user_id', 'subject_type', 'subject_id', 'action', 'decision_code', 'severity', 'created_at']);

        return [
            'report' => 'audit_sensitive_actions',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'basis' => 'append-only audit_decisions',
            'total_audit_events' => (clone $base)->count(),
            'sensitive_events' => empty($sensitiveActions) ? 0 : (clone $base)->whereIn('action', $sensitiveActions)->count(),
            'critical_events' => (clone $base)->where('severity', 'critical')->count(),
            'denied_or_blocked' => (clone $base)
                ->where(function ($q) {
                    $q->where('decision_code', 'LIKE', '%DENY%')
                        ->orWhere('decision_code', 'LIKE', '%BLOCK%')
                        ->orWhere('decision_code', 'LIKE', '%REJECT%');
                })->count(),
            'by_action' => $actionGroups
                ->filter(fn ($row) => in_array((string) $row->action, $sensitiveActions, true))
                ->map(fn ($row) => ['value' => (string) $row->action, 'total' => (int) $row->total])
                ->values()->all(),
            'recent' => $recent->map(fn ($row) => [
                'decision_id' => (string) $row->decision_id,
                'actor_type' => (string) $row->actor_type,
                'actor_user_id' => $row->actor_user_id ? (int) $row->actor_user_id : null,
                'subject_type' => (string) ($row->subject_type ?? ''),
                'subject_id' => $row->subject_id,
                'action' => (string) $row->action,
                'decision_code' => (string) ($row->decision_code ?? ''),
                'severity' => (string) ($row->severity ?? ''),
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function rbacChanges(?string $from = null, ?string $to = null, int $limit = 50): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);
        if (! Schema::hasTable('audit_decisions')) {
            return $this->unavailable('audit_decisions');
        }

        $base = AuditDecision::query()->whereBetween('created_at', [$fromDate, $toDate]);
        $actionGroups = (clone $base)
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')->orderByDesc('total')->get();

        $actions = $actionGroups
            ->filter(fn ($row) => $this->isRbacAction((string) $row->action))
            ->pluck('action')->values()->all();

        $recent = empty($actions)
            ? collect()
            : (clone $base)->whereIn('action', $actions)->orderByDesc('created_at')->limit($limit)
                ->get(['decision_id', 'actor_type', 'actor_user_id', 'subject_type', 'subject_id', 'action', 'decision_code', 'severity', 'created_at']);

        return [
            'report' => 'rbac_changes',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'basis' => 'append-only audit_decisions',
            'total_changes' => empty($actions) ? 0 : (clone $base)->whereIn('action', $actions)->count(),
            'by_action' => $actionGroups
                ->filter(fn ($row) => in_array((string) $row->action, $actions, true))
                ->map(fn ($row) => ['value' => (string) $row->action, 'total' => (int) $row->total])
                ->values()->all(),
            'recent' => $recent->map(fn ($row) => [
                'decision_id' => (string) $row->decision_id,
                'actor_user_id' => $row->actor_user_id ? (int) $row->actor_user_id : null,
                'subject_type' => (string) ($row->subject_type ?? ''),
                'subject_id' => $row->subject_id,
                'action' => (string) $row->action,
                'decision_code' => (string) ($row->decision_code ?? ''),
                'severity' => (string) ($row->severity ?? ''),
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function countBy(string $table, string $column): array
    {
        if (! Schema::hasColumn($table, $column)) return [];

        return DB::table($table)
            ->selectRaw("COALESCE({$column}, 'unknown') as value, COUNT(*) as total")
            ->groupBy($column)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['value' => (string) $row->value, 'total' => (int) $row->total])
            ->all();
    }

    private function period(?string $from, ?string $to): array
    {
        return [
            Carbon::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay(),
            Carbon::parse($to ?: now()->toDateString())->endOfDay(),
        ];
    }

    private function isSensitiveAction(string $action): bool
    {
        $upper = strtoupper($action);
        foreach (['FREEZE', 'UNFREEZE', 'PIN', 'PASSWORD', 'EMAIL', 'PII', 'TREASURY', 'ROLE', 'PERMISSION', 'KYC', 'AML', 'REFUND', 'EXPORT', 'SESSION', 'WALLET', 'LIMIT', 'APPROVAL'] as $needle) {
            if (str_contains($upper, $needle)) return true;
        }
        return false;
    }

    private function isRbacAction(string $action): bool
    {
        $upper = strtoupper($action);
        foreach (['ROLE', 'PERMISSION', 'RBAC', 'STAFF_ACCESS', 'ACCESS_GRANT', 'ACCESS_REVOKE'] as $needle) {
            if (str_contains($upper, $needle)) return true;
        }
        return false;
    }

    private function unavailable(string $source): array
    {
        return [
            'available' => false,
            'source' => $source,
            'reason' => 'source_table_missing',
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
