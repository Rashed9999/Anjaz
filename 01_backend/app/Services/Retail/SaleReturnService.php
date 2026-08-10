<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\SaleLine;
use App\Models\Retail\SaleReturn;
use App\Models\Retail\SaleReturnItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٦ — **المرتجعُ سطراً سطراً**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * وكان المرتجعُ مبلغاً حرّاً: يُرتجَع «٥٠٠ ريال» من فاتورةٍ فيها خمسةُ
 * أصناف، **فلا يُعرف ما عاد إلى الرفّ** ولا ما نقص من مبيعات أيّ صنف،
 * ولا تُصحَّح تكلفةُ المبيعات لأنّ الصنفَ مجهول.
 *
 * **وقرارُ إعادة التخزين لكلّ سطر**: سليمٌ يعود للرفّ، وتالفٌ يذهب
 * هالكاً. وإعادةُ الجميع تلقائياً **تُعيد التالفَ للبيع** — ويشتريه غيرُ
 * الذي أعاده.
 *
 * **والمالُ ليس هنا.** `MerchantSaleRefundService` يملكه ويُنشئ قيدَه،
 * وهذه تملك البضاعةَ وأسطرَها. وبابان للمال يُنتجان مبلغين.
 */
class SaleReturnService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array  $lines  [['sale_item_id'=>, 'quantity'=>, 'condition'=>, 'restock'=>bool]]
     */
    public function create(User $merchant, string $saleUlid, array $lines, array $opts = []): SaleReturn
    {
        $sale = MerchantSale::where('sale_ulid', $saleUlid)
            ->where('merchant_user_id', $merchant->id)->with('lines')->first();
        if (! $sale) {
            throw new DomainException('العملية غير موجودة');
        }
        if ($lines === []) {
            throw new DomainException('لا أسطر في المرتجع');
        }

        return DB::transaction(function () use ($merchant, $sale, $lines, $opts) {
            $location = ! empty($opts['location_id'])
                ? MerchantLocation::where('id', $opts['location_id'])
                    ->where('merchant_user_id', $merchant->id)->first()
                : null;
            $location ??= $this->stock->defaultLocation($merchant->id);

            $return = SaleReturn::create([
                'uuid' => (string) Str::uuid(),
                'merchant_user_id' => $merchant->id,
                'sale_id' => $sale->id,
                'sale_ulid' => $sale->sale_ulid,
                'location_id' => $location->id,
                'total_amount' => '0',
                'refund_method' => $opts['refund_method'] ?? 'cash',
                'status' => 'pending',
                'created_by' => $opts['actor_id'] ?? $merchant->id,
                'reason' => $opts['reason'] ?? null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            $total = '0';

            foreach ($lines as $raw) {
                $lineId = (int) ($raw['sale_item_id'] ?? 0);
                $qty = (string) ($raw['quantity'] ?? 0);

                if (bccomp($qty, '0', 3) <= 0) {
                    continue;
                }

                /** @var SaleLine|null $line */
                $line = SaleLine::where('id', $lineId)->where('sale_id', $sale->id)->first();
                if (! $line) {
                    throw new DomainException('سطر المبيعة غير موجود في هذه العملية');
                }

                // **ولا يُرتجَع أكثر ممّا بيع** — ولا مرّتين.
                $available = $line->refundableQuantity();
                if (bccomp($qty, $available, 3) > 0) {
                    throw new DomainException(sprintf(
                        'المتاح للارتجاع من «%s» هو %s فقط', $line->name, $available));
                }

                $condition = $raw['condition'] ?? 'good';
                if (! in_array($condition, ['good', 'damaged', 'expired'], true)) {
                    throw new DomainException('حالة الصنف المرتجَع غير معروفة');
                }

                // **التالفُ لا يعود للرفّ ولو طُلب.** والقرارُ المسموح هو
                // «لا تُعِد السليم»، لا «أعِد التالف».
                $restock = $condition === 'good' && (bool) ($raw['restock'] ?? true);

                $lineTotal = bcmul($qty, (string) $line->unit_price, 4);
                $total = bcadd($total, $lineTotal, 4);

                SaleReturnItem::create([
                    'return_id' => $return->id,
                    'sale_item_id' => $line->id,
                    'product_id' => $line->product_id,
                    'name' => $line->name,
                    'quantity' => $qty,
                    'unit_price' => $line->unit_price,
                    'line_total' => $lineTotal,
                    'unit_cost' => $line->unit_cost,
                    'condition' => $condition,
                    'restock' => $restock,
                ]);
            }

            if ($return->items()->count() === 0) {
                throw new DomainException('لا أسطر صالحة في المرتجع');
            }

            $return->update(['total_amount' => $total]);

            return $return->fresh('items');
        });
    }

    /**
     * الاعتماد — **وهنا تعود البضاعةُ للرفّ** ويُقفَل السطرُ الأصليّ.
     */
    public function approve(User $actor, SaleReturn $return): SaleReturn
    {
        if ($return->status !== 'pending') {
            throw new DomainException('هذا المرتجع حُسم مسبقاً');
        }

        return DB::transaction(function () use ($actor, $return) {
            $location = MerchantLocation::findOrFail($return->location_id);

            foreach ($return->items()->get() as $item) {
                // ① يُقفَل السطرُ الأصليّ أوّلاً — **فلا يُرتجَع مرّتين**
                //    ولو تكرّر النداء.
                if ($item->sale_item_id) {
                    $line = SaleLine::lockForUpdate()->find($item->sale_item_id);
                    if ($line) {
                        $newReturned = bcadd(
                            (string) $line->returned_quantity, (string) $item->quantity, 3);
                        if (bccomp($newReturned, (string) $line->quantity, 3) > 0) {
                            throw new DomainException(
                                "«{$line->name}» ارتُجع بالكامل قبل اعتماد هذا المرتجع");
                        }
                        $line->update(['returned_quantity' => $newReturned]);
                    }
                }

                // ② البضاعةُ تعود إن كانت سليمةً وطُلبت إعادتُها
                if ($item->restock && $item->product_id) {
                    $product = MerchantProduct::find($item->product_id);
                    if ($product) {
                        $this->stock->move(
                            product: $product,
                            location: $location,
                            delta: (string) $item->quantity,
                            reason: 'sale_return',
                            actor: $actor,
                            unitCost: $item->unit_cost,
                            sourceType: 'sale_return',
                            sourceId: $return->id,
                            note: 'مرتجع ' . $return->sale_ulid,
                        );
                    }
                }
            }

            $return->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            return $return->fresh('items');
        });
    }

    public function reject(User $actor, SaleReturn $return, string $reason): SaleReturn
    {
        if ($return->status !== 'pending') {
            throw new DomainException('هذا المرتجع حُسم مسبقاً');
        }

        $return->update([
            'status' => 'rejected',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'reason' => trim($reason) ?: $return->reason,
        ]);

        return $return->fresh();
    }

    /** ما يمكن ارتجاعُه من بيعةٍ — **سطراً سطراً بالمتاح**. */
    public function refundableLines(User $merchant, string $saleUlid): array
    {
        $sale = MerchantSale::where('sale_ulid', $saleUlid)
            ->where('merchant_user_id', $merchant->id)->with('lines')->first();
        if (! $sale) {
            throw new DomainException('العملية غير موجودة');
        }

        return $sale->lines->map(fn (SaleLine $l) => [
            'sale_item_id' => $l->id,
            'product_id' => $l->product_id,
            'name' => $l->name,
            'sold_quantity' => (string) $l->quantity,
            'returned_quantity' => (string) $l->returned_quantity,
            'refundable_quantity' => $l->refundableQuantity(),
            'unit_price' => (string) $l->unit_price,
        ])->values()->all();
    }
}
