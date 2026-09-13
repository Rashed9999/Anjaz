<?php

namespace App\Services\Reporting;

use App\Models\MerchantProfile;
use App\Models\SubscriptionChange;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-CENTER-003 — تقارير الأعمال والتشغيل P1.
 *
 * مهم: MRR هنا «قيمة اشتراكات حالية متكررة» لا إيراداً محاسبياً معترفاً به.
 * الإيراد المحاسبي يبقى من الدفتر. وكل مبالغ SAR هنا decimal strings بلا float.
 */
class P1BusinessOperationsReportService
{
    public function subscriptions(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);

        if (! Schema::hasTable('merchant_profiles')) {
            return $this->unavailable('merchant_profiles');
        }

        $now = now();
        $active = MerchantProfile::query()
            ->where(function ($q) use ($now) {
                $q->whereNull('subscription_expires_at')
                    ->orWhere('subscription_expires_at', '>=', $now);
            });

        $byPlan = [];
        $mrr = '0.00';
        $totalActive = 0;
        $totalPaying = 0;

        foreach (A::ALL_PLANS as $plan) {
            $count = (clone $active)->where('subscription_plan', $plan)->count();
            $price = bcadd((string) (A::PLAN_PRICES_SAR[$plan] ?? 0), '0', 2);
            $value = bcmul((string) $count, $price, 2);

            $mrr = bcadd($mrr, $value, 2);
            $totalActive += $count;
            if ($plan !== A::PLAN_FREE) $totalPaying += $count;

            $byPlan[] = [
                'code' => $plan,
                'label' => A::PLAN_LABELS[$plan] ?? $plan,
                'count' => $count,
                'monthly_price_sar' => $price,
                'monthly_contract_value_sar' => $value,
            ];
        }

        $changes = Schema::hasTable('subscription_changes')
            ? SubscriptionChange::query()->whereBetween('created_at', [$fromDate, $toDate])
            : null;

        $collected = '0.00';
        $activity = [];
        if ($changes) {
            $raw = (clone $changes)
                ->whereNotNull('price_paid_sar')
                ->selectRaw('COALESCE(SUM(price_paid_sar), 0) as total')
                ->value('total');
            $collected = bcadd((string) ($raw ?? '0'), '0', 2);

            $activity = (clone $changes)
                ->selectRaw('action as value, COUNT(*) as total')
                ->groupBy('action')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => ['value' => (string) $row->value, 'total' => (int) $row->total])
                ->all();
        }

        return [
            'report' => 'subscriptions',
            'basis' => 'merchant_profiles current state + immutable subscription_changes',
            'currency' => 'SAR',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'mrr_contract_value_sar' => $mrr,
            'arr_contract_value_sar' => bcmul($mrr, '12', 2),
            'mrr_is_accounting_revenue' => false,
            'total_active' => $totalActive,
            'total_paying' => $totalPaying,
            'by_plan' => $byPlan,
            'expiring_in_7_days' => MerchantProfile::query()
                ->whereNotNull('subscription_expires_at')
                ->where('subscription_plan', '!=', A::PLAN_FREE)
                ->whereBetween('subscription_expires_at', [$now, $now->copy()->addDays(7)])
                ->count(),
            'expiring_in_30_days' => MerchantProfile::query()
                ->whereNotNull('subscription_expires_at')
                ->where('subscription_plan', '!=', A::PLAN_FREE)
                ->whereBetween('subscription_expires_at', [$now, $now->copy()->addDays(30)])
                ->count(),
            'expired_not_processed' => MerchantProfile::query()
                ->whereNotNull('subscription_expires_at')
                ->where('subscription_plan', '!=', A::PLAN_FREE)
                ->where('subscription_expires_at', '<', $now)
                ->count(),
            'collected_in_period_sar' => $collected,
            'changes_by_action' => $activity,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function customerActivity(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);

        if (! Schema::hasTable('users')) {
            return $this->unavailable('users');
        }

        $customers = User::query()->where('type', CUSTOMER_TYPE);
        $total = (clone $customers)->count();
        $newInPeriod = (clone $customers)->whereBetween('created_at', [$fromDate, $toDate])->count();

        $transacting = null;
        $transactionsInPeriod = null;
        $dormant90 = null;
        if (Schema::hasTable('transactions')) {
            $transactionsInPeriod = DB::table('transactions as t')
                ->join('users as u', 'u.id', '=', 't.user_id')
                ->where('u.type', CUSTOMER_TYPE)
                ->whereBetween('t.created_at', [$fromDate, $toDate])
                ->count();

            $transacting = DB::table('transactions as t')
                ->join('users as u', 'u.id', '=', 't.user_id')
                ->where('u.type', CUSTOMER_TYPE)
                ->whereBetween('t.created_at', [$fromDate, $toDate])
                ->distinct()
                ->count('t.user_id');

            $cutoff = now()->subDays(90);
            $dormant90 = User::query()
                ->where('type', CUSTOMER_TYPE)
                ->where('created_at', '<=', $cutoff)
                ->whereNotExists(function ($q) use ($cutoff) {
                    $q->selectRaw('1')
                        ->from('transactions as tx')
                        ->whereColumn('tx.user_id', 'users.id')
                        ->where('tx.created_at', '>=', $cutoff);
                })->count();
        }

        return [
            'report' => 'customer_activity',
            'basis' => 'users + transaction ownership rows',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'total_customers' => $total,
            'new_customers_in_period' => $newInPeriod,
            'transacting_customers_in_period' => $transacting,
            'transaction_rows_in_period' => $transactionsInPeriod,
            'dormant_90_days' => $dormant90,
            'transactions_source_available' => Schema::hasTable('transactions'),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function supportOperations(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);

        if (! Schema::hasTable('support_tickets')) {
            return $this->unavailable('support_tickets');
        }

        $period = SupportTicket::query()->whereBetween('created_at', [$fromDate, $toDate]);
        $openStatuses = ['open', 'investigating', 'waiting_customer'];
        $openNow = SupportTicket::query()->whereIn('status', $openStatuses);

        $resolved = (clone $period)
            ->whereNotNull('resolved_at')
            ->get(['created_at', 'resolved_at']);
        $resolutionMinutes = null;
        if ($resolved->isNotEmpty()) {
            $totalMinutes = $resolved->reduce(
                fn (int $carry, SupportTicket $ticket) => $carry
                    + max(0, $ticket->created_at->diffInMinutes($ticket->resolved_at, false)),
                0,
            );
            $resolutionMinutes = (int) round($totalMinutes / $resolved->count());
        }

        return [
            'report' => 'support_sla',
            'basis' => 'support_tickets lifecycle timestamps',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'created_in_period' => (clone $period)->count(),
            'resolved_in_period' => (clone $period)->whereNotNull('resolved_at')->count(),
            'open_backlog' => (clone $openNow)->count(),
            'unassigned_backlog' => (clone $openNow)->whereNull('assigned_admin_id')->count(),
            'urgent_backlog' => (clone $openNow)->where('priority', 'urgent')->count(),
            'average_resolution_minutes' => $resolutionMinutes,
            'backlog_aging' => [
                '0_1_days' => (clone $openNow)->where('created_at', '>=', now()->subDay())->count(),
                '2_3_days' => (clone $openNow)->whereBetween('created_at', [now()->subDays(3), now()->subDay()])->count(),
                '4_7_days' => (clone $openNow)->whereBetween('created_at', [now()->subDays(7), now()->subDays(3)])->count(),
                '8_plus_days' => (clone $openNow)->where('created_at', '<', now()->subDays(7))->count(),
            ],
            'by_status' => $this->countTicketsBy($period, 'status'),
            'by_priority' => $this->countTicketsBy($period, 'priority'),
            'by_category' => $this->countTicketsBy($period, 'category'),
            // لا نخترع SLA. عند إضافة سياسة زمنية رسمية يمكن حساب breach rate.
            'sla_target_configured' => false,
            'sla_breach_rate' => null,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function countTicketsBy($query, string $column): array
    {
        return (clone $query)
            ->selectRaw("{$column} as value, COUNT(*) as total")
            ->groupBy($column)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['value' => (string) ($row->value ?? 'unknown'), 'total' => (int) $row->total])
            ->all();
    }

    private function period(?string $from, ?string $to): array
    {
        return [
            Carbon::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay(),
            Carbon::parse($to ?: now()->toDateString())->endOfDay(),
        ];
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
