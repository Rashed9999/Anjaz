<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\Log;

/**
 * AMIAL-WHATSAPP-OTP-001 — موزّع OTP متعدّد القنوات.
 *
 * يختار قناة الإرسال حسب تفضيل قابل للضبط، مع **fallback تلقائي**:
 *   - whatsapp_first (الافتراضي): جرّب واتساب (إن وُجد مزوّد مُفعّل) ثم SMS.
 *   - sms_first: جرّب SMS ثم واتساب.
 *   - whatsapp_only / sms_only: قناة واحدة بلا fallback.
 *
 * التفضيل من `addon_settings` (key_name='otp_channel', settings_type='otp_config',
 * live_values={"value":"whatsapp_first"}) — وإلا الافتراضي whatsapp_first.
 *
 * يُعيد 'success' | 'error' | 'not_found' (متوافق مع المتصلين القدامى لـ SmsModule::send).
 */
class OtpDispatcher
{
    public const DEFAULT_PREFERENCE = 'whatsapp_first';

    public static function send(string $receiver, string $otp): string
    {
        $order = match (self::channelPreference()) {
            'sms_only'      => ['sms'],
            'whatsapp_only' => ['whatsapp'],
            'sms_first'     => ['sms', 'whatsapp'],
            default         => ['whatsapp', 'sms'],
        };

        $last = 'not_found';

        foreach ($order as $channel) {
            $result = $channel === 'whatsapp'
                ? self::tryWhatsapp($receiver, $otp)
                : SmsModule::sendViaSmsProviders($receiver, $otp);

            if ($result === 'success') {
                return 'success';
            }
            // 'not_found' (لا مزوّد مُفعّل لهذه القناة) لا يطغى على خطأ فعلي
            if ($result !== 'not_found') {
                $last = $result;
            }
        }

        if ($last === 'not_found') {
            Log::warning('OtpDispatcher: no enabled channel/provider for OTP');
        }
        return $last;
    }

    private static function tryWhatsapp(string $receiver, string $otp): string
    {
        if (!WhatsappModule::hasEnabledProvider()) {
            return 'not_found';
        }
        return WhatsappModule::send($receiver, $otp);
    }

    public static function channelPreference(): string
    {
        try {
            $data = config_settings('otp_channel', 'otp_config');
            if ($data && !is_null($data->live_values)) {
                $value = json_decode($data->live_values, true)['value'] ?? null;
                if (in_array($value, ['whatsapp_first', 'sms_first', 'whatsapp_only', 'sms_only'], true)) {
                    return $value;
                }
            }
        } catch (\Throwable $e) {
            // الإعداد غير متاح (مثلاً قبل الهجرات) — نسقط للافتراضي بأمان.
        }
        return self::DEFAULT_PREFERENCE;
    }
}
