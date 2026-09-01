<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\PharmacyBatch;
use App\Models\PharmacyCustomer;
use App\Models\PharmacyCategory;
use App\Models\PharmacyProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * خدمة الصيدلية: المحطة + المنتجات + Batches + العملاء.
 */
class PharmacyService
{
    public function getOrCreatePharmacy(User $merchant, ?array $data = null): Pharmacy
    {
        $pharmacy = Pharmacy::where('merchant_user_id', $merchant->id)->first();

        if (!$pharmacy) {
            $pharmacy = Pharmacy::create([
                'merchant_user_id' => $merchant->id,
                'pharmacy_name' => $data['pharmacy_name'] ?? "صيدلية {$merchant->f_name}",
                'license_number' => $data['license_number'] ?? null,
                'pharmacist_name' => $data['pharmacist_name'] ?? null,
                'pharmacist_license' => $data['pharmacist_license'] ?? null,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);
        } elseif ($data) {
            $pharmacy->update(array_intersect_key($data, array_flip([
                'pharmacy_name', 'license_number', 'pharmacist_name',
                'pharmacist_license', 'city', 'address', 'is_active',
            ])));
        }

        return $pharmacy->fresh();
    }

    // ============ المنتجات ============

    public function addProduct(Pharmacy $pharmacy, array $data): PharmacyProduct
    {
        if (empty($data['trade_name'])) {
            throw new InvalidArgumentException('الاسم التجاري مطلوب');
        }
        if (!isset($data['sale_price']) || (float)$data['sale_price'] <= 0) {
            throw new InvalidArgumentException('سعر البيع يجب أن يكون موجباً');
        }

        return DB::transaction(function () use ($pharmacy, $data) {
            $categoryId = $data['category_id'] ?? null;
            if (!empty($data['category_name'])) {
                $category = PharmacyCategory::firstOrCreate(
                    ['pharmacy_id' => $pharmacy->id, 'name' => trim($data['category_name'])],
                    ['sort_order' => (int) PharmacyCategory::where('pharmacy_id', $pharmacy->id)->max('sort_order') + 1],
                );
                $categoryId = $category->id;
            } elseif ($categoryId !== null && !PharmacyCategory::where('id', $categoryId)
                ->where('pharmacy_id', $pharmacy->id)->exists()) {
                throw new InvalidArgumentException('التصنيف لا يتبع لهذه الصيدلية');
            }

            $product = PharmacyProduct::create([
            'pharmacy_id' => $pharmacy->id,
            'category_id' => $categoryId,
            'sku' => $data['sku'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'trade_name' => $data['trade_name'],
            'generic_name' => $data['generic_name'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'unit' => $data['unit'] ?? 'قطعة',
            'sale_price' => MoneyService::normalize($data['sale_price']),
            'cost_price' => isset($data['cost_price'])
                ? MoneyService::normalize($data['cost_price']) : null,
            'requires_prescription' => (bool)($data['requires_prescription'] ?? false),
            'low_stock_threshold' => (int)($data['low_stock_threshold'] ?? 10),
            'description' => $data['description'] ?? null,
            'dosage_instructions' => $data['dosage_instructions'] ?? null,
            'current_stock' => '0',
            'is_active' => true,
            ]);

            // لا تُنشأ "كمية" مستقلة عن دفعة: الدفعة هي مصدر مخزون الدواء
            // وتكلفته وصلاحيته، ولذلك يُنشآن في المعاملة نفسها أو لا شيء.
            if (!empty($data['initial_batch'])) {
                $this->addBatch($product, $data['initial_batch']);
            }

            return $product->fresh(['category', 'batches']);
        });
    }

    public function updateProduct(PharmacyProduct $product, array $data): PharmacyProduct
    {
        $allowed = array_intersect_key($data, array_flip([
            'category_id', 'sku', 'barcode', 'trade_name', 'generic_name',
            'manufacturer', 'unit', 'sale_price', 'cost_price',
            'requires_prescription', 'low_stock_threshold',
            'description', 'dosage_instructions', 'is_active',
        ]));
        if (isset($allowed['sale_price'])) {
            $allowed['sale_price'] = MoneyService::normalize((string)$allowed['sale_price']);
        }
        if (isset($allowed['cost_price'])) {
            $allowed['cost_price'] = MoneyService::normalize((string)$allowed['cost_price']);
        }
        $product->update($allowed);
        return $product->fresh();
    }

    // ============ Batches ============

    public function addBatch(PharmacyProduct $product, array $data): PharmacyBatch
    {
        if (empty($data['batch_number'])) {
            throw new InvalidArgumentException('رقم الـ Batch مطلوب');
        }
        if (empty($data['expiry_date'])) {
            throw new InvalidArgumentException('تاريخ الصلاحية مطلوب');
        }
        if (!isset($data['quantity_received']) || (float)$data['quantity_received'] <= 0) {
            throw new InvalidArgumentException('الكمية يجب أن تكون موجبة');
        }

        // ارفض إن التاريخ في الماضي
        $expiry = \Carbon\Carbon::parse($data['expiry_date']);
        if ($expiry->isPast()) {
            throw new InvalidArgumentException('تاريخ الصلاحية في الماضي');
        }
        $manufacturedAt = !empty($data['manufactured_at'])
            ? \Carbon\Carbon::parse($data['manufactured_at']) : null;
        if ($manufacturedAt && $manufacturedAt->greaterThanOrEqualTo($expiry)) {
            throw new InvalidArgumentException('تاريخ الإنتاج يجب أن يسبق تاريخ الصلاحية');
        }

        return DB::transaction(function () use ($product, $data, $expiry, $manufacturedAt) {
            if (PharmacyBatch::where('product_id', $product->id)
                ->where('batch_number', $data['batch_number'])->exists()) {
                throw new InvalidArgumentException('رقم التشغيلة مسجّل لهذا الدواء مسبقاً');
            }
            $qty = MoneyService::normalize((string)$data['quantity_received']);

            $batch = PharmacyBatch::create([
                'batch_ulid' => (string) Str::ulid(),
                'product_id' => $product->id,
                'batch_number' => $data['batch_number'],
                'expiry_date' => $expiry->toDateString(),
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'manufactured_at' => $manufacturedAt?->toDateString(),
                'quantity_received' => $qty,
                'quantity_remaining' => $qty,
                'cost_per_unit' => isset($data['cost_per_unit'])
                    ? MoneyService::normalize((string)$data['cost_per_unit']) : null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_invoice' => $data['supplier_invoice'] ?? null,
                'status' => 'active',
            ]);

            // حدّث current_stock
            $newStock = MoneyService::add((string)$product->current_stock, $qty);
            $product->update(['current_stock' => $newStock]);

            return $batch->fresh();
        });
    }

    /**
     * السحب يمنع البيع فوراً ويُنقص الرصيد المتاح، مع حفظ السبب وصاحب
     * القرار. لا نحذف الدفعة كي تبقى البيوع السابقة قابلة للتدقيق.
     */
    public function recallBatch(PharmacyBatch $batch, User $actor, string $reason): PharmacyBatch
    {
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('سبب سحب التشغيلة مطلوب');
        return DB::transaction(function () use ($batch, $actor, $reason) {
            $locked = PharmacyBatch::lockForUpdate()->findOrFail($batch->id);
            if ($locked->status === 'recalled') {
                throw new InvalidArgumentException('هذه التشغيلة مسحوبة مسبقاً');
            }
            $product = PharmacyProduct::lockForUpdate()->findOrFail($locked->product_id);
            $remaining = (string) $locked->quantity_remaining;
            if (MoneyService::compare((string) $product->current_stock, $remaining) < 0) {
                throw new InvalidArgumentException('رصيد الدواء لا يطابق رصيد التشغيلة؛ لا يمكن سحبها قبل مراجعة الجرد');
            }
            $locked->update([
                'status' => 'recalled', 'recall_reason' => $reason,
                'recalled_by_user_id' => $actor->id, 'recalled_at' => now(),
            ]);
            $product->update(['current_stock' => MoneyService::sub((string) $product->current_stock, $remaining)]);
            return $locked->fresh();
        });
    }

    // ============ العملاء/المرضى ============

    public function addCustomer(Pharmacy $pharmacy, array $data): PharmacyCustomer
    {
        if (empty($data['full_name'])) {
            throw new InvalidArgumentException('الاسم مطلوب');
        }

        return PharmacyCustomer::create([
            'pharmacy_id' => $pharmacy->id,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'is_pregnant' => (bool)($data['is_pregnant'] ?? false),
            'is_breastfeeding' => (bool)($data['is_breastfeeding'] ?? false),
            'allergies' => $data['allergies'] ?? null,
            'chronic_conditions' => $data['chronic_conditions'] ?? null,
            'regular_medications' => $data['regular_medications'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
        ]);
    }

    public function updateCustomer(PharmacyCustomer $customer, array $data): PharmacyCustomer
    {
        $allowed = array_intersect_key($data, array_flip([
            'full_name', 'phone', 'date_of_birth', 'gender',
            'is_pregnant', 'is_breastfeeding',
            'allergies', 'chronic_conditions', 'regular_medications',
            'notes', 'is_active',
        ]));
        $customer->update($allowed);
        return $customer->fresh();
    }

    public function findCustomerByPhone(Pharmacy $pharmacy, string $phone): ?PharmacyCustomer
    {
        return PharmacyCustomer::where('pharmacy_id', $pharmacy->id)
            ->whereIn('phone', \App\Support\Phone::variants($phone))
            ->where('is_active', true)
            ->first();
    }
}
