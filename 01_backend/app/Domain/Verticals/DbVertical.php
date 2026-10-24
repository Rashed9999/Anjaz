<?php

namespace App\Domain\Verticals;

use App\Models\Access\MerchantVerticalDefinition;
use App\Support\Access\AccessConstants as A;

/**
 * AMIAL-VERTICAL-COMPOSE-001 — **قطاعٌ صفُّه في القاعدة، وسلوكُه كسلوك
 * إخوته المبنيّة.**
 *
 * وهو الجوابُ على «إضافةُ قطاعٍ تحتاج ترميزاً»: المربّعُ نفسُه
 * (`MerchantVertical`) يسأل أربعةَ أسئلةٍ لا أكثر — الرمزُ والاسمُ
 * والنواةُ والعمقُ المُباع — **وأربعتُها بياناتٌ تُملأ من لوحة**.
 *
 * ولذلك لا يرث هذا الصنفُ شيئاً غيرَ ما ترثه القطاعاتُ الستّة: الصندوقُ
 * المشترك (`shared()`) وسُلَّمُ الباقات (`planReaches`) وتركيبُ
 * `featuresFor` — كلُّها في الأب، **فلا مسارَ ثانٍ للحساب**.
 */
final class DbVertical extends MerchantVertical
{
    public function __construct(private readonly MerchantVerticalDefinition $row) {}

    public function code(): string
    {
        return (string) $this->row->code;
    }

    /**
     * **ويُقرأ من صفّه لا من `BUSINESS_TYPE_LABELS`.**
     *
     * الأبُ يشتقّ الاسمَ من ذلك الثابت، وهو لا يعرف قطاعاً أُنشئ بعد
     * آخرِ نشرة — فيردّ الرمزَ الإنجليزيَّ خاماً في ترويسة التاجر.
     */
    public function nameAr(): string
    {
        $name = trim((string) $this->row->name_ar);

        return $name !== '' ? $name : $this->code();
    }

    /** @return array<int,string> */
    public function own(): array
    {
        return $this->clean($this->row->core_features ?? []);
    }

    /** @return array<string,array<int,string>> */
    public function paidDepth(): array
    {
        $out = [];

        foreach ((array) ($this->row->paid_depth ?? []) as $plan => $features) {
            $plan = (string) $plan;

            // **باقةٌ لا وجودَ لها تُطرَح لا تُمرَّر**: `planReaches` تبحث
            // عنها في `ALL_PLANS` فلا تجدها فتُرجع false — أي عمقٌ
            // مدفوعٌ لا يصل أحداً أبداً، وصمتاً.
            if (! in_array($plan, A::ALL_PLANS, true)) {
                continue;
            }

            $list = $this->clean(is_array($features) ? $features : []);

            if ($list !== []) {
                $out[$plan] = $list;
            }
        }

        return $out;
    }

    /** الأيقونةُ واللونُ والتلميحُ — لبطاقة الاختيار في التطبيق. */
    public function icon(): ?string { return $this->nullIfBlank($this->row->icon); }
    public function color(): ?string { return $this->nullIfBlank($this->row->color); }
    public function hint(): ?string { return $this->nullIfBlank($this->row->hint_ar); }

    /**
     * **ولا تُرجَع قدرةٌ لم يمنحها هذا القطاع.** رمزٌ بقي في الصفّ بعد
     * نزعه من القائمة يفتح شاشةً يردّها الخادمُ بـ٤٠٢ — بابٌ يُفتح على
     * جدار.
     */
    public function homeCapability(): ?string
    {
        $code = $this->nullIfBlank($this->row->home_capability);

        return $code !== null && $this->grants($code) ? $code : null;
    }

    public function sortOrder(): int
    {
        return (int) $this->row->sort_order;
    }

    /**
     * **ترشيحٌ لا ثقةٌ عمياء — والقياسُ على `composable()` لا على وجود
     * القدرة.**
     *
     * وأوّلُ صياغةٍ لهذه الدالّة رشّحت على `CapabilityRegistry::find()`
     * وحدَها — أي «أموجودةٌ؟» — **فأسقطها حارسُها ③ في أوّل تشغيل**:
     * الفحصُ الذي يمنع محرّكَ قطاعٍ آخرَ كان في المتحكّم وحدَه، وهذا
     * الصنفُ **يوسّع انطباقَ القدرة** بقائمة المنح (انظر
     * `Capability::appliesTo`). فصفٌّ فيه `pharmacy_batches` — بكتابةٍ
     * مباشرةٍ في القاعدة، أو بمسارٍ يُضاف غداً — كان يفتح دفعاتِ
     * الصيدليّة في مخبز.
     *
     * **فحُرّك الحدُّ إلى القراءة**: ما لا يُركَّب لا يُمنَح مهما كان في
     * الصفّ. وحدٌّ في نقطة الكتابة وحدَها يحرس البابَ الذي يعرفه.
     *
     * @param  array<int|string,mixed>  $features
     * @return array<int,string>
     */
    private function clean(array $features): array
    {
        $composable = \App\Support\Access\CapabilityRegistry::composable();
        $out = [];

        foreach ($features as $feature) {
            if (! is_string($feature)) {
                continue;
            }

            $feature = trim($feature);

            if ($feature !== '' && isset($composable[$feature])) {
                $out[] = $feature;
            }
        }

        return array_values(array_unique($out));
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
