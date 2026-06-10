<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client as TwilioClient;

/**
 * AMIAL-WHATSAPP-OTP-001 — إرسال OTP/إشعارات عبر WhatsApp Business API.
 *
 * يدعم **كل المزوّدين** الشائعين، ويُجرّبهم بالترتيب حتى ينجح أحدهم:
 *   1. meta_cloud  — Meta WhatsApp Cloud API (الرسمي/الأرخص غالباً)
 *   2. twilio      — Twilio WhatsApp
 *   3. 360dialog   — 360dialog (BSP)
 *   4. wati        — WATI
 *   5. ultramsg    — UltraMsg (الأبسط/الأرخص، رسالة نصّية حرّة)
 *
 * الإعداد مثل SMS تماماً: صفّ في `addon_settings` بـ
 * (key_name=<provider>, settings_type='whatsapp_config', live_values=JSON).
 *
 * شكل live_values لكل مزوّد:
 *   meta_cloud: {status, access_token, phone_number_id, template_name, lang_code, otp_button?}
 *   twilio:     {status, sid, token, from, otp_template}            // from = رقم whatsapp بدون "whatsapp:"
 *   360dialog:  {status, api_key, template_name, lang_code, otp_button?}
 *   wati:       {status, api_endpoint, access_token, template_name, broadcast_name}
 *   ultramsg:   {status, instance_id, token, otp_template}
 *
 * البصمة: send()/مزوّدات تُعيد 'success' | 'error' | 'not_found' (مطابقة لـ SmsModule).
 */
class WhatsappModule
{
    private const HTTP_TIMEOUT = 10;

    /** ترتيب التجربة */
    private const PROVIDERS = ['meta_cloud', 'twilio', '360dialog', 'wati', 'ultramsg'];

    /** هل يوجد أيّ مزوّد واتساب مُفعّل؟ (يستخدمه الموزّع لتحديد القناة) */
    public static function hasEnabledProvider(): bool
    {
        foreach (self::PROVIDERS as $name) {
            $c = self::get_settings($name);
            if ($c && (int)($c['status'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    /** يختار أول مزوّد واتساب مُفعّل ويرسل. */
    public static function send(string $receiver, string $otp): string
    {
        foreach (self::PROVIDERS as $name) {
            $config = self::get_settings($name);
            if ($config && (int)($config['status'] ?? 0) === 1) {
                return match ($name) {
                    'meta_cloud' => self::meta_cloud($receiver, $otp),
                    'twilio'     => self::twilio($receiver, $otp),
                    '360dialog'  => self::dialog360($receiver, $otp),
                    'wati'       => self::wati($receiver, $otp),
                    'ultramsg'   => self::ultramsg($receiver, $otp),
                };
            }
        }

        Log::warning('WhatsappModule: no enabled provider found');
        return 'not_found';
    }

    // ============================================================
    // إشعارات نصّية حرّة (إيصالات/تنبيهات — لا OTP)
    // ============================================================

    /**
     * يرسل رسالة نصّية حرّة (إشعار) عبر أوّل مزوّد مُفعّل.
     * ملاحظة: قوالب Meta/360dialog للنصّ الحرّ تتطلّب نافذة جلسة 24 ساعة؛ خارجها
     * قد تفشل وفق سياسة واتساب (يتكفّل المتصل بالـ fallback إن لزم).
     */
    public static function sendText(string $receiver, string $message): string
    {
        foreach (self::PROVIDERS as $name) {
            $config = self::get_settings($name);
            if ($config && (int)($config['status'] ?? 0) === 1) {
                return self::dispatchText($name, $config, $receiver, $message);
            }
        }
        Log::warning('WhatsappModule.sendText: no enabled provider found');
        return 'not_found';
    }

    private static function dispatchText(string $name, array $config, string $receiver, string $message): string
    {
        try {
            switch ($name) {
                case 'twilio':
                    $client = new TwilioClient($config['sid'] ?? '', $config['token'] ?? '');
                    $client->messages->create('whatsapp:' . self::normalize($receiver, true), [
                        'from' => 'whatsapp:' . self::normalize($config['from'] ?? '', true),
                        'body' => $message,
                    ]);
                    break;

                case 'ultramsg':
                    $r = Http::asForm()->timeout(self::HTTP_TIMEOUT)
                        ->post("https://api.ultramsg.com/{$config['instance_id']}/messages/chat", [
                            'token' => $config['token'] ?? '',
                            'to' => self::normalize($receiver, true),
                            'body' => $message,
                        ]);
                    if (!($r->successful() && (($r->json()['sent'] ?? null) === 'true' || ($r->json()['sent'] ?? null) === true))) {
                        throw new \RuntimeException('ultramsg HTTP ' . $r->status());
                    }
                    break;

                case 'meta_cloud':
                    $r = Http::withToken($config['access_token'] ?? '')->timeout(self::HTTP_TIMEOUT)
                        ->post("https://graph.facebook.com/v19.0/{$config['phone_number_id']}/messages", [
                            'messaging_product' => 'whatsapp',
                            'to' => self::normalize($receiver, false),
                            'type' => 'text',
                            'text' => ['body' => $message],
                        ]);
                    if (!($r->successful() && isset($r->json()['messages'][0]['id']))) {
                        throw new \RuntimeException('meta HTTP ' . $r->status());
                    }
                    break;

                case '360dialog':
                    $r = Http::withHeaders(['D360-API-KEY' => $config['api_key'] ?? ''])->timeout(self::HTTP_TIMEOUT)
                        ->post('https://waba-v2.360dialog.io/messages', [
                            'messaging_product' => 'whatsapp',
                            'to' => self::normalize($receiver, false),
                            'type' => 'text',
                            'text' => ['body' => $message],
                        ]);
                    if (!($r->successful() && isset($r->json()['messages'][0]['id']))) {
                        throw new \RuntimeException('360dialog HTTP ' . $r->status());
                    }
                    break;

                case 'wati':
                    $endpoint = rtrim($config['api_endpoint'] ?? '', '/');
                    $to = self::normalize($receiver, false);
                    $r = Http::withToken($config['access_token'] ?? '')->timeout(self::HTTP_TIMEOUT)
                        ->post("{$endpoint}/api/v1/sendSessionMessage/{$to}?messageText=" . rawurlencode($message));
                    if (!$r->successful()) {
                        throw new \RuntimeException('wati HTTP ' . $r->status());
                    }
                    break;

                default:
                    return 'error';
            }

            self::logSent($name, $receiver);
            return 'success';
        } catch (\Throwable $e) {
            self::logFailure($name, $receiver, $e->getMessage());
            return 'error';
        }
    }

    // ============================================================
    // 1) Meta WhatsApp Cloud API
    // ============================================================
    public static function meta_cloud(string $receiver, string $otp): string
    {
        $config = self::get_settings('meta_cloud');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $token = $config['access_token'] ?? '';
            $phoneId = $config['phone_number_id'] ?? '';
            $template = $config['template_name'] ?? 'otp_code';
            $lang = $config['lang_code'] ?? 'ar';
            if (empty($token) || empty($phoneId)) {
                Log::warning('WhatsappModule.meta_cloud: missing access_token/phone_number_id');
                return 'error';
            }

            $components = [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $otp]]],
            ];
            // قوالب المصادقة في Meta تتطلّب زر "نسخ الرمز" — مُفعّل افتراضياً.
            if (($config['otp_button'] ?? true)) {
                $components[] = [
                    'type' => 'button', 'sub_type' => 'url', 'index' => '0',
                    'parameters' => [['type' => 'text', 'text' => $otp]],
                ];
            }

            $response = Http::withToken($token)->timeout(self::HTTP_TIMEOUT)
                ->post("https://graph.facebook.com/v19.0/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => self::normalize($receiver, withPlus: false),
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $lang],
                        'components' => $components,
                    ],
                ]);

            if ($response->successful() && isset($response->json()['messages'][0]['id'])) {
                self::logSent('meta_cloud', $receiver);
                return 'success';
            }
            self::logFailure('meta_cloud', $receiver, 'HTTP ' . $response->status() . ' ' . $response->body());
            return 'error';
        } catch (\Throwable $e) {
            self::logFailure('meta_cloud', $receiver, $e->getMessage());
            return 'error';
        }
    }

    // ============================================================
    // 2) Twilio WhatsApp
    // ============================================================
    public static function twilio(string $receiver, string $otp): string
    {
        $config = self::get_settings('twilio');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $sid = $config['sid'] ?? '';
            $token = $config['token'] ?? '';
            $from = $config['from'] ?? '';
            if (empty($sid) || empty($token) || empty($from)) {
                Log::warning('WhatsappModule.twilio: missing sid/token/from');
                return 'error';
            }
            $message = str_replace('#OTP#', $otp, $config['otp_template'] ?? 'رمز التحقق: #OTP#');

            $client = new TwilioClient($sid, $token);
            $client->messages->create('whatsapp:' . self::normalize($receiver, withPlus: true), [
                'from' => 'whatsapp:' . self::normalize($from, withPlus: true),
                'body' => $message,
            ]);

            self::logSent('twilio', $receiver);
            return 'success';
        } catch (\Throwable $e) {
            self::logFailure('twilio', $receiver, $e->getMessage());
            return 'error';
        }
    }

    // ============================================================
    // 3) 360dialog
    // ============================================================
    public static function dialog360(string $receiver, string $otp): string
    {
        $config = self::get_settings('360dialog');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $apiKey = $config['api_key'] ?? '';
            $template = $config['template_name'] ?? 'otp_code';
            $lang = $config['lang_code'] ?? 'ar';
            if (empty($apiKey)) {
                Log::warning('WhatsappModule.360dialog: missing api_key');
                return 'error';
            }

            $components = [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $otp]]],
            ];
            if (($config['otp_button'] ?? true)) {
                $components[] = [
                    'type' => 'button', 'sub_type' => 'url', 'index' => '0',
                    'parameters' => [['type' => 'text', 'text' => $otp]],
                ];
            }

            $response = Http::withHeaders(['D360-API-KEY' => $apiKey])->timeout(self::HTTP_TIMEOUT)
                ->post('https://waba-v2.360dialog.io/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => self::normalize($receiver, withPlus: false),
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $lang],
                        'components' => $components,
                    ],
                ]);

            if ($response->successful() && isset($response->json()['messages'][0]['id'])) {
                self::logSent('360dialog', $receiver);
                return 'success';
            }
            self::logFailure('360dialog', $receiver, 'HTTP ' . $response->status() . ' ' . $response->body());
            return 'error';
        } catch (\Throwable $e) {
            self::logFailure('360dialog', $receiver, $e->getMessage());
            return 'error';
        }
    }

    // ============================================================
    // 4) WATI
    // ============================================================
    public static function wati(string $receiver, string $otp): string
    {
        $config = self::get_settings('wati');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $endpoint = rtrim($config['api_endpoint'] ?? '', '/');
            $token = $config['access_token'] ?? '';
            $template = $config['template_name'] ?? 'otp_code';
            $broadcast = $config['broadcast_name'] ?? 'otp';
            if (empty($endpoint) || empty($token)) {
                Log::warning('WhatsappModule.wati: missing api_endpoint/access_token');
                return 'error';
            }

            $to = self::normalize($receiver, withPlus: false);
            $response = Http::withToken($token)->timeout(self::HTTP_TIMEOUT)
                ->post("{$endpoint}/api/v1/sendTemplateMessage?whatsappNumber={$to}", [
                    'template_name' => $template,
                    'broadcast_name' => $broadcast,
                    'parameters' => [['name' => '1', 'value' => $otp]],
                ]);

            $ok = $response->successful()
                && (($response->json()['result'] ?? null) === true || ($response->json()['result'] ?? null) === 'true');
            if ($ok) {
                self::logSent('wati', $receiver);
                return 'success';
            }
            self::logFailure('wati', $receiver, 'HTTP ' . $response->status() . ' ' . $response->body());
            return 'error';
        } catch (\Throwable $e) {
            self::logFailure('wati', $receiver, $e->getMessage());
            return 'error';
        }
    }

    // ============================================================
    // 5) UltraMsg (الأبسط/الأرخص — رسالة نصّية حرّة)
    // ============================================================
    public static function ultramsg(string $receiver, string $otp): string
    {
        $config = self::get_settings('ultramsg');
        if (!$config || (int)($config['status'] ?? 0) !== 1) {
            return 'error';
        }

        try {
            $instance = $config['instance_id'] ?? '';
            $token = $config['token'] ?? '';
            if (empty($instance) || empty($token)) {
                Log::warning('WhatsappModule.ultramsg: missing instance_id/token');
                return 'error';
            }
            $message = str_replace('#OTP#', $otp, $config['otp_template'] ?? 'رمز التحقق: #OTP#');

            $response = Http::asForm()->timeout(self::HTTP_TIMEOUT)
                ->post("https://api.ultramsg.com/{$instance}/messages/chat", [
                    'token' => $token,
                    'to' => self::normalize($receiver, withPlus: true),
                    'body' => $message,
                ]);

            $sent = $response->json()['sent'] ?? null;
            if ($response->successful() && ($sent === 'true' || $sent === true)) {
                self::logSent('ultramsg', $receiver);
                return 'success';
            }
            self::logFailure('ultramsg', $receiver, 'HTTP ' . $response->status() . ' ' . $response->body());
            return 'error';
        } catch (\Throwable $e) {
            self::logFailure('ultramsg', $receiver, $e->getMessage());
            return 'error';
        }
    }

    // ============================================================
    // مساعدات
    // ============================================================
    public static function get_settings(string $name): ?array
    {
        $data = config_settings($name, 'whatsapp_config');
        if (isset($data) && !is_null($data->live_values)) {
            return json_decode($data->live_values, true);
        }
        return null;
    }

    /** توحيد الرقم: إزالة الفراغات/الرموز، وضبط وجود "+" حسب المزوّد. */
    private static function normalize(string $phone, bool $withPlus): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        return $withPlus ? '+' . $digits : $digits;
    }

    private static function logSent(string $provider, string $receiver): void
    {
        Log::info("WhatsappModule.{$provider}: sent OTP", [
            'provider' => $provider,
            'channel' => 'whatsapp',
            'receiver_masked' => self::maskPhone($receiver),
        ]);
    }

    private static function logFailure(string $provider, string $receiver, string $error): void
    {
        Log::warning("WhatsappModule.{$provider}: failed to send", [
            'provider' => $provider,
            'channel' => 'whatsapp',
            'receiver_masked' => self::maskPhone($receiver),
            'error_short' => mb_substr($error, 0, 200),
        ]);
    }

    private static function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return '****';
        }
        return substr($phone, 0, 5) . str_repeat('*', max(0, strlen($phone) - 8)) . substr($phone, -3);
    }
}
