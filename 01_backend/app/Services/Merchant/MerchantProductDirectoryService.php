<?php

namespace App\Services\Merchant;

use App\Models\FuelProduct;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\PharmacyProduct;
use App\Models\User;
use App\Models\WholesaleProduct;
use App\Support\Access\AccessConstants as A;
use App\Services\Retail\StockService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class MerchantProductDirectoryService
{
    public function __construct(private readonly StockService $stock) {}

    public function search(User $merchant, array $filters): array
    {
        $vertical = (string) (MerchantProfile::where('user_id', $merchant->id)
            ->value('business_type') ?: A::BIZ_RETAIL);

        [$query, $source, $supportsStock] = match ($vertical) {
            A::BIZ_PHARMACY => [$this->pharmacy($merchant), 'pharmacy_products', true],
            A::BIZ_WHOLESALE => [$this->wholesale($merchant), 'wholesale_products', true],
            A::BIZ_FUEL => [$this->fuel($merchant), 'fuel_products', false],
            default => [$this->generic($merchant), 'merchant_products', true],
        };

        $this->applySearch($query, $vertical, trim((string) ($filters['search'] ?? '')));

        $status = (string) ($filters['status'] ?? 'active');
        if ($status === 'active') $query->where('is_active', true);
        elseif ($status === 'inactive') $query->where('is_active', false);

        if ($supportsStock && ! empty($filters['low_stock_only'])) {
            match ($vertical) {
                A::BIZ_PHARMACY, A::BIZ_WHOLESALE
                    => $query->whereColumn('current_stock', '<=', 'low_stock_threshold'),
                default => $this->applyGenericLowStockFilter($query, $merchant),
            };
        }

        $page = $query->orderBy($this->sortColumn($vertical))->paginate(25);
        $summary = $this->summary($merchant, $vertical);

        return [
            'vertical' => $vertical,
            'source' => $source,
            'supports_stock' => $supportsStock,
            'rows' => collect($page->items())->map(fn ($model) => $this->present($model, $vertical))->all(),
            'summary' => $summary,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    private function generic(User $merchant): Builder
    {
        return MerchantProduct::query()
            ->where('merchant_user_id', $merchant->id)
            ->with([
                'barcodes:id,product_id,barcode,pack_size,is_primary',
                'stocks.location:id,merchant_user_id,name,is_active',
            ]);
    }

    private function pharmacy(User $merchant): Builder
    {
        $pharmacyId = DB::table('pharmacies')->where('merchant_user_id', $merchant->id)->value('id');

        return PharmacyProduct::query()
            ->where('pharmacy_id', $pharmacyId ?: 0)
            ->with('category:id,name');
    }

    private function wholesale(User $merchant): Builder
    {
        $businessId = DB::table('wholesale_businesses')->where('merchant_user_id', $merchant->id)->value('id');

        return WholesaleProduct::query()->where('business_id', $businessId ?: 0);
    }

    private function fuel(User $merchant): Builder
    {
        $stationId = DB::table('fuel_stations')->where('merchant_user_id', $merchant->id)->value('id');

        return FuelProduct::query()->where('station_id', $stationId ?: 0);
    }

    private function applySearch(Builder $query, string $vertical, string $search): void
    {
        if ($search === '') return;

        $query->where(function (Builder $q) use ($vertical, $search) {
            $like = "%{$search}%";
            if ($vertical === A::BIZ_PHARMACY) {
                $q->where('trade_name', 'like', $like)
                    ->orWhere('generic_name', 'like', $like)
                    ->orWhere('active_ingredient', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', $search);
            } elseif ($vertical === A::BIZ_WHOLESALE) {
                $q->where('name', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', $search);
            } elseif ($vertical === A::BIZ_FUEL) {
                $q->where('name', 'like', $like)
                    ->orWhere('product_code', 'like', $like);
            } else {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', $search)
                    ->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', $search));
            }
        });
    }

    private function sortColumn(string $vertical): string
    {
        return match ($vertical) {
            A::BIZ_PHARMACY => 'trade_name',
            default => 'name',
        };
    }

    private function present($model, string $vertical): array
    {
        $row = $model->toArray();

        if (! array_key_exists('display_name', $row)) {
            $row['display_name'] = $vertical === A::BIZ_PHARMACY
                ? (string) $model->trade_name
                : (method_exists($model, 'displayName') ? $model->displayName() : (string) $model->name);
        }

        if (in_array($vertical, [A::BIZ_RETAIL, A::BIZ_QUICK_SALE, A::BIZ_RESTAURANT], true)) {
            $stock = $this->genericStockSnapshot($model);
            $row['stock_value'] = $stock['on_hand'];
            $row['available_stock'] = $stock['available'];
            $row['reserved_stock'] = $stock['reserved'];
            $row['stock_location_count'] = $stock['location_count'];
            $row['stock_locations'] = $stock['locations'];
            $row['stock_source'] = $stock['source'];
            $row['low_stock'] = $stock['low'];
            $row['out_of_stock'] = $stock['out'];

            return $row;
        }

        $row['stock_value'] = match ($vertical) {
            A::BIZ_PHARMACY, A::BIZ_WHOLESALE => $model->current_stock,
            A::BIZ_FUEL => null,
            default => null,
        };
        $row['low_stock'] = match ($vertical) {
            A::BIZ_PHARMACY, A::BIZ_WHOLESALE => (float) $model->current_stock <= (float) $model->low_stock_threshold,
            A::BIZ_FUEL => null,
            default => null,
        };

        return $row;
    }

    /**
     * لقطة دليل المنتجات: الموقع هو الحقيقة، والكمية القديمة fallback فقط
     * للصنف الذي لم يُوزع بعد على أي موقع فعال.
     */
    private function genericStockSnapshot(MerchantProduct $product): array
    {
        $stocks = $product->stocks
            ->filter(fn ($s) => $s->location && (bool) $s->location->is_active);

        if ($stocks->isEmpty()) {
            $legacy = bcadd((string) ($product->quantity ?? '0'), '0', 3);
            $reorder = (string) ($product->reorder_level ?? '0');

            return [
                'on_hand' => $legacy,
                'reserved' => '0.000',
                'available' => $legacy,
                'location_count' => 0,
                'locations' => [],
                'source' => 'legacy_unallocated',
                'low' => (bool) $product->track_stock
                    && bccomp($reorder, '0', 3) > 0
                    && bccomp($legacy, $reorder, 3) <= 0,
                'out' => (bool) $product->track_stock && bccomp($legacy, '0', 3) <= 0,
            ];
        }

        $onHand = '0.000';
        $reserved = '0.000';
        $available = '0.000';
        $low = false;
        $out = false;
        $locations = [];

        foreach ($stocks as $stock) {
            $sellable = $stock->available();
            $onHand = bcadd($onHand, (string) $stock->on_hand, 3);
            $reserved = bcadd($reserved, (string) $stock->reserved, 3);
            $available = bcadd($available, $sellable, 3);
            $low = $low || $stock->isLow();
            $out = $out || bccomp($sellable, '0', 3) <= 0;

            if (count($locations) < 12) {
                $locations[] = [
                    'location_id' => (int) $stock->location_id,
                    'location' => $stock->location->name,
                    'on_hand' => (string) $stock->on_hand,
                    'reserved' => (string) $stock->reserved,
                    'available' => $sellable,
                    'reorder_level' => (string) $stock->reorder_level,
                    'state' => bccomp($sellable, '0', 3) <= 0
                        ? 'out'
                        : ($stock->isLow() ? 'low' : 'ok'),
                ];
            }
        }

        return [
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $available,
            'location_count' => $stocks->count(),
            'locations' => $locations,
            'source' => 'product_stocks',
            'low' => $low,
            'out' => $out,
        ];
    }

    private function applyGenericLowStockFilter(Builder $query, User $merchant): void
    {
        $query->where('track_stock', true)
            ->where(function (Builder $q) use ($merchant) {
                $q->whereHas('stocks', fn (Builder $stocks) => $stocks
                    ->where('reorder_level', '>', 0)
                    ->whereRaw('(product_stocks.on_hand - product_stocks.reserved) <= product_stocks.reorder_level')
                    ->whereHas('location', fn (Builder $location) => $location
                        ->where('merchant_user_id', $merchant->id)
                        ->where('is_active', true)))
                ->orWhere(function (Builder $legacy) use ($merchant) {
                    $legacy->whereDoesntHave('stocks', fn (Builder $stocks) => $stocks
                        ->whereHas('location', fn (Builder $location) => $location
                            ->where('merchant_user_id', $merchant->id)
                            ->where('is_active', true)))
                        ->where('reorder_level', '>', 0)
                        ->whereColumn('quantity', '<=', 'reorder_level');
                });
            });
    }

    private function summary(User $merchant, string $vertical): array
    {
        $q = match ($vertical) {
            A::BIZ_PHARMACY => $this->pharmacy($merchant),
            A::BIZ_WHOLESALE => $this->wholesale($merchant),
            A::BIZ_FUEL => $this->fuel($merchant),
            default => $this->generic($merchant),
        };

        $total = (clone $q)->count();
        $active = (clone $q)->where('is_active', true)->count();
        $inactive = $total - $active;

        if ($vertical === A::BIZ_FUEL) {
            return compact('total', 'active', 'inactive') + ['low_stock' => null, 'out_of_stock' => null];
        }

        if (in_array($vertical, [A::BIZ_PHARMACY, A::BIZ_WHOLESALE], true)) {
            $low = (clone $q)->where('is_active', true)
                ->whereColumn('current_stock', '<=', 'low_stock_threshold')->count();
            $out = (clone $q)->where('is_active', true)->where('current_stock', '<=', 0)->count();
        } else {
            $health = $this->stock->healthCounts($merchant->id);

            // سجلات ما قبل المخزون بالموقع لا تختفي: تُحسب كتنبيهات
            // انتقالية فقط إذا لم يوجد للصنف أي رصيد في موقع فعال.
            $legacy = (clone $q)->where('is_active', true)->where('track_stock', true)
                ->whereDoesntHave('stocks', fn (Builder $stocks) => $stocks
                    ->whereHas('location', fn (Builder $location) => $location
                        ->where('merchant_user_id', $merchant->id)
                        ->where('is_active', true)));

            $legacyLow = (clone $legacy)->where('reorder_level', '>', 0)
                ->whereColumn('quantity', '<=', 'reorder_level')->count();
            $legacyOut = (clone $legacy)->where('quantity', '<=', 0)->count();

            $low = $health['low_locations'] + $legacyLow;
            $out = $health['out_locations'] + $legacyOut;
        }

        return compact('total', 'active', 'inactive') + ['low_stock' => $low, 'out_of_stock' => $out];
    }
}
