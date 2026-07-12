<?php

namespace App\Services;

use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-CASHIER-001 — كاشير خفيف.
 *
 * لا منطق مالي جديد: النقد والأجل سجلّات فقط؛ "أميال باي" يُربط بعملية دفع
 * نفّذها العميل عبر مسار دفع التاجر القائم (التطبيق يمرّر paid_transaction_id).
 */
class CashierService
{
    // ============ المنتجات (اختيارية) ============

    public function addProduct(User $merchant, array $data): MerchantProduct
    {
        $created = MerchantProduct::create([
            'merchant_user_id' => $merchant->id,
            'name' => $data['name'],
            'price' => MoneyService::normalize((string)($data['price'] ?? 0)),           // سعر البيع
            'cost_price' => MoneyService::normalize((string)($data['cost_price'] ?? 0)),  // التكلفة
            'offer_price' => isset($data['offer_price']) && $data['offer_price'] !== null && $data['offer_price'] !== ''
                ? MoneyService::normalize((string)$data['offer_price']) : null,           // العرض
            'quantity' => (string)($data['quantity'] ?? 0),                               // المخزون
            'production_date' => $data['production_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'category' => $data['category'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'is_active' => true,
        ]);

        return $created->fresh();
    }

    public function updateProduct(User $merchant, int $productId, array $data): MerchantProduct
    {
        $product = MerchantProduct::where('id', $productId)
            ->where('merchant_user_id', $merchant->id)
            ->firstOrFail();

        $product->fill([
            'name' => $data['name'] ?? $product->name,
            'price' => isset($data['price']) ? MoneyService::normalize((string)$data['price']) : $product->price,
            'cost_price' => isset($data['cost_price']) ? MoneyService::normalize((string)$data['cost_price']) : $product->cost_price,
            'offer_price' => array_key_exists('offer_price', $data)
                ? ($data['offer_price'] !== null && $data['offer_price'] !== '' ? MoneyService::normalize((string)$data['offer_price']) : null)
                : $product->offer_price,
            'quantity' => $data['quantity'] ?? $product->quantity,
            'production_date' => $data['production_date'] ?? $product->production_date,
            'expiry_date' => $data['expiry_date'] ?? $product->expiry_date,
            'category' => $data['category'] ?? $product->category,
            'barcode' => $data['barcode'] ?? $product->barcode,
            'is_active' => $data['is_active'] ?? $product->is_active,
        ])->save();

        return $product;
    }

    public function listProducts(User $merchant, ?string $search = null)
    {
        return MerchantProduct::where('merchant_user_id', $merchant->id)
            ->where('is_active', true)
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")->orWhere('barcode', $search);
            }))
            ->orderBy('name')
            ->get();
    }

    /**
     * AMIAL-CASHIER-BARCODE-001 — بحث منتج الكاشير بالباركود (لمسح الكاشير).
     * يعيد المنتج الفعّال المطابق (id/الاسم/السعر/المخزون) أو null إن لم يوجد.
     */
    public function findByBarcode(User $merchant, string $barcode): ?MerchantProduct
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        return MerchantProduct::where('merchant_user_id', $merchant->id)
            ->where('is_active', true)
            ->where('barcode', $barcode)
            ->first();
    }

    // ============ البيع ============

    /**
     * تسجيل بيع. لا يحرّك مالاً.
     *  - cash   → completed
     *  - credit → credit_unpaid (يلزم اسم/رقم العميل) + يُسجَّل في حساب العميل الائتماني تلقائياً.
     *  - amial_pay → completed مع ربط paid_transaction_id (دفعها العميل مسبقاً)
     *
     * @param array $items عناصر حرّة [{name, qty, price}] — اختيارية (بيع بمبلغ حرّ مدعوم)
     * @param string|null $creditDueDate تاريخ استحقاق بيع الأجل (Y-m-d). اختياري.
     */
    public function recordSale(
        User $merchant,
        string $total,
        string $paymentMethod,
        array $items = [],
        ?int $posUserId = null,
        ?array $customer = null,
        ?string $paidTransactionId = null,
        ?string $creditDueDate = null,
    ): MerchantSale {
        if (!in_array($paymentMethod, MerchantSale::METHODS, true)) {
            throw new InvalidArgumentException('طريقة دفع غير صحيحة');
        }
        $total = MoneyService::normalize($total);
        if (!MoneyService::isPositive($total)) {
            throw new InvalidArgumentException('إجمالي البيع يجب أن يكون موجباً');
        }

        $status = 'completed';
        if ($paymentMethod === 'credit') {
            // بيع الأجل يلزم اسم ورقم العميل (للحساب الائتماني)
            if (empty($customer['name']) || empty($customer['phone'])) {
                throw new InvalidArgumentException('بيع الأجل يحتاج اسم ورقم العميل');
            }
            $status = 'credit_unpaid';
        }
        // أميال باي: إن لم يُمرَّر مرجع الدفع بعد → بيع معلّق يُربط لاحقاً (linkPayment).
        if ($paymentMethod === 'amial_pay') {
            $status = empty($paidTransactionId) ? 'pending_payment' : 'completed';
        }

        return DB::transaction(function () use ($merchant, $total, $paymentMethod, $status, $items, $posUserId, $customer, $paidTransactionId, $creditDueDate) {
            $sale = MerchantSale::create([
                'sale_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'pos_user_id' => $posUserId,
                'total_amount' => $total,
                'payment_method' => $paymentMethod,
                'status' => $status,
                'items' => $items,
                'customer_name' => $customer['name'] ?? null,
                'customer_phone' => $customer['phone'] ?? null,
                'paid_transaction_id' => $paidTransactionId,
                'settled_at' => ($paymentMethod === 'amial_pay' && !empty($paidTransactionId)) ? now() : null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            // خصم المخزون للعناصر المرتبطة بمنتج (product_id)، إن لم يكن بيعاً معلّقاً
            if ($status !== 'pending_payment') {
                $this->decrementStock($merchant->id, $items);
            }

            // AMIAL-CUSTOMER-CREDIT-001 — ربط بيع الأجل بحساب العميل الائتماني
            if ($paymentMethod === 'credit') {
                $credit = app(CustomerCreditService::class);
                $account = $credit->findOrCreateAccount(
                    $merchant->id,
                    $customer['phone'],
                    $customer['name'],
                );
                $credit->recordSale(
                    account: $account,
                    amount: $total,
                    dueDate: $creditDueDate,
                    note: 'بيع آجل من الكاشير',
                    createdBy: $posUserId ?? $merchant->id,
                    referenceType: 'merchant_sale',
                    referenceId: $sale->sale_ulid,
                    referenceNumber: '#' . substr($sale->sale_ulid, -8),
                );
            }

            return $sale;
        });
    }

    /** خصم المخزون للعناصر التي تحمل product_id (إن وُجدت). آمن: لا يخصم تحت الصفر. */
    private function decrementStock(int $merchantId, array $items): void
    {
        foreach ($items as $item) {
            $pid = $item['product_id'] ?? null;
            if (!$pid) continue;
            // AMIAL-FIX: التطبيق يرسل quantity — كان يُقرأ qty فقط فيخصم 1 دائماً
            $qty = (string)($item['quantity'] ?? $item['qty'] ?? 1);
            $product = MerchantProduct::where('id', $pid)
                ->where('merchant_user_id', $merchantId)
                ->lockForUpdate()
                ->first();
            if (!$product) continue;
            $newQty = bcsub((string)$product->quantity, $qty, 3);
            if (bccomp($newQty, '0', 3) < 0) {
                $newQty = '0'; // لا مخزون سالب
            }
            $product->update(['quantity' => $newQty]);
        }
    }

    /**
     * ربط بيع أميال باي المعلّق بعملية الدفع بعد نجاحها (يُستدعى من مسار الدفع).
     */
    public function linkPayment(string $saleUlid, string $txId, int $merchantId): ?MerchantSale
    {
        return DB::transaction(function () use ($saleUlid, $txId, $merchantId) {
            $sale = MerchantSale::where('sale_ulid', $saleUlid)
                ->where('merchant_user_id', $merchantId)
                ->where('status', 'pending_payment')
                ->lockForUpdate()
                ->first();
            if (!$sale) {
                return null; // لا بيع معلّق مطابق — تجاهل بأمان
            }
            $sale->update([
                'status' => 'completed',
                'paid_transaction_id' => $txId,
                'settled_at' => now(),
            ]);
            $this->decrementStock($merchantId, $sale->items ?? []);
            return $sale->fresh();
        });
    }

    /** تسوية بيع أجل (تحويله مدفوعاً). */
    public function settleCredit(User $merchant, int $saleId, ?string $paidTransactionId = null): MerchantSale
    {
        return DB::transaction(function () use ($merchant, $saleId, $paidTransactionId) {
            $sale = MerchantSale::where('id', $saleId)
                ->where('merchant_user_id', $merchant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->payment_method !== 'credit') {
                throw new RuntimeException('هذه ليست عملية أجل');
            }
            if ($sale->status === 'credit_paid') {
                throw new RuntimeException('تمت تسوية الأجل مسبقاً');
            }

            $sale->update([
                'status' => 'credit_paid',
                'paid_transaction_id' => $paidTransactionId,
                'settled_at' => now(),
            ]);

            return $sale->fresh();
        });
    }

    // ============ التقرير اليومي ============

    public function dailyReport(User $merchant, ?string $date = null): array
    {
        $day = $date ? Carbon::parse($date) : now();
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $sales = MerchantSale::where('merchant_user_id', $merchant->id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $byMethod = ['cash' => '0', 'credit' => '0', 'amial_pay' => '0'];
        $topProducts = [];

        foreach ($sales as $sale) {
            $byMethod[$sale->payment_method] = MoneyService::add(
                $byMethod[$sale->payment_method] ?? '0',
                (string) $sale->total_amount
            );
            foreach (($sale->items ?? []) as $item) {
                $name = $item['name'] ?? null;
                if (!$name) continue;
                $qty = (int) ($item['qty'] ?? 1);
                $topProducts[$name] = ($topProducts[$name] ?? 0) + $qty;
            }
        }
        arsort($topProducts);

        // إجمالي الإيرادات الفعلية (نقد + أميال باي) — الأجل غير المسوّى مستحقّات
        $realized = MoneyService::add($byMethod['cash'], $byMethod['amial_pay']);
        $outstandingCredit = (string) MerchantSale::where('merchant_user_id', $merchant->id)
            ->where('status', 'credit_unpaid')
            ->sum('total_amount');

        return [
            'date' => $day->format('Y-m-d'),
            'sales_count' => $sales->count(),
            'total_all' => (string) $sales->sum(fn ($s) => (float) $s->total_amount),
            'realized_revenue' => $realized, // نقد + رقمي
            'by_method' => $byMethod,
            'outstanding_credit_total' => $outstandingCredit, // كل الأجل غير المسوّى
            'top_products' => array_slice(
                array_map(fn ($k, $v) => ['name' => $k, 'qty' => $v], array_keys($topProducts), $topProducts),
                0, 5
            ),
        ];
    }
}
