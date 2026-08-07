<?php

namespace App\Services\Otp;

use App\Services\Messaging\ProviderRegistry;

/**
 * AMIAL-OTP-SPLIT-001 — الرمزُ الثابت لأرقام العرض وحدها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الحال قبل هذا الملفّ: مفتاحٌ واحدٌ يفتح كلّ الأبواب.**
 *
 *     'amial_demo_otp' => env('AMIAL_DEMO_OTP', '123456'),
 *
 * ما دام مضبوطاً، فـ`123456` يُقبل **لأيّ رقمٍ في اليمن**. ولم يكن ذلك
 * كلَّ شيء: `checkPhone` كانت تُرجع الرمز في جسد الاستجابة نفسِه
 * (`'demo_otp' => …`) — فمن يعرف العنوان يسجّل باسم أيّ رقمٍ بلا أن
 * يملكه.
 *
 * وهو **استيلاءٌ على الحساب** لا ثغرةُ راحة: من سجّل برقم غيره صار هو
 * صاحبَ المحفظة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والمعضلة التي أوقفت الإصلاح شهوراً:**
 *
 * إفراغُ `AMIAL_DEMO_OTP` يُقفل التسجيل على الجميع — لأنّ بوّابة الرسائل
 * لم تُوصل بعد. فالخياران كانا: بابٌ مفتوحٌ للجميع، أو بابٌ مغلقٌ على
 * الجميع.
 *
 * **والخيار الثالث أنّ الرقم يحدّد الطريق، لا مفتاحٌ عامّ:**
 *
 *     رقمٌ في قائمة العرض  →  الرمزُ الثابت، بلا بوّابة، ويُفصح عنه
 *     رقمٌ حقيقيّ          →  رمزٌ عشوائيّ عبر البوّابة، ولا يُفصح عنه أبداً
 *
 * فتبقى حساباتُ العرض تعمل للتجربة والعرض على المستثمر، **ولا يُقبل
 * `123456` من رقمٍ حقيقيٍّ ولو كان المتغيّر مضبوطاً**.
 *
 * (القاعدة الثامنة: الهويّة تحدّد النطاق لا القائمةُ المنسدلة — وهنا:
 * الرقمُ يحدّد الطريق، لا مفتاحٌ في البيئة.)
 */
class OtpPolicy
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /** الرمزُ الثابت المضبوط، أو `null` إن عُطِّل. */
    public function demoCode(): ?string
    {
        $c = config('amial.otp.demo_code');

        return (is_string($c) && $c !== '') ? $c : null;
    }

    /**
     * أرقامُ العرض، موحَّدةً إلى أرقامٍ فقط.
     *
     * ══════════════════════════════════════════════════════════════
     * **الجدولُ أوّلاً، والمتغيّرُ بذرةٌ لا حكم.**
     *
     * AMIAL-OTP-CENTER-001: انتقلت الأرقام إلى `otp_demo_numbers` كي
     * تُدار من شاشةٍ لا بنشرٍ كامل. ولو أُلغي المتغيّرُ دفعةً واحدة
     * لأُقفلت حساباتُ العرض على الخوادم القائمة التي تعمل بقيمته الآن.
     *
     * فإن كان الجدولُ فارغاً يُقرأ المتغيّر — وإن امتلأ فهو وحده الحكم.
     * **وإفراغُ الجدول من الشاشة يُقفل الباب فعلاً**، ولا يعود المتغيّرُ
     * يفتحه (وإلّا لصار زرُّ الإقفال كاذباً).
     *
     * @return array<int,string>
     */
    public function demoNumbers(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'amial.otp.demo_numbers', 60,
            function () {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('otp_demo_numbers')) {
                        $rows = \Illuminate\Support\Facades\DB::table('otp_demo_numbers')
                            ->pluck('phone', 'is_active');

                        // الجدولُ مأهولٌ ⇒ هو الحكم، ولو كانت كلُّها معطَّلة.
                        if (\Illuminate\Support\Facades\DB::table('otp_demo_numbers')->exists()) {
                            return \Illuminate\Support\Facades\DB::table('otp_demo_numbers')
                                ->where('is_active', true)
                                ->pluck('phone')
                                ->map(fn ($n) => $this->digits((string) $n))
                                ->filter()->values()->all();
                        }
                    }
                } catch (\Throwable $e) {
                    // قبل الهجرات أو عند تعذّر القاعدة — تُقرأ البذرة.
                }

                return array_values(array_filter(array_map(
                    fn ($n) => $this->digits((string) $n),
                    (array) config('amial.otp.demo_numbers', [])
                )));
            }
        );
    }

    /** تُنسى النسخة المحفوظة بعد أيّ تعديلٍ من الشاشة. */
    public static function forget(): void
    {
        \Illuminate\Support\Facades\Cache::forget('amial.otp.demo_numbers');
    }

    /**
     * أهذا رقمُ عرض؟
     *
     * **والمقارنةُ بالمقطع الأخير لا بالتطابق التامّ** — لأنّ الرقم نفسه
     * يصل بأشكالٍ أربعة: `+967777100001` و`967777100001` و`00967777100001`
     * و`777100001`. ومقارنةٌ حرفيّةٌ تجعل حسابَ العرض يعمل من شاشةٍ
     * ويُرفض من أخرى — وهو عطلٌ لا يُنتج خطأً في أيّ سجلّ.
     */
    public function isDemo(string $phone): bool
    {
        $d = $this->digits($phone);

        if ($d === '' || $this->demoCode() === null) {
            return false;
        }

        foreach ($this->demoNumbers() as $known) {
            $tail = substr($known, -9);

            if ($tail !== '' && str_ends_with($d, $tail)) {
                return true;
            }
        }

        return false;
    }

    /**
     * الرمزُ الذي يُصدَر لهذا الرقم.
     *
     * أرقامُ العرض تأخذ الثابت، وما عداها **عشوائيٌّ دائماً** — ولو كان
     * `AMIAL_DEMO_OTP` مضبوطاً.
     */
    public function codeFor(string $phone): string
    {
        if ($this->isDemo($phone)) {
            return (string) $this->demoCode();
        }

        return (string) random_int(100000, 999999);
    }

    /**
     * أيُفصَح عن الرمز في الاستجابة؟
     *
     * **لأرقام العرض وحدها.** والإفصاح عن رمز رقمٍ حقيقيّ يُلغي التحقّق
     * من أصله: يصير «أثبت أنّك تملك الرقم» «انسخ ما أعطيناك».
     */
    public function mayDisclose(string $phone): bool
    {
        return $this->isDemo($phone);
    }

    /**
     * أيُحتاج إلى بوّابةٍ لإيصال الرمز إلى هذا الرقم؟
     *
     * أرقامُ العرض لا تحتاج — الرمزُ معلومٌ ومُفصَحٌ عنه.
     */
    public function needsDelivery(string $phone): bool
    {
        return !$this->isDemo($phone);
    }

    /** هل تُوجد قناةُ إيصالٍ عاملة (واتساب أو رسائل قصيرة)؟ */
    public function deliveryReady(): bool
    {
        return $this->registry->hasEnabled('whatsapp')
            || $this->registry->hasEnabled('sms');
    }

    /**
     * **رسالةٌ تقول ما وقع بدل صمتٍ يُقرأ نجاحاً.**
     *
     * حين لا بوّابةَ ورقمٌ حقيقيّ، كان المستعمل يقف عند الخطوة الأخيرة
     * بلا سبب. (القاعدة السابعة: الغيابُ يُقال صراحةً مع سببه.)
     */
    public function unavailableMessage(): string
    {
        return 'خدمةُ إرسال رمز التحقّق غير مهيّأة حالياً. يُرجى المحاولة لاحقاً أو مراسلة الدعم.';
    }

    private function digits(string $s): string
    {
        return (string) preg_replace('/[^0-9]/', '', $s);
    }
}
