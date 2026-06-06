<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\PharmacyBatch;
use App\Models\PharmacyCustomer;
use App\Models\PharmacyProduct;
use App\Models\PharmacySale;
use App\Models\PharmacySaleItem;
use App\Models\PharmacyStockAlert;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-PHARMACY-001 — خدمة الصيدلية (الجوهر).
 *
 * المنطق الجوهري:
 *   1. recordSale(): يخصم من Batches بـ FIFO (الأقدم انتهاءً أولاً).
 *   2. يفحص الحساسيات قبل البيع (إن العميل مسجّل).
 *   3. يتحقّق من وجود وصفة للمنتجات التي تطلبها.
 *   4. يحدّث current_stock للمنتج تلقائياً.
 *   5. يُنشئ stock_alerts عند الحاجة.
 */
class PharmacySaleService
{
    use TransactionTrait;

    public function __construct(
        private readonly PharmacyAlertService $alerts,
    ) {}

    /**
     * تسجيل عملية بيع كاملة.
     *
     * @param array $items [
     *   ['product_id' => int, 'quantity' => float],
     *   ...
     * ]
     * @param array $data {
     *   customer_id?: int (إن مسجّل),
     *   payment_method: cash|amial_pay|credit,
     *   paid_transaction_id?: string,
     *   prescription_number?: string,
     *   prescribing_doctor?: string,
     *   prescription_date?: string,
     *   discount_amount?: float,
     *   warnings_acknowledged?: array (تأكيد المستخدم على التحذيرات),
     * }
     */
    public function recordSale(
        User $merchant,
        Pharmacy $pharmacy,
        ?int $posUserId,
        array $items,
        array $data,
    ): PharmacySale {
        if (empty($items)) {
            throw new InvalidArgumentException('السلّة فارغة');
        }
        if (!isset($data['payment_method']) || !in_array($data['payment_method'], PharmacySale::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException('طريقة الدفع غير صحيحة');
        }

        // ===== 1) Pre-flight checks (قبل DB transaction) =====
        $customer = null;
        if (!empty($data['customer_id'])) {
            $customer = PharmacyCustomer::where('id', $data['customer_id'])
                ->where('pharmacy_id', $pharmacy->id)
                ->first();
            if (!$customer) {
                throw new RuntimeException('العميل غير موجود');
            }
        }

        // اجمع المنتجات + تحقّق من الكمّيات والوصفة والحساسيات
        $resolvedItems = [];
        $requiresPrescription = false;
        $allergyWarnings = [];

        foreach ($items as $idx => $item) {
            $product = PharmacyProduct::where('id', $item['product_id'] ?? 0)
                ->where('pharmacy_id', $pharmacy->id)
                ->where('is_active', true)
                ->first();
            if (!$product) {
                throw new RuntimeException("المنتج رقم #{$idx} غير موجود");
            }

            $qty = (float)($item['quantity'] ?? 0);
            if ($qty <= 0) {
                throw new InvalidArgumentException("كمّية المنتج {$product->trade_name} غير صحيحة");
            }

            // التحقّق من المخزون الكلّي
            if ((float)$product->current_stock < $qty) {
                throw new RuntimeException(
                    "المخزون غير كاف لـ {$product->trade_name} (المتاح: {$product->current_stock})"
                );
            }

            // إن يحتاج وصفة
            if ($product->requires_prescription) {
                $requiresPrescription = true;
            }

            // فحص الحساسيات (إن العميل مسجّل)
            if ($customer) {
                $conflicts = $customer->checkAllergyConflict($product);
                if (!empty($conflicts)) {
                    $allergyWarnings[] = [
                        'product_name' => $product->trade_name,
                        'allergies' => $conflicts,
                    ];
                }
            }

            $resolvedItems[] = ['product' => $product, 'quantity' => $qty];
        }

        // ===== 2) فحوصات إلزامية =====
        // وصفة طبيب (إن منتج يحتاجها ولم تُذكر وصفة)
        if ($requiresPrescription && empty($data['prescription_number'])) {
            throw new InvalidArgumentException(
                'هذا البيع يحتوي منتجات تستلزم وصفة طبية — أدخل رقم الوصفة'
            );
        }

        // تحذيرات الحساسية: لا نمنع البيع، لكن نطلب تأكيد المستخدم
        if (!empty($allergyWarnings)) {
            $ackList = $data['warnings_acknowledged'] ?? [];
            $allAcked = collect($allergyWarnings)->every(
                fn($w) => in_array($w['product_name'], $ackList, true)
            );
            if (!$allAcked) {
                $msg = "⚠️ تنبيه حساسية: " . implode(' / ', array_map(
                    fn($w) => "{$w['product_name']} (يحتوي: " . implode(', ', $w['allergies']) . ")",
                    $allergyWarnings,
                ));
                throw new InvalidArgumentException($msg);
            }
        }

        // ===== 3) تنفيذ DB transaction =====
        return DB::transaction(function () use (
            $merchant, $pharmacy, $posUserId, $resolvedItems, $data, $customer, $allergyWarnings,
        ) {
            // احسب الإجمالي
            $subtotal = '0';
            foreach ($resolvedItems as $r) {
                $line = bcmul((string)$r['quantity'], (string)$r['product']->sale_price, 4);
                $subtotal = MoneyService::add($subtotal, $line);
            }
            $discount = MoneyService::normalize((string)($data['discount_amount'] ?? '0'));
            $total = MoneyService::sub($subtotal, $discount);
            if ((float)$total < 0) {
                throw new InvalidArgumentException('الخصم أكبر من الإجمالي');
            }

            // أنشئ سجل البيع
            $sale = PharmacySale::create([
                'sale_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'pos_user_id' => $posUserId,
                'pharmacy_id' => $pharmacy->id,
                'customer_id' => $customer?->id,
                'prescription_number' => $data['prescription_number'] ?? null,
                'prescribing_doctor' => $data['prescribing_doctor'] ?? null,
                'prescription_date' => $data['prescription_date'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'payment_method' => $data['payment_method'],
                'paid_transaction_id' => $data['paid_transaction_id'] ?? null,
                'warnings_acknowledged' => empty($allergyWarnings) ? null : $allergyWarnings,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            // اخصم من Batches بـ FIFO (واحد لكلّ منتج)
            foreach ($resolvedItems as $r) {
                $this->deductFromBatchesFifo($sale, $r['product'], (string)$r['quantity']);
            }

            // فحص تنبيهات المخزون بعد البيع
            foreach ($resolvedItems as $r) {
                $this->alerts->checkLowStock($r['product']->fresh());
            }

            return $sale->fresh(['items', 'customer']);
        });
    }

    /**
     * يخصم كمّية من Batches بترتيب FIFO.
     * قد تُوزّع كمّية على عدّة batches إن لم تكفِ واحدة.
     */
    private function deductFromBatchesFifo(
        PharmacySale $sale, PharmacyProduct $product, string $totalQty,
    ): void {
        $remaining = $totalQty;

        // اقفل المنتج + Batches للأمان
        $product = PharmacyProduct::lockForUpdate()->find($product->id);

        $batches = PharmacyBatch::where('product_id', $product->id)
            ->where('status', 'active')
            ->where('quantity_remaining', '>', 0)
            ->where('expiry_date', '>=', now()->toDateString()) // لا نبيع منتهي
            ->orderBy('expiry_date') // FIFO
            ->lockForUpdate()
            ->get();

        if ($batches->isEmpty()) {
            throw new RuntimeException(
                "لا توجد Batches صالحة لـ {$product->trade_name}"
            );
        }

        foreach ($batches as $batch) {
            if (MoneyService::compare($remaining, '0') <= 0) break;

            $deduct = MoneyService::compare((string)$batch->quantity_remaining, $remaining) >= 0
                ? $remaining
                : (string)$batch->quantity_remaining;

            // أنشئ سجل البيع للـ batch
            PharmacySaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'product_trade_name' => $product->trade_name,
                'quantity' => $deduct,
                'unit_price' => (string)$product->sale_price,
                'total_price' => bcmul($deduct, (string)$product->sale_price, 4),
                'required_prescription' => $product->requires_prescription,
            ]);

            // اخصم من الـ batch
            $newRemaining = MoneyService::sub((string)$batch->quantity_remaining, $deduct);
            $newStatus = MoneyService::compare($newRemaining, '0') <= 0 ? 'exhausted' : 'active';
            $batch->update([
                'quantity_remaining' => $newRemaining,
                'status' => $newStatus,
            ]);

            $remaining = MoneyService::sub($remaining, $deduct);
        }

        if (MoneyService::compare($remaining, '0') > 0) {
            throw new RuntimeException(
                "المخزون من Batches غير كاف لـ {$product->trade_name} (ناقص: {$remaining})"
            );
        }

        // حدّث current_stock للمنتج
        $newStock = MoneyService::sub((string)$product->current_stock, $totalQty);
        $product->update(['current_stock' => $newStock]);
    }
}
