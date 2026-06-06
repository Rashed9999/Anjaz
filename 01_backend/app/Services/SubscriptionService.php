<?php

namespace App\Services;

use App\Models\MerchantProfile;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CRITICAL-001-SUBS — خدمة إدارة الاشتراكات.
 *
 * المسؤوليات:
 *   1. تغيير خطّة + تسجيل audit log ذرّياً (transaction).
 *   2. تمديد / تجديد اشتراك.
 *   3. كشف الاشتراكات المنتهية + معالجتها (لـ cron).
 *   4. تحليلات MRR + churn + counts.
 *
 * مبدأ التصميم:
 *   - كل تغيير على MerchantProfile.subscription_plan يجب أن يمرّ من هنا.
 *   - الـ audit log immutable: لا يُحدّث ولا يُحذف.
 *   - كل عملية ذرّية (DB transaction) لمنع state غير متّسق.
 */
class SubscriptionService
{
    /**
     * تغيير خطّة تاجر مع تسجيل audit log.
     *
     * @param User $merchant التاجر المتأثّر
     * @param string $newPlan الخطّة الجديدة (must be in A::ALL_PLANS)
     * @param User|null $actor من نفّذ التغيير (null = system)
     * @param array $options [
     *     'expires_at'         => Carbon|string|null,
     *     'price_paid_sar'     => float|null,
     *     'payment_method'     => string|null,
     *     'payment_reference'  => string|null,
     *     'notes'              => string|null,
     *     'metadata'           => array|null,
     *     'action'             => string|null  // override action نوع التغيير
     * ]
     */
    public function changePlan(
        User $merchant, string $newPlan, ?User $actor = null, array $options = []
    ): SubscriptionChange {
        if (!in_array($newPlan, A::ALL_PLANS, true)) {
            throw new \InvalidArgumentException("Invalid plan: {$newPlan}");
        }

        return DB::transaction(function () use ($merchant, $newPlan, $actor, $options) {
            $profile = MerchantProfile::where('user_id', $merchant->id)->lockForUpdate()->first();
            if (!$profile) {
                throw new \RuntimeException("Merchant profile not found for user {$merchant->id}");
            }

            $oldPlan = $profile->subscription_plan ?? A::PLAN_FREE;
            $oldExpiresAt = $profile->subscription_expires_at;

            // تحديد action تلقائياً إن لم يُحدَّد
            $action = $options['action'] ?? $this->inferAction($oldPlan, $newPlan);

            // الـ expires_at الجديد:
            //   - إذا مُرِّر صراحةً: استخدمه
            //   - إذا الخطّة FREE: null (دائم)
            //   - وإلا: 30 يوماً من الآن (للخطط المدفوعة الجديدة)
            $newExpiresAt = $this->resolveExpiresAt(
                $options['expires_at'] ?? null, $newPlan, $oldPlan, $oldExpiresAt, $action,
            );

            // تطبيق التغيير على Profile
            $profile->subscription_plan = $newPlan;
            $profile->subscription_expires_at = $newExpiresAt;
            if (!empty($options['notes'])) {
                $profile->subscription_notes = $options['notes'];
            }
            $profile->save();

            // P1-BRANCHES — أنشئ الفرع الافتراضي تلقائياً للخطط التي تدعم فروعاً
            if ($newPlan !== A::PLAN_FREE && A::maxBranches($newPlan) !== 0) {
                try {
                    app(\App\Services\BranchService::class)->ensureDefaultBranch($merchant);
                } catch (\Throwable $e) {
                    \Log::warning('[Subs] ensureDefaultBranch failed', [
                        'merchant_user_id' => $merchant->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            // تسجيل في audit log
            return SubscriptionChange::create([
                'merchant_user_id' => $merchant->id,
                'actor_user_id' => $actor?->id,
                'actor_role' => $actor
                    ? SubscriptionChange::ACTOR_ADMIN
                    : SubscriptionChange::ACTOR_SYSTEM,
                'action' => $action,
                'old_plan' => $oldPlan,
                'old_expires_at' => $oldExpiresAt,
                'new_plan' => $newPlan,
                'new_expires_at' => $newExpiresAt,
                'price_paid_sar' => $options['price_paid_sar'] ?? null,
                'payment_method' => $options['payment_method'] ?? null,
                'payment_reference' => $options['payment_reference'] ?? null,
                'notes' => $options['notes'] ?? null,
                'metadata' => $options['metadata'] ?? null,
            ]);
        });
    }

    /**
     * تمديد اشتراك بإضافة أيام (دون تغيير خطّة).
     */
    public function extend(
        User $merchant, int $days, ?User $actor = null, array $options = []
    ): SubscriptionChange {
        if ($days <= 0) {
            throw new \InvalidArgumentException("Days must be positive: {$days}");
        }

        $profile = MerchantProfile::where('user_id', $merchant->id)->first();
        if (!$profile) {
            throw new \RuntimeException("Merchant profile not found");
        }
        $currentPlan = $profile->subscription_plan ?? A::PLAN_FREE;
        if ($currentPlan === A::PLAN_FREE) {
            throw new \LogicException('Cannot extend a FREE plan');
        }

        // النقطة المرجعية: إن كان الاشتراك ساري، أضف على expires_at
        // إن كان منتهياً، أضف من الآن
        $baseTime = ($profile->subscription_expires_at && $profile->subscription_expires_at->isFuture())
            ? $profile->subscription_expires_at
            : now();

        return $this->changePlan($merchant, $currentPlan, $actor, array_merge($options, [
            'expires_at' => $baseTime->copy()->addDays($days),
            'action' => SubscriptionChange::ACTION_EXTEND,
            'metadata' => array_merge($options['metadata'] ?? [], ['extended_days' => $days]),
        ]));
    }

    /**
     * تجديد سريع (renew) — نفس الخطّة بـ 30 يوم إضافية من الآن.
     */
    public function renew(User $merchant, ?User $actor = null, array $options = []): SubscriptionChange
    {
        $profile = MerchantProfile::where('user_id', $merchant->id)->first();
        if (!$profile) throw new \RuntimeException("Merchant profile not found");
        $currentPlan = $profile->subscription_plan ?? A::PLAN_FREE;
        if ($currentPlan === A::PLAN_FREE) {
            throw new \LogicException('Cannot renew a FREE plan');
        }

        return $this->changePlan($merchant, $currentPlan, $actor, array_merge($options, [
            'expires_at' => now()->addDays(30),
            'action' => SubscriptionChange::ACTION_RENEW,
        ]));
    }

    // ============ Expiry Processing (للـ cron) ============

    /**
     * يجد كل الاشتراكات المنتهية فعلاً ولم تُعالَج بعد.
     * يُعيد كل واحد إلى FREE + يسجّل expire_auto.
     *
     * يجب استدعاؤه يومياً من cron.
     *
     * @return int عدد الاشتراكات التي تمّت معالجتها
     */
    public function processExpired(): int
    {
        $now = now();
        $expired = MerchantProfile::query()
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<', $now)
            ->where('subscription_plan', '!=', A::PLAN_FREE)
            ->get();

        $count = 0;
        foreach ($expired as $profile) {
            try {
                $user = User::find($profile->user_id);
                if (!$user) continue;
                $this->changePlan($user, A::PLAN_FREE, null, [
                    'action' => SubscriptionChange::ACTION_EXPIRE_AUTO,
                    'notes' => 'انتهت مدّة الاشتراك تلقائياً',
                    'expires_at' => null,
                ]);
                $count++;
            } catch (\Throwable $e) {
                \Log::error('Failed to expire subscription', [
                    'merchant_user_id' => $profile->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    /**
     * يُرجع الاشتراكات المنتهية خلال $days المقبلة (لإرسال تذكيرات).
     */
    public function expiringSoon(int $days = 7): \Illuminate\Support\Collection
    {
        $now = now();
        $cutoff = $now->copy()->addDays($days);

        return MerchantProfile::query()
            ->with('user')
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_plan', '!=', A::PLAN_FREE)
            ->whereBetween('subscription_expires_at', [$now, $cutoff])
            ->orderBy('subscription_expires_at')
            ->get();
    }

    // ============ Analytics (للـ Admin Dashboard) ============

    /**
     * MRR (Monthly Recurring Revenue) + KPIs.
     *
     * المنطق:
     *   - لكل خطّة نشطة، ضرب عدد المشتركين × السعر الشهري.
     *   - الخطط السنوية تُحسب كـ /12 على شهر.
     *   - FREE = 0 (مجاناً، لا revenue).
     */
    public function summary(): array
    {
        $now = now();

        // عدّاد لكل خطّة (active فقط — الاشتراكات السارية)
        $activeCounts = MerchantProfile::query()
            ->where(function ($q) use ($now) {
                $q->whereNull('subscription_expires_at')
                  ->orWhere('subscription_expires_at', '>=', $now);
            })
            ->groupBy('subscription_plan')
            ->selectRaw('subscription_plan, COUNT(*) as cnt')
            ->pluck('cnt', 'subscription_plan')
            ->toArray();

        $mrr = 0.0;
        $byPlan = [];
        foreach (A::ALL_PLANS as $code) {
            $count = (int)($activeCounts[$code] ?? 0);
            $monthlyPrice = (float) (A::PLAN_PRICES_SAR[$code] ?? 0);
            $planRevenue = $count * $monthlyPrice;
            $mrr += $planRevenue;
            $byPlan[] = [
                'code' => $code,
                'label' => A::PLAN_LABELS[$code] ?? $code,
                'count' => $count,
                'price_monthly_sar' => $monthlyPrice,
                'revenue_monthly_sar' => round($planRevenue, 2),
            ];
        }

        // المنتهية قريباً
        $expiringIn7 = MerchantProfile::query()
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_plan', '!=', A::PLAN_FREE)
            ->whereBetween('subscription_expires_at',
                [$now, $now->copy()->addDays(7)])
            ->count();

        $expiringIn30 = MerchantProfile::query()
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_plan', '!=', A::PLAN_FREE)
            ->whereBetween('subscription_expires_at',
                [$now, $now->copy()->addDays(30)])
            ->count();

        $expired = MerchantProfile::query()
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_plan', '!=', A::PLAN_FREE)
            ->where('subscription_expires_at', '<', $now)
            ->count();

        // إحصائيات الأنشطة آخر 30 يوم
        $last30 = $now->copy()->subDays(30);
        $recentActivity = SubscriptionChange::query()
            ->where('created_at', '>=', $last30)
            ->groupBy('action')
            ->selectRaw('action, COUNT(*) as cnt')
            ->pluck('cnt', 'action')
            ->toArray();

        // الإيرادات المُحصَّلة فعلياً (آخر 30 يوم)
        $revenue30 = (float) SubscriptionChange::query()
            ->where('created_at', '>=', $last30)
            ->whereNotNull('price_paid_sar')
            ->sum('price_paid_sar');

        return [
            'mrr_sar' => round($mrr, 2),
            'arr_sar' => round($mrr * 12, 2),
            'total_active' => array_sum($activeCounts),
            'total_paying' => array_sum(array_filter($activeCounts,
                fn($_, $k) => $k !== A::PLAN_FREE, ARRAY_FILTER_USE_BOTH)),
            'by_plan' => $byPlan,
            'expiring_in_7_days' => $expiringIn7,
            'expiring_in_30_days' => $expiringIn30,
            'expired_not_processed' => $expired,
            'last_30_days' => [
                'revenue_collected_sar' => round($revenue30, 2),
                'upgrades' => (int)($recentActivity['upgrade'] ?? 0),
                'downgrades' => (int)($recentActivity['downgrade'] ?? 0),
                'renewals' => (int)($recentActivity['renew'] ?? 0),
                'auto_expirations' => (int)($recentActivity['expire_auto'] ?? 0),
            ],
        ];
    }

    // ============ Helpers ============

    /** يستنتج نوع الـ action من قيم الـ plans قبل وبعد. */
    private function inferAction(string $oldPlan, string $newPlan): string
    {
        if ($oldPlan === $newPlan) return SubscriptionChange::ACTION_RENEW;

        $rank = [
            A::PLAN_FREE => 0, A::PLAN_STARTER => 1, A::PLAN_BUSINESS => 2,
            A::PLAN_MERCHANT_PRO => 3, A::PLAN_ENTERPRISE => 4,
        ];
        $oldR = $rank[$oldPlan] ?? 0;
        $newR = $rank[$newPlan] ?? 0;

        if ($newR > $oldR) return SubscriptionChange::ACTION_UPGRADE;
        if ($newR < $oldR) return SubscriptionChange::ACTION_DOWNGRADE;
        return SubscriptionChange::ACTION_CHANGE_PLAN;
    }

    private function resolveExpiresAt(
        $explicit, string $newPlan, string $oldPlan, ?Carbon $oldExp, string $action,
    ): ?Carbon {
        if ($explicit !== null) {
            return $explicit instanceof Carbon ? $explicit : Carbon::parse($explicit);
        }
        if ($newPlan === A::PLAN_FREE) return null;
        // EXPIRE_AUTO يقبل null كما هو
        if ($action === SubscriptionChange::ACTION_EXPIRE_AUTO) return null;
        // افتراضي: 30 يوم من الآن للخطط الجديدة
        return now()->addDays(30);
    }
}
