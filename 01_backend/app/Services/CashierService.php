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
    public function __construct(
        private readonly MerchantPaymentReferenceService $paymentReference,
    ) {}

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

    /**
     * أصنافُ الكاشير.
     *
     * ══════════════════════════════════════════════════════════════════
     * AMIAL-VARIANT-PARENT-001 — **الأبُ لا يُباع، فلا يُعرَض.**
     *
     * **ما وصل صاحبَ المشروع:** يضيف متغيّراتٍ لمنتج، ثمّ يفتح الكاشيرَ
     * فإذا **«نفذ المخزون»** على كلّ شيء.
     *
     * وسببُه شقّان يظهران معاً:
     *
     * ① **الأبُ يبقى في القائمة.** `is_variant_parent` مضبوطٌ عليه و
     *    `track_stock=false` — أي أنّه **مِظلّةٌ لا صنفٌ يُباع**، كما يقول
     *    تعليقُ النموذج بنصّه: «الأبُ لا يُباع ولا يُخزَّن». ومع ذلك كان
     *    هذا الاستعلامُ يجلبه كسائر الأصناف.
     *
     * ② **والمتغيّراتُ تُولَّد بكمّيّة صفر** (وهو صحيح: المخزونُ يُدخَل
     *    لكلّ مقاسٍ على حدة). فالشاشةُ تعرض الأبَ ومعه تسعةَ متغيّراتٍ
     *    **كلُّها صفر** — فيقرؤها البائعُ «نفذ» ولا يستطيع بيعَ شيء.
     *
     * **ولا يمسكه شيء**: الصفوفُ سليمة، والاستعلامُ يعمل، والرقمُ يُقرأ.
     * لا يظهر إلّا لمن يبيع فعلاً.
     * ══════════════════════════════════════════════════════════════════
     */
    public function listProducts(
        User $merchant,
        ?string $search = null,
        // **وشاشةُ إدارة المنتجات ليست شبكةَ البيع.**
        //
        // استثناءُ المِظلّة صحيحٌ في الكاشير وخطأٌ في الكتالوج: لو غابت
        // عن الإدارة أيضاً **لم يعد للتاجر بابٌ إليها إطلاقاً** — لا
        // تعديلَ اسمٍ ولا وصولَ إلى «الأنواع» ليوزّع مخزونَها. فتُقاد
        // من الطلب، والافتراضيُّ سلوكُ الكاشير.
        bool $includeVariantParents = false,
    ) {
        return MerchantProduct::where('merchant_user_id', $merchant->id)
            ->where('is_active', true)
            // **مِظلّةُ المتغيّرات تُستثنى** — ومخزونُها ليس مخزوناً يُباع.
            ->when(! $includeVariantParents,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('is_variant_parent')
                    ->orWhere('is_variant_parent', false)))
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")->orWhere('barcode', $search);
            }))
            ->orderBy('name')
            ->get()
            // **والاسمُ المعروضُ يميّز المتغيّر** — ولولاه ظهرت تسعةُ صفوفٍ
            // باسم «قميص» ولا يُعرف أيُّها الذي في اليد.
            ->each(function (MerchantProduct $p) {
                $p->setAttribute('display_name', $p->displayName());
                // **وتُقال صفةُ المِظلّة للشاشة** — فزرُّ «الأنواع» لا
                // يُعرَض إلّا عليها، وشاشةٌ فارغةٌ على صنفٍ عاديٍّ تُقرأ عطلاً.
                $p->setAttribute('is_variant_parent', (bool) $p->is_variant_parent);
            });
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
        // AMIAL-MULTI-CURRENCY-003 — **افتراضُه الأساس، فكلُّ نداءٍ قائمٍ
        // يعني الريالَ حرفاً بحرف.**
        ?string $currency = null,
        // ══════════════════════════════════════════════════════════════
        // AMIAL-LOYALTY-AT-PAYMENT-001 — **النقاطُ تُصرَف داخل البيعة.**
        //
        // وكان الاستبدالُ يقع في شاشةٍ أخرى ثمّ يُقال للكاشير «طبّقه على
        // الفاتورة»: نقاطٌ تُحرَق ثمّ خصمٌ يُطبَّق **إن تذكّر**. وإن
        // نسِي أو أُلغيت البيعة ذهبت النقاطُ ودفع العميلُ كاملاً.
        //
        // فصارت هنا: تُصرَف في معاملة البيعة نفسِها — تسقط البيعةُ فتعود
        // النقاط، وتُحفظ فتُوسَم حركتُها بمعرّفها.
        // ══════════════════════════════════════════════════════════════
        ?float $redeemPoints = null,
        // ══════════════════════════════════════════════════════════════
        // AMIAL-CASH-TENDERED-001 — **ما استلمه الكاشيرُ نقداً.**
        //
        // والباقي يُحسب منه ومن الإجماليّ، **ولا يُخزَّن** — عمودٌ ثالثٌ
        // يمكن أن يناقض الاثنين (القاعدة السادسة). و`null` تعني «لم
        // يُدخَل» لا صفراً: الآجلُ والمحفظةُ لا مستلَمَ فيهما أصلاً.
        // ══════════════════════════════════════════════════════════════
        ?string $amountReceived = null,
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

        // ══════════════════════════════════════════════════════════════
        // AMIAL-MULTI-CURRENCY-003 — **عملةُ البيعة وسعرُها المجمَّد.**
        //
        // **وثلاثةُ شروطٍ قبل قبول عملةٍ غيرِ الأساس، وكلٌّ منها يمنع
        // مالاً خارج الرقابة:**
        //
        // ① **أن تكون مدعومةً** — رمزٌ مخترَعٌ يُنشئ بيعاتٍ لا يجدها تقرير.
        // ② **أن يكون التاجرُ قد فعّل قبضَها** — وإلّا سجّل موظّفٌ بيعةً
        //    بعملةٍ لم يقرّرها صاحبُ المتجر.
        // ③ **أن يكون لها سعرٌ مضبوط** — فبيعةٌ بلا مكافئٍ لا يُحسَب عليها
        //    حدُّ استلامٍ ولا تدخل تقريراً. (القاعدة السابعة: لا يُفترَض
        //    السعرُ واحداً — فمئةُ دولارٍ تصير مئةَ ريال.)
        // ══════════════════════════════════════════════════════════════
        $currency = \App\Support\Money\Currencies::normalize(
            $currency ?: \App\Support\Money\Currencies::BASE
        );
        $fxRate = '1';

        if (!\App\Support\Money\Currencies::isBase($currency)) {
            $accepted = \App\Models\MerchantCurrency::where('merchant_user_id', $merchant->id)
                ->where('code', $currency)->where('accepts_payments', true)->exists();

            if (!$accepted) {
                throw new InvalidArgumentException(sprintf(
                    'المتجر لا يقبل الدفع بـ%s — تُفعَّل من شاشة «محافظي».',
                    \App\Support\Money\Currencies::nameAr($currency)
                ));
            }

            // يرمي `RuntimeException` برسالةٍ تقول أنّ السعرَ غيرُ مضبوط.
            $fxRate = app(\App\Services\FxRateService::class)->rateToBase($currency);
        }

        // **المكافئُ يُحسب مرّةً هنا ويُحفَظ** — لا يُعاد حسابُه عند القراءة
        // بسعرِ ذلك اليوم، فتقريرُ الشهر الماضي لا يتغيّر كلَّ صباح.
        $baseTotal = bcmul($total, $fxRate, 4);

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

        // ── AMIAL-RETAIL-VERTICAL-001 · المرحلة ٨ — **سقفُ الخصم** ─────
        //
        // كان `discount_amount` يُقبل كما يأتي: كاشيرٌ يخصم نصفَ الفاتورة
        // لمن يعرفه، **ولا شيءَ يمنعه ولا أثرَ يدلّ عليه**.
        //
        // والمحرّكُ جاهزٌ منذ جولة الوقود: `max_amount` في منحة الدور.
        // فلا يُبنى فحصٌ جديد — يُنادى القائم.
        //
        // **والسقفُ مبلغٌ لا نسبة**: النسبةُ تُقاس على إجماليٍّ يكتبه
        // الكاشيرُ نفسُه، فيرفعه ثمّ يخصم منه «٥٪» فيخرج بأكثرَ ممّا نوى
        // المالكُ السماحَ به.
        if (MoneyService::isPositive($discountAmount)) {
            $this->assertDiscountAllowed($merchant, $posUserId, $discountAmount);
        }

        // **ونقاطٌ بلا عميلٍ لا تُصرَف**: الرصيدُ محفوظٌ على هاتفه، فبلا
        // هاتفٍ لا يُعرف من يدفع من رصيده.
        if ($redeemPoints !== null && $redeemPoints > 0 && empty($customer['phone'])) {
            throw new InvalidArgumentException(
                'استبدالُ النقاط يحتاج رقمَ العميل — فرصيدُ النقاط محفوظٌ على رقمه');
        }

        return DB::transaction(function () use ($merchant, $total, $paymentMethod, $status, $items, $posUserId, $customer, $paidTransactionId, $creditDueDate, $corporateAccount, $corporateMemberId, $discountAmount, $promotionId, $cashAmount, $walletAmount, $clientUuid, $currency, $fxRate, $baseTotal, $redeemPoints, $amountReceived) {
            // لا يكفي `paid_transaction_id` القادم من Flutter. يجب أن يكون
            // طلب QR مدفوعاً لمحفظة المنشأة وبالمبلغ نفسه وغير مستهلك.
            // البيع بلا مرجع يبقى معلّقاً إلى أن تُتم شاشة QR الدفع؛ أمّا إذا
            // زوّد التطبيق مرجعاً فلا يجوز اعتباره دليلاً بنفسه.
            if ($paymentMethod === 'amial_pay' && !empty($paidTransactionId)) {
                $this->paymentReference->assertPaidForMerchant(
                    $merchant, $paidTransactionId, $total,
                );
            }
            if ($paymentMethod === 'mixed' && MoneyService::isPositive((string) $walletAmount)) {
                $this->paymentReference->assertPaidForMerchant(
                    $merchant, $paidTransactionId, (string) $walletAmount,
                );
            }

            $sale = MerchantSale::create([
                'sale_ulid' => (string) Str::ulid(),
                'client_uuid' => $clientUuid ?: null,
                'merchant_user_id' => $merchant->id,
                'pos_user_id' => $posUserId,
                'total_amount' => $total,
                // AMIAL-MULTI-CURRENCY-003 — العملةُ والسعرُ المجمَّد والمكافئ.
                'currency' => $currency,
                'fx_rate_to_base' => $fxRate,
                'base_amount' => $baseTotal,
                'discount_amount' => $discountAmount,
                'amount_received' => $amountReceived,
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

            // AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — **الأسطرُ تُكتب جدولاً
            // والتكلفةُ تُلتقَط الآن**، قبل أن يتغيّر سعرُ الشراء غداً.
            //
            // وتُكتب للبيع المعلّق أيضاً: البيعةُ سُجّلت وسطورُها جزءٌ منها؛
            // والمؤجَّلُ هو **خصمُ المخزون** وحدَه.
            app(\App\Services\Retail\SaleLineService::class)->writeLines($sale, $items);

            $fresh = $sale->fresh();

            if ($status !== 'pending_payment') {
                // خصم المخزون للعناصر المرتبطة بمنتج (product_id)
                $this->decrementStockForSale($fresh);
            } else {
                // AMIAL-RETAIL-VERTICAL-001 · المرحلة ٩ — **حجزٌ لا خصم**.
                //
                // الدفعُ بأميال باي غيرُ متزامن. وكان المخزونُ إمّا يُنقص
                // فوراً — **فينقص لبيعةٍ لم تقع** — وإمّا لا يُمسّ فتُباع
                // آخرُ حبّةٍ لزبونين معاً. والحجزُ يُبقيها موجودةً وغيرَ
                // متاحة، حتّى ينجح الدفعُ أو تنتهي المهلة.
                app(\App\Services\Retail\StockReservationService::class)
                    ->holdForSale($fresh);
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

            // ══════════════════════════════════════════════════════════
            // AMIAL-LOYALTY-AT-PAYMENT-001 — **الاستبدالُ داخل المعاملة.**
            //
            // **ولا يُبتلَع خطؤه** — بخلاف الكسب أسفلَه. والفرقُ ليس
            // اجتهاداً: الكسبُ **يُعطي**، فسقوطُه يُنقص العميلَ نقاطاً
            // ولا يمسّ مالاً. والاستبدالُ **يأخذ من رصيده ويُنقص ما
            // يدفع**؛ فابتلاعُ خطئه يُنتج أسوأَ حالتين:
            //
            //   · إن مرّ الخصمُ ولم تُنقَص النقاط ⇒ المتجرُ خسِر خصماً
            //     بلا مقابل، مرّةً بعد مرّة.
            //   · وإن نُقصت النقاطُ وسقطت البيعة ⇒ العميلُ خسِر رصيدَه.
            //
            // فالرميُ هنا يُسقط البيعةَ كلَّها، **فتعود النقاطُ ولا
            // يُقبَض المال** — وبيعةٌ تُعاد أهونُ من رصيدٍ يضيع.
            // ══════════════════════════════════════════════════════════
            if ($redeemPoints !== null && $redeemPoints > 0) {
                $redeemed = app(\App\Services\LoyaltyService::class)->redeem(
                    $merchant, (string) $customer['phone'], $redeemPoints,
                    $posUserId ?? $merchant->id, $sale->sale_ulid,
                );

                $sale->loyalty_points_redeemed = $redeemPoints;
                $sale->loyalty_discount = $redeemed['discount'];
                $sale->save();
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

    /**
     * خصمُ المخزون — **بحركةٍ في موقعٍ، لا بكتابةٍ على عمود**.
     *
     * ══════════════════════════════════════════════════════════════════
     * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٠.
     *
     * **ما كان:** `$product->update(['quantity' => $newQty])`.
     *
     *   ① **بلا موقع** — متجرٌ بثلاثة فروعٍ له رقمٌ واحد، فبيعٌ في عدن
     *     ينقص مخزونَ المكلا.
     *   ② **بلا حركة** — لا يُعرف من أين نقص، والجردُ يُخرج فرقاً بلا أثر.
     *   ③ **والأخطر: كان يقصّ السالبَ إلى صفرٍ صامتاً.**
     *
     * والثالثةُ محوُ دليل: من باع خمساً وفي النظام ثلاث، سُجّلت بيعةُ
     * خمسٍ وصار المخزونُ صفراً — **والحبّتان الوهميّتان تختفيان بلا أثر**.
     * فيُقفَل الفرقُ قبل أن يُرى، ولا يعرف صاحبُ المتجر أنّ بياناته
     * انحرفت أصلاً.
     *
     * **فصار السالبُ يُسجَّل ولا يُقَصّ.** والبيعةُ تمرّ — البضاعةُ خرجت
     * من الرفّ فعلاً، ورفضُها بعد خروجها لا يُفيد أحداً — **لكنّ الرصيد
     * السالب يبقى ظاهراً** حتّى يُصلحه جردٌ، وهو الإشارةُ التي كانت تُمحى.
     */
    private function decrementStockForSale(MerchantSale $sale, ?int $locationId = null): void
    {
        $stock = app(\App\Services\Retail\StockService::class);
        $location = $locationId
            ? \App\Models\Retail\MerchantLocation::find($locationId)
            : null;
        $location ??= $stock->defaultLocation($sale->merchant_user_id);

        // AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — **يُقرأ السطرُ لا الـJSON**.
        // فالكمّيّةُ طُبّعت مرّةً عند الكتابة، ولا تُقرأ هنا بمفتاحين.
        foreach ($sale->lines()->whereNotNull('product_id')->get() as $line) {
            $qty = (string) $line->quantity;
            if (bccomp($qty, '0', 3) <= 0) continue;

            $product = MerchantProduct::where('id', $line->product_id)
                ->where('merchant_user_id', $sale->merchant_user_id)
                ->first();
            if (!$product) continue;

            $stock->move(
                product: $product,
                location: $location,
                delta: '-' . $qty,
                reason: 'sale',
                // **التكلفةُ الملتقَطة في السطر** — لا القراءةُ الحاليّة.
                unitCost: $line->unit_cost !== null ? (string) $line->unit_cost : null,
                sourceType: 'merchant_sale',
                sourceId: $sale->id,
                // **يُسمح بالسالب عمداً** — انظر شرح الدالّة أعلاه.
                allowNegative: true,
            );
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
            // AMIAL-RETAIL-VERTICAL-001 · المرحلة ٩ — **الحجزُ يصير بيعاً**.
            //
            // ولا يُنادى `decrementStockForSale` هنا: البضاعةُ محجوزةٌ
            // بالفعل، وخصمُها مرّةً أخرى يخصم ضعفَ ما بيع.
            $reservations = app(\App\Services\Retail\StockReservationService::class);
            $consumed = $reservations->consumeForSale($sale);

            // **ولا حجزَ = بيعةٌ قديمةٌ سبقت المرحلة ٩** — تُخصم كما كانت،
            // فلا يمرّ دفعٌ بلا خصمِ مخزون.
            if ($consumed === 0) {
                $this->decrementStockForSale($sale);
            }

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

            // التسوية من لوحة التاجر ليست حالةً محليةً للبيع فقط؛ يجب أن
            // تنشر قيد السداد في الدفتر الذي يراه العميل أيضاً.
            if (!empty($sale->customer_phone)) {
                $account = \App\Models\CustomerCreditAccount::where('merchant_user_id', $merchant->id)
                    ->whereIn('customer_phone', \App\Support\Phone::variants((string) $sale->customer_phone))
                    ->lockForUpdate()
                    ->first();
                if ($account) {
                    app(CustomerCreditService::class)->recordPayment(
                        account: $account,
                        amount: (string) $sale->total_amount,
                        note: 'تسوية بيع آجل من الكاشير',
                        createdBy: $merchant->id,
                        referenceType: 'merchant_sale_settlement',
                        referenceId: $sale->sale_ulid,
                    );
                }
            }

            return $sale->fresh();
        });
    }

    // ============ تقرير الربحية (AMIAL-PROFIT-001) ============

    /**
     * تقرير ربحية لمدى أيام: الإجماليات (إيراد/تكلفة/ربح/هامش) +
     * اتجاه يومي + تفصيل حسب المنتج.
     *
     * ══════════════════════════════════════════════════════════════════
     * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — **التكلفةُ من السطر لا من
     * المنتج**.
     *
     * وكان يُقرأ `cost_price` الحاليّ لكلّ منتج. فمن اشترى بـ٥٠٠ ثمّ
     * بـ٦٠٠ **يُعاد حسابُ ربح الشهر الماضي بالسعر الجديد**: التقريرُ نفسُه
     * يُطبع مرّتين بعددين، ولا خطأ في أيّ سجلّ.
     *
     * **وما لا تُعرف تكلفتُه لا يُحسب صفراً** (القاعدة ٧): يُعزَل في
     * `unknown_cost` ويُقال عددُه وإيرادُه، فيعرف القارئ أنّ الهامش
     * محسوبٌ على جزءٍ لا على الكلّ.
     */
    public function profitReport(User $merchant, int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $from = now()->subDays($days - 1)->startOfDay();

        $sales = MerchantSale::where('merchant_user_id', $merchant->id)
            ->whereIn('status', ['completed', 'credit_unpaid', 'credit_paid'])
            ->where('created_at', '>=', $from)
            ->with('lines')
            ->get(['id', 'total_amount', 'items', 'created_at']);

        $totalRevenue = '0';
        $totalCost = '0';
        $daily = [];   // date => [revenue, cost]
        $byProduct = []; // pid => [qty, revenue, cost, name]

        $unknownLines = 0;
        $unknownRevenue = '0';
        $estimatedLines = 0;

        foreach ($sales as $sale) {
            $rev = (string) $sale->total_amount;
            $totalRevenue = bcadd($totalRevenue, $rev, 4);
            $day = $sale->created_at->format('Y-m-d');
            $daily[$day]['revenue'] = bcadd($daily[$day]['revenue'] ?? '0', $rev, 4);

            foreach ($sale->lines as $line) {
                $pid = $line->product_id;
                $qty = (string) $line->quantity;
                $lineRev = (string) $line->line_total;

                if ($line->line_cost === null) {
                    $unknownLines++;
                    $unknownRevenue = bcadd($unknownRevenue, $lineRev, 4);
                    $lineCost = '0';
                } else {
                    $lineCost = (string) $line->line_cost;
                    $totalCost = bcadd($totalCost, $lineCost, 4);
                    $daily[$day]['cost'] = bcadd($daily[$day]['cost'] ?? '0', $lineCost, 4);
                    if ($line->cost_source === \App\Models\Retail\SaleLine::COST_ESTIMATED) {
                        $estimatedLines++;
                    }
                }

                $key = $pid !== null ? (string) $pid : ('~' . $line->name);
                $byProduct[$key]['name'] = $line->name;
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
            // **الشفافيّةُ جزءٌ من الرقم لا حاشيةٌ له.**
            'cost_coverage' => [
                'unknown_cost_lines' => $unknownLines,
                'unknown_cost_revenue' => $unknownRevenue,
                'estimated_cost_lines' => $estimatedLines,
                'note' => $unknownLines > 0
                    ? "الهامش محسوب على ما عُرفت تكلفته؛ {$unknownLines} سطراً بلا تكلفة مُدخَلة"
                    : null,
            ],
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
            ->withCount('lines')
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
                    'items_count' => (int) ($s->lines_count ?? 0),
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

    /**
     * سقفُ الخصم — **يُفحص على مُنفِّذ البيعة لا على صاحب المتجر**.
     *
     * فالمالكُ يخصم ما شاء (`isOwner` يمرّ بلا قيد)، والموظّفُ محدودٌ
     * بمنحة دوره. ومن لم يُسنَد إليه دورٌ بعد **لا يُمنع**: المتاجرُ
     * القائمةُ تعمل اليوم بلا أدوار، وقلبُ ذلك يُوقف بيعاً يعمل.
     */
    private function assertDiscountAllowed(User $merchant, ?int $posUserId, string $discount): void
    {
        $actor = $posUserId
            ? \App\Models\PosUser::where('id', $posUserId)->value('user_id')
            : null;
        $actorUser = $actor ? User::find($actor) : null;

        if (! $actorUser || (int) $actorUser->id === (int) $merchant->id) {
            return;   // البيعةُ من صاحب المتجر نفسِه
        }

        $perm = app(\App\Services\Merchant\MerchantPermissionService::class);

        // **بلا أدوارٍ مُسنَدة لا يُمنع** — انظر شرح الدالّة.
        if ($perm->effective($actorUser) === []) {
            return;
        }

        $perm->assert(
            $actorUser,
            \App\Support\Merchant\MerchantPermissions::RETAIL_DISCOUNT_APPLY,
            ['owner_user_id' => $actorUser->id],
            $discount,
        );
    }

    /** كمّيّةٌ صحيحةٌ تُخرج عدداً صحيحاً، والكسريّةُ تبقى كسراً. */
    private function tidyQty(string $v): int|float
    {
        $f = (float) $v;

        return floor($f) === $f ? (int) $f : $f;
    }

    // ============ التقرير اليومي ============

    public function dailyReport(User $merchant, ?string $date = null): array
    {
        $day = $date ? Carbon::parse($date) : now();
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $sales = MerchantSale::where('merchant_user_id', $merchant->id)
            ->whereBetween('created_at', [$from, $to])
            ->with('lines')
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
            // AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — **من السطر لا من الـJSON**.
            //
            // وكان يُقرأ `qty` وحدَه، والمطعمُ يرسل `quantity`: فبيعُ عشرين
            // طبقاً كان يُعدّ **طبقاً واحداً** في «أكثر المبيعات». رقمٌ
            // يُقرأ حقيقةً وهو خطأ، ولا خطأ في أيّ سجلّ.
            foreach ($sale->lines as $line) {
                $name = $line->name;
                if (!$name) continue;
                $topProducts[$name] = bcadd(
                    (string) ($topProducts[$name] ?? '0'), (string) $line->quantity, 3
                );
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
            ->sum(DB::raw('COALESCE(base_amount, total_amount)'));

        return [
            'date' => $day->format('Y-m-d'),
            'sales_count' => $sales->count(),
            'total_all' => (string) $sales->sum(fn ($s) => (float) $s->total_amount),
            'realized_revenue' => $realized, // نقد + رقمي
            'by_method' => $byMethod,
            'outstanding_credit_total' => $outstandingCredit, // كل الأجل غير المسوّى
            'top_products' => array_slice(
                array_map(
                    // الكمّيّةُ عشريّةٌ في المخزن (كيلو، لتر) — وتُخرج صحيحةً
                    // متى كانت صحيحة، فلا يقرأ الكاشيرُ «٢٫٠٠٠ علبة».
                    fn ($k, $v) => ['name' => $k, 'qty' => $this->tidyQty($v)],
                    array_keys($topProducts), $topProducts
                ),
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
