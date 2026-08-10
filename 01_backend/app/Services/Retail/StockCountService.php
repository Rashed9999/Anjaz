<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\Retail\MerchantCategory;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\Retail\StockCount;
use App\Models\Retail\StockCountItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٥ — **الجرد**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **و`system_quantity` لقطةٌ تُكتب لحظةَ فتح الجرد** ولا تُقرأ عند
 * الاعتماد. فبيعٌ يقع أثناء العدّ يغيّر النظامَ تحت يد العادّ: عدّ ٢٠
 * والنظامُ كان ٢٠، فبِيعت ثلاثٌ قبل الاعتماد، فيُقرأ فرقاً ‎+٣ ويُنسب
 * خطأَ إدخال — **وهو بيعٌ صحيحٌ تماماً**.
 *
 * **ومن عدّ ليس من يعتمد**: وإلّا كان الجردُ باباً خلفيّاً لتسوية النقص.
 *
 * **ولا يمسّ الجردُ المخزونَ إلّا عند الاعتماد**، وبحركةٍ واحدةٍ بسببٍ
 * `count_adjustment` — فيبقى الأثرُ مقروءاً بعد شهر.
 */
class StockCountService
{
    public function __construct(private StockService $stock) {}

    /**
     * فتحُ جردٍ وتجميدُ أرقام النظام.
     *
     * @param  array  $productIds  للجرد الموضعيّ وحدَه
     */
    public function open(
        User $merchant, int $locationId, string $kind = 'full',
        ?int $categoryId = null, array $productIds = [], ?int $actorId = null,
    ): StockCount {
        $location = MerchantLocation::where('id', $locationId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (! $location) {
            throw new DomainException('الموقع غير موجود');
        }

        if (! in_array($kind, ['full', 'cycle', 'spot'], true)) {
            throw new DomainException('نوع جرد غير معروف');
        }
        if ($kind === 'cycle' && ! $categoryId) {
            throw new DomainException('الجرد الدوري يحتاج تصنيفاً');
        }
        if ($kind === 'spot' && $productIds === []) {
            throw new DomainException('الجرد الموضعي يحتاج أصنافاً محدَّدة');
        }

        // **جردٌ مفتوحٌ في الموقع نفسِه يمنع ثانياً.** وجردان متزامنان
        // يُنتجان تسويتين على الرصيد نفسِه، والثانيةُ تُلغي الأولى.
        $open = StockCount::where('location_id', $location->id)
            ->whereIn('status', [StockCount::DRAFT, StockCount::COUNTING, StockCount::REVIEW])
            ->first();
        if ($open) {
            throw new DomainException("جرد «{$open->code}» ما زال مفتوحاً في هذا الموقع");
        }

        return DB::transaction(function () use (
            $merchant, $location, $kind, $categoryId, $productIds, $actorId
        ) {
            $count = StockCount::create([
                'uuid' => (string) Str::uuid(),
                'merchant_user_id' => $merchant->id,
                'location_id' => $location->id,
                'code' => $this->code($merchant->id),
                'kind' => $kind,
                'status' => StockCount::COUNTING,
                'scope_category_id' => $categoryId,
                'started_by' => $actorId ?? $merchant->id,
                'started_at' => now(),
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            $query = MerchantProduct::where('merchant_user_id', $merchant->id)
                ->where('is_active', true)
                ->where('is_variant_parent', false)
                ->where('track_stock', true);

            if ($kind === 'cycle') {
                $cat = MerchantCategory::where('id', $categoryId)
                    ->where('merchant_user_id', $merchant->id)->first();
                if (! $cat) {
                    throw new DomainException('التصنيف غير موجود');
                }
                // **الشجرةُ كلُّها** — وجردُ «ألبان» بلا «أجبان» يترك نصفَها.
                $query->whereIn('category_id', $cat->selfAndDescendantIds());
            }
            if ($kind === 'spot') {
                $query->whereIn('id', $productIds);
            }

            $rows = [];
            $query->orderBy('id')->chunk(300, function ($products) use (&$rows, $count, $location) {
                foreach ($products as $p) {
                    $snapshot = (string) (ProductStock::where('product_id', $p->id)
                        ->where('location_id', $location->id)->value('on_hand') ?? '0');

                    $rows[] = [
                        'count_id' => $count->id,
                        'product_id' => $p->id,
                        'name' => $p->displayName(),
                        'system_quantity' => $snapshot,
                        // **`null` = «لم يُعدّ»** — والصفرُ «عُدّ فلم يوجد».
                        'counted_quantity' => null,
                        'variance' => null,
                        'unit_cost' => $p->cost_price,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            });

            if ($rows === []) {
                throw new DomainException('لا أصناف في نطاق هذا الجرد');
            }

            foreach (array_chunk($rows, 200) as $batch) {
                DB::table('stock_count_items')->insert($batch);
            }

            return $count->fresh();
        });
    }

    /** تسجيلُ عدّ سطرٍ — يُعاد ما شاء العادُّ حتّى الاعتماد. */
    public function countLine(
        StockCount $count, int $productId, string $quantity,
        ?string $reason = null, ?string $note = null, ?int $actorId = null,
    ): StockCountItem {
        if (! in_array($count->status, [StockCount::COUNTING, StockCount::REVIEW], true)) {
            throw new DomainException('الجرد غير مفتوح للعدّ');
        }
        if (bccomp($quantity, '0', 3) < 0) {
            throw new DomainException('الكمّيّة المعدودة لا تكون سالبة');
        }
        if ($reason !== null && ! in_array($reason, StockCountItem::REASONS, true)) {
            throw new DomainException('سبب فرق غير معروف');
        }

        $item = StockCountItem::where('count_id', $count->id)
            ->where('product_id', $productId)->first();
        if (! $item) {
            throw new DomainException('الصنف ليس في نطاق هذا الجرد');
        }

        $item->update([
            'counted_quantity' => $quantity,
            'variance' => bcsub($quantity, (string) $item->system_quantity, 3),
            'variance_reason' => $reason,
            'note' => $note,
            'counted_by' => $actorId,
            'counted_at' => now(),
        ]);

        return $item->fresh();
    }

    /** إنهاءُ العدّ ورفعُه للمراجعة. */
    public function submit(StockCount $count): StockCount
    {
        if ($count->status !== StockCount::COUNTING) {
            throw new DomainException('الجرد ليس في طور العدّ');
        }

        $count->update(['status' => StockCount::REVIEW, 'counted_at' => now()]);

        return $count->fresh();
    }

    /**
     * الاعتماد — **وهنا وحدَها يمسّ الجردُ المخزون**.
     *
     * والأسطرُ التي لم تُعدّ **لا تُصفَّر**: صنفٌ لم يصل إليه العادُّ ليس
     * صنفاً مفقوداً. وتصفيرُها يشطب مخزوناً موجوداً على الرفّ.
     */
    public function approve(User $actor, StockCount $count): array
    {
        if ($count->status !== StockCount::REVIEW) {
            throw new DomainException('الجرد لم يُرفع للمراجعة بعد');
        }
        if ((int) $count->started_by === (int) $actor->id
            && (int) $count->merchant_user_id !== (int) $actor->id) {
            throw new DomainException('من بدأ الجرد لا يعتمده');
        }

        return DB::transaction(function () use ($actor, $count) {
            $location = MerchantLocation::findOrFail($count->location_id);
            $adjusted = 0;
            $skipped = 0;
            $netValue = '0';

            foreach ($count->items()->get() as $item) {
                if (! $item->wasCounted()) {
                    $skipped++;   // **لم يُعدّ ≠ صفر**
                    continue;
                }

                $delta = (string) $item->variance;
                if (bccomp($delta, '0', 3) === 0) {
                    continue;
                }

                $this->stock->move(
                    product: MerchantProduct::findOrFail($item->product_id),
                    location: $location,
                    delta: $delta,
                    reason: 'count_adjustment',
                    actor: $actor,
                    unitCost: $item->unit_cost,
                    sourceType: 'stock_count',
                    sourceId: $count->id,
                    note: 'جرد ' . $count->code . ' · ' . $item->reasonAr(),
                    // فرقٌ سالبٌ يتجاوز الرصيد ممكنٌ نظريّاً — والعدُّ هو
                    // الحقيقة، فلا يُرفض لأنّ اللقطة تخالفه.
                    allowNegative: true,
                );

                $adjusted++;
                if ($item->unit_cost !== null) {
                    $netValue = bcadd($netValue, bcmul($delta, (string) $item->unit_cost, 4), 4);
                }
            }

            $count->update([
                'status' => StockCount::APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            ProductStock::where('location_id', $location->id)
                ->whereIn('product_id', $count->items()->whereNotNull('counted_quantity')
                    ->pluck('product_id'))
                ->update(['last_counted_at' => now()]);

            return [
                'code' => $count->code,
                'adjusted_lines' => $adjusted,
                // **يُقال صراحةً** — ولا يُقرأ الجردُ كاملاً وهو ناقص.
                'not_counted_lines' => $skipped,
                'net_value_change' => $netValue,
            ];
        });
    }

    /** ملخّصُ الفروقات قبل الاعتماد — **يُقرأ ثمّ يُقرَّر**. */
    public function variances(StockCount $count): array
    {
        $items = $count->items()->get();

        return [
            'code' => $count->code,
            'status' => $count->statusAr(),
            'kind' => $count->kindAr(),
            'total_lines' => $items->count(),
            'counted_lines' => $items->whereNotNull('counted_quantity')->count(),
            'not_counted_lines' => $items->whereNull('counted_quantity')->count(),
            'lines' => $items->filter(fn ($i) => $i->wasCounted()
                    && bccomp((string) $i->variance, '0', 3) !== 0)
                ->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'name' => $i->name,
                    'system' => (string) $i->system_quantity,
                    'counted' => (string) $i->counted_quantity,
                    'variance' => (string) $i->variance,
                    'reason' => $i->variance_reason,
                    'reason_ar' => $i->reasonAr(),
                    'value' => $i->unit_cost !== null
                        ? bcmul((string) $i->variance, (string) $i->unit_cost, 4) : null,
                ])->values()->all(),
        ];
    }

    private function code(int $merchantId): string
    {
        $n = StockCount::where('merchant_user_id', $merchantId)->count() + 1;

        return 'CNT' . now()->format('ymd') . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
