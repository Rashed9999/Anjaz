<?php

namespace App\Services\Whatsapp;

use App\Models\PaymentRequest;
use App\Services\PaymentRequestService;

/**
 * AMIAL-WA-003 — يحلّ رمز/رابط طلب دفع (نصّ حرّ أو نتيجة فكّ QR) إلى
 * PaymentRequest فعلي. يعتمد على IntentParser::extractPaymentRequestCode()
 * كمصدر وحيد لمنطق الاستخراج (لا تكرار للـ regex بين الملفّين).
 */
class WhatsappPaymentRequestResolver
{
    public function __construct(
        private readonly PaymentRequestService $service,
        private readonly IntentParser $parser,
    ) {}

    /** يحلّ نصّاً حرّاً (رابط/رمز/نتيجة فكّ QR) إلى PaymentRequest. */
    public function resolve(string $text): ?PaymentRequest
    {
        $code = $this->parser->extractPaymentRequestCode($text);
        if (!$code) return null;

        return $this->service->findByCode($code);
    }
}
