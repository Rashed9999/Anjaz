<?php

namespace App\Jobs;

use App\CentralLogics\Helpers;
use App\Services\FirebaseTokenService;
use App\Services\NotificationDeliveryLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * Job لإرسال إشعار FCM. ينفّذ خارج DB::transaction المالية.
 *
 * المشكلة المُحلولة (AUDIT 1.9):
 *   Helpers::send_transaction_notification كانت تُستدعى داخل DB::transaction،
 *   فتقفل صفوف المحفظة طوال HTTP roundtrip لـ FCM (2 requests، ~500ms).
 *   تحت الحمل: deadlocks ورصد كاذب.
 *
 * الحل: TransactionTrait تُنشر هذا Job بعد commit الـ DB transaction.
 * Laravel يدعم `DB::afterCommit()` — نستخدمه في الـ trait.
 *
 * Retries: 3 محاولات مع backoff [10s, 60s, 300s]. بعدها يفشل بصمت.
 * إشعار FCM ليس critical — لو فشل، الرصيد المُحدّث في التطبيق يخبر المستخدم.
 */
class SendTransactionNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $userId,
        public readonly string $amount,           // decimal string
        public readonly string $transactionType,  // SEND_MONEY, RECEIVED_MONEY, ...
        public readonly ?string $notificationType = null,
        public readonly ?string $transactionId = null,
    ) {
        // وضع الـ job على queue اسمها 'notifications' (low priority)
        $this->onQueue('notifications');
    }

    public function handle(FirebaseTokenService $tokenService, NotificationDeliveryLogService $delivery): void
    {
        $attempt = max(1, $this->attempts());
        $user = \App\Models\User::find($this->userId);
        if (!$user) {
            $delivery->skipped($this->userId, 'USER_NOT_FOUND', $this->notificationType ?? $this->transactionType, $this->transactionId, $attempt);
            return;
        }

        // AMIAL-NOTIF-INAPP-SYNC-001: ضمان إنشاء إشعار داخل التطبيق (amial_notifications)
        // لكل حركة مالية تمرّ عبر TransactionTrait حتى لو لم يكن لدى المستخدم fcm_token بعد،
        // وبلا تكرار إن سبق للخدمة الطالبة إنشاء الإشعار (مثل PaymentRequestService مع push:false).
        $inApp = $this->ensureInAppNotification($user);

        $fcmToken = trim((string) ($user->fcm_token ?? ''));
        if ($fcmToken === '' || $fcmToken === '@') {
            if ($fcmToken === '@') {
                $user->forceFill(['fcm_token' => null])->save();
            }
            $delivery->skipped($this->userId, 'FCM_TOKEN_MISSING', $this->notificationType ?? $this->transactionType, $this->transactionId, $attempt);
            return;
        }

        $messageBody = Helpers::order_status_update_message($this->transactionType);
        $description = null;
        if ($messageBody) {
            $displayAmount = \App\Services\MoneyService::display($this->amount);
            $description = Helpers::set_symbol((float) $displayAmount) . ' ' . $messageBody;
        } elseif ($inApp !== null) {
            $description = $inApp->body;
        }

        if (!$description) {
            $delivery->skipped($this->userId, 'NOTIFICATION_MESSAGE_DISABLED', $this->notificationType ?? $this->transactionType, $this->transactionId, $attempt);
            return;
        }

        $serviceKey = \App\Services\NotificationService::resolveFcmServiceKey();
        if (empty($serviceKey)) {
            Log::warning('SendTransactionNotificationJob: missing FCM service key');
            $delivery->skipped($this->userId, 'FCM_SERVICE_KEY_MISSING', $this->notificationType ?? $this->transactionType, $this->transactionId, $attempt);
            return;
        }

        $accessToken = $tokenService->getAccessToken($serviceKey);
        if (!$accessToken) {
            Log::warning('SendTransactionNotificationJob: no access token, will retry');
            $delivery->failed(
                $this->userId,
                'FCM_ACCESS_TOKEN_UNAVAILABLE',
                'تعذر إنشاء access token لـ FCM.',
                $this->notificationType ?? $this->transactionType,
                $this->transactionId,
                null,
                $attempt,
            );
            $this->release(30); // ضع الـ job مرة أخرى بعد 30s
            return;
        }

        $projectId = $serviceKey['project_id'];
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $title = $inApp?->title ?: 'أميال باي';

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'data' => [
                    'title' => $title,
                    'body' => $description,
                    'image' => '',
                    'type' => $this->notificationType ?? $this->transactionType,
                    'transaction_id' => $this->transactionId ?? '',
                    'notification_id' => $inApp ? (string) $inApp->id : '',
                    'action_url' => $inApp?->action_url ?? '/receipts',
                ],
                'notification' => [
                    'title' => $title,
                    'body' => $description,
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'channel_id' => 'amial_pay_default',
                        'sound' => 'notification',
                    ],
                ],
                'apns' => [
                    'payload' => ['aps' => ['sound' => 'notification.wav']],
                ],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ])->timeout(10)->post($url, $payload);

        // 401 = token expired/invalid → invalidate cache و retry
        if ($response->status() === 401) {
            $tokenService->invalidate($projectId);
            $delivery->failed(
                $this->userId,
                'FCM_HTTP_401',
                'رفض FCM رمز الوصول؛ تم إبطال الكاش وستعاد المحاولة.',
                $this->notificationType ?? $this->transactionType,
                $this->transactionId,
                401,
                $attempt,
            );
            throw new \RuntimeException('FCM returned 401, token invalidated, retrying');
        }

        if (\App\Services\NotificationService::isUnregisteredFcmResponse($response->status(), $response->body())) {
            \App\Models\User::where('id', $user->id)
                ->where('fcm_token', $fcmToken)
                ->update(['fcm_token' => null]);
            $delivery->skipped(
                $this->userId,
                'FCM_TOKEN_UNREGISTERED',
                $this->notificationType ?? $this->transactionType,
                $this->transactionId,
                $attempt,
            );
            return;
        }

        if (!$response->successful()) {
            Log::warning('FCM send failed', [
                'status' => $response->status(),
                'body_excerpt' => substr($response->body(), 0, 200),
                'user_id' => $this->userId,
                'transaction_type' => $this->transactionType,
            ]);
            $delivery->failed(
                $this->userId,
                'FCM_HTTP_' . $response->status(),
                'رفض FCM طلب الإرسال.',
                $this->notificationType ?? $this->transactionType,
                $this->transactionId,
                $response->status(),
                $attempt,
            );
            // نعتبره فشل → Laravel سيُعيد المحاولة وفق $tries و $backoff
            throw new \RuntimeException('FCM send failed with status ' . $response->status());
        }

        $delivery->accepted(
            $this->userId,
            $this->notificationType ?? $this->transactionType,
            $this->transactionId,
            $response->json('name'),
            $response->status(),
            $attempt,
        );
    }

    private function ensureInAppNotification(\App\Models\User $user): ?\App\Models\AmialNotification
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('amial_notifications')) {
                return null;
            }

            $recent = \App\Models\AmialNotification::where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(8)
                ->get();

            if ($this->transactionId !== null && $this->transactionId !== '') {
                foreach ($recent as $item) {
                    $data = is_array($item->data) ? $item->data : [];
                    if (($data['transaction_id'] ?? null) === $this->transactionId) {
                        return $item;
                    }
                }
            }

            [$type, $title, $fallbackBody] = $this->resolveInAppTemplate();
            $customMsg = Helpers::order_status_update_message($this->transactionType);
            $displayAmount = \App\Services\MoneyService::display($this->amount);
            $body = $customMsg
                ? (Helpers::set_symbol((float) $displayAmount) . ' ' . $customMsg)
                : $fallbackBody;

            // حماية إضافية من التكرار عند غياب transactionId (مثل PaymentRequestService مع push:false)
            foreach ($recent as $item) {
                if (
                    $item->created_at !== null
                    && $item->created_at->gt(now()->subSeconds(90))
                    && ($item->type === $type || $item->type === ($this->notificationType ?? ''))
                ) {
                    return $item;
                }
            }

            return app(\App\Services\NotificationService::class)->dispatch(
                user: $user,
                type: $type,
                title: $title,
                body: $body,
                actionUrl: '/receipts',
                data: array_filter([
                    'transaction_id' => $this->transactionId,
                    'transaction_type' => $this->transactionType,
                    'amount' => (string) $this->amount,
                ], fn ($v) => $v !== null && $v !== ''),
                push: false, // هذا الـ Job نفسه يتكفّل بإرسال FCM مباشرةً دون إقحام Job ثانٍ
            );
        } catch (\Throwable $e) {
            Log::warning('SendTransactionNotificationJob: in-app notification skipped', [
                'user_id' => $user->id,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            return null;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveInAppTemplate(): array
    {
        $amt = \App\CentralLogics\Helpers::money((string) $this->amount);
        $raw = strtolower($this->notificationType ?? $this->transactionType);

        return match ($raw) {
            'received_money', 'money_received' => [
                'money_received',
                'استلمت حوالة مالية',
                "وصلك مبلغ {$amt} ر.ي إلى محفظتك.",
            ],
            'send_money', 'transfer_sent' => [
                'transfer_sent',
                'تم إرسال الحوالة',
                "تم تحويل مبلغ {$amt} ر.ي من محفظتك بنجاح.",
            ],
            'cash_in', 'add_money' => [
                'cash_in',
                'تم إيداع رصيد في محفظتك',
                "تمت إضافة مبلغ {$amt} ر.ي إلى رصيد محفظتك.",
            ],
            'cash_out', 'withdraw' => [
                'cash_out',
                'تم السحب النقدي',
                "تم تنفيذ سحب نقدي بمبلغ {$amt} ر.ي من محفظتك.",
            ],
            'merchant_received', 'merchant_payment_received' => [
                'merchant_payment_received',
                'استلمت دفعة مبيعات',
                "تم تحصيل مبلغ {$amt} ر.ي في حساب التاجر.",
            ],
            'merchant_payment' => [
                'transfer_sent',
                'تم الدفع للتاجر بنجاح',
                "تم خصم مبلغ {$amt} ر.ي مقابل عملية شراء.",
            ],
            'credit_payment' => [
                'credit_payment',
                'تسديد مديونية',
                "تم تسجيل سداد بمبلغ {$amt} ر.ي.",
            ],
            default => [
                $this->notificationType ?: 'system',
                'إشعار عملية مالية',
                "تم تسجيل عملية مالية بمبلغ {$amt} ر.ي في حسابك.",
            ],
        };
    }

    /**
     * بعد استنفاذ المحاولات. نلوغ ولا نرمي.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendTransactionNotificationJob permanently failed', [
            'user_id' => $this->userId,
            'transaction_id' => $this->transactionId,
            'error' => $exception->getMessage(),
        ]);

        app(NotificationDeliveryLogService::class)->failed(
            $this->userId,
            'FCM_PERMANENT_FAILURE',
            $exception->getMessage(),
            $this->notificationType ?? $this->transactionType,
            $this->transactionId,
            null,
            $this->tries,
            true,
        );
    }
}
