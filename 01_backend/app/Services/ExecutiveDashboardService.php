<?php

namespace App\Services;

use App\Models\AccountSecurityEvent;
use App\Models\EMoney;
use App\Models\PlatformFeeEntry;
use App\Models\SentinelEvent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-EXEC-DASHBOARD-001 — اللوحة التنفيذية العليا.
 *
 * يجمّع كل مؤشّرات الأداء الرئيسية (KPIs) للإدارة العليا في استدعاء واحد.
 *
 * مبدأ التصميم: **fail-safe** — كل مؤشّر معزول في try/catch ويُرجع قيمة آمنة
 * إن غاب الجدول/النموذج (لأن بعض جداول القاعدة من 6cash قد لا تكون مدموجة بعد).
 */
class ExecutiveDashboardService
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = Carbon::today();

        return [
            'generated_at' => now()->toIso8601String(),
            'wallets_total' => $this->walletsTotal(),
            'payments_today' => $this->paymentsToday($today),
            'purchases_today' => $this->purchasesToday($today),
            'active_users_today' => $this->activeUsersToday($today),
            'new_users_today' => $this->newUsersToday($today),
            'security_alerts_today' => $this->securityAlertsToday($today),
            'suspended_accounts' => $this->suspendedAccounts(),
            'revenue' => $this->revenue($today),
            'top_merchants' => $this->topMerchants($today),
            'top_fuel_stations' => $this->topFuelStations($today),
            'system_status' => $this->systemStatus(),
        ];
    }

    /** 💰 إجمالي أرصدة المحافظ. */
    private function walletsTotal(): string
    {
        return $this->safe(fn () => (string) (EMoney::sum('current_balance') ?? 0), '0');
    }

    /** 💳 حجم المدفوعات اليوم (عدد + قيمة). */
    private function paymentsToday(Carbon $today): array
    {
        return $this->safe(function () use ($today) {
            $q = Transaction::where('created_at', '>=', $today);

            return [
                'count' => (int) (clone $q)->count(),
                'volume' => (string) ((clone $q)->sum('amount') ?? 0),
            ];
        }, ['count' => 0, 'volume' => '0']);
    }

    /** 🛒 عدد عمليات الشراء اليوم (POS: تاجر/وقود/صيدلية/جملة). */
    private function purchasesToday(Carbon $today): int
    {
        $total = 0;
        foreach (['merchant_sales', 'fuel_sales', 'pharmacy_sales', 'wholesale_invoices'] as $table) {
            $total += $this->safe(
                fn () => (int) DB::table($table)->where('created_at', '>=', $today)->count(),
                0
            );
        }

        return $total;
    }

    /** 👥 المستخدمون النشطون اليوم. */
    private function activeUsersToday(Carbon $today): int
    {
        return $this->safe(fn () => (int) User::where('last_active_at', '>=', $today)->count(), 0);
    }

    /** 🆕 المستخدمون الجدد اليوم. */
    private function newUsersToday(Carbon $today): int
    {
        return $this->safe(fn () => (int) User::where('created_at', '>=', $today)->count(), 0);
    }

    /** ⚠️ التنبيهات الأمنية اليوم (Sentinel + أحداث أمن الحساب الحرجة). */
    private function securityAlertsToday(Carbon $today): array
    {
        return $this->safe(function () use ($today) {
            $sentinel = SentinelEvent::where('created_at', '>=', $today)
                ->whereIn('severity', ['warning', 'critical'])->count();
            $account = AccountSecurityEvent::where('created_at', '>=', $today)
                ->where('severity', 'critical')->count();

            return ['sentinel' => (int) $sentinel, 'account' => (int) $account, 'total' => (int) ($sentinel + $account)];
        }, ['sentinel' => 0, 'account' => 0, 'total' => 0]);
    }

    /** 🚫 الحسابات الموقوفة (security hold فعّال). */
    private function suspendedAccounts(): int
    {
        return $this->safe(fn () => (int) User::where('security_hold_until', '>', now())->count(), 0);
    }

    /** 📈 الإيرادات (رسوم اليوم + اشتراكات نشطة حسب الباقة). */
    private function revenue(Carbon $today): array
    {
        $feesToday = $this->safe(
            fn () => (string) (PlatformFeeEntry::where('created_at', '>=', $today)->sum('amount') ?? 0),
            '0'
        );

        $chargeEarned = $this->safe(
            fn () => (string) (EMoney::where('user_id', 1)->value('charge_earned') ?? 0),
            '0'
        );

        $subscriptions = $this->safe(function () {
            return DB::table('merchant_profiles')
                ->where('subscription_plan', '!=', 'free')
                ->whereNotNull('subscription_plan')
                ->select('subscription_plan', DB::raw('COUNT(*) as cnt'))
                ->groupBy('subscription_plan')
                ->pluck('cnt', 'subscription_plan')
                ->all();
        }, []);

        return [
            'fees_today' => $feesToday,
            'charge_earned_total' => $chargeEarned,
            'active_subscriptions' => $subscriptions,
        ];
    }

    /** 🏪 أكثر التجار نشاطاً اليوم. */
    private function topMerchants(Carbon $today): array
    {
        return $this->safe(function () use ($today) {
            return Transaction::where('transactions.created_at', '>=', $today)
                ->whereNotNull('to_user_id')
                ->join('users', 'users.id', '=', 'transactions.to_user_id')
                ->where('users.type', 1)
                ->select(
                    'to_user_id',
                    DB::raw("CONCAT(COALESCE(users.f_name,''),' ',COALESCE(users.l_name,'')) as name"),
                    DB::raw('COUNT(*) as tx_count'),
                    DB::raw('SUM(transactions.amount) as volume')
                )
                ->groupBy('to_user_id', 'users.f_name', 'users.l_name')
                ->orderByDesc('tx_count')
                ->limit(5)
                ->get()
                ->toArray();
        }, []);
    }

    /** ⛽ أكثر محطات الوقود مبيعاً اليوم. */
    private function topFuelStations(Carbon $today): array
    {
        return $this->safe(function () use ($today) {
            return DB::table('fuel_sales')
                ->where('fuel_sales.created_at', '>=', $today)
                ->leftJoin('fuel_stations', 'fuel_stations.id', '=', 'fuel_sales.station_id')
                ->select(
                    'fuel_sales.station_id',
                    DB::raw('MAX(fuel_stations.station_name) as station_name'),
                    DB::raw('SUM(fuel_sales.total_amount) as total'),
                    DB::raw('SUM(fuel_sales.liters) as liters')
                )
                ->groupBy('fuel_sales.station_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->toArray();
        }, []);
    }

    /** 🖥️ حالة الخوادم وواجهات الـ API. */
    private function systemStatus(): array
    {
        $database = $this->safe(function () {
            DB::select('SELECT 1');

            return true;
        }, false);

        $cache = $this->safe(function () {
            Cache::put('exec_dash_ping', 1, 5);

            return Cache::get('exec_dash_ping') === 1;
        }, false);

        $queue = $this->safe(function () {
            // عمق الطابور (إن كان driver يدعمه)
            return (int) (DB::table('jobs')->count());
        }, null);

        return [
            'api' => true, // إن وصلنا هنا فالـ API حيّ
            'database' => $database,
            'cache' => $cache,
            'queue_depth' => $queue,
        ];
    }

    /**
     * يشغّل closure ويُرجع البديل عند أي خطأ (جدول مفقود/قاعدة غير مدموجة).
     *
     * @template T
     * @param callable():T $fn
     * @param T $fallback
     * @return T
     */
    private function safe(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
