<?php

namespace App\Services;

use App\Models\FavouriteNumber;
use App\Models\User;

/**
 * AMIAL-FEE-TRUTH-002 — **الخصمُ سياسةٌ فوق الرسم، لا حسابٌ موازٍ له.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي أدخل هذا الملفّ:**
 *
 * كان خصمُ «الأرقام المفضّلة» مكتوباً **مرّتين** داخل متحكّم المعاملات —
 * مرّةً للتحويل ومرّةً للسحب — ويقرأ الرسمَ الأصليَّ من `business_settings`
 * لا من محرّك الرسوم. فصار الخصمُ **جزءاً من محرّكٍ ثانٍ** بدل أن يكون
 * طبقةً فوق المحرّك الواحد.
 *
 * وأثرُه: يغيّر الأدمنُ رسمَ التحويل في «إدارة الرسوم» فلا يتغيّر شيء،
 * لأنّ المسارَ الحيَّ لا يقرأ من هناك أصلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والفصلُ مقصود:**
 *
 *   `FeeService`         **كم الرسمُ؟**      — مصدرُ الحقيقة الوحيد
 *   `FeeDiscountPolicy`  **أيُخصَم منه؟**    — سياسةٌ تُطبَّق على الناتج
 *
 * فالخصمُ **لا يُنشئ رسماً** ولا يقرأ إعداداً موازياً للتسعير: يأخذ رسماً
 * محسوباً ويُنقصه بنسبةٍ معلومة، ويقول كم أنقص ولماذا — فيظهر في الإيصال
 * وفي التدقيق بدل أن يكون فرقاً غامضاً.
 */
class FeeDiscountPolicy
{
    /**
     * يُطبّق خصمَ الأرقام المفضّلة على رسمٍ محسوب.
     *
     * @param  string  $fee  الرسمُ كما حسبه `FeeService` (نصٌّ عشريّ)
     * @return array{fee:string, discount:string, reason:?string}
     */
    public function applyFavouriteNumber(
        User $sender,
        ?string $receiverPhone,
        string $fee,
        string $operation,
    ): array {
        $none = ['fee' => MoneyService::normalize($fee), 'discount' => '0.0000', 'reason' => null];

        if ($receiverPhone === null || $receiverPhone === '') {
            return $none;
        }

        if (! $this->enabled()) {
            return $none;
        }

        $percent = $this->discountPercentFor($operation);

        if ($percent <= 0) {
            return $none;
        }

        $isFavourite = FavouriteNumber::where('user_id', $sender->id)
            ->contacts()->pluck('phone')->contains($receiverPhone);

        if (! $isFavourite) {
            return $none;
        }

        // **الحسابُ عشريٌّ لا عائم** — `amial-financial-truth`. وكان
        // `$charge * ($p/100)` بـ`float`، فيُنتج كسوراً لا تُطابق الدفتر.
        $discount = MoneyService::normalize(
            MoneyService::div(MoneyService::mul($fee, (string) $percent), '100'));

        // **ولا يصير الرسمُ سالباً** — خصمُ ١٢٠٪ يُنتج رسماً سالباً، أي
        // منصّةً تدفع للعميل ليحوّل. فيُقصَّ عند الصفر ويُقال ذلك.
        if (MoneyService::gt($discount, $fee)) {
            $discount = MoneyService::normalize($fee);
        }

        return [
            'fee' => MoneyService::normalize(MoneyService::sub($fee, $discount)),
            'discount' => $discount,
            'reason' => 'favourite_number',
        ];
    }

    /**
     * **هل الميزةُ مفعّلة؟**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهنا كان عطلٌ يُبطل الزرَّ كلَّه:** الشاشةُ تُرسل `1`/`0`
     * والمتحكّمُ يفحص `'on'`. فالتفعيلُ لا يُحفظ أبداً، والقيمةُ تبقى كما
     * هي مهما ضُغط الزرّ.
     *
     * فالقراءةُ هنا **تقبل الصيغ الثلاث** — `1` و`'on'` و`'true'` —
     * لأنّ في القاعدة صفوفاً كُتبت بكلٍّ منها عبر السنين، وقراءةُ واحدةٍ
     * تجعل إعداداً محفوظاً يُقرأ مطفأً.
     */
    public function enabled(): bool
    {
        return $this->truthy(\App\CentralLogics\Helpers::get_business_settings('favorite_number_status'));
    }

    /** نسبةُ الخصم لهذه العمليّة — و`0` تعني «لا خصم». */
    public function discountPercentFor(string $operation): float
    {
        $key = match ($operation) {
            'SEND_MONEY' => 'favorite_number_send_money_charge_discount',
            'CASH_OUT' => 'favorite_number_cash_out_charge_discount',
            default => null,
        };

        if ($key === null) {
            return 0.0;
        }

        return max(0.0, (float) \App\CentralLogics\Helpers::get_business_settings($key));
    }

    /** حدُّ عدد الأرقام المفضّلة — و`0` تعني «بلا حدّ مضبوط». */
    public function limit(): int
    {
        return (int) \App\CentralLogics\Helpers::get_business_settings('favorite_number_limit');
    }

    /**
     * **`'on'` و`1` و`'true'` كلُّها تفعيل.**
     *
     * فصيغةُ الحفظ تغيّرت عبر الشاشات، والقاعدةُ تحمل الثلاث.
     */
    private function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'on', 'true', 'yes'], true);
    }
}
