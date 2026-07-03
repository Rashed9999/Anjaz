<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Whatsapp\WhatsappBotService;
use App\Services\Whatsapp\WhatsappMediaDownloader;
use App\Services\Whatsapp\WhatsappQrDecoderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-WA-001 — يستقبل Webhooks من Meta WhatsApp Cloud API.
 *
 * AMIAL-WA-003 — يدعم أيضاً رسائل الصور (QR — Section 23): يُحمّل
 * الصورة، يفكّ شيفرتها، ويُمرّر النصّ المُفكَّك لـ WhatsappBotService
 * كأنّه نصّ مكتوب عادي (رابط/رمز طلب دفع) — لا منطق خاصّ مطلوب في
 * طبقة الـ Bot نفسها.
 *
 * Endpoints:
 *   GET  /wa/webhook  ← التحقّق من الـ Webhook عند إعداد Meta
 *   POST /wa/webhook  ← استقبال الرسائل الواردة
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsappBotService       $bot,
        private readonly WhatsappMediaDownloader  $mediaDownloader,
        private readonly WhatsappQrDecoderService $qrDecoder,
    ) {}

    /**
     * GET /wa/webhook
     * Meta يستدعيه مرّة واحدة للتحقّق أنّ السيرفر حقيقي.
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // AMIAL-FIX: من config (لا env وقت الطلب)، وبلا افتراضي مكشوف.
        $expectedToken = (string) config('services.whatsapp.verify_token', '');
        if ($expectedToken === '') {
            Log::warning('[WA Webhook] verify_token not configured — rejecting');
            return new JsonResponse(['error' => 'not configured'], 403);
        }

        if ($mode === 'subscribe' && hash_equals($expectedToken, (string) $token)) {
            Log::info('[WA Webhook] Verified successfully');
            return response((string)$challenge, 200);
        }

        Log::warning('[WA Webhook] Verification failed', [
            'mode' => $mode, 'token_match' => $token === $expectedToken,
        ]);
        return new JsonResponse(['error' => 'Verification failed'], 403);
    }

    /**
     * POST /wa/webhook
     * يستقبل كل رسالة واردة من Meta.
     */
    public function receive(Request $request): JsonResponse
    {
        // ردّ فوري بـ 200 (Meta تعيد المحاولة إن لم تستقبل 200 خلال 20 ثانية)
        // المعالجة تحدث في background بعد الردّ

        $payload = $request->all();

        // التحقّق من الـ Signature (أمان)
        if (!$this->verifySignature($request)) {
            Log::warning('[WA Webhook] Invalid signature');
            return new JsonResponse(['status' => 'ok']); // لا نكشف السبب
        }

        // AMIAL-FIX: نُعالِج بعد إرسال الردّ عبر terminating callback (داخل نفس
        // العملية، بلا تسلسل closure — كان dispatch()->afterResponse يفشل بتسلسل
        // ReflectionMethod). الردّ 200 يصل فوراً لـ Meta ثم تُعالَج الرسائل.
        app()->terminating(function () use ($payload) {
            $this->processPayload($payload);
        });

        return new JsonResponse(['status' => 'ok']);
    }

    // ============ Private ============

    private function processPayload(array $payload): void
    {
        try {
            $entries = $payload['entry'] ?? [];

            foreach ($entries as $entry) {
                $changes = $entry['changes'] ?? [];
                foreach ($changes as $change) {
                    $value    = $change['value'] ?? [];
                    $messages = $value['messages'] ?? [];

                    foreach ($messages as $msg) {
                        $this->processMessage($msg);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('[WA Webhook] processPayload error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processMessage(array $msg): void
    {
        $from = $msg['from'] ?? null;
        $type = $msg['type'] ?? 'text';

        if (!$from) return;

        // AMIAL-FIX (idempotency): Meta تُعيد إرسال الويبهوك عند timeout. نزيل تكرار
        // معالجة نفس الرسالة عبر معرّفها. Cache::add ذرّي: يعيد false لو سبق تسجيلها.
        $msgId = $msg['id'] ?? null;
        if ($msgId && !\Illuminate\Support\Facades\Cache::add("wa_msg_seen:{$msgId}", 1, now()->addHours(6))) {
            Log::info('[WA Webhook] Duplicate message ignored', ['id' => $msgId]);
            return;
        }

        // صورة QR ثقيلة المعالجة (تحميل + فكّ) — نحمّلها فقط لأرقام مربوطة (auth gate أوّلاً).
        if ($type === 'image') {
            if (!$this->bot->isLinked($from)) {
                Log::info('[WA Webhook] Image from unlinked number ignored');
                return;
            }
            $this->processImageMessage($from, $msg);
            return;
        }

        // نتعامل مع النصّ والأزرار فقط حالياً
        $body = match ($type) {
            'text'         => $msg['text']['body']         ?? '',
            'interactive'  => $msg['interactive']['button_reply']['title']
                           ?? $msg['interactive']['list_reply']['title']
                           ?? '',
            'button'       => $msg['button']['text']       ?? '',
            default        => '',
        };

        if (empty(trim($body))) {
            Log::info('[WA Webhook] Unsupported message type', [
                'from' => substr($from, 0, 6) . '****',
                'type' => $type,
            ]);
            return;
        }

        Log::info('[WA Webhook] Processing message', [
            'from_masked' => substr($from, 0, 6) . '****',
            'type'        => $type,
        ]);

        $this->bot->handle($from, trim($body));
    }

    /**
     * AMIAL-WA-003 — يُحمّل صورة QR من واتساب، يفكّ شيفرتها، ويُمرّر
     * النصّ المُفكَّك لـ Bot كأنّه رسالة نصّية عادية (رابط/رمز طلب دفع).
     */
    private function processImageMessage(string $from, array $msg): void
    {
        $mediaId = $msg['image']['id'] ?? null;
        if (!$mediaId) {
            Log::info('[WA Webhook] Image message without media id', [
                'from' => substr($from, 0, 6) . '****',
            ]);
            return;
        }

        $bytes = $this->mediaDownloader->download($mediaId);
        if (!$bytes) {
            $this->bot->notifyQrDecodeFailed($from);
            return;
        }

        $decoded = $this->qrDecoder->decode($bytes);
        if (!$decoded) {
            $this->bot->notifyQrDecodeFailed($from);
            return;
        }

        Log::info('[WA Webhook] QR decoded successfully', [
            'from_masked' => substr($from, 0, 6) . '****',
        ]);

        // النصّ المُفكَّك يُعامَل تماماً كرسالة نصّية عادية — IntentParser
        // يكتشف رابط/رمز طلب الدفع تلقائياً عبر INTENT_PAY_CODE.
        $this->bot->handle($from, $decoded);
    }

    /**
     * يتحقّق من أنّ الـ Webhook مرسَل فعلاً من Meta.
     * X-Hub-Signature-256 هي HMAC-SHA256 بـ App Secret.
     */
    private function verifySignature(Request $request): bool
    {
        // AMIAL-FIX: من config (تنجو من config:cache)، وبلا fail-open.
        $appSecret = (string) config('services.whatsapp.app_secret', '');
        if ($appSecret === '') {
            // بلا سرّ لا يمكن التحقّق → نرفض دائماً (fail-closed) حتى خارج الإنتاج.
            Log::warning('[WA Webhook] META_WA_APP_SECRET not configured — rejecting webhook');
            return false;
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $expected  = 'sha256=' . hash_hmac(
            'sha256',
            $request->getContent(),
            $appSecret
        );

        return hash_equals($expected, $signature);
    }
}
