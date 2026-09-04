<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\Retail\MerchantBrand;
use App\Models\Retail\MerchantCategory;
use App\Models\Retail\MerchantUnit;
use App\Models\Retail\ProductBarcode;
use App\Services\Retail\StockService;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلتان ٢ و٣ — **محرّكُ الأصناف**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * تصنيفاتٌ وعلاماتٌ ووحداتٌ وباركوداتٌ ومتغيّرات. **ولا تمسّ المخزون**:
 * `StockService` بابُه الوحيد، وخدمتان تكتبان مخزوناً تُنتجان رقمين.
 */
class ProductCatalogService
{
    // ══════════════════════════════════════════════════════════════════
    //  التصنيفات
    // ══════════════════════════════════════════════════════════════════

    public function addCategory(User $merchant, array $data): MerchantCategory
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')));
        if ($name === '') {
            throw new DomainException('اسم التصنيف مطلوب');
        }

        // **الأبُ من التاجر نفسِه** — وتصنيفٌ يُعلَّق تحت شجرةِ غيره
        // يُسرّب أسماءَ أصنافِه في كلّ قائمة.
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            $parent = MerchantCategory::where('id', $parentId)
                ->where('merchant_user_id', $merchant->id)->first();
            if (! $parent) {
                throw new DomainException('التصنيف الأب غير موجود');
            }
        }

        return MerchantCategory::create([
            'uuid' => (string) Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'parent_id' => $parentId ?: null,
            'name' => $name,
            'code' => $this->uniqueCode(
                'merchant_categories', $merchant->id, $data['code'] ?? $name
            ),
            'icon' => $data['icon'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => true,
            'created_by' => $merchant->id,
        ]);
    }

    /** الشجرةُ كاملةً بمستوياتها — تقرؤها الشاشةُ ولا تبنيها بنفسها. */
    public function categoryTree(User $merchant): array
    {
        $all = MerchantCategory::where('merchant_user_id', $merchant->id)
            ->orderBy('sort_order')->orderBy('name')->get();

        $byParent = $all->groupBy(fn ($c) => (int) ($c->parent_id ?? 0));

        $build = function (int $parent, int $depth) use (&$build, $byParent): array {
            // عمقٌ محدود — يمنع حلقةً لو أُفسد أبٌ فأشار إلى ابنه.
            if ($depth > 8) {
                return [];
            }

            return $byParent->get($parent, collect())->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'icon' => $c->icon,
                'is_active' => (bool) $c->is_active,
                'products_count' => MerchantProduct::where('category_id', $c->id)->count(),
                'children' => $build($c->id, $depth + 1),
            ])->values()->all();
        };

        return $build(0, 0);
    }

    // ══════════════════════════════════════════════════════════════════
    //  العلامات والوحدات
    // ══════════════════════════════════════════════════════════════════

    public function addBrand(User $merchant, array $data): MerchantBrand
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new DomainException('اسم العلامة مطلوب');
        }

        return MerchantBrand::create([
            'uuid' => (string) Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => $name,
            'code' => $this->uniqueCode('merchant_brands', $merchant->id, $data['code'] ?? $name),
            'country' => $data['country'] ?? null,
            'is_active' => true,
            'created_by' => $merchant->id,
        ]);
    }

    public function addUnit(User $merchant, array $data): MerchantUnit
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new DomainException('اسم الوحدة مطلوب');
        }

        $decimals = (int) ($data['decimals'] ?? 0);
        if ($decimals < 0 || $decimals > 3) {
            throw new DomainException('عدد الكسور بين ٠ و٣');
        }

        $baseId = $data['base_unit_id'] ?? null;
        if ($baseId) {
            $base = MerchantUnit::where('id', $baseId)
                ->where('merchant_user_id', $merchant->id)->first();
            if (! $base) {
                throw new DomainException('الوحدة الأساس غير موجودة');
            }
            if ($base->base_unit_id !== null) {
                // **مستوىً واحد**: كرتون ← حبّة. وسلسلةٌ أطول تُنتج معاملَ
                // تحويلٍ يُحسب بطريقتين فيختلف.
                throw new DomainException('الوحدة الأساس لا يجوز أن تكون مشتقّة بدورها');
            }
        }

        $factor = (string) ($data['factor'] ?? '1');
        if (bccomp($factor, '0', 4) <= 0) {
            throw new DomainException('معامل التحويل يجب أن يكون موجباً');
        }

        return MerchantUnit::create([
            'uuid' => (string) Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => $name,
            'code' => $this->uniqueCode('merchant_units', $merchant->id, $data['code'] ?? $name),
            'decimals' => $decimals,
            'base_unit_id' => $baseId ?: null,
            'factor' => $factor,
            'is_active' => true,
        ]);
    }

    public function defaultUnit(User $merchant): MerchantUnit
    {
        return MerchantUnit::firstOrCreate(
            ['merchant_user_id' => $merchant->id, 'code' => 'PCS'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'حبة', 'decimals' => 0, 'factor' => 1, 'is_active' => true,
            ],
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  الباركودات
    // ══════════════════════════════════════════════════════════════════

    /**
     * إضافةُ باركودٍ لصنف — **والحجمُ جزءٌ من الرمز**.
     *
     * كرتونٌ فيه ٢٤ يُمسح فيضيف ٢٤ حبّةً للسلّة. ومن أضافه بحجم ١ يخصم
     * حبّةً ويبيع كرتوناً — **ينحرف المخزونُ ثلاثاً وعشرين كلَّ مسح**.
     */
    public function addBarcode(User $merchant, int $productId, array $data): ProductBarcode
    {
        $code = trim((string) ($data['barcode'] ?? ''));
        if ($code === '') {
            throw new DomainException('الباركود مطلوب');
        }

        $product = MerchantProduct::where('id', $productId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (! $product) {
            throw new DomainException('الصنف غير موجود');
        }
        if ($product->is_variant_parent) {
            throw new DomainException('الصنف الأب لا يُمسح — الباركود لمتغيّراته');
        }

        $clash = ProductBarcode::where('merchant_user_id', $merchant->id)
            ->where('barcode', $code)->first();
        if ($clash) {
            $other = MerchantProduct::find($clash->product_id);
            throw new DomainException(
                'هذا الباركود مسجَّل على «' . ($other->name ?? 'صنف آخر') . '»');
        }

        $pack = (string) ($data['pack_size'] ?? '1');
        if (bccomp($pack, '0', 3) <= 0) {
            throw new DomainException('حجم العبوة يجب أن يكون موجباً');
        }

        return DB::transaction(function () use ($merchant, $product, $code, $data, $pack) {
            $isPrimary = (bool) ($data['is_primary'] ?? false)
                || ! ProductBarcode::where('product_id', $product->id)->exists();

            if ($isPrimary) {
                ProductBarcode::where('product_id', $product->id)
                    ->update(['is_primary' => false]);
            }

            $row = ProductBarcode::create([
                'merchant_user_id' => $merchant->id,
                'product_id' => $product->id,
                'barcode' => $code,
                'unit_id' => $data['unit_id'] ?? $product->unit_id,
                'pack_size' => $pack,
                'is_primary' => $isPrimary,
            ]);

            if ($isPrimary) {
                // المرآةُ القديمة تتبع الأساسيّ — تقرؤها شاشاتٌ اليوم.
                $product->newQuery()->where('id', $product->id)
                    ->update(['barcode' => $code]);
            }

            return $row;
        });
    }

    /**
     * مسحُ رمزٍ — **يعيد الصنفَ ومعه كم حبّةً يعني هذا الرمز**.
     */
    public function scan(User $merchant, string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $row = ProductBarcode::where('merchant_user_id', $merchant->id)
            ->where('barcode', $barcode)->with('product')->first();

        if ($row && $row->product) {
            return [
                'product' => $row->product,
                'pack_size' => (string) $row->pack_size,
                'unit_id' => $row->unit_id,
            ];
        }

        // احتياطاً: الأصنافُ التي لم تُهاجَر بعد تقرأ العمودَ القديم.
        $legacy = MerchantProduct::where('merchant_user_id', $merchant->id)
            ->where('barcode', $barcode)->where('is_active', true)->first();

        return $legacy ? ['product' => $legacy, 'pack_size' => '1', 'unit_id' => $legacy->unit_id] : null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  المتغيّرات — المرحلة ٣
    // ══════════════════════════════════════════════════════════════════

    /**
     * توليدُ متغيّراتٍ من محاور: `{"اللون":["أحمر","أزرق"],"المقاس":["S","L"]}`
     * ⇒ أربعةُ أصناف، كلٌّ منها صنفٌ كاملٌ يُباع ويُخزَّن.
     *
     * **والأبُ يصير مِظلّةً لا يُباع** — وبيعُه يعني بيعَ «قميص» بلا لون.
     */
    /**
     * @param  string|null  $unallocated  **يُخرَج بالإشارة**: مخزونُ الأب الذي
     *   صُفِّر ويحتاج توزيعاً على المتغيّرات. ومعاملٌ اختياريٌّ بالإشارة
     *   يُبقي النداءات القائمة كما هي حرفاً بحرف — وهي في متحكّمٍ
     *   وحارسَين.
     */
    public function generateVariants(
        User $merchant,
        int $parentId,
        array $axes,
        ?string &$unallocated = null,
    ): array {
        $parent = MerchantProduct::where('id', $parentId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (! $parent) {
            throw new DomainException('الصنف الأب غير موجود');
        }
        if ($parent->parent_product_id !== null) {
            throw new DomainException('المتغيّر لا يُولّد متغيّرات — مستوىً واحد');
        }

        $axes = array_filter($axes, static fn ($v) => is_array($v) && $v !== []);
        if ($axes === []) {
            throw new DomainException('لا محاور للمتغيّرات');
        }

        $combos = $this->cartesian($axes);

        // **حدٌّ على العدد**: ثلاثةُ محاورَ بعشرة قيمٍ = ألفُ صنفٍ في ضغطة،
        // ولا يُتراجع عنها بضغطة.
        if (count($combos) > 200) {
            throw new DomainException(
                'المحاور تُنتج ' . count($combos) . ' متغيّراً — الحدّ ٢٠٠ في المرّة');
        }

        return DB::transaction(function () use ($merchant, $parent, $combos, &$unallocated) {
            // ══════════════════════════════════════════════════════════
            // AMIAL-VARIANT-PARENT-001 — **مخزونُ الأب لا يضيع صامتاً.**
            //
            // كان السطرُ التالي يقلب الأبَ مِظلّةً **ويترك `quantity` كما
            // هي**. فتاجرٌ عنده عشرةُ قمصانٍ يولّد المتغيّراتِ فإذا:
            //
            //     الأب     : ١٠ قطعة  ← **لا تُباع** (صار مِظلّة)
            //     المتغيّرات: ٠ لكلٍّ  ← «نفذ المخزون» على الشاشة
            //
            // **فعشرُ قطعٍ حقيقيّةٍ اختفت من البيع بضغطةٍ واحدة**، ولا خطأَ
            // في أيّ سجلّ — الصفُّ موجودٌ ورقمُه صحيح، لكن لا بابَ إليه.
            //
            // **ولا تُوزَّع تلقائيّاً**: قسمةُ العشرة على تسعةِ متغيّراتٍ
            // اختراعٌ لا يعرفه إلّا التاجر (كم أحمرَ وكم أزرق؟). فالمخزونُ
            // **يُصفَّر بحركةِ مخزونٍ مسجَّلةٍ سببُها `correction`**، ويُعاد
            // العددُ في الردّ ليقوله التطبيقُ صراحةً: «١٠ وحداتٍ بانتظار
            // التوزيع». (القاعدة السابعة: الغيابُ يُقال ولا يُبتلع.)
            // ══════════════════════════════════════════════════════════
            $leftOver = (string) ($parent->quantity ?? '0');
            $hadStock = bccomp($leftOver, '0', 3) > 0;

            if ($hadStock) {
                try {
                    $stock = app(StockService::class);
                    $stock->move(
                        product: $parent,
                        location: $stock->defaultLocation($merchant->id),
                        delta: '-'.$leftOver,
                        reason: 'correction',
                        actor: $merchant,
                        note: 'تحويلُ الصنف إلى مِظلّةِ متغيّرات — المخزونُ '
                            .'ينتقل إلى المتغيّرات ويُوزَّع يدويّاً',
                        allowNegative: true,
                    );
                } catch (\Throwable $e) {
                    // **ولا يسقط التوليدُ لأجل حركةِ أثر.** الأثرُ يُحاوَل،
                    // والتصفيرُ يقع بكلّ حال — فبقاءُ الرقم على الأب أخطرُ
                    // من غياب سطرٍ في سجلّ الحركة.
                    \Log::warning('AMIAL-VARIANT-PARENT-001: تعذّر تسجيل حركة تصفير الأب', [
                        'product_id' => $parent->id, 'error' => $e->getMessage(),
                    ]);
                }

                $parent->quantity = '0';
            }

            $unallocated = $hadStock ? $leftOver : '0';

            $parent->is_variant_parent = true;
            $parent->track_stock = false;
            $parent->save();

            $made = [];
            foreach ($combos as $combo) {
                $signature = implode('|', array_values($combo));

                $exists = MerchantProduct::where('parent_product_id', $parent->id)
                    ->get()->first(fn ($p) => implode('|', array_values(
                        (array) ($p->variant_attributes ?? []))) === $signature);
                if ($exists) {
                    continue;   // إعادةُ التوليد لا تُكرّر ما وُلد
                }

                $made[] = MerchantProduct::create([
                    'uuid' => (string) Str::uuid(),
                    'merchant_user_id' => $merchant->id,
                    'name' => $parent->name,
                    'sku' => $this->variantSku($parent, $combo),
                    'price' => $parent->price,
                    'cost_price' => $parent->cost_price,
                    'offer_price' => $parent->offer_price,
                    'quantity' => '0',
                    'category_id' => $parent->category_id,
                    'category' => $parent->category,
                    'brand_id' => $parent->brand_id,
                    'unit_id' => $parent->unit_id,
                    'parent_product_id' => $parent->id,
                    'variant_attributes' => $combo,
                    'is_variant_parent' => false,
                    'track_stock' => true,
                    'is_active' => true,
                ]);
            }

            return $made;
        });
    }

    /** الضربُ الديكارتيّ للمحاور. */
    private function cartesian(array $axes): array
    {
        $result = [[]];

        foreach ($axes as $axis => $values) {
            $next = [];
            foreach ($result as $row) {
                foreach ($values as $v) {
                    $next[] = $row + [$axis => (string) $v];
                }
            }
            $result = $next;
        }

        return $result;
    }

    private function variantSku(MerchantProduct $parent, array $combo): string
    {
        $base = $parent->sku ?: ('P' . $parent->id);
        $tail = strtoupper(substr(md5(implode('|', array_values($combo))), 0, 5));

        return substr($base, 0, 40) . '-' . $tail;
    }

    /** توليدُ SKU لصنفٍ لا رمزَ له — **ولا يتكرّر داخل التاجر**. */
    public function ensureSku(MerchantProduct $product): string
    {
        if (! empty($product->sku)) {
            return $product->sku;
        }

        $prefix = 'SKU';
        if ($product->category_id) {
            $cat = MerchantCategory::find($product->category_id);
            if ($cat) {
                $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $cat->code) ?: 'CAT', 0, 4)) ?: 'CAT';
            }
        }

        for ($i = 0; $i < 5; $i++) {
            $sku = $prefix . '-' . str_pad((string) $product->id, 5, '0', STR_PAD_LEFT)
                . ($i > 0 ? '-' . strtoupper(Str::random(3)) : '');

            $taken = MerchantProduct::where('merchant_user_id', $product->merchant_user_id)
                ->where('sku', $sku)->where('id', '!=', $product->id)->exists();
            if (! $taken) {
                $product->newQuery()->where('id', $product->id)->update(['sku' => $sku]);

                return $sku;
            }
        }

        throw new DomainException('تعذّر توليد رمز صنف فريد');
    }

    // ══════════════════════════════════════════════════════════════════

    private function uniqueCode(string $table, int $merchantId, string $seed): string
    {
        $base = Str::slug(trim($seed), '_');
        if ($base === '') {
            $base = 'c';
        }
        $base = substr($base, 0, 30);

        $code = strtoupper($base);
        $n = 1;
        while (DB::table($table)->where('merchant_user_id', $merchantId)
            ->where('code', $code)->exists()) {
            $code = strtoupper($base) . '_' . (++$n);
            if ($n > 200) {
                $code = strtoupper($base) . '_' . strtoupper(Str::random(4));
                break;
            }
        }

        return substr($code, 0, 40);
    }
}
