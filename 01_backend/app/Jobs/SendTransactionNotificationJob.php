<?php

namespace App\Jobs;

use App\CentralLogics\Helpers;
use App\Services\FirebaseTokenService;
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

    public function handle(FirebaseTokenService $tokenService): void
    {
        $user = \App\Models\User::find($this->userId);
        if (!$user || empty($user->fcm_token)) {
            // لا fcm token — لا شيء نفعل
            return;
        }

        $messageBody = Helpers::order_status_update_message($this->transactionType);
        if (!$messageBody) {
            return;
        }

        $serviceKey = Helpers::get_business_settings('push_notification_service_file_content');
        if (empty($serviceKey)) {
            Log::warning('SendTransactionNotificationJob: missing FCM service key');
            return;
        }
        $serviceKey = (array)$serviceKey;

        $accessToken = $tokenService->getAccessToken($serviceKey);
        if (!$accessToken) {
            Log::warning('SendTransactionNotificationJob: no access token, will retry');
            $this->release(30); // ضع الـ job مرة أخرى بعد 30s
            return;
        }

        $projectId = $serviceKey['project_id'];
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        // نضع الرقم بتنسيق العرض (2 منازل عشرية)
        $displayAmount = \App\Services\MoneyService::display($this->amount);
        $description = Helpers::set_symbol((float)$displayAmount) . ' ' . $messageBody;

        $payload = [
            'message' => [
                'token' => $user->fcm_token,
                'data' => [
                    'title' => 'أميال باي',
                    'body' => $description,
                    'image' => '',
                    'type' => $this->notificationType ?? $this->transactionType,
                    'transaction_id' => $this->transactionId ?? '',
                ],
                'notification' => [
                    'title' => 'أميال باي',
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
            throw new \RuntimeException('FCM returned 401, token invalidated, retrying');
        }

        if (!$response->successful()) {
            Log::warning('FCM send failed', [
                'status' => $response->status(),
                'body_excerpt' => substr($response->body(), 0, 200),
                'user_id' => $this->userId,
                'transaction_type' => $this->transactionType,
            ]);
            // نعتبره فشل → Laravel سيُعيد المحاولة وفق $tries و $backoff
            throw new \RuntimeException('FCM send failed with status ' . $response->status());
        }
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
    }
}
