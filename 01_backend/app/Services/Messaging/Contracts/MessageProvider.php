<?php

namespace App\Services\Messaging\Contracts;

/**
 * AMIAL-MESSAGING-001 — عقدُ مزوّدِ الرسائل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا وُجد هذا العقد؟**
 *
 * كان في `SmsModule` و`WhatsappModule` تسعةُ مزوّدين، ولكلٍّ منهم فرعٌ في
 * `match` أو `switch`. فإضافةُ مزوّدٍ يمنيٍّ واحدٍ تعني تعديل:
 *
 *     WhatsappModule::send()        ← فرعُ match
 *     WhatsappModule::sendText()    ← فرعُ switch
 *     WhatsappModule::PROVIDERS     ← ثابتُ الترتيب
 *     WhatsappModule::hasEnabled…   ← الحلقة نفسُها
 *
 * أربعةُ مواضعَ في ملفٍّ واحدٍ لكلّ مزوّد. **ومن يعدّل ثلاثةً ينسى الرابع
 * بلا خطأ ترجمة** — فيُقال «المزوّد مُفعّل» ولا يُرسِل.
 *
 * وهذا ما يمنعه مبدأ Open/Closed: مفتوحٌ للإضافة، مغلقٌ على التعديل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وإضافةُ مزوّدٍ اليوم = إسقاطُ ملفٍّ في `Providers/Sms` أو
 * `Providers/Whatsapp`. ولا سطرَ واحدٌ قائمٌ يُمَسّ.**
 *
 * لأنّ `ProviderRegistry` يمسح المجلّد، والأولويّةُ يُعلنها المزوّدُ عن
 * نفسه في `priority()` — لا قائمةٌ مركزيّةٌ تُحرَّر.
 *
 * ويحرس ذلك `MessagingContractTest::adding_a_provider_needs_no_edit…`
 * الذي يزرع مزوّداً جديداً ويتأكّد أنّه يعمل بلا تعديل.
 */
interface MessageProvider
{
    /** مفتاحُ الإعدادات في `addon_settings.key_name` — مثل `ultramsg`. */
    public function key(): string;

    /** `sms` أو `whatsapp`. */
    public function channel(): string;

    /**
     * ترتيبُ التجربة داخل القناة — الأصغرُ يُجرَّب أوّلاً.
     *
     * **ويُعلنه المزوّدُ عن نفسه** لا قائمةٌ مركزيّة. فمزوّدٌ محلّيٌّ أرخص
     * يأخذ ١٠ فيسبق العالميّين بلا لمسِ ملفٍّ آخر.
     */
    public function priority(): int;

    /** هل ضُبط وفُعِّل؟ (`status = 1` وحقولُه المطلوبة موجودة) */
    public function isEnabled(): bool;

    /**
     * يُرسل رمز تحقّق.
     *
     * @return bool نجح الإرسال فعلاً — لا «قُبل الطلب».
     */
    public function sendOtp(string $to, string $otp): bool;
}
