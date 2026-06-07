<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client as TwilioClient;

/**
 * AMIAL-REFACTOR-CORE-001 — REPLACE
 *
 * SmsModule المعاد كتابته. التغييرات (راجع AUDIT_v0.6.md 1.12):
 *
 *   1. حذف كل `echo` — كانت تلوث JSON responses (السطر 79 الأصلي).
 *   2. كل خطأ → Log::warning أو error مع context.
 *   3. منطق two_factor و msg_91 المضطرب أُصلح:
 *      - لا نكتب فوق $response بنتيجة الـ API ثم نتجاهلها.
 *      - نقرأ status code فعلياً ونتحقق منه.
 *   4. timeout موحّد (10s) — يمنع توقف الـ request لو SMS provider بطيء.
 *   5. استبدال curl_init الخام بـ Http facade — أسهل في testing و monitoring.
 *   6. لا نلوغ OTP الفعلي — فقط hash مختصر للـ trace (سياسة قسم 23.8).
 *
 * يبقى البصمة (signature) من send() ودوال providers متطابقة للخلف.
 */
class SmsModule
{
    /** Timeout موحّد لكل HTTP requests (ثوانٍ) */
    private const HTTP_TIMEOUT = 10;

    /**
     * نقطة الدخول التاريخية لإرسال OTP (يستدعيها ~14 موضعاً).
     *
     * AMIAL-WHATSAPP-OTP-001: صارت تفويضاً شفّافاً إلى OtpDispatcher الذي يُجرّب
     * **واتساب أولاً ثم SMS** (حسب التفضيل). كل المتصلين القدامى يكتسبون واتساب
     * تلقائياً دون أي تغيير. مسار الـ SMS الفعلي في sendViaSmsProviders() أدناه.
     */
    public static function send(string $receiver, string $otp): string
    {
        return OtpDispatcher::send($receiver, $otp);
    }

    /**
     * مسار SMS الخالص (يُجرّب مزوّدي الـ SMS بالترتيب). يستدعيه OtpDispatcher.
     * يعيد 'success' | 'error' | 'not_found'.
     */
    public static function sendViaSmsProviders(string $receiver, string $otp): string
    {
        $providers = ['twilio', 'nexmo', '2factor', 'msg91'];

        foreach ($providers as $name) {
            $config = self::get_settings($name);
            if (isset($config) && (int)($config['status'] ?? 0) === 1) {
                return match ($name) {
                    'twilio' => self::twilio($receiver, $otp),
                    'nexmo' => self::nexmo($receiver, $otp),
                    '2factor' => self::two_factor($receiver, $otp),
                    'msg91' => self::msg_91($receiver, $otp),
                };
            }
        }

        Log::warning('SmsModule: no enabled provider found');
        return 'not_found';
    }

    public static function twilio(string $receiver, string $otp): string
    {
        $config = self::get_settings('twilio');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $message = str_replace('#OTP#', $otp, $config['otp_template'] ?? '#OTP#');
            $sid = $config['sid'] ?? '';
            $token = $config['token'] ?? '';

            if (empty($sid) || empty($token)) {
                Log::warning('SmsModule.twilio: missing sid/token in config');
                return 'error';
            }

            $twilio = new TwilioClient($sid, $token);
            $twilio->messages->create($receiver, [
                'messagingServiceSid' => $config['messaging_service_sid'] ?? '',
                'body' => $message,
            ]);

            self::logSent('twilio', $receiver);
            return 'success';

        } catch (\Throwable $e) {
            self::logFailure('twilio', $receiver, $e->getMessage());
            return 'error';
        }
    }

    public static function nexmo(string $receiver, string $otp): string
    {
        $config = self::get_settings('nexmo');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $message = str_replace('#OTP#', $otp, $config['otp_template'] ?? '#OTP#');

            // AMIAL-REFACTOR-CORE-001: استخدام Http بدل curl_init
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->asForm()
                ->post('https://rest.nexmo.com/sms/json', [
                    'from' => $config['from'] ?? '',
                    'text' => $message,
                    'to' => $receiver,
                    'api_key' => $config['api_key'] ?? '',
                    'api_secret' => $config['api_secret'] ?? '',
                ]);

            if (!$response->successful()) {
                self::logFailure('nexmo', $receiver, 'HTTP ' . $response->status());
                return 'error';
            }

            // Nexmo يعيد JSON: { messages: [ { status: '0' = success } ] }
            $body = $response->json();
            $status = $body['messages'][0]['status'] ?? null;
            if ($status !== '0' && $status !== 0) {
                self::logFailure('nexmo', $receiver, 'Nexmo status=' . $status);
                return 'error';
            }

            self::logSent('nexmo', $receiver);
            return 'success';

        } catch (\Throwable $e) {
            self::logFailure('nexmo', $receiver, $e->getMessage());
            return 'error';
        }
    }

    public static function two_factor(string $receiver, string $otp): string
    {
        $config = self::get_settings('2factor');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $apiKey = $config['api_key'] ?? '';
            if (empty($apiKey)) {
                return 'error';
            }

            // AMIAL-REFACTOR-CORE-001: استبدال curl + إصلاح المنطق المضطرب
            // الأصلي كان يكتب فوق $response بقيمة curl_exec ثم يستبدله بـ 'success'/'error'
            // النتيجة: قراءة الـ API ضائعة. الآن نتحقق فعلاً من status code.
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->get("https://2factor.in/API/V1/{$apiKey}/SMS/{$receiver}/{$otp}");

            if (!$response->successful()) {
                self::logFailure('2factor', $receiver, 'HTTP ' . $response->status());
                return 'error';
            }

            $body = $response->json();
            if (($body['Status'] ?? null) !== 'Success') {
                self::logFailure('2factor', $receiver, 'API Status=' . ($body['Status'] ?? 'null'));
                return 'error';
            }

            self::logSent('2factor', $receiver);
            return 'success';

        } catch (\Throwable $e) {
            self::logFailure('2factor', $receiver, $e->getMessage());
            return 'error';
        }
    }

    public static function msg_91(string $receiver, string $otp): string
    {
        $config = self::get_settings('msg91');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $receiver = str_replace('+', '', $receiver);
            $authKey = $config['auth_key'] ?? '';
            $templateId = $config['template_id'] ?? '';

            if (empty($authKey) || empty($templateId)) {
                Log::warning('SmsModule.msg91: missing auth_key or template_id');
                return 'error';
            }

            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withHeaders(['content-type' => 'application/json'])
                ->withBody(json_encode(['OTP' => $otp]), 'application/json')
                ->get('https://api.msg91.com/api/v5/otp', [
                    'template_id' => $templateId,
                    'mobile' => $receiver,
                    'authkey' => $authKey,
                ]);

            if (!$response->successful()) {
                self::logFailure('msg91', $receiver, 'HTTP ' . $response->status());
                return 'error';
            }

            $body = $response->json();
            if (($body['type'] ?? null) !== 'success') {
                self::logFailure('msg91', $receiver, 'API type=' . ($body['type'] ?? 'null'));
                return 'error';
            }

            self::logSent('msg91', $receiver);
            return 'success';

        } catch (\Throwable $e) {
            self::logFailure('msg91', $receiver, $e->getMessage());
            return 'error';
        }
    }

    public static function get_settings(string $name): ?array
    {
        $data = config_settings($name, 'sms_config');
        if (isset($data) && !is_null($data->live_values)) {
            return json_decode($data->live_values, true);
        }
        return null;
    }

    /** نلوغ النجاح بدون OTP الفعلي */
    private static function logSent(string $provider, string $receiver): void
    {
        Log::info("SmsModule.{$provider}: sent OTP", [
            'provider' => $provider,
            // نخفي رقم الهاتف جزئياً (لا نلوغه كاملاً)
            'receiver_masked' => self::maskPhone($receiver),
        ]);
    }

    /** نلوغ الفشل مع تفاصيل تقنية فقط (لا OTP) */
    private static function logFailure(string $provider, string $receiver, string $error): void
    {
        Log::warning("SmsModule.{$provider}: failed to send", [
            'provider' => $provider,
            'receiver_masked' => self::maskPhone($receiver),
            'error_short' => mb_substr($error, 0, 200),
        ]);
    }

    /** "+967739123456" → "+96773****456" */
    private static function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return '****';
        }
        return substr($phone, 0, 5) . str_repeat('*', max(0, strlen($phone) - 8)) . substr($phone, -3);
    }
}
