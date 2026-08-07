<?php

namespace App\CentralLogics;

use App\Services\Messaging\ProviderRegistry;

/**
 * AMIAL-MESSAGING-001 — واجهةٌ تاريخيّةٌ رقيقة فوق `ProviderRegistry`.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **كان هذا الملفّ ٤٣١ سطراً، فيها خمسةُ مزوّدين منفَّذين مرّتين لكلٍّ:**
 * مرّةً في `send()` لرمز التحقّق، ومرّةً في `dispatchText()` للنصّ الحرّ.
 * وحلقةُ اختيار المزوّد مكتوبةٌ ثلاثَ مرّات.
 *
 * فإضافةُ مزوّدٍ يمنيٍّ واحدٍ كانت تعني تعديل **أربعة مواضع** في هذا
 * الملفّ: الثابت `PROVIDERS`، وفرعَ `match` في `send()`، وفرعَ `switch`
 * في `dispatchText()`، وحلقةَ `hasEnabledProvider()`. **ومن يعدّل ثلاثةً
 * ينسى الرابع بلا خطأ ترجمة.**
 *
 * صارت المزوّدات أصنافاً في `app/Services/Messaging/Providers/Whatsapp`،
 * ويكتشفها السجلُّ بالمسح. **وإضافةُ مزوّدٍ اليوم إسقاطُ ملفٍّ واحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا بقي هذا الصنف بدل حذفه؟**
 *
 * لأنّ ٢٠ موضعاً في المشروع تستدعيه، وبينها مسارُ التسجيل نفسُه. وتغييرُ
 * عشرين موضعاً في خطوةٍ واحدةٍ مع إعادة الهيكلة يخلط عطلَ النقل بعطل
 * التصميم — فإذا سقط شيءٌ لم يُعرف من أيّهما.
 *
 * فالبصماتُ محفوظةٌ حرفيّاً: `'success' | 'error' | 'not_found'`.
 */
class WhatsappModule
{
    /** هل يوجد مزوّدُ واتساب مُفعَّل؟ (يستعمله الموزّع لاختيار القناة) */
    public static function hasEnabledProvider(): bool
    {
        return app(ProviderRegistry::class)->hasEnabled('whatsapp');
    }

    /** يُرسل رمزَ تحقّقٍ عبر أوّل مزوّدٍ ينجح. */
    public static function send(string $receiver, string $otp): string
    {
        return app(ProviderRegistry::class)->sendOtp('whatsapp', $receiver, $otp);
    }

    /** يُرسل رسالةً نصّيّةً حرّة (إشعار/إيصال — لا OTP). */
    public static function sendText(string $receiver, string $message): string
    {
        return app(ProviderRegistry::class)->sendText('whatsapp', $receiver, $message);
    }

    /**
     * إعداداتُ مزوّدٍ — تستعملها شاشاتُ الإدارة لعرض الحالة.
     *
     * وتبقى هنا لأنّ من يقرأ الإعدادات ليس من يُرسل.
     */
    public static function get_settings(string $name): ?array
    {
        $data = config_settings($name, 'whatsapp_config');

        if (isset($data) && !is_null($data->live_values)) {
            return json_decode($data->live_values, true);
        }

        return null;
    }
}
