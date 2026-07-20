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
        ?int $corporateAccountId = null,
        ?int $corporateMemberId = null,
        ?string $discountAmount = null,
        ?int $promotionId = null,
        ?string $cashAmount = null,
        ?string $walletAmount = null,
        ?string $clientUuid = null,
    ): MerchantSale {
        // AMIAL-OFFLINE-POS-001: idempotency — بيع دون اتصال يُعاد إرساله بنفس
        // client_uuid عند المزامنة؛ إن كان مُسجَّلاً سابقاً نُعيده كما هو دون
        // تكرار للحركة (لا مخزون/ولاء/مال مزدوج).
        if (!empty($clientUuid)) {
            $existing = MerchantSale::where('merchant_user_id', $merchant->id)
                ->where('client_uuid', $clientUuid)->first();
            if ($existing) return $existing;
        }

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

        // AMIAL-MIXED-PAYMENT-001: مختلط = جزء نقد + جزء محفظة. الجزء المحفظي يُحصَّل
        // عبر QR (paid_transaction_id) والجزء النقدي يدوي. يجب أن يساوي مجموعهما الإجمالي.
        if ($paymentMethod === 'mixed') {
            $cash = MoneyService::normalize($cashAmount ?? '0');
            $wallet = MoneyService::normalize($walletAmount ?? '0');
            if (MoneyService::normalize(MoneyService::add($cash, $wallet)) !== $total) {
                throw new InvalidArgumentException('مجموع النقد والمحفظة يجب أن يساوي الإجمالي');
            }
            if (MoneyService::isPositive($wallet) && empty($paidTransactionId)) {
                throw new InvalidArgumentException('الجزء المحفظي يحتاج تحصيلاً فعلياً عبر QR');
            }
            $cashAmount = $cash;
            $walletAmount = $wallet;
            $status = 'completed';
        }

        // AMIAL-CORPORATE-ACCOUNTS-001: بيع على حساب شركة — يلزم حساب مملوك للتاجر.
        $corporateAccount = null;
        if ($paymentMethod === 'corporate') {
            $corporateAccount = \App\Models\CorporateAccount::where('id', (int) $corporateAccountId)
                ->where('merchant_user_id', $merchant->id)->first();
            if (!$corporateAccount) {
                throw new InvalidArgumentException('حساب الشركة غير موجود');
            }
            // اسم الشركة يظهر على الفاتورة
            $customer = ['name' => $corporateAccount->company_name, 'phone' => $corporateAccount->account_code];
        }

        $discountAmount = $discountAmount !== null ? MoneyService::normalize($discountAmount) : '0';

        return DB::transaction(function () use ($merchant, $total, $paymentMethod, $status, $items, $posUserId, $customer, $paidTransactionId, $creditDueDate, $corporateAccount, $corporateMemberId, $discountAmount, $promotionId, $cashAmount, $walletAmount, $clientUuid) {
            $sale = MerchantSale::create([
                'sale_ulid' => (string) Str::ulid(),
                'client_uuid' => $clientUuid ?: null,
                'merchant_user_id' => $merchant->id,
                'pos_user_id' => $posUserId,
                'total_amount' => $total,
                'discount_amount' => $discountAmount,
                'promotion_id' => $promotionId,
                'cash_amount' => $paymentMethod === 'mixed' ? $cashAmount : null,
                'wallet_amount' => $paymentMethod === 'mixed' ? $walletAmount : null,
                'payment_method' => $paymentMethod,
                'status' => $status,
                'items' => $items,
                'customer_name' => $customer['name'] ?? null,
                'customer_phone' => $customer['phone'] ?? null,
                'paid_transaction_id' => $paidTransactionId,
                'settled_at' => (($paymentMethod === 'amial_pay' && !empty($paidTransactionId)) || $paymentMethod === 'mixed') ? now() : null,
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

            // AMIAL-CORPORATE-ACCOUNTS-001 — تحميل البيع على حساب الشركة (يفرض الحدّ)
            if ($paymentMethod === 'corporate' && $corporateAccount) {
                $member = $corporateMemberId
                    ? \App\Models\CorporateAccountMember::where('id', $corporateMemberId)
                        ->where('corporate_account_id', $corporateAccount->id)->first()
                    : null;
                app(\App\Services\CorporateAccountService::class)->recordCharge(
                    account: $corporateAccount,
                    amount: $total,
                    member: $member,
                    createdBy: $posUserId ?? $merchant->id,
                    note: 'بيع من الكاشير',
                    referenceType: 'merchant_sale',
                    referenceId: $sale->sale_ulid,
                    referenceNumber: '#' . substr($sale->sale_ulid, -8),
                );
            }

            // AMIAL-PROMOTIONS-001 — استهلاك استخدام العرض (يحترم الحدّ) عند البيع.
            if ($promotionId) {
                try {
                    app(\App\Services\PromotionService::class)->consume($merchant, $promotionId);
                } catch (\Throwable $e) {
                    logger()->warning('Promotion consume failed: ' . $e->getMessage());
                }
            }

            // AMIAL-LOYALTY-001 — كسب نقاط الولاء مركزياً لكل بيع مُتمّ بعميل معروف.
            // آمنة تماماً: تتجاهل بصمت إن لا برنامج مُفعّل/لا هاتف (لا تُعطّل البيع).
            if ($status === 'completed' && !empty($customer['phone'])) {
                try {
                    app(\App\Services\LoyaltyService::class)->earnForSale(
                        $merchant, $customer['phone'], $customer['name'] ?? null, $total, $sale->sale_ulid,
                    );
                } catch (\Throwable $e) {
                    logger()->warning('Loyalty earn failed: ' . $e->getMessage());
                }
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

    // ============ تقرير الربحية (AMIAL-PROFIT-001) ============

    /**
     * تقرير ربحية لمدى أيام: الإجماليات (إيراد/تكلفة/ربح/هامش) +
     * اتجاه يومي + تفصيل حسب المنتج (التكلفة من cost_price الحالي للمنتج).
     */
    public function profitReport(User $merchant, int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $from = now()->subDays($days - 1)->startOfDay();

        $sales = MerchantSale::where('merchant_user_id', $merchant->id)
            ->whereIn('status', ['completed', 'credit_unpaid', 'credit_paid'])
            ->where('created_at', '>=', $from)
            ->get(['id', 'total_amount', 'items', 'created_at']);

        // خريطة تكلفة المنتجات الحالية
        $costs = MerchantProduct::where('merchant_user_id', $merchant->id)
            ->pluck('cost_price', 'id');
        $names = MerchantProduct::where('merchant_user_id', $merchant->id)
            ->pluck('name', 'id');

        $totalRevenue = '0';
        $totalCost = '0';
        $daily = [];   // date => [revenue, cost]
        $byProduct = []; // pid => [qty, revenue, cost, name]

        foreach ($sales as $sale) {
            $rev = (string) $sale->total_amount;
            $totalRevenue = bcadd($totalRevenue, $rev, 4);
            $day = $sale->created_at->format('Y-m-d');
            $daily[$day]['revenue'] = bcadd($daily[$day]['revenue'] ?? '0', $rev, 4);

            foreach ((array) $sale->items as $item) {
                $pid = $item['product_id'] ?? null;
                $qty = (string) ($item['quantity'] ?? $item['qty'] ?? 1);
                $price = (string) ($item['price'] ?? 0);
                $lineRev = bcmul($qty, $price, 4);
                $cost = $pid !== null ? (string) ($costs[$pid] ?? '0') : '0';
                $lineCost = bcmul($qty, $cost, 4);

                $totalCost = bcadd($totalCost, $lineCost, 4);
                $daily[$day]['cost'] = bcadd($daily[$day]['cost'] ?? '0', $lineCost, 4);

                $key = $pid !== null ? (string) $pid : ('~' . ($item['name'] ?? '?'));
                $byProduct[$key]['name'] = $pid !== null
                    ? ($names[$pid] ?? ($item['name'] ?? '؟'))
                    : ($item['name'] ?? '؟');
                $byProduct[$key]['qty'] = bcadd($byProduct[$key]['qty'] ?? '0', $qty, 3);
                $byProduct[$key]['revenue'] = bcadd($byProduct[$key]['revenue'] ?? '0', $lineRev, 4);
                $byProduct[$key]['cost'] = bcadd($byProduct[$key]['cost'] ?? '0', $lineCost, 4);
            }
        }

        $profit = bcsub($totalRevenue, $totalCost, 4);
        $margin = bccomp($totalRevenue, '0', 4) > 0
            ? bcmul(bcdiv($profit, $totalRevenue, 6), '100', 2)
            : '0';

        // سلسلة يومية كاملة (تشمل أيام الصفر) للأشرطة
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $rev = $daily[$d]['revenue'] ?? '0';
            $cst = $daily[$d]['cost'] ?? '0';
            $series[] = [
                'date' => $d,
                'revenue' => $rev,
                'profit' => bcsub($rev, $cst, 4),
            ];
        }

        // أعلى المنتجات ربحاً
        $products = collect($byProduct)->map(function ($p) {
            $p['profit'] = bcsub($p['revenue'], $p['cost'], 4);
            return $p;
        })->sortByDesc(fn ($p) => (float) $p['profit'])->take(10)->values()->all();

        return [
            'range' => ['from' => $from->format('Y-m-d'), 'days' => $days],
            'totals' => [
                'revenue' => $totalRevenue,
                'cost' => $totalCost,
                'profit' => $profit,
                'margin_percent' => $margin,
                'sales_count' => $sales->count(),
            ],
            'daily' => $series,
            'products' => $products,
        ];
    }

    // ============ قائمة المبيعات ============

    /**
     * AMIAL-CASHIER-REFUND-001 — قائمة مبيعات يوم واحد بمعرّفاتها.
     * المدخل الطبيعي لشاشة الاسترجاع: كل صف يحمل sale_ulid يُفتح به
     * تدفّق «refundable → refund». يُرفَق بكل بيع إجمالي ما استُرجع منه.
     */
    public function listSales(User $merchant, ?string $date = null, int $limit = 100): array
    {
        $day = $date ? Carbon::parse($date) : now();

        $sales = MerchantSale::where('merchant_user_id', $merchant->id)
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 200)))
            ->get();

        $refunded = \App\Models\MerchantRefund::whereIn('original_sale_ulid', $sales->pluck('sale_ulid'))
            ->where('status', '!=', 'rejected')
            ->selectRaw('original_sale_ulid, SUM(refund_amount) as refunded_total')
            ->groupBy('original_sale_ulid')
            ->pluck('refunded_total', 'original_sale_ulid');

        return [
            'date' => $day->format('Y-m-d'),
            'sales' => $sales->map(function (MerchantSale $s) use ($refunded) {
                $refundedTotal = (string) ($refunded[$s->sale_ulid] ?? '0');
                return [
                    'sale_ulid' => $s->sale_ulid,
                    'id' => $s->id,
                    'total_amount' => MoneyService::normalize((string) $s->total_amount),
                    'payment_method' => $s->payment_method,
                    'status' => $s->status,
                    'items_count' => count($s->items ?? []),
                    'customer_name' => $s->customer_name,
                    'refunded_total' => MoneyService::normalize($refundedTotal),
                    'fully_refunded' => MoneyService::compare(
                        MoneyService::sub((string) $s->total_amount, $refundedTotal), '0'
                    ) <= 0,
                    'created_at' => $s->created_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
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

        // AMIAL-REPORTS-HOURLY-001: توزيع المبيعات على 24 ساعة (عدد + مبلغ).
        $byHour = [];
        for ($h = 0; $h < 24; $h++) {
            $byHour[$h] = ['count' => 0, 'total' => '0'];
        }

        foreach ($sales as $sale) {
            $byMethod[$sale->payment_method] = MoneyService::add(
                $byMethod[$sale->payment_method] ?? '0',
                (string) $sale->total_amount
            );
            $h = (int) Carbon::parse($sale->created_at)->format('G'); // 0..23 بتوقيت التطبيق
            $byHour[$h]['count']++;
            $byHour[$h]['total'] = MoneyService::add($byHour[$h]['total'], (string) $sale->total_amount);
            foreach (($sale->items ?? []) as $item) {
                $name = $item['name'] ?? null;
                if (!$name) continue;
                $qty = (int) ($item['qty'] ?? 1);
                $topProducts[$name] = ($topProducts[$name] ?? 0) + $qty;
            }
        }
        arsort($topProducts);

        // ساعة الذروة (الأعلى مبيعاً)
        $peakHour = null;
        $peakTotal = '0';
        foreach ($byHour as $h => $b) {
            if (MoneyService::gt($b['total'], $peakTotal)) {
                $peakTotal = $b['total'];
                $peakHour = $h;
            }
        }

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
            // AMIAL-REPORTS-HOURLY-001: تفصيل بالساعة + ساعة الذروة
            'by_hour' => array_map(
                fn ($h, $b) => ['hour' => $h, 'count' => $b['count'], 'total' => $b['total']],
                array_keys($byHour), $byHour
            ),
            'peak_hour' => $peakHour,
            'peak_hour_total' => $peakTotal,
        ];
    }
}
