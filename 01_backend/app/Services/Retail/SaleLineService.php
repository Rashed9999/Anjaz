<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\Retail\SaleLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — **البابُ الواحد لأسطر المبيعة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا خدمةٌ لا سطرٌ في `CashierService`:**
 *
 * كانت أسطرُ المبيعة تُقرأ في **أربعة مواضع** بأربع طرق. والكاشير يرسل
 * `qty`، والمطعمُ يرسل `quantity`:
 *
 * | الموضع | يقرأ | فالنتيجة |
 * |---|---|---|
 * | `decrementStock` | `quantity ?? qty` | صحيح |
 * | `profitReport` | `quantity ?? qty` | صحيح |
 * | **`dailyReport`** | **`qty` وحدَه** | **بيعُ المطعم يُعدّ حبّةً واحدة** |
 *
 * فبيعُ عشرين طبقاً يظهر في «أكثر المبيعات» طبقاً واحداً. **ولا خطأ في
 * أيّ سجلّ** — رقمٌ يُقرأ حقيقةً وهو خطأ. وعلاجُه ليس إضافةَ `?? quantity`
 * في الموضع الثالث؛ فرابعٌ سيُكتب غداً. **بل شكلٌ واحدٌ يُطبَّع عند
 * العتبة، ولا يُقرأ الخامُّ بعدها.**
 */
class SaleLineService
{
    /**
     * تطبيعُ عنصرٍ خامٍّ آتٍ من التطبيق إلى شكلٍ واحد.
     *
     * **وهذا هو الموضعُ الوحيد** الذي يُقبل فيه `qty` أو `quantity`.
     */
    public function normalize(array $raw): ?array
    {
        $qty = (string) ($raw['quantity'] ?? $raw['qty'] ?? 1);
        if (! is_numeric($qty) || bccomp($qty, '0', 3) <= 0) {
            return null;
        }

        $price = (string) ($raw['price'] ?? $raw['unit_price'] ?? 0);
        if (! is_numeric($price)) {
            $price = '0';
        }

        $discount = (string) ($raw['discount'] ?? $raw['line_discount'] ?? 0);
        if (! is_numeric($discount) || bccomp($discount, '0', 4) < 0) {
            $discount = '0';
        }

        $pid = $raw['product_id'] ?? null;

        return [
            'product_id' => $pid ? (int) $pid : null,
            'name' => trim((string) ($raw['name'] ?? '')) ?: 'صنف',
            'barcode' => $raw['barcode'] ?? null,
            'quantity' => $qty,
            'unit_price' => $price,
            'line_discount' => $discount,
        ];
    }

    /** تطبيعُ قائمةٍ كاملة — وتُسقَط الأسطرُ التي لا كمّيّةَ لها. */
    private function normalizeAll(array $items): array
    {
        $out = [];
        foreach ($items as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $line = $this->normalize($raw);
            if ($line !== null) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * كتابةُ أسطر المبيعة — **والتكلفةُ تُقرأ من المنتج هنا ثمّ تُجمَّد**.
     *
     * وتُقرأ من المنتج لا من الطلب: التطبيقُ لا يعرف التكلفة ولا يجوز أن
     * يمليها — من أملى تكلفتَه أملى ربحَه.
     */
    public function writeLines(MerchantSale $sale, array $items): Collection
    {
        $normalized = $this->normalizeAll($items);
        if ($normalized === []) {
            return collect();
        }

        $productIds = array_values(array_filter(array_column($normalized, 'product_id')));
        $products = $productIds === []
            ? collect()
            : MerchantProduct::where('merchant_user_id', $sale->merchant_user_id)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

        $rows = collect();

        foreach ($normalized as $line) {
            $product = $line['product_id'] ? $products->get($line['product_id']) : null;

            // ── التكلفة ───────────────────────────────────────────────
            //
            // **الفراغُ ليس صفراً.** منتجٌ بلا تكلفةٍ مُدخَلة، أو سطرٌ
            // بلا منتجٍ أصلاً، تكلفتُه غيرُ معروفة. وصفرٌ مكانَها يجعل
            // الهامش ١٠٠٪ ويُقرأ ربحاً ممتازاً على بضاعةٍ لم يُعرف ثمنُها.
            $unitCost = null;
            $source = SaleLine::COST_UNKNOWN;
            if ($product !== null
                && $product->cost_price !== null
                && is_numeric((string) $product->cost_price)
                && bccomp((string) $product->cost_price, '0', 4) > 0
            ) {
                $unitCost = (string) $product->cost_price;
                $source = SaleLine::COST_CAPTURED;
            }

            $gross = bcmul($line['quantity'], $line['unit_price'], 4);
            $total = bcsub($gross, $line['line_discount'], 4);
            if (bccomp($total, '0', 4) < 0) {
                $total = '0.0000';
            }

            $rows->push(SaleLine::create([
                'uuid' => (string) Str::uuid(),
                'merchant_user_id' => $sale->merchant_user_id,
                'sale_id' => $sale->id,
                'sale_ulid' => $sale->sale_ulid,
                'product_id' => $line['product_id'],
                'name' => $product?->name ?: $line['name'],
                'barcode' => $line['barcode'] ?? $product?->barcode,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_discount' => $line['line_discount'],
                'line_total' => $total,
                'unit_cost' => $unitCost,
                'line_cost' => $unitCost !== null ? bcmul($line['quantity'], $unitCost, 4) : null,
                'cost_source' => $source,
                'returned_quantity' => 0,
                'zone_code' => $sale->zone_code ?? 'SOUTH',
            ]));
        }

        return $rows;
    }

    /**
     * تكلفةُ المبيعات لمجموعةِ أسطر — **مفصولةً عمّا لا يُعرف**.
     *
     * ولا يُجمع المعروفُ والمجهول في رقمٍ واحد: مجموعٌ فيه أسطرٌ بلا
     * تكلفةٍ يُقرأ ربحاً أعلى من الحقيقة بمقدار ما جُهل — **والقارئُ لا
     * يرى الفرق**. فيخرج العددان معاً: ما حُسب، وكم سطراً لم يُحسب.
     *
     * @return array{cost:string, known_lines:int, unknown_lines:int,
     *               unknown_revenue:string, estimated_lines:int}
     */
    public function costOf(iterable $lines): array
    {
        $cost = '0';
        $known = 0;
        $unknown = 0;
        $estimated = 0;
        $unknownRevenue = '0';

        foreach ($lines as $line) {
            if ($line->line_cost !== null) {
                $cost = bcadd($cost, (string) $line->line_cost, 4);
                $known++;
                if ($line->cost_source === SaleLine::COST_ESTIMATED) {
                    $estimated++;
                }
                continue;
            }

            $unknown++;
            $unknownRevenue = bcadd($unknownRevenue, (string) $line->line_total, 4);
        }

        return [
            'cost' => $cost,
            'known_lines' => $known,
            'unknown_lines' => $unknown,
            'estimated_lines' => $estimated,
            'unknown_revenue' => $unknownRevenue,
        ];
    }
}
