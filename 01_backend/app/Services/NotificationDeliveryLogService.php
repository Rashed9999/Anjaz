<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-NOTIFICATION-DELIVERY-001
 *
 * سجل مستقل لمحاولات التسليم الخارجي (Push / Email).
 * لا نخزن FCM token ولا عنوان البريد ولا payload ولا مفاتيح مزود.
 * provider_accepted تعني أن المزود قبل الرسالة؛ وليست دليلاً نهائياً على
 * عرض Push على الجهاز أو قراءة البريد من المستلم.
 */
class NotificationDeliveryLogService
{
    public function accepted(
        ?int $userId,
        ?string $notificationType = null,
        ?string $transactionId = null,
        ?string $providerMessageId = null,
        ?int $httpStatus = null,
        int $attempt = 1,
        ?int $notificationId = null,
        string $channel = 'fcm',
    ): void {
        $this->write([
            'user_id' => $userId,
            'channel' => $channel,
            'notification_id' => $notificationId,
            'notification_type' => $notificationType,
            'transaction_id' => $transactionId,
            'status' => 'provider_accepted',
            'attempt' => max(1, $attempt),
            'http_status' => $httpStatus,
            'provider_message_id' => $providerMessageId,
            'accepted_at' => now(),
        ]);
    }

    public function failed(
        ?int $userId,
        string $errorCode,
        ?string $errorMessage = null,
        ?string $notificationType = null,
        ?string $transactionId = null,
        ?int $httpStatus = null,
        int $attempt = 1,
        bool $permanent = false,
        ?int $notificationId = null,
        string $channel = 'fcm',
    ): void {
        $this->write([
            'user_id' => $userId,
            'channel' => $channel,
            'notification_id' => $notificationId,
            'notification_type' => $notificationType,
            'transaction_id' => $transactionId,
            'status' => $permanent ? 'permanent_failure' : 'provider_failed',
            'attempt' => max(1, $attempt),
            'http_status' => $httpStatus,
            'error_code' => mb_substr($errorCode, 0, 100),
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 500),
            'failed_at' => now(),
        ]);
    }

    public function skipped(
        ?int $userId,
        string $reason,
        ?string $notificationType = null,
        ?string $transactionId = null,
        int $attempt = 1,
        ?int $notificationId = null,
        string $channel = 'fcm',
    ): void {
        $this->write([
            'user_id' => $userId,
            'channel' => $channel,
            'notification_id' => $notificationId,
            'notification_type' => $notificationType,
            'transaction_id' => $transactionId,
            'status' => 'skipped',
            'attempt' => max(1, $attempt),
            'error_code' => mb_substr($reason, 0, 100),
        ]);
    }

    /** @param array<string,mixed> $values */
    private function write(array $values): void
    {
        try {
            if (!Schema::hasTable('notification_delivery_logs')) {
                return;
            }

            DB::table('notification_delivery_logs')->insert(array_merge([
                'channel' => 'fcm',
                'created_at' => now(),
                'updated_at' => now(),
            ], $values));
        } catch (\Throwable $e) {
            // Fail-soft: فشل إثبات الإشعار لا يغيّر نتيجة العملية المالية ولا يعيد إرسالها.
            Log::warning('Notification delivery audit write failed', [
                'user_id' => $values['user_id'] ?? null,
                'status' => $values['status'] ?? null,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }
}
