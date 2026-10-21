<?php

namespace App\Services\Muwathaq;

use App\Models\MuwathaqOpeningEvidence;
use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * يسلم ملخص الدليل إلى قضية موثّق الموقعة.
 *
 * الملف نفسه يبقى مشفراً في أميال باي؛ موثّق يرى الحالة والبيانات اللازمة
 * للمراجعة ولا يستقبل نسخة غير محمية من المستند.
 */
class MuwathaqEvidenceBridgeService
{
    public function tryDeliver(MuwathaqOpeningEvidence $evidence): void
    {
        $evidence->loadMissing('application');
        if (!app(MuwathaqBridgeService::class)->isConfigured()) {
            $evidence->forceFill(['muwathaq_delivery_error' => 'CHANNEL_NOT_CONFIGURED'])->save();
            return;
        }

        $payload = [
            'event_version' => 1,
            'event_type' => 'account_opening.evidence_submitted',
            'evidence' => [
                'id' => $evidence->id,
                'kind' => $evidence->kind,
                'state' => $evidence->state,
                'document_received' => $evidence->file_encrypted_path !== null,
                'document_mime' => $evidence->file_mime,
                'submitted_at' => $evidence->submitted_at?->toIso8601String(),
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, MuwathaqTransportSecrets::amialToMuwathaq());

        try {
            $response = Http::acceptJson()->withHeaders([
                'X-Amial-Timestamp' => $timestamp,
                'X-Amial-Signature' => $signature,
                'Idempotency-Key' => 'evidence-'.$evidence->id.'-'.$evidence->updated_at?->timestamp,
                'Content-Type' => 'application/json',
            ])->timeout((int) config('muwathaq.timeout_seconds', 10))
                ->send('POST', rtrim((string) config('muwathaq.base_url'), '/')
                    .'/api/v1/internal/applications/'.$evidence->application->application_ulid.'/evidence', ['body' => $body]);

            if (!$response->successful()) {
                throw new DomainException('HTTP_'.$response->status());
            }

            $evidence->forceFill([
                'muwathaq_delivered_at' => now(),
                'muwathaq_delivery_error' => null,
            ])->save();
        } catch (\Throwable $error) {
            // لا نفقد الدليل: يبقى محلياً وتظهر حالته صراحةً؛ يعاد التسليم
            // عند إعادة الإرسال/المهمة المجدولة في المرحلة التالية.
            $evidence->forceFill([
                'muwathaq_delivery_error' => mb_substr($error->getMessage(), 0, 160),
            ])->save();
        }
    }
}
