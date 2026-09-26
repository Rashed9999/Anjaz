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
 * AMIAL-NOTIFICATION-PUSH-001
 *
 * Push عام للإشعار الداخلي في amial_notifications.
 * لا يحمل payload العمل الكامل ولا FCM token في سجل الإدارة؛ فقط الحد
 * الأدنى اللازم للعرض والتنقّل، ونتيجة المزود تُكتب في سجل مستقل.
 */
class SendAmialNotificationPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $userId,
        public readonly int $notificationId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(
        FirebaseTokenService $tokenService,
        NotificationDeliveryLogService $delivery,
    ): void {
        $attempt = max(1, $this->attempts());
        $user = \App\Models\User::find($this->userId);

        if (!$user) {
            $delivery->skipped(
                $this->userId, 'USER_NOT_FOUND', $this->type, null, $attempt, $this->notificationId,
            );
            return;
        }

        if (empty($user->fcm_token)) {
            $delivery->skipped(
                $this->userId, 'FCM_TOKEN_MISSING', $this->type, null, $attempt, $this->notificationId,
            );
            return;
        }

        $serviceKey = Helpers::get_business_settings('push_notification_service_file_content');
        if (empty($serviceKey)) {
            $delivery->skipped(
                $this->userId, 'FCM_SERVICE_KEY_MISSING', $this->type, null, $attempt, $this->notificationId,
            );
            return;
        }

        $serviceKey = (array) $serviceKey;
        $projectId = trim((string) ($serviceKey['project_id'] ?? ''));
        if ($projectId === '') {
            $delivery->skipped(
                $this->userId, 'FCM_PROJECT_ID_MISSING', $this->type, null, $attempt, $this->notificationId,
            );
            return;
        }

        $accessToken = $tokenService->getAccessToken($serviceKey);
        if (!$accessToken) {
            $delivery->failed(
                $this->userId,
                'FCM_ACCESS_TOKEN_UNAVAILABLE',
                'تعذر إنشاء access token لـ FCM.',
                $this->type,
                null,
                null,
                $attempt,
                false,
                $this->notificationId,
            );
            throw new \RuntimeException('Unable to create FCM access token');
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $payload = [
            'message' => [
                'token' => $user->fcm_token,
                'data' => [
                    'title' => $this->title,
                    'body' => $this->body,
                    'image' => '',
                    'type' => $this->type,
                    'notification_id' => (string) $this->notificationId,
                    'action_url' => $this->actionUrl ?? '',
                ],
                'notification' => [
                    'title' => $this->title,
                    'body' => $this->body,
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

        if ($response->status() === 401) {
            $tokenService->invalidate($projectId);
            $delivery->failed(
                $this->userId,
                'FCM_HTTP_401',
                'رفض FCM رمز الوصول؛ تم إبطال الكاش وستعاد المحاولة.',
                $this->type,
                null,
                401,
                $attempt,
                false,
                $this->notificationId,
            );
            throw new \RuntimeException('FCM returned 401');
        }

        if (!$response->successful()) {
            $delivery->failed(
                $this->userId,
                'FCM_HTTP_' . $response->status(),
                'رفض FCM طلب الإرسال.',
                $this->type,
                null,
                $response->status(),
                $attempt,
                false,
                $this->notificationId,
            );
            throw new \RuntimeException('FCM send failed with status ' . $response->status());
        }

        $delivery->accepted(
            $this->userId,
            $this->type,
            null,
            $response->json('name'),
            $response->status(),
            $attempt,
            $this->notificationId,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendAmialNotificationPushJob permanently failed', [
            'user_id' => $this->userId,
            'notification_id' => $this->notificationId,
            'type' => $this->type,
            'error' => mb_substr($exception->getMessage(), 0, 200),
        ]);

        app(NotificationDeliveryLogService::class)->failed(
            $this->userId,
            'FCM_PERMANENT_FAILURE',
            $exception->getMessage(),
            $this->type,
            null,
            null,
            $this->tries,
            true,
            $this->notificationId,
        );
    }
}
