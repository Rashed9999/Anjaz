<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\Retail\StockMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٠ — **البابُ الوحيد إلى المخزون**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُكتب على `on_hand` من خارج هذه الخدمة.**
 *
 * فكلُّ تغيّرٍ حركةٌ لها سببٌ وموقعٌ وفاعل، والرصيدُ لقطةٌ تتبعها. وكان
 * `CashierService` يكتب `$product->update(['quantity' => $newQty])`
 * مباشرةً — **فلا يُعرف من أين نقص**، ويُخرج الجردُ فرقاً بلا أثر.
 *
 * ولمَ خدمةٌ لا سمة: يمسّها البيعُ والمشترياتُ والتحويلُ والجردُ والهالك
 * والمرتجع — ستّةُ مسارات. وسمةٌ تُنسَخ في ستّة أصنافٍ تنحرف عند أوّل
 * تعديل. (والمهارةُ العشرون: `NO GOD SERVICE` — وهذه تفعل شيئاً واحداً:
 * تُحرّك المخزون.)
 */
class StockService
{
    /**
     * الموقعُ الافتراضيّ للتاجر — يُنشأ إن لم يكن.
     *
     * **ولا يُترك النداءُ بلا موقع**: بيعٌ بلا موقعٍ يعود بنا إلى الرقم
     * العالميّ الذي وُجدت هذه الطبقةُ لإزالته.
     */
    public function defaultLocation(int $merchantUserId): MerchantLocation
    {
        $loc = MerchantLocation::where('merchant_user_id', $merchantUserId)
            ->where('is_default', true)->first();

        if ($loc) {
            return $loc;
        }

        return MerchantLocation::create([
            'merchant_user_id' => $merchantUserId,
            'kind' => 'store',
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function addLocation(User $merchant, array $data): MerchantLocation
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if ($code === '') {
            throw new DomainException('رمز الموقع مطلوب');
        }

        if (MerchantLocation::where('merchant_user_id', $merchant->id)
            ->where('code', $code)->exists()) {
            throw new DomainException("الرمز «{$code}» مستعمل في موقعٍ آخر");
        }

        $kind = $data['kind'] ?? 'store';

        if (! in_array($kind, MerchantLocation::KINDS, true)) {
            throw new DomainException('نوع الموقع غير صحيح (متجر أو مستودع)');
        }

        return MerchantLocation::create([
            'merchant_user_id' => $merchant->id,
            'kind' => $kind,
            'name' => trim((string) ($data['name'] ?? $code)),
            'code' => $code,
            'branch_id' => $data['branch_id'] ?? null,
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'is_active' => true,
            'is_default' => false,
            'created_by' => $merchant->id,
        ]);
    }

    /**
     * **الحركةُ الواحدة** — وكلُّ ما يمسّ المخزون يمرّ من هنا.
     *
     * @param  string  $delta  موجبٌ يزيد وسالبٌ ينقص
     */
    public function move(
        MerchantProduct $product,
        MerchantLocation $location,
        string $delta,
        string $reason,
        ?User $actor = null,
        ?string $unitCost = null,
        ?string $sourceType = null,
        $sourceId = null,
        ?string $note = null,
        bool $allowNegative = false,
    ): StockMovement {
        if (! in_array($reason, StockMovement::REASONS, true)) {
            throw new DomainException("سبب حركة مخزون غير معروف: {$reason}");
        }

        if (bccomp($delta, '0', 3) === 0) {
            throw new DomainException('حركة بمقدار صفر لا تُسجَّل');
        }

        // **الإشارةُ تُفحص ضدّ السبب.** فـ«بيع» يُنقص دائماً و«استلام»
        // يزيد دائماً، وانقلابُ الإشارة يقلب الجردَ كلَّه: يظهر فائضٌ
        // حيث العجز، ويُغلَق تحقيقٌ على قراءةٍ معكوسة.
        $isOut = bccomp($delta, '0', 3) < 0;

        if (in_array($reason, StockMovement::OUTBOUND, true) && ! $isOut) {
            throw new DomainException(
                "سبب «{$reason}» يُنقص المخزون دائماً — والمقدار موجب");
        }

        if (in_array($reason, StockMovement::INBOUND, true) && $isOut) {
            throw new DomainException(
                "سبب «{$reason}» يزيد المخزون دائماً — والمقدار سالب");
        }

        return DB::transaction(function () use (
            $product, $location, $delta, $reason, $actor,
            $unitCost, $sourceType, $sourceId, $note, $allowNegative
        ) {
            // **قفلُ الصفّ قبل القراءة.** بيعتان متزامنتان على آخر حبّةٍ
            // تقرآن ١ وتخصمان معاً فيصير ‎-١. (وهو عينُ ما قِيس في
            // شبّاك الوكيل تحت الضغط.)
            $stock = ProductStock::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->lockForUpdate()->first();

            if (! $stock) {
                $stock = $this->openStockRow($product, $location);
            }

            $after = bcadd((string) $stock->on_hand, $delta, 3);

            if (! $allowNegative && bccomp($after, '0', 3) < 0) {
                throw new DomainException(sprintf(
                    'الكمية غير كافية في «%s»: المتاح %s والمطلوب %s',
                    $location->name, $stock->on_hand, ltrim($delta, '-'),
                ));
            }

            $movement = StockMovement::create([
                'merchant_user_id' => $product->merchant_user_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'reason' => $reason,
                'quantity_delta' => $delta,
                'balance_after' => $after,
                // **التكلفةُ تُلتقَط لحظةَ الحركة** — بها تُحسب تكلفةُ
                // المبيعات. وقراءتُها لاحقاً من `cost_price` تُعطي تكلفةَ
                // اليوم لا تكلفةَ يوم البيع.
                'unit_cost' => $unitCost ?? $product->cost_price,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'actor_user_id' => $actor?->id,
                'note' => $note,
                'zone_code' => $product->zone_code ?? 'SOUTH',
            ]);

            $stock->update([
                'on_hand' => $after,
                'last_movement_at' => now(),
            ]);

            $this->syncLegacyQuantity($product);

            return $movement;
        });
    }


    /**
     * أوّلُ صفِّ مخزونٍ لصنفٍ في موقع — **ويتبنّى الرصيدَ القديم**.
     *
     * ══════════════════════════════════════════════════════════════════
     * الهجرةُ ملأت `product_stocks` للأصناف القائمة يومَها. **وما أُنشئ
     * بعدها بكمّيّةٍ في العمود القديم لا صفَّ له** — فأوّلُ بيعةٍ تبدأ من
     * صفرٍ وتُنتج سالباً، **ويختفي رصيدٌ كان مكتوباً**.
     *
     * فيُتبنّى الرقمُ القديم رصيداً افتتاحيّاً بحركةٍ مؤرَّخة: كان هو
     * الحقيقةَ حتّى هذه اللحظة، وإهمالُه محوُ بيانات.
     *
     * وهذا جسرٌ يعمل ما دام العمودُ القديم يُكتب — ويسقط وحدَه حين يُزال.
     */
    private function openStockRow(
        MerchantProduct $product, MerchantLocation $location,
    ): ProductStock {
        $legacy = (string) ($product->quantity ?? '0');

        // **لا يُتبنّى إلّا في الموقع الافتراضيّ**: الرقمُ القديم عالميٌّ
        // بلا موقع، ووضعُه في مستودعٍ أو فرعٍ ثانٍ يخترع مخزوناً لم يوجد.
        $adopt = $location->is_default && bccomp($legacy, '0', 3) > 0;

        $row = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand' => $adopt ? $legacy : '0',
            'reserved' => '0',
        ]);

        if ($adopt) {
            StockMovement::create([
                'merchant_user_id' => $product->merchant_user_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'reason' => 'opening_balance',
                'quantity_delta' => $legacy,
                'balance_after' => $legacy,
                'unit_cost' => $product->cost_price,
                'note' => 'تبنّي الرصيد القديم عند أوّل حركة',
                'zone_code' => $product->zone_code ?? 'SOUTH',
            ]);
        }

        return ProductStock::where('id', $row->id)->lockForUpdate()->first();
    }

    /**
     * **العمودُ القديم يبقى مرآةً لمجموع المواقع.**
     *
     * `merchant_products.quantity` تقرؤه شاشاتٌ وتقاريرُ ونقطةُ بيعٍ في
     * مواضعَ كثيرة، وحذفُه في هجرةٍ واحدة يُعطّل ما يعمل. فصار **مشتقّاً
     * لا مصدراً**، ويُزال حين لا يبقى قارئ.
     *
     * ولا يُقرأ منه قرارٌ في هذه الخدمة إطلاقاً.
     */
    private function syncLegacyQuantity(MerchantProduct $product): void
    {
        $total = (string) (ProductStock::where('product_id', $product->id)
            ->sum('on_hand') ?? '0');

        $product->newQuery()->where('id', $product->id)
            ->update(['quantity' => $total]);
    }

    /** المتاحُ للبيع في موقع — الموجودُ ناقصَ المحجوز. */
    public function available(int $productId, int $locationId): string
    {
        $s = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)->first();

        return $s ? $s->available() : '0';
    }

    /**
     * الرصيدُ **محسوباً من الحركات** — لا من اللقطة.
     *
     * تُستعمل في الحارس وفي المصالحة: لقطةٌ تنحرف عن مصدرها تصير رقماً
     * يُقرأ حقيقةً وهو ليس كذلك.
     */
    public function computedFromMovements(int $productId, int $locationId): string
    {
        return (string) (StockMovement::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->sum('quantity_delta') ?? '0');
    }

    /**
     * مخزونُ صنفٍ في كلّ المواقع — **ومن لا موقعَ له يُقال لا يُخمَّن**.
     */
    public function acrossLocations(MerchantProduct $product): array
    {
        return ProductStock::where('product_id', $product->id)
            ->with('location:id,name,kind,is_active')
            ->get()
            ->map(fn (ProductStock $s) => [
                'location_id' => (int) $s->location_id,
                'location' => $s->location->name ?? '—',
                'kind' => $s->location->kind ?? 'store',
                'on_hand' => (string) $s->on_hand,
                'reserved' => (string) $s->reserved,
                'available' => $s->available(),
                'is_low' => $s->isLow(),
                'never_counted' => $s->neverCounted(),
                'last_counted_at' => $s->last_counted_at?->toIso8601String(),
            ])->all();
    }

    /**
     * أصنافٌ تحت حدّ إعادة الطلب في موقع.
     *
     * **ومن لم يُضبَط له حدٌّ لا يُعدّ منخفضاً** — حدُّ الصفر يعني «لم
     * يُضبط»، وعدُّه منخفضاً يُغرق الشاشة بتنبيهاتٍ لا معنى لها.
     */
    public function lowStock(int $merchantUserId, ?int $locationId = null): array
    {
        $q = ProductStock::query()
            ->whereHas('product', fn ($w) => $w->where('merchant_user_id', $merchantUserId))
            ->where('reorder_level', '>', 0)
            ->whereColumn('on_hand', '<=', 'reorder_level')
            ->with(['product:id,name,barcode', 'location:id,name']);

        if ($locationId) {
            $q->where('location_id', $locationId);
        }

        return $q->limit(200)->get()
            ->map(fn (ProductStock $s) => [
                'product_id' => (int) $s->product_id,
                'product' => $s->product->name ?? '—',
                'location' => $s->location->name ?? '—',
                'on_hand' => (string) $s->on_hand,
                'reorder_level' => (string) $s->reorder_level,
                // كم يُطلب ليبلغ الحدَّ الأقصى — اقتراحٌ لا أمرُ شراء.
                'suggested_order' => bccomp((string) $s->max_level, '0', 3) > 0
                    ? bcsub((string) $s->max_level, (string) $s->on_hand, 3)
                    : null,
            ])->all();
    }
}
