<?php

namespace App\Services;

/**
 * AMIAL-DOC-QR-002 — مصدر واحد لرابط وQR التحقق في كل مستندات التاجر.
 *
 * الكود هو معرّف المستند الموثوق نفسه (ULID/رقم تحقق)، والصفحة العامة
 * تقرأ حالته الحالية من قاعدة البيانات. إعادة الطباعة لا تولّد رمزاً جديداً.
 */
class DocumentQrService
{
    public function url(string $code): string
    {
        return rtrim((string) config('app.url', 'https://amialpay.com'), '/')
            . '/v/' . rawurlencode(trim($code));
    }

    public function dataUri(string $code): ?string
    {
        if (trim($code) === '') {
            return null;
        }

        try {
            $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
                ->size(170)
                ->margin(0)
                ->generate($this->url($code));

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable) {
            return null;
        }
    }
}
