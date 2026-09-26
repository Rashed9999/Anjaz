<?php

namespace App\Services;

use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesalePriceTier;
use App\Models\WholesaleProduct;
use App\Models\WholesaleProductLot;
use App\Models\WholesaleProductPrice;
use App\Models\WholesaleProductUnit;
use App\Models\WholesaleSalesRep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AMIAL-WHOLESALE-001 — الخدمات الإدارية:
 * - إنشاء/تحديث Business + Price Tiers + Products + Customers + Sales Reps + Multi-Pricing.
 */
class WholesaleService
{
    // ============ Business ============

    public function getOrCreateBusiness(User $merchant, ?array $data = null): WholesaleBusiness
    {
        $biz = WholesaleBusiness::where('merchant_user_id', $merchant->id)->first();

        if (!$biz) {
            $biz = WholesaleBusiness::create([
                'merchant_user_id' => $merchant->id,
                'business_name' => $data['business_name'] ?? "متجر جملة {$merchant->f_name}",
                'commercial_register' => $data['commercial_register'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? $merchant->phone,
                'email' => $data['email'] ?? null,
                'default_tax_rate' => (float)($data['default_tax_rate'] ?? 0),
                'invoice_prefix' => $data['invoice_prefix'] ?? 'INV',
                'default_payment_terms_days' => (int)($data['default_payment_terms_days'] ?? 30),
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            // أنشئ شرائح أسعار افتراضية
            $this->createDefaultPriceTiers($biz);
        } elseif ($data) {
            $allowed = array_intersect_key($data, array_flip([
                'business_name', 'commercial_register', 'tax_number', 'city', 'address',
                'phone', 'email', 'default_tax_rate', 'invoice_prefix',
                'default_payment_terms_days', 'is_active',
            ]));
            $biz->update($allowed);
        }

        return $biz->fresh();
    }

    private function createDefaultPriceTiers(WholesaleBusiness $biz): void
    {
        $defaults = [
            ['code' => 'retail', 'name' => 'تجزئة', 'sort_order' => 1, 'is_default' => false],
            ['code' => 'wholesale', 'name' => 'جملة', 'sort_order' => 2, 'is_default' => true],
            ['code' => 'super_wholesale', 'name' => 'جملة الجملة', 'sort_order' => 3, 'is_default' => false],
        ];
        foreach ($defaults as $d) {
            WholesalePriceTier::create(array_merge(['business_id' => $biz->id], $d));
        }
    }

    // ============ Price Tiers ============

    public function addPriceTier(WholesaleBusiness $biz, array $data): WholesalePriceTier
    {
        if (empty($data['code']) || empty($data['name'])) {
            throw new InvalidArgumentException('code + name مطلوبان');
        }

        return DB::transaction(function () use ($biz, $data) {
            // إن is_default = true، أزِل الافتراضي السابق
            if (!empty($data['is_default'])) {
                WholesalePriceTier::where('business_id', $biz->id)
                    ->update(['is_default' => false]);
            }

            return WholesalePriceTier::create([
                'business_id' => $biz->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'sort_order' => (int)($data['sort_order'] ?? 99),
                'is_default' => (bool)($data['is_default'] ?? false),
                'is_active' => true,
            ]);
        });
    }

    // ============ Products ============

    public function addProduct(WholesaleBusiness $biz, array $data): WholesaleProduct
    {
        if (empty($data['name'])) {
            throw new InvalidArgumentException('اسم المنتج مطلوب');
        }
        if (!isset($data['base_price']) || (float)$data['base_price'] <= 0) {
            throw new InvalidArgumentException('سعر أساسي صحيح مطلوب');
        }

        $product = WholesaleProduct::create([
            'business_id' => $biz->id,
            'sku' => $data['sku'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'name' => $data['name'],
            'manufacturer' => $data['manufacturer'] ?? null,
            'unit' => $data['unit'] ?? 'قطعة',
            'base_price' => MoneyService::normalize($data['base_price']),
            'cost_price' => isset($data['cost_price']) ? MoneyService::normalize($data['cost_price']) : null,
            'low_stock_threshold' => (int)($data['low_stock_threshold'] ?? 10),
            'current_stock' => MoneyService::normalize((string)($data['initial_stock'] ?? '0')),
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);
        $this->ensureBaseUnit($product);
        return $product->fresh(['units']);
    }

    public function updateProduct(WholesaleProduct $product, array $data): WholesaleProduct
    {
        $allowed = array_intersect_key($data, array_flip([
            'sku', 'barcode', 'name', 'manufacturer', 'unit',
            'base_price', 'cost_price', 'low_stock_threshold',
            'description', 'is_active',
        ]));
        if (isset($allowed['base_price'])) {
            $allowed['base_price'] = MoneyService::normalize((string)$allowed['base_price']);
        }
        if (isset($allowed['cost_price'])) {
            $allowed['cost_price'] = MoneyService::normalize((string)$allowed['cost_price']);
        }
        $product->update($allowed);
        return $product->fresh();
    }

    /** وحدات المنتج: كل عامل موجب ويشير دائماً إلى وحدة الأساس. */
    public function listUnits(WholesaleProduct $product): array
    {
        $this->ensureBaseUnit($product);
        return $product->units()->orderByDesc('is_base')->orderBy('factor_to_base')->get()->all();
    }

    public function saveUnit(WholesaleProduct $product, array $data): WholesaleProductUnit
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $factor = MoneyService::normalize((string) ($data['factor_to_base'] ?? '0'));
        if ($code === '' || $name === '' || MoneyService::compare($factor, '0') <= 0) {
            throw new InvalidArgumentException('رمز الوحدة واسمها وعامل التحويل الموجب مطلوبة');
        }

        return DB::transaction(function () use ($product, $code, $name, $factor, $data) {
            $this->ensureBaseUnit($product);
            $isBase = (bool) ($data['is_base'] ?? false);
            if ($isBase && MoneyService::compare($factor, '1') !== 0) {
                throw new InvalidArgumentException('وحدة الأساس عاملها 1 دائماً');
            }
            $currentBase = WholesaleProductUnit::where('product_id', $product->id)
                ->where('is_base', true)->lockForUpdate()->first();
            if ($isBase && $currentBase !== null && $currentBase->code !== $code) {
                // تغيير وحدة الأساس بعد وجود مخزون أو فواتير يغيّر معنى كل
                // كمية تاريخية؛ التحويل يضاف كوحدة بيع ولا يعيد تعريف الأصل.
                throw new InvalidArgumentException('لا يمكن تغيير وحدة الأساس؛ أضف وحدة بيع بعامل تحويل');
            }
            if ($isBase) {
                WholesaleProductUnit::where('product_id', $product->id)->update(['is_base' => false]);
            }
            return WholesaleProductUnit::updateOrCreate(
                ['product_id' => $product->id, 'code' => $code],
                ['name' => $name, 'factor_to_base' => $factor, 'is_base' => $isBase, 'is_active' => true],
            );
        });
    }

    /** استلام دفعة: الكمية تدخل بوحدة مختارة وتُحفظ/تُضاف كمخزون أساس فقط. */
    public function receiveLot(WholesaleProduct $product, array $data): WholesaleProductLot
    {
        $lotNumber = trim((string) ($data['lot_number'] ?? ''));
        $qty = MoneyService::normalize((string) ($data['quantity'] ?? '0'));
        if ($lotNumber === '' || MoneyService::compare($qty, '0') <= 0) {
            throw new InvalidArgumentException('رقم الدفعة والكمية الموجبة مطلوبان');
        }
        $expiry = !empty($data['expiry_date']) ? \Carbon\Carbon::parse($data['expiry_date'])->startOfDay() : null;
        if ($expiry !== null && $expiry->isPast()) {
            throw new InvalidArgumentException('لا يمكن استلام دفعة منتهية الصلاحية');
        }

        return DB::transaction(function () use ($product, $data, $lotNumber, $qty, $expiry) {
            $locked = WholesaleProduct::lockForUpdate()->findOrFail($product->id);
            $unit = $this->unitFor($locked, $data['unit_id'] ?? null, true);
            $baseQty = MoneyService::mul($qty, (string) $unit->factor_to_base);
            $location = trim((string) ($data['location'] ?? '')) ?: null;
            $exists = WholesaleProductLot::where('product_id', $locked->id)
                ->where('lot_number', $lotNumber)->where('location', $location)->exists();
            if ($exists) {
                throw new InvalidArgumentException('هذه الدفعة مسجلة مسبقاً في الموقع نفسه');
            }
            $lot = WholesaleProductLot::create([
                'lot_ulid' => (string) \Illuminate\Support\Str::ulid(),
                'product_id' => $locked->id, 'lot_number' => $lotNumber, 'location' => $location,
                'received_at' => !empty($data['received_at']) ? $data['received_at'] : now()->toDateString(),
                'expiry_date' => $expiry?->toDateString(),
                'quantity_received' => $baseQty, 'quantity_available' => $baseQty,
                'cost_per_base_unit' => isset($data['cost_per_unit'])
                    ? MoneyService::div(MoneyService::normalize((string) $data['cost_per_unit']), (string) $unit->factor_to_base)
                    : null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'status' => 'active',
            ]);
            $locked->update(['current_stock' => MoneyService::add((string) $locked->current_stock, $baseQty)]);
            return $lot->fresh();
        });
    }

    public function unitFor(WholesaleProduct $product, mixed $unitId = null, bool $lock = false): WholesaleProductUnit
    {
        $this->ensureBaseUnit($product);
        $query = WholesaleProductUnit::where('product_id', $product->id)->where('is_active', true);
        if ($lock) $query->lockForUpdate();
        $unit = $unitId ? $query->where('id', (int) $unitId)->first() : $query->where('is_base', true)->first();
        if ($unit === null) throw new InvalidArgumentException('وحدة بيع المنتج غير صالحة');
        return $unit;
    }

    private function ensureBaseUnit(WholesaleProduct $product): void
    {
        if (WholesaleProductUnit::where('product_id', $product->id)->where('is_base', true)->exists()) return;
        WholesaleProductUnit::firstOrCreate(
            ['product_id' => $product->id, 'code' => 'base'],
            ['name' => $product->unit ?: 'قطعة', 'factor_to_base' => '1', 'is_base' => true, 'is_active' => true],
        );
    }

    /** تعديل مخزون يدوياً (تعديل جرد). */
    public function adjustStock(
        WholesaleProduct $product, float $newStock, string $reason,
    ): WholesaleProduct {
        if ($newStock < 0) {
            throw new InvalidArgumentException('المخزون لا يمكن أن يكون سالباً');
        }
        $product->update([
            'current_stock' => MoneyService::normalize((string)$newStock),
        ]);
        // ملاحظة: لا audit log في هذه الجولة — يُضاف لاحقاً.
        return $product->fresh();
    }

    // ============ Multi-Pricing ============

    /**
     * تعيين/تحديث سعر منتج لشريحة + كمّية أدنى.
     * إن وُجد price بنفس (product, tier, min_quantity) — يحدّثه.
     */
    public function setProductPrice(
        WholesaleProduct $product,
        int $tierId,
        float $price,
        float $minQuantity = 1.0,
    ): WholesaleProductPrice {
        if ($price <= 0) {
            throw new InvalidArgumentException('السعر يجب أن يكون موجباً');
        }
        if ($minQuantity <= 0) {
            throw new InvalidArgumentException('الحد الأدنى للكمية يجب أن يكون موجباً');
        }

        // تأكّد أن الـ tier ينتمي لنفس business
        $tier = WholesalePriceTier::find($tierId);
        if (!$tier || $tier->business_id !== $product->business_id) {
            throw new InvalidArgumentException('شريحة سعر غير صحيحة');
        }

        return WholesaleProductPrice::updateOrCreate(
            [
                'product_id' => $product->id,
                'tier_id' => $tierId,
                'min_quantity' => MoneyService::normalize((string)$minQuantity),
            ],
            ['price' => MoneyService::normalize((string)$price)],
        );
    }

    public function removeProductPrice(WholesaleProductPrice $price): void
    {
        $price->delete();
    }

    // ============ Customers ============

    public function addCustomer(WholesaleBusiness $biz, array $data): WholesaleCustomer
    {
        if (empty($data['full_name'])) {
            throw new InvalidArgumentException('الاسم مطلوب');
        }

        return WholesaleCustomer::create([
            'business_id' => $biz->id,
            'default_tier_id' => $data['default_tier_id'] ?? $this->defaultTierId($biz),
            'full_name' => $data['full_name'],
            'company_name' => $data['company_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'credit_limit' => MoneyService::normalize((string)($data['credit_limit'] ?? '0')),
            'payment_terms_days' => (int)($data['payment_terms_days'] ?? $biz->default_payment_terms_days),
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
        ]);
    }

    public function updateCustomer(WholesaleCustomer $customer, array $data): WholesaleCustomer
    {
        $allowed = array_intersect_key($data, array_flip([
            'default_tier_id', 'full_name', 'company_name', 'phone', 'email',
            'city', 'address', 'tax_number',
            'credit_limit', 'payment_terms_days', 'notes', 'is_active',
        ]));
        if (isset($allowed['credit_limit'])) {
            $allowed['credit_limit'] = MoneyService::normalize((string)$allowed['credit_limit']);
        }
        $customer->update($allowed);
        return $customer->fresh();
    }

    // ============ Sales Reps ============

    public function addSalesRep(WholesaleBusiness $biz, array $data): WholesaleSalesRep
    {
        if (empty($data['full_name'])) {
            throw new InvalidArgumentException('الاسم مطلوب');
        }
        $rate = (float)($data['default_commission_rate'] ?? 0);
        if ($rate < 0 || $rate > 100) {
            throw new InvalidArgumentException('نسبة العمولة يجب أن تكون بين 0 و 100');
        }

        return WholesaleSalesRep::create([
            'business_id' => $biz->id,
            'user_id' => $data['user_id'] ?? null,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'default_commission_rate' => $rate,
            'is_active' => true,
        ]);
    }

    public function recordCommissionPayment(WholesaleSalesRep $rep, float $amount): WholesaleSalesRep
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('المبلغ يجب أن يكون موجباً');
        }
        $pending = $rep->pendingCommission();
        if ($amount > $pending) {
            throw new InvalidArgumentException("المبلغ يتجاوز العمولة المستحقّة ({$pending})");
        }

        $rep->update([
            'total_commission_paid' => MoneyService::add(
                (string)$rep->total_commission_paid, (string)$amount,
            ),
        ]);
        return $rep->fresh();
    }

    // ============ Helpers ============

    private function defaultTierId(WholesaleBusiness $biz): ?int
    {
        return WholesalePriceTier::where('business_id', $biz->id)
            ->where('is_default', true)->where('is_active', true)
            ->value('id');
    }
}
