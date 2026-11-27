<?php

namespace App\Services;

use App\Jobs\SendAmialNotificationPushJob;
use App\Models\AmialNotification;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        'transfer_received', 'transfer_sent', 'money_received', 'transfer_completed', 'transfer_refunded',
        'cash_in', 'cash_out',
        'payment_request_received', 'payment_request_declined',
        'withdrawal_completed', 'withdrawal_failed', 'withdrawal_pending', 'withdraw_pending', 'withdraw_cancelled',
        'credit_sale', 'credit_payment', 'credit_over_limit',
        'merchant_payment_received',
        'bill_payment_success', 'bill_payment_pending', 'bill_payment_failed',
        'safe_payment_created', 'safe_payment_accepted', 'safe_payment_rejected',
        'safe_payment_delivery', 'safe_payment_released', 'safe_payment_refunded', 'safe_payment_disputed',
        'family_fund_invited', 'family_fund_contributed', 'family_fund_disbursed', 'family_fund_disbursement_proposed',
        'donation_completed',
        'merchant_verified', 'merchant_verification_rejected', 'merchant_verification_submitted', 'merchant_resubmission_required',
        'kyc_update_required', 'kyc_verification',
        'security_alert',
        'subscription_expiring', 'subscription_daily_digest',
        'refund_received', 'refund_pending',
        'system', 'promo', 'terms_update',
    ];

    /**
     * يسترجع مفتاح خدمة Firebase من business_settings أو من متغير البيئة FIREBASE_CREDENTIALS.
     */
    public static function resolveFcmServiceKey(): ?array
    {
        $raw = \App\CentralLogics\Helpers::get_business_settings('push_notification_service_file_content');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['project_id'])) {
                return $decoded;
            }
        } elseif (is_array($raw) && !empty($raw['project_id'])) {
            return $raw;
        } elseif (is_object($raw)) {
            $arr = (array) $raw;
            if (!empty($arr['project_id'])) {
                return $arr;
            }
        }

        $envCreds = (string) (config('services.firebase.credentials') ?: env('FIREBASE_CREDENTIALS', ''));
        $envCreds = trim($envCreds);
        if ($envCreds !== '') {
            if (str_starts_with($envCreds, '{')) {
                $decoded = json_decode($envCreds, true);
                if (is_array($decoded) && !empty($decoded['project_id'])) {
                    return $decoded;
                }
            } elseif (is_file($envCreds) && is_readable($envCreds)) {
                $decoded = json_decode((string) file_get_contents($envCreds), true);
                if (is_array($decoded) && !empty($decoded['project_id'])) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * هل ردّ FCM يعني أنّ رمز الجهاز لم يعد مسجّلاً (إلغاء تثبيت التطبيق أو تدوير الرمز)؟
     */
    public static function isUnregisteredFcmResponse(int $status, string $body): bool
    {
        if ($status === 404) {
            return true;
        }
        if ($status === 400 && (str_contains($body, 'UNREGISTERED') || str_contains($body, 'registration-token-not-registered'))) {
            return true;
        }
        return str_contains($body, '"errorCode":"UNREGISTERED"')
            || str_contains($body, '"errorCode": "UNREGISTERED"');
    }

    public function dispatch(
        User $user,
        string $type,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $actionUrl = null,
        ?array $data = null,
        bool $push = true,
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

        // كل قناة خارجية تنتظر commit: لا Push ولا واتساب لعملية تراجعت.
        // push=false مخصص فقط لمسار يملك Push مالي متخصصاً مسبقاً لمنع الازدواج.
        $this->queueExternalAfterCommit($notification, $user, $push);

        return $notification;
    }

    private function queueExternalAfterCommit(
        AmialNotification $notification,
        User $user,
        bool $push,
    ): void {
        $afterCommit = function () use ($notification, $user, $push): void {
            if ($push) {
                try {
                    SendAmialNotificationPushJob::dispatch(
                        userId: (int) $user->id,
                        notificationId: (int) $notification->id,
                        type: (string) $notification->type,
                        title: (string) $notification->title,
                        body: (string) $notification->body,
                        actionUrl: $notification->action_url ? (string) $notification->action_url : null,
                    );
                } catch (\Throwable $e) {
                    app(NotificationDeliveryLogService::class)->failed(
                        (int) $user->id,
                        'PUSH_QUEUE_DISPATCH_FAILED',
                        $e->getMessage(),
                        (string) $notification->type,
                        null,
                        null,
                        1,
                        true,
                        (int) $notification->id,
                    );

                    Log::warning('Amial notification push queue dispatch failed', [
                        'user_id' => $user->id,
                        'notification_id' => $notification->id,
                        'type' => $notification->type,
                        'error' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }
            }

            // AMIAL-WHATSAPP-OTP-001: القناة الثانوية أيضاً بعد commit.
            $this->echoToWhatsapp(
                $user,
                (string) $notification->type,
                (string) $notification->title,
                (string) $notification->body,
            );
        };

        try {
            DB::afterCommit($afterCommit);
        } catch (\Throwable $e) {
            // خارج transaction أو في driver قديم: لا نخسر القنوات بسبب hook.
            $afterCommit();
        }
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
            str_contains($type, 'transfer') || $type === 'money_received' => 'swap_horiz',
            $type === 'cash_in' => 'arrow_downward',
            $type === 'cash_out' => 'arrow_upward',
            str_contains($type, 'withdrawal') => 'arrow_downward',
            str_contains($type, 'withdraw') => 'arrow_downward',
            str_contains($type, 'safe_payment') => 'shield',
            str_contains($type, 'family_fund') => 'groups',
            str_contains($type, 'donation') => 'volunteer_activism',
            str_contains($type, 'refund') => 'undo',
            str_contains($type, 'credit') => 'receipt_long',
            str_contains($type, 'merchant_payment') => 'store',
            $type === 'promo' => 'local_offer',
            $type === 'terms_update' => 'description',
            default => 'notifications',
        };
    }
}
