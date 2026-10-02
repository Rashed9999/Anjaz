<?php

namespace App\Services\Merchant;

use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Merchant Portal V2 — normalized owner sales directory.
 *
 * The portal needs one searchable surface, but the financial source remains
 * vertical-specific. This service normalizes rows for presentation without
 * moving or duplicating any sale.
 */
final class MerchantSalesDirectoryService
{
    public function search(User $merchant, array $filters): array
    {
        $vertical = (string) (DB::table('merchant_profiles')
            ->where('user_id', $merchant->id)->value('business_type') ?: A::BIZ_RETAIL);

        [$query, $source, $dateColumn] = match ($vertical) {
            A::BIZ_FUEL => [$this->fuel($merchant), 'fuel_sales', 'fs.created_at'],
            A::BIZ_PHARMACY => [$this->pharmacy($merchant), 'pharmacy_sales', 'ps.created_at'],
            A::BIZ_WHOLESALE => [$this->wholesale($merchant), 'wholesale_invoices', 'wi.invoice_date'],
            default => [$this->merchantSales($merchant, $vertical), 'merchant_sales', 'ms.created_at'],
        };

        $this->applyFilters($query, $filters, $dateColumn, $vertical);

        // Aggregate over the normalized projection so aliases such as
        // "amount" are real columns of the subquery, not guessed source
        // columns on different vertical tables.
        $summaryBase = DB::query()->fromSub(clone $query, 'sales_directory');
        $count = (int) (clone $summaryBase)->count();
        $total = (string) ((clone $summaryBase)->sum('amount') ?: '0');

        $page = $query->orderByDesc('occurred_at')->paginate(20);

        return [
            'vertical' => $vertical,
            'source' => $source,
            'rows' => collect($page->items())->map(fn ($row) => [
                'id' => $row->id,
                'detail_id' => $row->detail_id ?? $row->id,
                'reference' => (string) ($row->reference ?: $row->id),
                'document_number' => $row->document_number ?: null,
                'customer_name' => $row->customer_name ?: null,
                'amount' => bcadd((string) ($row->amount ?? '0'), '0', 4),
                'payment_method' => $row->payment_method ?: null,
                'status' => $row->status ?: null,
                'employee_id' => $row->employee_id !== null ? (int) $row->employee_id : null,
                'employee_name' => $row->employee_name ?: null,
                'branch_name' => $row->branch_name ?: null,
                'occurred_at' => $row->occurred_at
                    ? \Carbon\Carbon::parse($row->occurred_at)->toIso8601String()
                    : null,
            ])->values()->all(),
            'summary' => [
                'count' => $count,
                'total' => bcadd($total, '0', 4),
            ],
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    private function merchantSales(User $merchant, string $vertical): Builder
    {
        $q = DB::table('merchant_sales as ms')
            ->leftJoin('pos_users as pu', 'pu.id', '=', 'ms.pos_user_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ms.branch_id')
            ->where('ms.merchant_user_id', $merchant->id)
            ->whereIn('ms.status', ['completed', 'credit_unpaid', 'credit_paid']);

        if ($vertical === A::BIZ_RESTAURANT) {
            $q->leftJoin('restaurant_orders as ro', function ($join) use ($merchant) {
                $join->on('ro.sale_ulid', '=', 'ms.sale_ulid')
                    ->where('ro.merchant_user_id', '=', $merchant->id);
            });
        }

        return $q->select([
            'ms.id',
            DB::raw($vertical === A::BIZ_RESTAURANT
                ? 'ro.id as detail_id'
                : 'ms.sale_ulid as detail_id'),
            'ms.sale_ulid as reference',
            DB::raw($vertical === A::BIZ_RESTAURANT
                ? 'COALESCE(ms.invoice_number, ro.invoice_number, ro.order_no) as document_number'
                : 'ms.invoice_number as document_number'),
            'ms.customer_name',
            DB::raw('COALESCE(ms.base_amount, ms.total_amount) as amount'),
            'ms.payment_method',
            'ms.status',
            'pu.id as employee_id',
            'pu.display_name as employee_name',
            'b.name as branch_name',
            'ms.created_at as occurred_at',
        ]);
    }

    private function fuel(User $merchant): Builder
    {
        return DB::table('fuel_sales as fs')
            ->leftJoin('pos_users as pu', 'pu.id', '=', 'fs.pos_user_id')
            ->leftJoin('fuel_company_accounts as fca', 'fca.id', '=', 'fs.company_account_id')
            ->where('fs.merchant_user_id', $merchant->id)
            ->where('fs.status', 'completed')
            ->select([
                'fs.id', 'fs.sale_ulid as detail_id', 'fs.sale_ulid as reference', 'fs.invoice_number as document_number',
                'fca.company_name as customer_name', 'fs.total_amount as amount',
                'fs.payment_method', 'fs.status', 'pu.id as employee_id',
                'pu.display_name as employee_name', DB::raw('NULL as branch_name'),
                'fs.created_at as occurred_at',
            ]);
    }

    private function pharmacy(User $merchant): Builder
    {
        return DB::table('pharmacy_sales as ps')
            ->leftJoin('pos_users as pu', 'pu.id', '=', 'ps.pos_user_id')
            ->leftJoin('pharmacy_customers as pc', 'pc.id', '=', 'ps.customer_id')
            ->where('ps.merchant_user_id', $merchant->id)
            ->where('ps.status', 'completed')
            ->select([
                'ps.id', 'ps.sale_ulid as detail_id', 'ps.sale_ulid as reference', 'ps.invoice_number as document_number',
                'pc.full_name as customer_name', 'ps.total_amount as amount',
                'ps.payment_method', 'ps.status', 'pu.id as employee_id',
                'pu.display_name as employee_name', DB::raw('NULL as branch_name'),
                'ps.created_at as occurred_at',
            ]);
    }

    private function wholesale(User $merchant): Builder
    {
        return DB::table('wholesale_invoices as wi')
            ->join('wholesale_businesses as wb', 'wb.id', '=', 'wi.business_id')
            ->leftJoin('wholesale_customers as wc', 'wc.id', '=', 'wi.customer_id')
            ->leftJoin('pos_users as pu', 'pu.user_id', '=', 'wi.created_by_user_id')
            ->leftJoin('branches as b', 'b.id', '=', 'wi.branch_id')
            ->where('wb.merchant_user_id', $merchant->id)
            ->whereNotIn('wi.status', ['draft', 'voided'])
            ->select([
                'wi.id', 'wi.id as detail_id', 'wi.invoice_ulid as reference', 'wi.invoice_number as document_number',
                DB::raw('COALESCE(wc.company_name, wc.full_name) as customer_name'),
                'wi.total_amount as amount', 'wi.payment_type as payment_method',
                'wi.status', 'pu.id as employee_id', 'pu.display_name as employee_name',
                'b.name as branch_name', 'wi.created_at as occurred_at',
            ]);
    }

    private function applyFilters(Builder $q, array $filters, string $dateColumn, string $vertical): void
    {
        if (! empty($filters['from'])) {
            $q->whereDate($dateColumn, '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate($dateColumn, '<=', $filters['to']);
        }

        if (! empty($filters['payment_method'])) {
            $column = $vertical === A::BIZ_WHOLESALE ? 'wi.payment_type'
                : ($vertical === A::BIZ_FUEL ? 'fs.payment_method'
                    : ($vertical === A::BIZ_PHARMACY ? 'ps.payment_method' : 'ms.payment_method'));
            $q->where($column, $filters['payment_method']);
        }

        if (! empty($filters['status'])) {
            $column = $vertical === A::BIZ_WHOLESALE ? 'wi.status'
                : ($vertical === A::BIZ_FUEL ? 'fs.status'
                    : ($vertical === A::BIZ_PHARMACY ? 'ps.status' : 'ms.status'));
            $q->where($column, $filters['status']);
        }

        if (! empty($filters['employee_id'])) {
            $q->where('pu.id', (int) $filters['employee_id']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') return;

        $q->where(function (Builder $w) use ($search, $vertical) {
            $like = "%{$search}%";
            if ($vertical === A::BIZ_WHOLESALE) {
                $w->where('wi.invoice_number', 'like', $like)
                    ->orWhere('wi.invoice_ulid', 'like', $like)
                    ->orWhere('wc.full_name', 'like', $like)
                    ->orWhere('wc.company_name', 'like', $like);
            } elseif ($vertical === A::BIZ_FUEL) {
                $w->where('fs.invoice_number', 'like', $like)
                    ->orWhere('fs.sale_ulid', 'like', $like)
                    ->orWhere('fs.vehicle_plate', 'like', $like)
                    ->orWhere('fca.company_name', 'like', $like);
            } elseif ($vertical === A::BIZ_PHARMACY) {
                $w->where('ps.invoice_number', 'like', $like)
                    ->orWhere('ps.sale_ulid', 'like', $like)
                    ->orWhere('pc.full_name', 'like', $like)
                    ->orWhere('pc.phone', 'like', $like);
            } else {
                $w->where('ms.invoice_number', 'like', $like)
                    ->orWhere('ms.sale_ulid', 'like', $like)
                    ->orWhere('ms.customer_name', 'like', $like)
                    ->orWhere('ms.customer_phone', 'like', $like);
            }
        });
    }
}
