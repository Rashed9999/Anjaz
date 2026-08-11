<?php

namespace App\Services;

use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesaleInvoiceItem;
use App\Models\WholesalePriceTier;
use App\Models\WholesaleProduct;
use App\Models\WholesaleSalesRep;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-WHOLESALE-001 — خدمة الفوترة الجوهرية.
 *
 * المنطق:
 *   1. createInvoice(): يولّد رقم فاتورة + يخصم من المخزون + يحدّث رصيد العميل.
 *   2. الفحص المالي: إن credit، يفحص credit_limit للعميل.
 *   3. تطبيق Multi-Pricing تلقائياً حسب tier_id + quantity.
 *   4. حساب العمولة لمندوب المبيعات (snapshot).
 *   5. كل شيء داخل DB transaction + lockForUpdate.
 */
class WholesaleInvoiceService
{
    use TransactionTrait;

    /**
     * إنشاء فاتورة كاملة.
     *
     * @param array $items [
     *   ['product_id' => int, 'quantity' => float, 'discount_per_unit'? => float],
     *   ...
     * ]
     * @param array $data {
     *   customer_id: int,
     *   payment_type: cash|credit,
     *   sales_rep_id?: int,
     *   discount_amount?: float,           // خصم إضافي على الإجمالي
     *   tax_rate?: float,                  // افتراضياً من business
     *   due_date?: string (YYYY-MM-DD),
     *   notes?: string,
     * }
     */
    public function createInvoice(
        User $merchant,
        WholesaleBusiness $business,
        array $items,
        array $data,
    ): WholesaleInvoice {
        if (empty($items)) {
            throw new InvalidArgumentException('الفاتورة فارغة');
        }
        if (!isset($data['customer_id'])) {
            throw new InvalidArgumentException('العميل مطلوب');
        }
        $paymentType = $data['payment_type'] ?? 'credit';
        if (!in_array($paymentType, WholesaleInvoice::PAYMENT_TYPES, true)) {
            throw new InvalidArgumentException('نوع الدفع غير صحيح');
        }

        return DB::transaction(function () use ($merchant, $business, $items, $data, $paymentType) {
            // 1) اقفل العميل
            $customer = WholesaleCustomer::where('id', $data['customer_id'])
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();
            if (!$customer) {
                throw new RuntimeException('العميل غير موجود');
            }
            $tierId = $customer->default_tier_id ?? $this->getDefaultTierId($business);
            if ($tierId === null) {
                throw new RuntimeException('لا توجد شريحة أسعار افتراضية');
            }

            // 2) احسب العناصر
            $resolvedItems = [];
            $subtotal = '0';

            foreach ($items as $idx => $item) {
                $product = WholesaleProduct::where('id', $item['product_id'] ?? 0)
                    ->where('business_id', $business->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
                if (!$product) {
                    throw new RuntimeException("المنتج #{$idx} غير موجود");
                }
                $qty = (float)($item['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException("كمية {$product->name} غير صحيحة");
                }
                if ((float)$product->current_stock < $qty) {
                    throw new RuntimeException(
                        "المخزون غير كاف لـ {$product->name} (المتاح: {$product->current_stock})"
                    );
                }

                $unitPrice = $product->priceFor($tierId, $qty);
                $discountPerUnit = (float)($item['discount_per_unit'] ?? 0);
                $effectivePrice = max(0, $unitPrice - $discountPerUnit);
                $lineTotal = MoneyService::normalize((string)($effectivePrice * $qty));

                $resolvedItems[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'unit_price' => MoneyService::normalize((string)$unitPrice),
                    'discount_per_unit' => MoneyService::normalize((string)$discountPerUnit),
                    'line_total' => $lineTotal,
                ];
                $subtotal = MoneyService::add($subtotal, $lineTotal);
            }

            // 3) الخصم الإضافي + الضريبة
            $discountAmount = MoneyService::normalize((string)($data['discount_amount'] ?? '0'));
            if (MoneyService::compare($discountAmount, $subtotal) > 0) {
                throw new InvalidArgumentException('الخصم أكبر من الإجمالي');
            }
            $afterDiscount = MoneyService::sub($subtotal, $discountAmount);

            // ══════════════════════════════════════════════════════════
            // AMIAL-WHOLESALE-FLOAT-001 — **ضريبةٌ وعمولةٌ تُحسبان بـfloat.**
            //
            // كان الحساب `(float)$afterDiscount * $taxRate / 100` ثمّ
            // `normalize`. و`float` في PHP ثنائيٌّ مزدوجُ الدقّة: لا يمثّل
            // الكسورَ العشريّة تمثيلاً تامّاً. فـ`0.1 + 0.2 !== 0.3`، و
            // ضريبةُ ٥٪ على ١٬٢٣٤٬٥٦٧٫٨٩ تنحرف في الخانة الرابعة.
            //
            // والانحرافُ لا يظهر في فاتورة، **ويظهر في المجموع**: ألفُ
            // فاتورةٍ تُراكم فرقاً يقف عنده مدقّقٌ ولا يجد له سبباً —
            // والدفترُ متوازنٌ لأنّ الرقم الخطأ رُحّل مرّتين.
            //
            // و`MoneyService::mul` و`div` مبنيّتان على `bcmath` — دقّةٌ
            // عشريّةٌ تامّة — وكانتا موجودتين، والحسابُ يتجاوزهما.
            $taxRate = (string) ($data['tax_rate'] ?? $business->default_tax_rate);
            $taxAmount = MoneyService::compare($taxRate, '0') > 0
                ? MoneyService::div(MoneyService::mul($afterDiscount, $taxRate), '100')
                : '0';

            $totalAmount = MoneyService::add($afterDiscount, $taxAmount);

            // 4) فحص الائتمان إن credit
            if ($paymentType === 'credit') {
                if (!$customer->canChargeCredit((float)$totalAmount)) {
                    throw new RuntimeException(
                        "تجاوز حدّ الائتمان (المتاح: {$customer->availableCredit()} ر.ي)"
                    );
                }
            }

            // 5) العمولة (snapshot)
            $commissionRate = null;
            $commissionAmount = null;
            $salesRep = null;
            if (!empty($data['sales_rep_id'])) {
                $salesRep = WholesaleSalesRep::where('id', $data['sales_rep_id'])
                    ->where('business_id', $business->id)
                    ->lockForUpdate()
                    ->first();
                if ($salesRep) {
                    // AMIAL-WHOLESALE-FLOAT-001 — انظر أعلاه. والعمولةُ
                    // أخطر: هي مالٌ يُدفع لمندوبٍ لا رقمٌ يُعرض.
                    $commissionRate = (string) $salesRep->default_commission_rate;
                    $commissionAmount = MoneyService::div(
                        MoneyService::mul($totalAmount, $commissionRate), '100'
                    );
                }
            }

            // 6) أنشئ الفاتورة
            $invoiceNumber = $business->nextInvoiceNumber();
            $dueDate = !empty($data['due_date'])
                ? \Carbon\Carbon::parse($data['due_date'])->toDateString()
                : now()->addDays($customer->payment_terms_days ?? 30)->toDateString();

            $invoice = WholesaleInvoice::create([
                'invoice_ulid' => (string) Str::ulid(),
                'invoice_number' => $invoiceNumber,
                'business_id' => $business->id,
                'branch_id' => $data['branch_id'] ?? null, // P1-BRANCHES
                'customer_id' => $customer->id,
                'sales_rep_id' => $salesRep?->id,
                'created_by_user_id' => $merchant->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'paid_amount' => $paymentType === 'cash' ? $totalAmount : '0',
                'balance_due' => $paymentType === 'cash' ? '0' : $totalAmount,
                'status' => $paymentType === 'cash' ? 'paid' : 'issued',
                'payment_type' => $paymentType,
                'sales_rep_commission_rate' => $commissionRate,
                'sales_rep_commission_amount' => $commissionAmount,
                'notes' => $data['notes'] ?? null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            // 7) أنشئ العناصر + اخصم من المخزون
            foreach ($resolvedItems as $r) {
                $product = $r['product'];
                WholesaleInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'unit' => $product->unit,
                    'quantity' => MoneyService::normalize((string)$r['quantity']),
                    'unit_price' => $r['unit_price'],
                    'discount_per_unit' => $r['discount_per_unit'],
                    'line_total' => $r['line_total'],
                    'tier_id' => $tierId,
                ]);

                // اخصم من المخزون
                $newStock = MoneyService::sub((string)$product->current_stock, (string)$r['quantity']);
                $product->update(['current_stock' => $newStock]);
            }

            // 8) حدّث رصيد العميل (إن credit)
            if ($paymentType === 'credit') {
                $newBalance = MoneyService::add((string)$customer->current_balance, $totalAmount);
                $customer->update([
                    'current_balance' => $newBalance,
                    'total_purchases' => MoneyService::add((string)$customer->total_purchases, $totalAmount),
                    'last_purchase_date' => now()->toDateString(),
                ]);
            } else {
                $customer->update([
                    'total_purchases' => MoneyService::add((string)$customer->total_purchases, $totalAmount),
                    'last_purchase_date' => now()->toDateString(),
                ]);
            }

            // 9) حدّث إحصائيات Sales Rep
            if ($salesRep && $commissionAmount !== null) {
                $salesRep->update([
                    'total_sales' => MoneyService::add((string)$salesRep->total_sales, $totalAmount),
                    'total_commission_earned' => MoneyService::add(
                        (string)$salesRep->total_commission_earned, $commissionAmount,
                    ),
                ]);
            }

            return $invoice->fresh(['items', 'customer', 'salesRep']);
        });
    }

    /** إبطال فاتورة (للأخطاء الإدخالية). */
    public function voidInvoice(WholesaleInvoice $invoice, string $reason): WholesaleInvoice
    {
        if ($invoice->status === 'voided') {
            throw new RuntimeException('الفاتورة مُبطَلة مسبقاً');
        }
        if ((float)$invoice->paid_amount > 0) {
            throw new RuntimeException('لا يمكن إبطال فاتورة دُفع منها — قم بـ refund');
        }

        return DB::transaction(function () use ($invoice, $reason) {
            // أرجع المخزون
            foreach ($invoice->items as $item) {
                if ($item->product_id) {
                    $product = WholesaleProduct::lockForUpdate()->find($item->product_id);
                    if ($product) {
                        $product->update([
                            'current_stock' => MoneyService::add(
                                (string)$product->current_stock, (string)$item->quantity,
                            ),
                        ]);
                    }
                }
            }

            // أعد رصيد العميل
            if ($invoice->payment_type === 'credit') {
                $customer = WholesaleCustomer::lockForUpdate()->find($invoice->customer_id);
                if ($customer) {
                    $customer->update([
                        'current_balance' => MoneyService::sub(
                            (string)$customer->current_balance, (string)$invoice->total_amount,
                        ),
                    ]);
                }
            }

            $invoice->update([
                'status' => 'voided',
                'notes' => trim(($invoice->notes ?? '') . "\n[VOIDED] {$reason}"),
            ]);

            return $invoice->fresh();
        });
    }

    private function getDefaultTierId(WholesaleBusiness $business): ?int
    {
        $tier = WholesalePriceTier::where('business_id', $business->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
        return $tier?->id;
    }
}
