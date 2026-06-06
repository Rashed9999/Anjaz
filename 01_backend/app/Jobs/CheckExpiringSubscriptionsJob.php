<?php

namespace App\Jobs;

use App\Models\MerchantProfile;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Notifications\DailySubscriptionDigestNotification;
use App\Notifications\SubscriptionExpiringSoonNotification;
use App\Services\NotificationService;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CRITICAL-001-SUBS — Job يومي:
 *   1. يكشف الاشتراكات المنتهية → يعالجها (FREE + audit)
 *   2. يكشف الاشتراكات على وشك الانتهاء → يرسل تذكير للتاجر
 *   3. يُولّد digest للأدمن
 *
 * Schedule: يومياً الساعة 8 صباحاً.
 *
 * استدعاء يدوي:
 *   App\Jobs\CheckExpiringSubscriptionsJob::dispatchSync();
 */
class CheckExpiringSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 دقائق (لقواعد بيانات كبيرة)
    public int $tries = 2;

    public function handle(SubscriptionService $svc): void
    {
        $startedAt = now();
        \Log::info('[Subs] CheckExpiringSubscriptionsJob started');

        // 1) معالجة المنتهية فعلاً
        $expiredCount = 0;
        try {
            $expiredCount = $svc->processExpired();
        } catch (\Throwable $e) {
            \Log::error('[Subs] processExpired failed', ['error' => $e->getMessage()]);
        }

        // 2) إشعارات للتجار على وشك الانتهاء
        $notified = $this->notifyExpiring();

        // 3) digest للأدمن
        $this->sendAdminDigest($expiredCount);

        \Log::info('[Subs] Job complete', [
            'expired_processed' => $expiredCount,
            'merchants_notified' => $notified,
            'duration_seconds' => now()->diffInSeconds($startedAt),
        ]);
    }

    /**
     * يرسل تذكير لكل تاجر اشتراكه ينتهي خلال:
     *   - 7 أيام (تذكير مبكّر)
     *   - يوم واحد (تذكير عاجل)
     *
     * يستخدم Notification database — التاجر يراها في app.
     * يُمنع التكرار: لا يُرسَل أكثر من مرّة في اليوم لنفس التاجر.
     */
    private function notifyExpiring(): int
    {
        $now = now();
        $notified = 0;

        // التجار المنتهية اشتراكاتهم خلال 7 أيام (لكن أكثر من يوم — نتجنّب الإشعار اليومي المُكرّر)
        $expiring = MerchantProfile::query()
            ->with('user')
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_plan', '!=', A::PLAN_FREE)
            ->whereBetween('subscription_expires_at', [$now, $now->copy()->addDays(7)])
            ->get();

        foreach ($expiring as $profile) {
            $user = $profile->user;
            if (!$user) continue;

            $daysLeft = (int) $now->copy()->startOfDay()->diffInDays($profile->subscription_expires_at->copy()->startOfDay(), false);

            // أرسل فقط في عتبات محدّدة (7، 3، 1، 0)
            if (!in_array($daysLeft, [7, 3, 1, 0], true)) continue;

            // امنع التكرار: تحقّق إن أُرسلت إشعار في آخر 20 ساعة
            if ($this->wasNotifiedRecently($user, $daysLeft)) continue;

            try {
                $arr = (new SubscriptionExpiringSoonNotification($profile, $daysLeft))->toArray($user);
                app(NotificationService::class)->dispatch(
                    $user, 'subscription_expiring', $arr['title'], $arr['body'], data: $arr,
                );
                $notified++;
            } catch (\Throwable $e) {
                \Log::warning('[Subs] notify failed', [
                    'user_id' => $user->id, 'error' => $e->getMessage(),
                ]);
            }
        }
        return $notified;
    }

    /** يفحص قاعدة notifications: هل أُرسل لهذا التاجر بنفس days_left آخر 20 ساعة؟ */
    private function wasNotifiedRecently(User $user, int $daysLeft): bool
    {
        return \DB::table('amial_notifications')
            ->where('user_id', $user->id)
            ->where('type', 'subscription_expiring')
            ->where('created_at', '>=', now()->subHours(20))
            ->where('data->days_left', $daysLeft)
            ->exists();
    }

    /**
     * يرسل tقرير يومي لكل الأدمن — مرّة واحدة في اليوم.
     */
    private function sendAdminDigest(int $expiredCount): void
    {
        // اجمع stats سريعة
        $now = now();
        $expiringIn7 = MerchantProfile::query()
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_plan', '!=', A::PLAN_FREE)
            ->whereBetween('subscription_expires_at', [$now, $now->copy()->addDays(7)])
            ->count();

        $revenueToday = (float) SubscriptionChange::query()
            ->whereDate('created_at', $now->toDateString())
            ->whereNotNull('price_paid_sar')
            ->sum('price_paid_sar');

        $stats = [
            'expired_today' => $expiredCount,
            'expiring_in_7_days' => $expiringIn7,
            'revenue_today_sar' => round($revenueToday, 2),
            'date' => $now->toDateString(),
        ];

        // أرسل لكل الأدمن
        $admins = User::where('type', 1)->get();
        foreach ($admins as $admin) {
            // منع التكرار: digest واحد في اليوم
            $alreadySent = \DB::table('amial_notifications')
                ->where('user_id', $admin->id)
                ->where('type', 'subscription_daily_digest')
                ->where('created_at', '>=', $now->copy()->startOfDay())
                ->exists();
            if ($alreadySent) continue;

            try {
                $arr = (new DailySubscriptionDigestNotification($stats))->toArray($admin);
                app(NotificationService::class)->dispatch(
                    $admin, 'subscription_daily_digest', $arr['title'], $arr['body'], data: $arr,
                );
            } catch (\Throwable $e) {
                \Log::warning('[Subs] admin digest failed', [
                    'admin_id' => $admin->id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
