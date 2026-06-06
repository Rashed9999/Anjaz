<?php

namespace App\Notifications;

use App\Models\MerchantProfile;
use App\Support\Access\AccessConstants as A;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * CRITICAL-001-SUBS — تذكير "الاشتراك ينتهي قريباً" للتاجر.
 *
 * يُرسَل في:
 *   - 7 أيام قبل الانتهاء
 *   - يوم واحد قبل الانتهاء
 *   - يوم الانتهاء نفسه
 */
class SubscriptionExpiringSoonNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly MerchantProfile $profile,
        public readonly int $daysLeft,
    ) {}

    public function via($notifiable): array
    {
        return ['database']; // Firebase/SMS لاحقاً (الآن DB فقط)
    }

    public function toArray($notifiable): array
    {
        $plan = $this->profile->subscription_plan;
        $urgency = match(true) {
            $this->daysLeft <= 0 => 'critical',
            $this->daysLeft <= 1 => 'high',
            $this->daysLeft <= 7 => 'medium',
            default => 'low',
        };

        return [
            'type' => 'subscription_expiring',
            'title' => $this->title(),
            'body' => $this->body(),
            'urgency' => $urgency,
            'days_left' => $this->daysLeft,
            'current_plan' => $plan,
            'current_plan_label' => A::PLAN_LABELS[$plan] ?? $plan,
            'expires_at' => $this->profile->subscription_expires_at?->toIso8601String(),
            'action' => 'open_plans_catalog',
        ];
    }

    private function title(): string
    {
        if ($this->daysLeft <= 0) return '⚠️ انتهى اشتراكك';
        if ($this->daysLeft === 1) return '⏰ اشتراكك ينتهي غداً';
        return "⏰ اشتراكك ينتهي خلال {$this->daysLeft} أيام";
    }

    private function body(): string
    {
        $planLabel = A::PLAN_LABELS[$this->profile->subscription_plan]
                  ?? $this->profile->subscription_plan;

        if ($this->daysLeft <= 0) {
            return "انتهى اشتراك خطّة {$planLabel}. أنت الآن على الخطّة المجانية. "
                 . 'للاستمرار في استخدام كل الميزات، جدّد اشتراكك.';
        }
        return "اشتراك خطّة {$planLabel} ينتهي قريباً. جدّد لتجنّب انقطاع الخدمة.";
    }
}
