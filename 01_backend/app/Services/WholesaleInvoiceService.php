<?php

namespace App\Services;

use App\Models\CustomerCreditAccount;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesaleInvoiceItem;
use App\Models\WholesaleInvoiceItemLot;
use App\Models\WholesalePriceTier;
use App\Models\WholesaleProduct;
use App\Models\WholesaleProductLot;
use App\Models\WholesaleProductUnit;
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

    public function __construct(
        private readonly MerchantPaymentReferenceService $paymentReference,
    ) {}

    /**
     * إنشاء فاتورة كاملة.
     *
     * @param array $items [
     *   ['product_id' => int, 'quantity' => float, 'discount_per_unit'? => float],
     *   ...
     * ]
     * @param array $data {
     *   customer_id: int,
     *   payment_type: cash|amial_pay|credit,
     *   paid_transaction_id?: string,    // إلزامي لأميال باي بعد تحصيل QR فعلي
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
        $paidTransactionId = trim((string) ($data['paid_transaction_id'] ?? ''));
        if ($paymentType === 'amial_pay' && $paidTransactionId === '') {
            throw new InvalidArgumentException('دفع أميال باي يحتاج مرجع التحصيل الفعلي');
        }

        return DB::transaction(function () use ($merchant, $business, $items, $data, $paymentType, $paidTransactionId) {
            // 1) اقفل العميل
            $customer = WholesaleCustomer::where('id', $data['customer_id'])
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();
            if (!$customer) {
                throw new RuntimeException('العميل غير موجود');
            }
            // الآجل ليس «رصيد عميل جملة» غامضاً؛ يجب أن يحمل هويةً تصلح
            // لدفتر الديون الموحّد. الرقم لا يطلب من العميل تثبيت أميال باي
            // أو فتح محفظة، لكنه يمنع فاتورةً بلا مدينٍ قابلٍ للتحصيل.
            if ($paymentType === 'credit' && trim((string) $customer->phone) === '') {
                throw new InvalidArgumentException(
                    'بيع الجملة الآجل يحتاج رقم هاتف العميل لربط الدين؛ لا يلزم تفعيل تطبيق أميال باي'
                );
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
                $qty = MoneyService::normalize((string) ($item['quantity'] ?? '0'));
                if (MoneyService::compare($qty, '0') <= 0) {
                    throw new InvalidArgumentException("كمية {$product->name} غير صحيحة");
                }
                $unit = $this->resolveUnit($product, $item['unit_id'] ?? null);
                $baseQty = MoneyService::mul($qty, (string) $unit->factor_to_base);
                if (MoneyService::compare((string) $product->current_stock, $baseQty) < 0) {
                    throw new RuntimeException(
                        "المخزون غير كاف لـ {$product->name} (المتاح: {$product->current_stock})"
                    );
                }

                // شرائح السعر ومخزون الجملة يُقاسان بوحدة الأساس دائماً؛
                // سعر الكرتون هو سعر القطعة الخادمي × عامل التحويل، لا رقم
                // يرسله التطبيق ولا شريحة منفصلة يمكن أن تتناقض مع المخزون.
                $baseUnitPrice = MoneyService::normalize((string) $product->priceFor($tierId, (float) $baseQty));
                $unitPrice = MoneyService::mul($baseUnitPrice, (string) $unit->factor_to_base);
                $discountPerUnit = MoneyService::normalize((string) ($item['discount_per_unit'] ?? '0'));
                if (MoneyService::compare($discountPerUnit, $unitPrice) > 0) {
                    throw new InvalidArgumentException("خصم {$product->name} أكبر من سعر الوحدة");
                }
                $effectivePrice = MoneyService::sub($unitPrice, $discountPerUnit);
                $lineTotal = MoneyService::mul($effectivePrice, $qty);

                $resolvedItems[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'unit' => $unit,
                    'unit_price' => $unitPrice,
                    'discount_per_unit' => $discountPerUnit,
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

            // أميال باي ليس خياراً شكلياً. مرجع الدفع يجب أن يكون لطلب QR
            // مكتمل، أنشئ باسم مالك التاجر (لا موظف نقطة البيع)، وبالمبلغ
            // نفسه. وبذلك لا يستطيع عميل إرسال رقم حركة أجنبي أو إعادة
            // استخدام حركة من فاتورة أخرى.
            if ($paymentType === 'amial_pay') {
                $this->paymentReference->assertPaidForMerchant(
                    $merchant, $paidTransactionId, $totalAmount,
                );
            }

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
                'created_by_user_id' => $data['created_by_user_id'] ?? $merchant->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'paid_amount' => in_array($paymentType, ['cash', 'amial_pay'], true) ? $totalAmount : '0',
                'balance_due' => in_array($paymentType, ['cash', 'amial_pay'], true) ? '0' : $totalAmount,
                'status' => in_array($paymentType, ['cash', 'amial_pay'], true) ? 'paid' : 'issued',
                'payment_type' => $paymentType,
                'paid_transaction_id' => $paymentType === 'amial_pay' ? $paidTransactionId : null,
                'sales_rep_commission_rate' => $commissionRate,
                'sales_rep_commission_amount' => $commissionAmount,
                'notes' => $data['notes'] ?? null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            // 7) أنشئ العناصر + اخصم من المخزون
            foreach ($resolvedItems as $r) {
                $product = $r['product'];
                $invoiceItem = WholesaleInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'product_unit_id' => $r['unit']->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'unit' => $r['unit']->name,
                    'quantity' => $r['quantity'],
                    'unit_factor' => $r['unit']->factor_to_base,
                    'base_quantity' => $r['base_quantity'],
                    'unit_price' => $r['unit_price'],
                    'discount_per_unit' => $r['discount_per_unit'],
                    'line_total' => $r['line_total'],
                    'tier_id' => $tierId,
                ]);

                // إن بدأ التاجر تتبُّع الدُفعات، لا نبيع من مخزونٍ مجهول
                // الصلاحية. تخصيص FIFO يحدث تحت القفل نفسه مع الخصم.
                $this->allocateLotsIfTracked($product, $invoiceItem, $r['base_quantity']);

                // اخصم من مخزون الأساس، لا من عدد الكراتين/الشدات المعروض.
                $newStock = MoneyService::sub((string)$product->current_stock, $r['base_quantity']);
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

                // صار الرقم إلزامياً للآجل أعلاه، لذلك لا يبقى دين الجملة
                // في دفتر موازٍ. يُنشر دائماً في الحساب الموحّد، ويرتبط
                // بحساب التطبيق فقط إن كان الرقم مستخدماً مسجّلاً.
                $credit = app(CustomerCreditService::class);
                $account = $credit->findOrCreateAccount(
                    $merchant->id,
                    (string) $customer->phone,
                    (string) $customer->full_name,
                    (string) $customer->credit_limit,
                );
                $credit->recordSale(
                    account: $account,
                    amount: $totalAmount,
                    dueDate: $dueDate,
                    note: 'فاتورة جملة آجل',
                    createdBy: $data['created_by_user_id'] ?? $merchant->id,
                    referenceType: 'wholesale_invoice',
                    referenceId: $invoice->invoice_ulid,
                    referenceNumber: $invoice->invoice_number,
                );
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

            // إيصال الحركة الموجود مسبقاً هو وثيقة دفع أميال باي؛ نربطه
            // بالفاتورة بعد نجاح إنشاء السجل فقط، من دون إنشاء قيد أو رصيد
            // جديد. النقد والآجل لديهما الفاتورة نفسها لكن لا حركة محفظة.
            if ($paymentType === 'amial_pay') {
                app(ReceiptService::class)->attachBusinessReference(
                    $paidTransactionId,
                    'wholesale_invoice',
                    (int) $invoice->id,
                    [
                        'merchant_vertical' => 'wholesale',
                        'invoice_number' => $invoice->invoice_number,
                        'payment_channel' => 'amial_pay',
                    ],
                );
            }

            return $invoice->fresh(['items', 'customer', 'salesRep']);
        });
    }

    /** يعيد وحدة الأساس تلقائياً للمنتجات القديمة ثم يقفل وحدة البيع المطلوبة. */
    private function resolveUnit(WholesaleProduct $product, mixed $unitId): WholesaleProductUnit
    {
        $base = WholesaleProductUnit::where('product_id', $product->id)->where('is_base', true)->first();
        if ($base === null) {
            $base = WholesaleProductUnit::firstOrCreate(
                ['product_id' => $product->id, 'code' => 'base'],
                ['name' => $product->unit ?: 'قطعة', 'factor_to_base' => '1', 'is_base' => true, 'is_active' => true],
            );
        }
        $query = WholesaleProductUnit::where('product_id', $product->id)->where('is_active', true)->lockForUpdate();
        $unit = $unitId ? $query->where('id', (int) $unitId)->first() : $query->where('is_base', true)->first();
        if ($unit === null) throw new InvalidArgumentException("وحدة بيع {$product->name} غير صالحة");
        return $unit;
    }

    /**
     * لا نفرض الدُفعات على منتجات قديمة لم تبدأ تتبُّعها بعد. لكن عند وجود
     * أي دفعة لا يسمح بالخروج من رصيدٍ لا يعرف صلاحيته أو حالته.
     */
    private function allocateLotsIfTracked(WholesaleProduct $product, WholesaleInvoiceItem $item, string $baseQty): void
    {
        $hasLots = WholesaleProductLot::where('product_id', $product->id)->exists();
        if (! $hasLots) return;

        $remaining = $baseQty;
        $lots = WholesaleProductLot::where('product_id', $product->id)
            ->where('status', 'active')->where('quantity_available', '>', 0)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>', now()->toDateString()))
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('received_at')
            ->lockForUpdate()->get();
        foreach ($lots as $lot) {
            if (MoneyService::compare($remaining, '0') <= 0) break;
            $take = MoneyService::compare((string) $lot->quantity_available, $remaining) >= 0
                ? $remaining : (string) $lot->quantity_available;
            WholesaleInvoiceItemLot::create([
                'invoice_item_id' => $item->id, 'lot_id' => $lot->id, 'base_quantity' => $take,
            ]);
            $lot->update(['quantity_available' => MoneyService::sub((string) $lot->quantity_available, $take)]);
            $remaining = MoneyService::sub($remaining, $take);
        }
        if (MoneyService::compare($remaining, '0') > 0) {
            throw new RuntimeException("لا توجد دفعات صالحة كافية لـ {$product->name}");
        }
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
                                (string)$product->current_stock,
                                (string) ($item->base_quantity ?: $item->quantity),
                            ),
                        ]);
                    }
                }
                foreach ($item->lotAllocations as $allocation) {
                    $lot = WholesaleProductLot::lockForUpdate()->find($allocation->lot_id);
                    if ($lot) {
                        $lot->update(['quantity_available' => MoneyService::add(
                            (string) $lot->quantity_available, (string) $allocation->base_quantity,
                        )]);
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

                    // دفتر الديون append-only: لا نحذف بيع الآجل الأصلي،
                    // بل ننشر مرتجعاً واضحاً عند إبطال فاتورة لم يُحصّل
                    // منها شيء. الفاتورة الجديدة تنشئ الحساب دائماً؛ أما
                    // فاتورة قديمة لم تكن موصولة بالدفتر فلا ننشئ لها حساباً
                    // سالباً عند الإبطال.
                    $account = $this->findUnifiedCreditAccount(
                        (int) WholesaleBusiness::whereKey($invoice->business_id)->value('merchant_user_id'),
                        (string) $customer->phone,
                    );
                    if ($account) {
                        app(CustomerCreditService::class)->recordReturn(
                            account: $account,
                            amount: (string) $invoice->total_amount,
                            note: 'إبطال فاتورة جملة: ' . $reason,
                            referenceType: 'wholesale_invoice_void',
                            referenceId: $invoice->invoice_ulid,
                            createdBy: $invoice->created_by_user_id,
                        );
                    }
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

    /**
     * لا ننشئ حساباً عند العكس: الحساب المفقود يعني فاتورة تاريخية سابقة
     * لتوحيد الديون، وإنشاؤه ثم تسجيل مرتجع سيصنع رصيداً سالباً مصطنعاً.
     */
    private function findUnifiedCreditAccount(int $merchantId, string $phone): ?CustomerCreditAccount
    {
        if ($merchantId <= 0 || trim($phone) === '') {
            return null;
        }

        return CustomerCreditAccount::where('merchant_user_id', $merchantId)
            ->whereIn('customer_phone', \App\Support\Phone::variants($phone))
            ->first();
    }
}
