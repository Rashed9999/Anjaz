<?php

namespace App\CentralLogics;

use App\Services\Messaging\ProviderRegistry;

/**
 * AMIAL-MESSAGING-001 — واجهةٌ تاريخيّةٌ رقيقة فوق `ProviderRegistry`.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **كان هذا الملفّ ٢٦٣ سطراً، فيها أربعةُ مزوّدين بنفس الشكل المكرَّر:**
 * قراءةُ الإعداد، وفحصُ الحالة، وnداءٌ شبكيّ، وtry/catch، وسطرا سجلّ.
 * وقياسُ `scripts/audit.php` وضع كتلَه مع `WhatsappModule` في **أكبر
 * تكرارٍ في المشروع كلِّه**.
 *
 * صارت المزوّدات أصنافاً في `app/Services/Messaging/Providers/Sms`، ولا
 * يكتب المزوّدُ إلّا ما يختلف فيه: نداءَه الشبكيَّ وحده.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وبصمةُ `send()` محفوظةٌ عمداً** — يستدعيها ١٤ موضعاً بينها مسارُ
 * التسجيل. وهي تبقى تفويضاً إلى `OtpDispatcher` كما كانت منذ
 * AMIAL-WHATSAPP-OTP-001: **واتساب أوّلاً ثمّ SMS** حسب التفضيل.
 */
class SmsModule
{
    /**
     * نقطةُ الدخول التاريخيّة لإرسال OTP (يستدعيها ١٤ موضعاً).
     *
     * تفويضٌ شفّافٌ إلى `OtpDispatcher` — فكلُّ المتّصلين القدامى يكتسبون
     * واتساب تلقائيّاً بلا تغييرٍ فيهم.
     */
    public static function send(string $receiver, string $otp): string
    {
        return OtpDispatcher::send($receiver, $otp);
    }

    /**
     * مسارُ الرسائل القصيرة الخالص — يستدعيه `OtpDispatcher`.
     *
     * @return string `success` · `error` · `not_found`
     */
    public static function sendViaSmsProviders(string $receiver, string $otp): string
    {
        return app(ProviderRegistry::class)->sendOtp('sms', $receiver, $otp);
    }

    /** إعداداتُ مزوّدٍ — تستعملها شاشاتُ الإدارة لعرض الحالة. */
    public static function get_settings(string $name): ?array
    {
        $data = config_settings($name, 'sms_config');

        if (isset($data) && !is_null($data->live_values)) {
            return json_decode($data->live_values, true);
        }

        return null;
    }
}
