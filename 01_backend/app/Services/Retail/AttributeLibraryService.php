<?php

namespace App\Services\Retail;

use App\Models\Retail\MerchantAttribute;
use App\Models\Retail\MerchantAttributeTerm;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-PRODUCT-ATTRIBUTES-001 — **مكتبةُ السمات.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * «اللون ← أحمر · أزرق» و«المقاس ← S · M · L» — تُعرَّف مرّةً ويُختار
 * منها في كلّ منتج.
 *
 * **وأهمُّ ما فيها التطبيعُ لا التخزين.** فالعطلُ الذي تمنعه ليس نسيانَ
 * القيمة، بل **افتراقَ إملائها**: «أحمر» و«احمر» (بلا همزة) و«أحمر »
 * (بمسافةٍ لاحقة) ثلاثُ قيمٍ مختلفة تماماً في نصٍّ حرّ — فيصير للتاجر
 * ثلاثةُ متغيّراتٍ للون واحدٍ ينقسم مخزونُها بينها.
 *
 * **ولا يمسكه شيء**: كلُّها صفوفٌ سليمةٌ في جدولٍ سليم، والشاشةُ تعرضها،
 * والبيعُ يقع. ولا يُكتشَف إلّا في جردٍ لا يوازن.
 */
class AttributeLibraryService
{
    /**
     * تطبيعُ نصٍّ عربيٍّ للمطابقة — **للمفتاح لا للعرض.**
     *
     * فالمعروضُ يبقى كما كتبه التاجرُ («أحمر»)، والمقارنةُ تقع على
     * الشكل المطبَّع. ولا يُعرَض المطبَّعُ أبداً: «احمر» بلا همزةٍ نصٌّ
     * ركيكٌ على فاتورةِ زبون.
     */
    public static function normalizeKey(string $value): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $value));
        $v = mb_strtolower($v, 'UTF-8');

        // الهمزاتُ والألفُ المقصورةُ والتاءُ المربوطة — وهي مصدرُ أكثر
        // الاختلافات في الكتابة اليوميّة.
        $v = strtr($v, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);

        // التشكيلُ والتطويل.
        $v = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $v);

        return $v;
    }

    private function slug(string $value): string
    {
        $key = self::normalizeKey($value);
        $slug = Str::slug($key, '-', null);   // يحفظ العربيّة كما هي

        // `Str::slug` قد يُفرغ نصّاً عربيّاً خالصاً في بعض الإعدادات —
        // **ومفتاحٌ فارغٌ يجعل كلَّ القيم واحدة**. فيُستعمل المطبَّعُ نفسُه.
        return $slug !== '' ? $slug : $key;
    }

    // ── السمات ───────────────────────────────────────────────────────

    public function addAttribute(User $merchant, string $name): MerchantAttribute
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new DomainException('اسم السمة مطلوب');
        }

        $slug = $this->slug($name);

        $existing = MerchantAttribute::where('merchant_user_id', $merchant->id)
            ->where('slug', $slug)->first();
        if ($existing) {
            // **وإعادةُ الإضافة ليست خطأً** — يُعاد القائمُ ولا يُنشأ ثانٍ.
            return $existing;
        }

        return MerchantAttribute::create([
            'uuid' => (string) Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    /** إضافةُ قيمةٍ (أو قيمٍ) إلى سمة — **ولا تتكرّر بإملاءٍ مختلف**. */
    public function addTerms(User $merchant, int $attributeId, array $values): array
    {
        $attr = MerchantAttribute::where('id', $attributeId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (! $attr) {
            throw new DomainException('السمة غير موجودة');
        }

        $made = [];
        foreach ($values as $raw) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $raw));
            if ($value === '') {
                continue;
            }

            $slug = $this->slug($value);

            $term = MerchantAttributeTerm::firstOrCreate(
                ['attribute_id' => $attr->id, 'slug' => $slug],
                [
                    'merchant_user_id' => $merchant->id,
                    'value' => $value,
                    'sort_order' => 0,
                ],
            );

            $made[] = $term;
        }

        return $made;
    }

    public function deleteTerm(User $merchant, int $termId): void
    {
        MerchantAttributeTerm::where('id', $termId)
            ->where('merchant_user_id', $merchant->id)->delete();
    }

    public function deleteAttribute(User $merchant, int $attributeId): void
    {
        DB::transaction(function () use ($merchant, $attributeId) {
            MerchantAttributeTerm::where('attribute_id', $attributeId)
                ->where('merchant_user_id', $merchant->id)->delete();
            MerchantAttribute::where('id', $attributeId)
                ->where('merchant_user_id', $merchant->id)->delete();
        });
    }

    /** المكتبةُ كاملةً — تقرؤها شاشةُ توليد المتغيّرات فيختار ولا يكتب. */
    public function library(User $merchant): array
    {
        return MerchantAttribute::where('merchant_user_id', $merchant->id)
            ->with('terms')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (MerchantAttribute $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'is_active' => (bool) $a->is_active,
                'terms' => $a->terms->map(fn ($t) => [
                    'id' => $t->id,
                    'value' => $t->value,
                    'slug' => $t->slug,
                    'color_hex' => $t->color_hex,
                ])->all(),
            ])->all();
    }

    /**
     * يحوّل اختياراً من المكتبة إلى محاورِ توليدٍ نصّيّة.
     *
     * `[{"attribute_id":1,"term_ids":[3,4]}]` ⇒ `{"اللون":["أحمر","أزرق"]}`
     *
     * **وهو الجسرُ الذي يُبقي مولّدَ المتغيّرات كما هو** — فلا تُبنى نسخةٌ
     * ثانيةٌ منه للمكتبة، ولا يفترق سلوكُ المسارين.
     */
    public function axesFromSelection(User $merchant, array $selection): array
    {
        $axes = [];

        foreach ($selection as $row) {
            $attrId = (int) ($row['attribute_id'] ?? 0);
            $termIds = array_map('intval', (array) ($row['term_ids'] ?? []));

            if ($attrId <= 0 || $termIds === []) {
                continue;
            }

            $attr = MerchantAttribute::where('id', $attrId)
                ->where('merchant_user_id', $merchant->id)->first();
            if (! $attr) {
                throw new DomainException('سمة غير موجودة في المكتبة');
            }

            $terms = MerchantAttributeTerm::where('attribute_id', $attr->id)
                ->whereIn('id', $termIds)
                ->where('merchant_user_id', $merchant->id)
                ->orderBy('sort_order')->orderBy('id')->pluck('value')->all();

            if ($terms === []) {
                throw new DomainException("لا قيمَ مختارةٌ للسمة «{$attr->name}»");
            }

            $axes[$attr->name] = $terms;
        }

        if ($axes === []) {
            throw new DomainException('لم تُختَر سماتٌ لتوليد الأنواع');
        }

        return $axes;
    }
}
