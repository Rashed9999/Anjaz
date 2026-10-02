<?php

namespace App\Services\Merchant;

use App\Models\FuelProduct;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\PharmacyProduct;
use App\Models\User;
use App\Models\WholesaleProduct;
use App\Support\Access\AccessConstants as A;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class MerchantProductDirectoryService
{
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
                default
                    => $query->where('track_stock', true)
                        ->whereColumn('quantity', '<=', 'reorder_level'),
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
            ->with(['barcodes:id,product_id,barcode,pack_size,is_primary']);
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

        $row['stock_value'] = match ($vertical) {
            A::BIZ_PHARMACY, A::BIZ_WHOLESALE => $model->current_stock,
            A::BIZ_FUEL => null,
            default => $model->quantity,
        };
        $row['low_stock'] = match ($vertical) {
            A::BIZ_PHARMACY, A::BIZ_WHOLESALE => (float) $model->current_stock <= (float) $model->low_stock_threshold,
            A::BIZ_FUEL => null,
            default => (bool) $model->track_stock
                && (float) $model->quantity <= (float) $model->reorder_level,
        };

        return $row;
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
            $low = (clone $q)->where('is_active', true)->where('track_stock', true)
                ->whereColumn('quantity', '<=', 'reorder_level')->count();
            $out = (clone $q)->where('is_active', true)->where('track_stock', true)
                ->where('quantity', '<=', 0)->count();
        }

        return compact('total', 'active', 'inactive') + ['low_stock' => $low, 'out_of_stock' => $out];
    }
}
