<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-WA-003 — يُحمّل ملفّات الوسائط (صور QR) من Meta WhatsApp Cloud API.
 *
 * تحميل أيّ وسائط من واتساب خطوتان دائماً:
 *   1. GET /{media-id}  → يُعيد JSON يحوي url مؤقّتاً (صالح دقائق قليلة)
 *   2. GET {url}        → بايتات الملفّ الفعلية (يتطلّب نفس access token)
 *
 * إن لم يكن Meta هو المزوّد المُفعَّل (Twilio/360Dialog)، يُعيد null —
 * هذا محدود بمزوّد Meta حالياً (الأكثر شيوعاً لاستقبال الوسائط).
 */
class WhatsappMediaDownloader
{
    private const META_API  = 'https://graph.facebook.com/v18.0';
    private const TIMEOUT   = 15;
    // AMIAL-FIX (DoS): حدّ أقصى لحجم الوسائط المُحمَّلة (صورة QR لا تحتاج أكثر).
    private const MAX_BYTES = 3_145_728; // 3 MB

    /**
     * يُحمّل بايتات الوسائط من معرّف media_id.
     *
     * @return string|null البايتات الخام، أو null لو فشل أيّ خطوة
     */
    public function download(string $mediaId): ?string
    {
        $token = (string) config('services.whatsapp.access_token', env('META_WA_ACCESS_TOKEN', ''));
        if (empty($token)) {
            Log::warning('[WA-Media] META_WA_ACCESS_TOKEN missing — cannot download media');
            return null;
        }

        try {
            // الخطوة 1: استرجاع رابط التحميل المؤقّت
            $metaResponse = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->get(self::META_API . "/{$mediaId}");

            if (!$metaResponse->successful()) {
                Log::warning('[WA-Media] Failed to resolve media URL', [
                    'media_id' => $mediaId, 'status' => $metaResponse->status(),
                ]);
                return null;
            }

            $url = $metaResponse->json('url');
            if (empty($url)) {
                Log::warning('[WA-Media] No url in media metadata response');
                return null;
            }

            // الخطوة 2: تحميل البايتات الفعلية
            $fileResponse = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->get($url);

            if (!$fileResponse->successful()) {
                Log::warning('[WA-Media] Failed to download media bytes', [
                    'status' => $fileResponse->status(),
                ]);
                return null;
            }

            // AMIAL-FIX (DoS): ارفض الوسائط غير الصورية أو المتجاوزة للحدّ.
            $contentType = strtolower((string) $fileResponse->header('Content-Type'));
            if ($contentType !== '' && !str_starts_with($contentType, 'image/')) {
                Log::warning('[WA-Media] Rejected non-image media', ['type' => $contentType]);
                return null;
            }
            $declaredLen = (int) $fileResponse->header('Content-Length');
            if ($declaredLen > self::MAX_BYTES) {
                Log::warning('[WA-Media] Rejected oversized media', ['bytes' => $declaredLen]);
                return null;
            }
            $body = $fileResponse->body();
            if (strlen($body) > self::MAX_BYTES) {
                Log::warning('[WA-Media] Rejected oversized media (actual)', ['bytes' => strlen($body)]);
                return null;
            }

            return $body;

        } catch (\Throwable $e) {
            Log::error('[WA-Media] download exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
