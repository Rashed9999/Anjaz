<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Log;
use Zxing\QrReader;

/**
 * AMIAL-WA-003 — يفكّ شيفرة QR من بايتات صورة خام (Section 23).
 *
 * يُستخدَم عندما يُرسل تاجر أو عميل صورة QR عبر واتساب. يعتمد على
 * khanamiryan/qrcode-detector-decoder (PHP خالص + GD — كلاهما متاح
 * مسبقاً في متطلّبات المشروع، لا حاجة لـ Imagick أو خدمة خارجية).
 *
 * ⚠️ يتطلّب: composer update (مكتبة جديدة أُضيفت لـ composer.json).
 */
class WhatsappQrDecoderService
{
    // AMIAL-FIX (image-bomb): حدود قبل تمرير الصورة لـ GD (يخصّص ذاكرة = 4 بايت/بكسل).
    private const MAX_BYTES  = 3_145_728;   // 3 MB
    private const MAX_PIXELS = 25_000_000;  // ~25MP (يمنع صور "القنبلة" صغيرة الحجم كبيرة الأبعاد)

    /**
     * يفكّ شيفرة QR من بايتات صورة (PNG/JPEG).
     *
     * @return string|null النصّ المُرمَّز، أو null لو فشل الفكّ
     */
    public function decode(string $imageBytes): ?string
    {
        if (empty($imageBytes)) {
            return null;
        }
        if (strlen($imageBytes) > self::MAX_BYTES) {
            Log::warning('[WA-QR] Image too large, rejected', ['bytes' => strlen($imageBytes)]);
            return null;
        }

        // فحص الأبعاد قبل GD (getimagesizefromstring لا يخصّص كامل الصورة).
        $info = @getimagesizefromstring($imageBytes);
        if ($info === false) {
            Log::info('[WA-QR] Not a valid image');
            return null;
        }
        if (((int) $info[0] * (int) $info[1]) > self::MAX_PIXELS) {
            Log::warning('[WA-QR] Image dimensions exceed limit (decompression-bomb guard)', [
                'w' => $info[0], 'h' => $info[1],
            ]);
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'wa_qr_');
        if ($tmpPath === false) {
            Log::error('[WA-QR] Failed to create temp file');
            return null;
        }

        try {
            file_put_contents($tmpPath, $imageBytes);

            $reader = new QrReader($tmpPath);
            $text   = $reader->text();

            if ($text === false || $text === null || trim((string) $text) === '') {
                Log::info('[WA-QR] No QR code detected in image');
                return null;
            }

            return trim((string) $text);

        } catch (\Throwable $e) {
            Log::error('[WA-QR] Decode failed', ['error' => $e->getMessage()]);
            return null;

        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
