<?php

namespace App\Services;

use App\Models\AmialNotification;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

/**
 * AMIAL-NOTIFICATIONS-001 — مركز الإشعارات.
 *
 *   $svc->dispatch($user, 'transfer_received', 'حوالة واردة', 'استلمت 5000 ر.ي', data: [...]);
 *
 * كل ميزة (تحويل، سحب، ديون...) تستدعي dispatch لإشعار المستفيدين.
 */
class NotificationService
{
    /** الأنواع المعروفة (للفلترة في الـ UI). أنواع جديدة تُقبل، لكنها بلا أيقونة افتراضية. */
    public const TYPES = [
        'transfer_received', 'transfer_sent',
        'payment_request_received', 'payment_request_declined',
        'withdrawal_completed', 'withdrawal_failed', 'withdrawal_pending', 'withdraw_pending', 'withdraw_cancelled',
        'credit_sale', 'credit_payment', 'credit_over_limit',
        'merchant_payment_received',
        'merchant_verified', 'merchant_verification_rejected', 'merchant_verification_submitted', 'merchant_resubmission_required',
        'kyc_update_required',
        'refund_received', 'refund_pending',
        'system', 'promo', 'terms_update',
    ];

    public function dispatch(
        User $user,
        string $type,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $actionUrl = null,
        ?array $data = null,
    ): AmialNotification {
        if (trim($title) === '' || trim($body) === '') {
            throw new InvalidArgumentException('عنوان الإشعار ونصّه مطلوبان');
        }
        if (trim($type) === '') {
            throw new InvalidArgumentException('نوع الإشعار مطلوب');
        }

        $notification = AmialNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'icon' => $icon ?? $this->defaultIcon($type),
            'action_url' => $actionUrl,
            'data' => $data,
        ]);

        // AMIAL-WHATSAPP-OTP-001: نسخة واتساب اختيارية من الإشعار (لا تكسر الإرسال أبداً)
        $this->echoToWhatsapp($user, $type, $title, $body);

        return $notification;
    }

    /**
     * إرسال نسخة من الإشعار عبر واتساب إن كان مُفعّلاً لهذا النوع.
     *
     * التفعيل عبر addon_settings:
     *   key_name='whatsapp_notifications', settings_type='whatsapp_config',
     *   live_values = {"status":1, "types":"all"}            ← كل الأنواع
     *                أو {"status":1, "types":["transfer_received","receipt_issued"]}
     *
     * أي فشل (لا إعداد/لا هاتف/خطأ مزوّد) يُتجاهل بصمت — الإشعار الداخلي هو الأصل.
     */
    private function echoToWhatsapp(User $user, string $type, string $title, string $body): void
    {
        try {
            $cfg = \App\CentralLogics\WhatsappModule::get_settings('whatsapp_notifications');
            if (!$cfg || (int)($cfg['status'] ?? 0) !== 1) {
                return;
            }
            $types = $cfg['types'] ?? 'all';
            if ($types !== 'all' && !in_array($type, (array) $types, true)) {
                return;
            }
            $phone = (string) ($user->phone ?? '');
            if ($phone === '') {
                return;
            }
            \App\CentralLogics\WhatsappModule::sendText($phone, "{$title}\n{$body}");
        } catch (\Throwable $e) {
            // قناة ثانوية — لا تُفشل الإشعار الأساسي إطلاقاً
        }
    }

    public function listForUser(
        User $user,
        int $page = 1,
        int $perPage = 20,
        bool $unreadOnly = false,
    ): LengthAwarePaginator {
        $q = AmialNotification::where('user_id', $user->id)
            ->orderByDesc('id');
        if ($unreadOnly) $q->whereNull('read_at');
        return $q->paginate($perPage, ['*'], 'page', $page);
    }

    public function countUnread(User $user): int
    {
        return AmialNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(AmialNotification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }
    }

    public function markAllRead(User $user): int
    {
        return AmialNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function defaultIcon(string $type): ?string
    {
        return match (true) {
            str_contains($type, 'transfer') => 'swap_horiz',
            str_contains($type, 'withdrawal') => 'arrow_downward',
            str_contains($type, 'withdraw') => 'arrow_downward',
            str_contains($type, 'refund') => 'undo',
            str_contains($type, 'credit') => 'receipt_long',
            str_contains($type, 'merchant_payment') => 'store',
            $type === 'promo' => 'local_offer',
            $type === 'terms_update' => 'description',
            default => 'notifications',
        };
    }
}
