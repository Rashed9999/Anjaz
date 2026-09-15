<?php

namespace App\Services;

use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\Retail\SaleLine;
use App\Models\User;
use Carbon\Carbon;

/**
 * AMIAL-SALES-BREAKDOWN-001 — **المبيعاتُ بالصنف وبالتصنيف.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان في المنتج `top_products`: **خمسةُ أسماءٍ بكمّيّاتها ليومٍ واحد**،
 * بلا إيرادٍ ولا تكلفةٍ ولا تصنيف. فالتاجرُ يعرف **ماذا** باع ولا يعرف
 * **بكم** ولا **بأيّ ربح** — وهو السؤالُ الذي يُغيّر ما يطلبه غداً.
 *
 * **وخمسةُ حدودٍ تحكم هذا التقرير، كلٌّ منها ثمنُ رقمٍ يكذب:**
 *
 *   ① **المرتجعُ يُطرَح.** `merchant_sale_items.returned_quantity` قائمٌ
 *      في الجدول منذ بُني، وقراءةُ `quantity` وحدَها تجعل صنفاً بيع
 *      عشرين ورُدّ ثمانيةَ عشرَ يتصدّر القائمة — **فيُعاد طلبُه**، وهو
 *      بالضبط الصنفُ الذي يجب أن يقلّ. والطرحُ **تناسبيٌّ** لا بالسعر
 *      المفرد: `line_total` يحمل الخصمَ فيه.
 *
 *   ② **والتكلفةُ المجهولةُ لا تُقرأ صفراً.** سطرٌ بلا `line_cost` يجعل
 *      الربحَ يساوي الإيراد — **أي هامشاً ١٠٠٪ على بضاعةٍ اشتُريت
 *      بمال**. فتُعزَل الأسطرُ المجهولةُ وتُعدّ ويُقال إيرادُها،
 *      والهامشُ يُحسب على ما عُرف وحدَه. (القاعدة السابعة.)
 *
 *   ③ **و«بلا تصنيف» تصنيفٌ يُقال.** منتجٌ لم يُصنَّف لا يُدسّ في
 *      «أخرى» ولا يُطوى: من رأى أنّ نصف مبيعاته «بلا تصنيف» عرف أنّ
 *      عليه أن يُصنّف، ومن لم يره ظنّ تقريرَه كاملاً.
 *
 *   ④ **والصنفُ المحذوفُ لا يُسقِط بيعتَه.** الاسمُ في السطر لقطةٌ وقتَ
 *      البيع، فيُقرأ منه — و`product_id` للتصنيف وحدَه. فحذفُ منتجٍ
 *      اليومَ لا يمحو ما بيع منه أمس.
 *
 *   ⑤ **والمدى يُقال في الردّ.** «١٢٠٬٠٠٠ ريالاً» بلا مدىً ليست رقماً:
 *      اليومَ؟ الشهرَ؟ فيُعاد `from`/`to` كما فُهما لا كما أُرسلا.
 *
 * يظهر في : التطبيق ← الكاشير ← «تقرير الأصناف» · وفي لوحة الإدارة: لا
 * (تقريرُ تاجرٍ عن بضاعته هو، لا رقمٌ للمنصّة).
 *
 * @see \Tests\Feature\SalesBreakdownGuardTest
 */
class SalesBreakdownService
{
    public const NO_CATEGORY = 'بلا تصنيف';

    /** أقصى مدىً يُقبل — تقريرٌ بلا سقفٍ يقرأ سنتين ويسقط بمهلة. */
    public const MAX_DAYS = 366;

    /**
     * @return array{range:array,totals:array,items:array,categories:array,cost_coverage:array}
     */
    public function report(User $merchant, ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $lines = SaleLine::query()
            ->whereIn('sale_id', MerchantSale::where('merchant_user_id', $merchant->id)
                ->whereIn('status', ['completed', 'credit_unpaid', 'credit_paid'])
                ->whereBetween('created_at', [$start, $end])
                ->select('id'))
            ->get(['product_id', 'name', 'barcode', 'quantity',
                'returned_quantity', 'line_total', 'line_cost']);

        // ④ التصنيفُ يُسأل من المنتج، والاسمُ من السطر.
        $categories = MerchantProduct::where('merchant_user_id', $merchant->id)
            ->whereNotNull('category')
            ->pluck('category', 'id');

        $items = [];
        $byCategory = [];

        $unknownCostLines = 0;
        $unknownCostRevenue = '0';

        $totalRevenue = '0';
        $totalCost = '0';
        $knownCostRevenue = '0';
        $totalQty = '0';

        foreach ($lines as $line) {
            $qty = (string) $line->quantity;

            if (bccomp($qty, '0', 3) <= 0) {
                continue;
            }

            // ① المرتجعُ يُطرَح تناسبيّاً — و`line_total` يحمل الخصم.
            $returned = (string) ($line->returned_quantity ?? '0');
            $netQty = bcsub($qty, $returned, 3);

            if (bccomp($netQty, '0', 3) <= 0) {
                continue;
            }

            $ratio = bcdiv($netQty, $qty, 8);
            $revenue = bcmul((string) $line->line_total, $ratio, 4);

            $hasCost = $line->line_cost !== null;
            $cost = $hasCost ? bcmul((string) $line->line_cost, $ratio, 4) : '0';

            if ($hasCost) {
                $totalCost = bcadd($totalCost, $cost, 4);
                $knownCostRevenue = bcadd($knownCostRevenue, $revenue, 4);
            } else {
                // ② المجهولةُ تُعزَل ولا تُقرأ صفراً.
                $unknownCostLines++;
                $unknownCostRevenue = bcadd($unknownCostRevenue, $revenue, 4);
            }

            $totalRevenue = bcadd($totalRevenue, $revenue, 4);
            $totalQty = bcadd($totalQty, $netQty, 3);

            $pid = $line->product_id !== null ? (int) $line->product_id : null;
            $key = $pid !== null ? "p{$pid}" : ('n'.$line->name);

            $items[$key] ??= [
                'product_id' => $pid,
                'name' => $line->name ?: '—',
                'barcode' => $line->barcode,
                'category' => $pid !== null ? ($categories[$pid] ?? self::NO_CATEGORY) : self::NO_CATEGORY,
                'qty' => '0', 'returned_qty' => '0',
                'revenue' => '0', 'cost' => '0',
                'unknown_cost_lines' => 0,
            ];

            $items[$key]['qty'] = bcadd($items[$key]['qty'], $netQty, 3);
            $items[$key]['returned_qty'] = bcadd($items[$key]['returned_qty'], $returned, 3);
            $items[$key]['revenue'] = bcadd($items[$key]['revenue'], $revenue, 4);
            $items[$key]['cost'] = bcadd($items[$key]['cost'], $cost, 4);

            if (! $hasCost) {
                $items[$key]['unknown_cost_lines']++;
            }

            // ③ «بلا تصنيف» تصنيفٌ يُقال.
            $cat = $items[$key]['category'];
            $byCategory[$cat] ??= [
                'category' => $cat, 'qty' => '0', 'revenue' => '0',
                'cost' => '0', 'items' => 0, 'unknown_cost_lines' => 0,
            ];
            $byCategory[$cat]['qty'] = bcadd($byCategory[$cat]['qty'], $netQty, 3);
            $byCategory[$cat]['revenue'] = bcadd($byCategory[$cat]['revenue'], $revenue, 4);
            $byCategory[$cat]['cost'] = bcadd($byCategory[$cat]['cost'], $cost, 4);

            if (! $hasCost) {
                $byCategory[$cat]['unknown_cost_lines']++;
            }
        }

        foreach ($byCategory as $cat => $_) {
            $byCategory[$cat]['items'] = count(array_filter(
                $items, fn ($i) => $i['category'] === $cat));
        }

        $items = array_values(array_map(fn ($i) => $this->finish($i), $items));
        $cats = array_values(array_map(fn ($c) => $this->finish($c), $byCategory));

        usort($items, fn ($a, $b) => bccomp($b['revenue'], $a['revenue'], 4));
        usort($cats, fn ($a, $b) => bccomp($b['revenue'], $a['revenue'], 4));

        return [
            // ⑤ المدى كما فُهم لا كما أُرسل.
            'range' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
                'days' => $this->days($start, $end),
            ],
            'totals' => [
                'revenue' => MoneyService::normalize($totalRevenue),
                'cost' => MoneyService::normalize($totalCost),
                'profit' => MoneyService::normalize(bcsub($knownCostRevenue, $totalCost, 4)),
                'margin_percent' => $this->margin($knownCostRevenue, $totalCost),
                'qty' => $this->tidy($totalQty),
                'items_count' => count($items),
                'categories_count' => count($cats),
            ],
            'items' => $items,
            'categories' => $cats,
            // **الشفافيّةُ جزءٌ من الرقم لا حاشيةٌ له.**
            'cost_coverage' => [
                'unknown_cost_lines' => $unknownCostLines,
                'unknown_cost_revenue' => MoneyService::normalize($unknownCostRevenue),
                'note' => $unknownCostLines > 0
                    ? "الربحُ محسوبٌ على ما عُرفت تكلفتُه؛ {$unknownCostLines} سطراً "
                        .'بلا تكلفةٍ مُدخَلة — أدخِل تكلفةَ الشراء ليصدق الهامش'
                    : null,
            ],
        ];
    }

    // ── داخليّ ─────────────────────────────────────────────────────────

    /** @return array{0:Carbon,1:Carbon} */
    private function range(?string $from, ?string $to): array
    {
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(29)->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        if ($this->days($start, $end) > self::MAX_DAYS) {
            $start = $end->copy()->subDays(self::MAX_DAYS - 1)->startOfDay();
        }

        return [$start, $end];
    }

    /**
     * **وعددُ الأيّام صحيحٌ لا كسر.**
     *
     * `diffInDays` بين بدايةِ يومٍ ونهايةِ آخرَ يُخرج `6.99999…` في
     * Carbon 3 — فيُطبع على شاشة التاجر «٧٫٩٩٩٩٩٩٩٩٩٩٨ يوماً». والفرقُ
     * يُقاس بين بدايتَي اليومين ثمّ يُزاد واحدٌ ليشمل طرفَيه.
     */
    private function days(Carbon $start, Carbon $end): int
    {
        return (int) $start->copy()->startOfDay()
            ->diffInDays($end->copy()->startOfDay()) + 1;
    }

    private function finish(array $row): array
    {
        $row['profit'] = MoneyService::normalize(bcsub($row['revenue'], $row['cost'], 4));
        $row['margin_percent'] = $this->margin($row['revenue'], $row['cost']);
        $row['revenue'] = MoneyService::normalize($row['revenue']);
        $row['cost'] = MoneyService::normalize($row['cost']);
        $row['qty'] = $this->tidy($row['qty']);

        if (isset($row['returned_qty'])) {
            $row['returned_qty'] = $this->tidy($row['returned_qty']);
        }

        return $row;
    }

    /** **وهامشٌ على إيرادٍ صفرٍ ليس صفراً — هو «لا ينطبق».** */
    private function margin(string $revenue, string $cost): ?string
    {
        if (bccomp($revenue, '0', 4) <= 0) {
            return null;
        }

        return bcmul(bcdiv(bcsub($revenue, $cost, 4), $revenue, 6), '100', 2);
    }

    /** الكمّيّةُ عشريّةٌ في المخزن — وتُخرج صحيحةً متى كانت صحيحة. */
    private function tidy(string $qty): string
    {
        return bccomp($qty, bcadd($qty, '0', 0), 3) === 0
            ? bcadd($qty, '0', 0)
            : rtrim(rtrim($qty, '0'), '.');
    }
}
