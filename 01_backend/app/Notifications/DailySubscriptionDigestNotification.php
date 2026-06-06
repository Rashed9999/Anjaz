<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * CRITICAL-001-SUBS — تقرير يومي للأدمن.
 *
 * يحتوي:
 *   - عدد الاشتراكات المنتهية اليوم (auto-expired)
 *   - عدد المنتهية خلال 7 أيام (في حاجة لتجديد)
 *   - الإيرادات اليومية
 */
class DailySubscriptionDigestNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly array $stats) {}

    public function via($notifiable): array { return ['database']; }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'subscription_daily_digest',
            'title' => '📊 تقرير الاشتراكات اليومي',
            'body' => $this->summary(),
            'stats' => $this->stats,
            'action' => 'open_subscriptions_admin',
        ];
    }

    private function summary(): string
    {
        $expired = $this->stats['expired_today'] ?? 0;
        $expiring7 = $this->stats['expiring_in_7_days'] ?? 0;
        $revenue = $this->stats['revenue_today_sar'] ?? 0;

        $lines = [];
        if ($expired > 0) $lines[] = "🔴 {$expired} اشتراك انتهى اليوم";
        if ($expiring7 > 0) $lines[] = "🟡 {$expiring7} اشتراك ينتهي خلال 7 أيام";
        if ($revenue > 0) $lines[] = "💰 إيرادات اليوم: {$revenue} ر.س";
        if (empty($lines)) return 'لا يوجد نشاط مهمّ اليوم.';
        return implode(' • ', $lines);
    }
}
