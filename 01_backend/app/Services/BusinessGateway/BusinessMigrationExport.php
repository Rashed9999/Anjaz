<?php

namespace App\Services\BusinessGateway;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditMovement;
use App\Models\Fuel\FuelDelivery;
use App\Models\Fuel\FuelNozzle;
use App\Models\Fuel\FuelTank;
use App\Models\FuelCompanyAccount;
use App\Models\FuelCompanyCard;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelStation;
use App\Models\MerchantProduct;
use App\Models\Pharmacy;
use App\Models\PharmacyBatch;
use App\Models\PharmacyCustomer;
use App\Models\PharmacyProduct;
use App\Models\Retail\ProductStock;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesalePriceTier;
use App\Models\WholesaleProduct;
use App\Models\WholesaleProductLot;
use App\Models\WholesaleProductPrice;
use App\Models\WholesaleProductUnit;
use App\Services\MoneyService;

/**
 * AMIAL-BUSINESS-MIGRATION-001 — تصدير بيانات التاجر لأميال أعمال (قراءة فقط).
 *
 * لا يكتب شيئاً ولا يحرّك مالاً. يصدّر ما تحتاجه أميال أعمال لتبدأ من أرصدة
 * افتتاحية صحيحة: الأصناف ومخزونها وتكلفتها، والعملاء وديونهم، والموردين
 * وأرصدتهم — لكل صنف تاجر من مصدره الحقيقي:
 *
 *   تجزئة : المخزون مجموع product_stocks؛ وصنفٌ بلا صفوفٍ يُقرأ من العمود القديم
 *           بقاعدة Retail\StockService::available نفسها.
 *   جملة  : الدفعات النشطة إن وُجدت، وإلا current_stock — والفرق بينهما يُبلَّغ.
 *   صيدلية: الدفعات النشطة غير المنتهية؛ المنتهية تُبلَّغ بقيمتها ولا تُصدَّر بيعاً.
 *
 * والأرصدة المخزّنة تُطابَق بآخر سطر في دفترها (balance_after/debt_after)،
 * وكل فرقٍ يُبلَّغ في الملخص ولا يُخفى (القاعدة السادسة والسابعة).
 */
class BusinessMigrationExport
{
    private const PAGE = 200;

    public function summary(BusinessLink $link): array
    {
        $merchantId = $link->merchant_user_id;
        $totals = ['products' => 0, 'stock_quantity' => '0', 'stock_value' => '0', 'customers' => 0, 'receivable' => '0', 'suppliers' => 0, 'payable' => '0', 'supplier_credit' => '0'];
        $warnings = [];

        foreach ($this->allProducts($merchantId) as $p) {
            $totals['products']++;
            $totals['stock_quantity'] = MoneyService::add($totals['stock_quantity'], $p['quantity']);
            $totals['stock_value'] = MoneyService::add($totals['stock_value'], MoneyService::mul($p['quantity'], $p['unit_cost'] ?? '0'));
            foreach ($p['warnings'] as $w) {
                $warnings[] = ['source' => $p['source'], 'source_id' => $p['source_id'], 'name' => $p['name'], 'warning' => $w];
            }
        }
        foreach ($this->allCustomers($merchantId) as $c) {
            $totals['customers']++;
            $totals['receivable'] = MoneyService::add($totals['receivable'], $c['balance']);
            foreach ($c['warnings'] as $w) {
                $warnings[] = ['source' => $c['source'], 'source_id' => $c['source_id'], 'name' => $c['name'], 'warning' => $w];
            }
        }
        foreach ($this->allSuppliers($merchantId) as $s) {
            $totals['suppliers']++;
            $totals['payable'] = MoneyService::add($totals['payable'], $s['payable']);
            $totals['supplier_credit'] = MoneyService::add($totals['supplier_credit'], $s['credit']);
            foreach ($s['warnings'] as $w) {
                $warnings[] = ['source' => 'supplier', 'source_id' => $s['source_id'], 'name' => $s['name'], 'warning' => $w];
            }
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'currency' => (string) config('amial_business.wallet_currency', 'YER'),
            'totals' => $totals,
            'credit_fingerprint' => $this->creditFingerprint($link),
            'warnings' => $warnings,
            'tiers' => $this->tiers($merchantId),
            // المطعم: الطاولات إعدادٌ يُنقل (الطلبات النشطة لا تُنقل؛ تُغلق قبل الترحيل).
            'restaurant_tables' => \App\Models\RestaurantTable::where('merchant_user_id', $merchantId)->where('is_active', true)
                ->orderBy('id')->get(['label', 'seats'])->map(fn ($t) => ['name' => (string) $t->label, 'seats' => (int) $t->seats])->values()->all(),
            'open_restaurant_orders' => \App\Models\RestaurantOrder::where('merchant_user_id', $merchantId)
                ->whereNotIn('status', ['closed', 'cancelled'])->count(),
        ];
    }

    /**
     * AMIAL-BUSINESS-CREDIT-001 — بصمة كل أرصدة الآجل للتاجر (عملاء وموردين).
     *
     * تقرأها أميال أعمال مع الملخّص، وتعيدها عند التسليم: إن تحرّك أي رصيد
     * بين القراءة والتسليم تختلف البصمة ويُرفض التسليم، فلا يُجمَّد دفترٌ
     * غير الذي رُحِّل.
     */
    public function creditFingerprint(BusinessLink $link): string
    {
        $rows = [];
        foreach ($this->allCustomers($link->merchant_user_id) as $c) {
            $rows[] = "c|{$c['source']}|{$c['source_id']}|{$c['balance']}";
        }
        foreach ($this->allSuppliers($link->merchant_user_id) as $s) {
            $rows[] = "s|{$s['source_id']}|{$s['payable']}|{$s['credit']}";
        }
        sort($rows, SORT_STRING);

        return hash('sha256', implode("\n", $rows));
    }

    /** @return array{items: array, next_cursor: ?string} */
    public function page(BusinessLink $link, string $section, ?string $cursor): array
    {
        $all = match ($section) {
            'products' => $this->allProducts($link->merchant_user_id),
            'customers' => $this->allCustomers($link->merchant_user_id),
            'suppliers' => $this->allSuppliers($link->merchant_user_id),
            default => throw new BusinessGatewayException('MIGRATION_SECTION_INVALID', 'قسم التصدير غير معروف', 404),
        };

        // المؤشّر = موضعٌ في قائمة مرتّبة ثابتة (المصدر ثم الرقم) — لا يتأثر بالإضافات أثناء القراءة.
        usort($all, fn ($a, $b) => [$a['source'], $a['source_id']] <=> [$b['source'], $b['source_id']]);
        $offset = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
        $items = array_slice($all, $offset, self::PAGE);
        $next = $offset + self::PAGE < count($all) ? (string) ($offset + self::PAGE) : null;

        return ['items' => array_map(fn ($i) => array_diff_key($i, ['warnings' => true]), $items), 'next_cursor' => $next];
    }

    private function allProducts(int $merchantId): array
    {
        $out = [];

        foreach (MerchantProduct::where('merchant_user_id', $merchantId)->where(fn ($q) => $q->whereNull('is_variant_parent')->orWhere('is_variant_parent', false))->orderBy('id')->get() as $p) {
            $rows = ProductStock::where('product_id', $p->id)->get(['on_hand']);
            $qty = $rows->isNotEmpty()
                ? (string) $rows->reduce(fn ($c, $r) => MoneyService::add($c, (string) $r->on_hand), '0')
                : MoneyService::normalize((string) ($p->quantity ?? '0'));
            $cost = $this->knownCost($p->cost_price);
            $out[] = $this->product('retail', $p->id, $p->sku, $p->barcode, $p->name, (string) $p->price, $qty, $cost, (bool) $p->is_active, (bool) ($p->track_stock ?? true));
        }

        foreach (WholesaleBusiness::where('merchant_user_id', $merchantId)->pluck('id') as $businessId) {
            foreach (WholesaleProduct::where('business_id', $businessId)->orderBy('id')->get() as $p) {
                $lots = WholesaleProductLot::where('product_id', $p->id)->where('status', 'active')->get(['quantity_available']);
                $lotQty = (string) $lots->reduce(fn ($c, $l) => MoneyService::add($c, (string) $l->quantity_available), '0');
                $stored = MoneyService::normalize((string) $p->current_stock);
                $item = $this->product('wholesale', $p->id, $p->sku, $p->barcode, $p->name, (string) $p->base_price,
                    $lots->isNotEmpty() ? $lotQty : $stored, $this->knownCost($p->cost_price), (bool) $p->is_active, true);
                if ($lots->isNotEmpty() && MoneyService::compare($lotQty, $stored) !== 0) {
                    $item['warnings'][] = "WHOLESALE_STOCK_MISMATCH: lots={$lotQty} stored={$stored}";
                }
                $item['units'] = WholesaleProductUnit::where('product_id', $p->id)->where('is_active', true)->where('is_base', false)->get()
                    ->map(fn ($u) => ['code' => $u->code, 'name' => $u->name, 'factor' => MoneyService::normalize((string) $u->factor_to_base)])->values()->all();
                $tierCodes = WholesalePriceTier::where('business_id', $businessId)->pluck('code', 'id');
                $item['prices'] = WholesaleProductPrice::where('product_id', $p->id)->get()
                    ->map(fn ($pr) => ['tier_code' => $tierCodes[$pr->tier_id] ?? null, 'min_quantity' => MoneyService::normalize((string) $pr->min_quantity), 'price' => MoneyService::normalize((string) $pr->price)])->values()->all();
                $out[] = $item;
            }
        }

        foreach (Pharmacy::where('merchant_user_id', $merchantId)->pluck('id') as $pharmacyId) {
            foreach (PharmacyProduct::where('pharmacy_id', $pharmacyId)->orderBy('id')->get() as $p) {
                $batches = PharmacyBatch::where('product_id', $p->id)->where('status', 'active')->where('quantity_remaining', '>', 0)->get();
                $valid = $batches->filter(fn ($b) => $b->expiry_date && $b->expiry_date->gt(today()));
                $expired = $batches->reject(fn ($b) => $b->expiry_date && $b->expiry_date->gt(today()));
                $qty = (string) $valid->reduce(fn ($c, $b) => MoneyService::add($c, (string) $b->quantity_remaining), '0');
                $item = $this->product('pharmacy', $p->id, $p->sku, $p->barcode, $p->trade_name, (string) $p->sale_price, $qty,
                    $this->knownCost($p->cost_price), (bool) $p->is_active, true);
                $item['pharmacy'] = [
                    'generic_name' => $p->generic_name, 'strength' => $p->strength, 'dosage_form' => $p->dosage_form,
                    'requires_prescription' => (bool) $p->requires_prescription,
                ];
                $item['batches'] = $valid->map(fn ($b) => [
                    'batch_number' => (string) $b->batch_number, 'expiry_date' => $b->expiry_date->toDateString(),
                    'quantity' => MoneyService::normalize((string) $b->quantity_remaining), 'unit_cost' => MoneyService::normalize((string) ($b->cost_per_unit ?? $p->cost_price ?? '0')),
                ])->values()->all();
                foreach ($expired as $b) {
                    $item['warnings'][] = "EXPIRED_BATCH_NOT_EXPORTED: {$b->batch_number} qty=".MoneyService::normalize((string) $b->quantity_remaining);
                }
                if (MoneyService::compare(MoneyService::normalize((string) $p->current_stock), (string) $batches->reduce(fn ($c, $b) => MoneyService::add($c, (string) $b->quantity_remaining), '0')) !== 0) {
                    $item['warnings'][] = 'PHARMACY_STOCK_MISMATCH: stored='.MoneyService::normalize((string) $p->current_stock);
                }
                $out[] = $item;
            }
        }

        // محطة الوقود: المخزون من دفتر كل خزّان، والتكلفة من آخر توريدٍ مرحَّل.
        foreach (FuelStation::where('merchant_user_id', $merchantId)->pluck('id') as $stationId) {
            $nozzles = FuelNozzle::whereIn('pump_id', FuelPump::where('station_id', $stationId)->pluck('id'))->where('is_active', true)->get();
            $pumpNumbers = FuelPump::where('station_id', $stationId)->pluck('pump_number', 'id');
            foreach (FuelProduct::where('station_id', $stationId)->orderBy('id')->get() as $p) {
                $tanks = FuelTank::where('station_id', $stationId)->where('fuel_product_id', $p->id)->where('is_active', true)->orderBy('tank_number')->get();
                $qty = (string) $tanks->reduce(fn ($c, $t) => MoneyService::add($c, (string) $t->book_liters), '0');
                $cost = $this->knownCost(FuelDelivery::where('fuel_product_id', $p->id)->where('status', 'posted')->latest('posted_at')->value('unit_cost'));
                $item = $this->product('fuel', $p->id, $p->product_code, null, $p->name, (string) $p->price_per_liter, $qty, $cost, (bool) $p->is_active, true);
                $tankNumbers = $tanks->pluck('tank_number', 'id');
                $item['fuel'] = [
                    'tanks' => $tanks->map(fn ($t) => [
                        'number' => (int) $t->tank_number, 'name' => $t->name,
                        'capacity_liters' => MoneyService::normalize((string) $t->capacity_liters),
                        'min_alert_liters' => MoneyService::normalize((string) $t->min_alert_liters),
                        'book_liters' => MoneyService::normalize((string) $t->book_liters),
                    ])->values()->all(),
                    'nozzles' => $nozzles->filter(fn ($n) => isset($tankNumbers[$n->tank_id]))->map(fn ($n) => [
                        'pump_number' => (int) ($pumpNumbers[$n->pump_id] ?? 0), 'nozzle_number' => (int) $n->nozzle_number,
                        'tank_number' => (int) $tankNumbers[$n->tank_id], 'meter' => (string) $n->current_meter_reading,
                    ])->values()->all(),
                ];
                if ($tanks->isEmpty()) {
                    $item['warnings'][] = 'FUEL_PRODUCT_WITHOUT_TANK';
                }
                $out[] = $item;
            }
        }

        return $out;
    }

    private function product(string $source, int $id, ?string $sku, ?string $barcode, string $name, string $price, string $qty, ?string $cost, bool $active, bool $trackStock): array
    {
        $warnings = [];
        if ($cost === null && MoneyService::isPositive($qty)) {
            $warnings[] = 'COST_UNKNOWN';
        }
        if (str_starts_with($qty, '-')) {
            $warnings[] = 'NEGATIVE_STOCK';
        }

        return [
            'source' => $source, 'source_id' => $id, 'sku' => $sku ?: null, 'barcode' => $barcode ?: null, 'name' => $name,
            'sale_price' => MoneyService::normalize($price), 'quantity' => MoneyService::normalize($qty), 'unit_cost' => $cost,
            'is_active' => $active, 'track_stock' => $trackStock, 'units' => [], 'prices' => [], 'batches' => [], 'pharmacy' => null, 'fuel' => null,
            'warnings' => $warnings,
        ];
    }

    /**
     * التكلفة «معروفة» إن كانت موجبة. عمود cost_price افتراضه صفر في القاعدة،
     * فصفرٌ على بضاعةٍ مخزّنة يعني «لم تُدخَل» لا «مجانية» (القاعدة السابعة).
     */
    private function knownCost(mixed $cost): ?string
    {
        return $cost !== null && MoneyService::isPositive((string) $cost) ? MoneyService::normalize((string) $cost) : null;
    }

    private function allCustomers(int $merchantId): array
    {
        $out = [];

        foreach (CustomerCreditAccount::where('merchant_user_id', $merchantId)->orderBy('id')->get() as $a) {
            $stored = MoneyService::normalize((string) $a->current_balance);
            $last = CustomerCreditMovement::where('account_id', $a->id)->orderByDesc('id')->value('balance_after');
            $warnings = $last !== null && MoneyService::compare(MoneyService::normalize((string) $last), $stored) !== 0 ? ["BALANCE_MISMATCH: ledger={$last} stored={$stored}"] : [];
            $out[] = ['source' => 'retail', 'source_id' => $a->id, 'name' => (string) ($a->customer_name ?: $a->customer_phone), 'phone' => $a->customer_phone,
                'credit_limit' => $a->credit_limit !== null ? MoneyService::normalize((string) $a->credit_limit) : null, 'balance' => $stored,
                'tier_code' => null, 'payment_terms_days' => null, 'warnings' => $warnings];
        }

        foreach (WholesaleBusiness::where('merchant_user_id', $merchantId)->get(['id', 'default_payment_terms_days']) as $business) {
            $tierCodes = WholesalePriceTier::where('business_id', $business->id)->pluck('code', 'id');
            foreach (WholesaleCustomer::where('business_id', $business->id)->orderBy('id')->get() as $c) {
                $out[] = ['source' => 'wholesale', 'source_id' => $c->id, 'name' => (string) ($c->company_name ?: $c->full_name), 'phone' => $c->phone,
                    'credit_limit' => $c->credit_limit !== null ? MoneyService::normalize((string) $c->credit_limit) : null,
                    'balance' => MoneyService::normalize((string) $c->current_balance),
                    'tier_code' => $c->default_tier_id ? ($tierCodes[$c->default_tier_id] ?? null) : null,
                    'payment_terms_days' => $c->payment_terms_days ?? $business->default_payment_terms_days, 'warnings' => []];
            }
        }

        // شركات الأساطيل في المحطة: عملاء آجل بأرصدتهم وبطاقات مركباتهم.
        foreach (FuelCompanyAccount::where('merchant_user_id', $merchantId)->orderBy('id')->get() as $a) {
            $limit = MoneyService::normalize((string) $a->credit_limit);
            $out[] = ['source' => 'fuel', 'source_id' => $a->id, 'name' => (string) $a->company_name, 'phone' => $a->contact_phone,
                'credit_limit' => MoneyService::isPositive($limit) ? $limit : null,
                'balance' => MoneyService::normalize((string) $a->current_balance), 'tier_code' => null, 'payment_terms_days' => null,
                'fuel_cards' => FuelCompanyCard::where('company_account_id', $a->id)->where('is_active', true)->orderBy('id')->get()->map(fn ($c) => [
                    'card_number' => (string) $c->card_number, 'vehicle_plate' => $c->vehicle_plate, 'driver_name' => $c->driver_name,
                    'daily_limit' => MoneyService::isPositive((string) $c->daily_limit) ? MoneyService::normalize((string) $c->daily_limit) : null,
                    'monthly_limit' => MoneyService::isPositive((string) $c->monthly_limit) ? MoneyService::normalize((string) $c->monthly_limit) : null,
                ])->values()->all(),
                'warnings' => []];
        }

        foreach (Pharmacy::where('merchant_user_id', $merchantId)->pluck('id') as $pharmacyId) {
            // الاسم والهاتف فقط: الملف الطبي (الحساسية والأمراض) لا يُنقل في هذا الإصدار.
            foreach (PharmacyCustomer::where('pharmacy_id', $pharmacyId)->orderBy('id')->get() as $c) {
                $out[] = ['source' => 'pharmacy', 'source_id' => $c->id, 'name' => (string) $c->full_name, 'phone' => $c->phone,
                    'credit_limit' => null, 'balance' => '0.0000', 'tier_code' => null, 'payment_terms_days' => null, 'warnings' => []];
            }
        }

        return $out;
    }

    private function allSuppliers(int $merchantId): array
    {
        $out = [];
        foreach (Supplier::where('merchant_user_id', $merchantId)->orderBy('id')->get() as $s) {
            $debt = MoneyService::normalize((string) ($s->current_debt ?? '0'));
            $credit = MoneyService::normalize((string) ($s->current_credit ?? '0'));
            $last = SupplierLedgerEntry::where('supplier_id', $s->id)->orderByDesc('id')->first(['debt_after', 'credit_after']);
            $warnings = [];
            if ($last && $last->debt_after !== null && MoneyService::compare(MoneyService::normalize((string) $last->debt_after), $debt) !== 0) {
                $warnings[] = "PAYABLE_MISMATCH: ledger={$last->debt_after} stored={$debt}";
            }
            $out[] = ['source' => 'supplier', 'source_id' => $s->id, 'name' => (string) $s->name, 'phone' => $s->phone,
                'payable' => $debt, 'credit' => $credit, 'warnings' => $warnings];
        }

        return $out;
    }

    private function tiers(int $merchantId): array
    {
        return WholesalePriceTier::whereIn('business_id', WholesaleBusiness::where('merchant_user_id', $merchantId)->pluck('id'))
            ->get(['code', 'name'])->unique('code')->map(fn ($t) => ['code' => $t->code, 'name' => $t->name])->values()->all();
    }
}
