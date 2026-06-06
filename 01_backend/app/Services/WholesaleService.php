<?php

namespace App\Services;

use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesalePriceTier;
use App\Models\WholesaleProduct;
use App\Models\WholesaleProductPrice;
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

        return WholesaleProduct::create([
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
