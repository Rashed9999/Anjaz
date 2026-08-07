<?php

namespace App\Services\Whatsapp;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Cache;

/**
 * AMIAL-WA-LIMIT-001 — الحدُّ الأعلى للمال عبر بوت واتساب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا حدٌّ خاصٌّ بالبوت وحده:**
 *
 * وتصحيحٌ لقولٍ لي قبل هذا العمل: قلتُ إنّ البوت «بلا رمز حماية»، وقِستُ
 * فوجدتُه **يتحقّق منه** — `PinService::verify` قبل دفع الفاتورة،
 * و`pin` يُمرَّر إلى `TransferService::initiate`. فالجملةُ كانت خطأً.
 *
 * **لكنّ القلق الحقيقيّ يبقى، وهو أدقُّ من ذاك:** الرمزُ في البوت
 * **يُكتب في محادثة واتساب**. فيبقى في سجلّ المحادثة على الهاتفين وعند
 * المزوّد، ويراه من يفتح الهاتف بعد ساعة. وهو أمرٌ لا يقع في التطبيق
 * حيث يُكتب الرمزُ في حقلٍ لا يُخزَّن.
 *
 * فالحمايةُ هنا ليست حاجزاً أقوى — بل **سقفاً يُحدّد أكبرَ خسارةٍ ممكنة**
 * حين يُقرأ الرمزُ من محادثة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ مسارات لا مسارٌ واحد:**
 *
 *   التحويل  → `TransferService::initiate`
 *   الفواتير → `BillPayService::createAndExecute`
 *   فاتورة تاجر → `PaymentRequestService::pay`
 *
 * وسقفٌ على واحدٍ من ثلاثةٍ ليس سقفاً: من بلغ الحدَّ في التحويل يُقسّمه
 * على فواتير. فيُفحص الثلاثةُ من موضعٍ واحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقيمةُ غيرُ المضبوطة ليست «بلا حدّ» — ولا صفراً.**
 *
 * فلو كان الغيابُ يعني «بلا حدّ» لشُحن النظامُ بلا حماية حتّى يتذكّرها
 * إنسان — وهو نمطُ العطل الذي يتكرّر في أميال باي: مبنيٌّ ولا يُوصَل
 * إليه. ولو كان يعني صفراً لأُقفل البوتُ فجأةً على من يستعمله.
 *
 * فالغيابُ يعني **الافتراضيَّ المكتوب في الإعداد**، و**الشاشةُ تقول
 * صراحةً أنّها قيمةٌ افتراضيّةٌ لم تُضبط بعد** (القاعدة السابعة).
 */
class WhatsappMoneyLimit
{
    public const KEY = 'whatsapp_bot_max_amount';

    private const CACHE_KEY = 'amial.wa.max_amount';

    /** صفرٌ **مضبوطٌ عمداً** يعني: لا مالَ عبر البوت إطلاقاً. */
    public function max(): string
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $row = BusinessSetting::where('key', self::KEY)->first();

            if ($row && $row->value !== null && $row->value !== '') {
                return (string) $row->value;
            }

            return (string) config('amial.whatsapp_bot_max_amount', '50000');
        });
    }

    /** أمضبوطةٌ من الإدارة أم افتراضيّة؟ الشاشةُ تقول الفرق. */
    public function isConfigured(): bool
    {
        $row = BusinessSetting::where('key', self::KEY)->first();

        return $row !== null && $row->value !== null && $row->value !== '';
    }

    /**
     * **يُردّ سببُ المنع نصّاً — لا `false` مجرّدة.**
     *
     * فمن مُنع عند صندوقٍ يحتاج أن يعرف السقفَ ليقسّم عمليّته أو يفتح
     * التطبيق. و«فشلت العمليّة» تُرسله يُعيد المحاولة مرّاتٍ بلا جدوى.
     *
     * يُرجع `null` حين يُسمح.
     */
    public function refuse(string $amount): ?string
    {
        $max = $this->max();

        if (bccomp($max, '0', 4) === 0) {
            return 'العمليّات المالية عبر واتساب موقوفة حالياً. استعمل التطبيق.';
        }

        if (bccomp($amount, $max, 4) === 1) {
            return 'أكبر مبلغ عبر واتساب هو ' . rtrim(rtrim(number_format((float) $max, 2, '.', ','), '0'), '.')
                . ' ر.ي. للمبالغ الأكبر استعمل التطبيق.';
        }

        return null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
