<?php

namespace App\Services;

use App\Models\FuelCompanyAccount;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelShift;
use App\Models\FuelStation;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-FUEL-001 — خدمة محطة الوقود.
 *
 * عمليات:
 *   - station: إنشاء/تحديث المحطة.
 *   - pumps: إدارة المضخّات.
 *   - products: أنواع الوقود + الأسعار.
 *   - recordSale: تسجيل بيع (الجوهر).
 *   - companyAccounts: إدارة حسابات الشركات.
 */
class FuelStationService
{
    use TransactionTrait;

    public function __construct(
        private readonly NotificationService $notif,
    ) {}

    // ============ المحطّة ============

    public function getOrCreateStation(User $merchant, ?array $data = null): FuelStation
    {
        $station = FuelStation::where('merchant_user_id', $merchant->id)->first();

        if (!$station) {
            $station = FuelStation::create([
                'merchant_user_id' => $merchant->id,
                'station_name' => $data['station_name'] ?? "محطة {$merchant->f_name}",
                'license_number' => $data['license_number'] ?? null,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);
        } elseif ($data) {
            $station->update(array_intersect_key($data, array_flip([
                'station_name', 'license_number', 'city', 'address',
                'latitude', 'longitude', 'is_active',
            ])));
        }

        return $station->fresh();
    }

    // ============ المضخّات ============

    public function addPump(FuelStation $station, array $data): FuelPump
    {
        if (empty($data['pump_number'])) {
            throw new InvalidArgumentException('رقم المضخّة مطلوب');
        }
        if (FuelPump::where('station_id', $station->id)
            ->where('pump_number', $data['pump_number'])
            ->exists()) {
            throw new InvalidArgumentException('رقم المضخّة مستخدم بالفعل');
        }
        $type = $data['pump_type'] ?? 'mechanical';
        if (!in_array($type, FuelPump::TYPES, true)) {
            throw new InvalidArgumentException('نوع المضخّة غير صحيح');
        }

        return FuelPump::create([
            'station_id' => $station->id,
            'pump_number' => $data['pump_number'],
            'pump_name' => $data['pump_name'] ?? null,
            'pump_type' => $type,
            'current_meter_reading' => $data['initial_meter_reading'] ?? 0,
            'is_active' => true,
        ]);
    }

    public function updatePump(FuelPump $pump, array $data): FuelPump
    {
        $allowed = array_intersect_key($data, array_flip([
            'pump_name', 'pump_type', 'is_active',
        ]));
        if (isset($allowed['pump_type']) && !in_array($allowed['pump_type'], FuelPump::TYPES, true)) {
            throw new InvalidArgumentException('نوع المضخّة غير صحيح');
        }
        $pump->update($allowed);
        return $pump->fresh();
    }

    /** ربط مضخّة بأنواع وقود (مع رقم الفوّهة). */
    public function linkPumpToProducts(FuelPump $pump, array $productLinks): void
    {
        // مثال productLinks: [['fuel_product_id' => 1, 'nozzle_number' => 1], ...]
        $pump->products()->detach(); // ابدأ نظيفاً
        foreach ($productLinks as $link) {
            if (empty($link['fuel_product_id'])) continue;
            $pump->products()->attach($link['fuel_product_id'], [
                'nozzle_number' => $link['nozzle_number'] ?? 1,
            ]);
        }
    }

    // ============ أنواع الوقود + الأسعار ============

    public function addProduct(FuelStation $station, array $data): FuelProduct
    {
        if (empty($data['name'])) {
            throw new InvalidArgumentException('اسم نوع الوقود مطلوب');
        }
        if (!isset($data['price_per_liter']) || (float)$data['price_per_liter'] <= 0) {
            throw new InvalidArgumentException('السعر للّتر يجب أن يكون موجباً');
        }

        return FuelProduct::create([
            'station_id' => $station->id,
            'name' => $data['name'],
            'product_code' => $data['product_code'] ?? null,
            'price_per_liter' => MoneyService::normalize($data['price_per_liter']),
            'color_hex' => $data['color_hex'] ?? null,
            'is_active' => true,
        ]);
    }

    /** تحديث السعر — يُستخدم بكثرة (الأسعار تتغيّر يومياً). */
    public function updateProductPrice(FuelProduct $product, string $newPrice, ?User $actor = null, ?string $note = null): FuelProduct
    {
        $price = MoneyService::normalize($newPrice);
        if (!MoneyService::isPositive($price)) {
            throw new InvalidArgumentException('السعر يجب أن يكون موجباً');
        }

        // AMIAL-FUEL-PRICE-HISTORY-001: سجّل التغيّر (قديم/جديد/فرق/من غيّر)
        // قبل الكتابة — يغذّي «سجل تغيّر الأسعار» وملخّص آخر تحديث في التطبيق.
        $oldPrice = MoneyService::normalize((string) $product->price_per_liter);
        return DB::transaction(function () use ($product, $price, $oldPrice, $actor, $note) {
            $product->update(['price_per_liter' => $price]);

            if (MoneyService::compare($oldPrice, $price) !== 0) {
                \App\Models\FuelPriceHistory::create([
                    'fuel_product_id' => $product->id,
                    'station_id' => $product->station_id,
                    'changed_by_user_id' => $actor?->id,
                    'old_price' => $oldPrice,
                    'new_price' => $price,
                    'delta' => MoneyService::sub($price, $oldPrice),
                    'note' => $note,
                    'created_at' => now(),
                ]);
            }
            return $product->fresh();
        });
    }

    /**
     * AMIAL-FUEL-PRICE-HISTORY-001 — سجلّ تغيّرات أسعار محطة (آخر N قيداً).
     */
    public function priceHistory(FuelStation $station, int $limit = 30): array
    {
        return \App\Models\FuelPriceHistory::where('station_id', $station->id)
            ->with(['product:id,name', 'changedBy:id,f_name,l_name'])
            ->orderByDesc('id')->limit(max(1, min($limit, 100)))->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'product' => $h->product->name ?? '—',
                'old_price' => (string) $h->old_price,
                'new_price' => (string) $h->new_price,
                'delta' => (string) $h->delta,
                'direction' => MoneyService::compare((string) $h->delta, '0') > 0 ? 'up'
                    : (MoneyService::compare((string) $h->delta, '0') < 0 ? 'down' : 'same'),
                'changed_by' => trim(($h->changedBy->f_name ?? '') . ' ' . ($h->changedBy->l_name ?? '')) ?: null,
                'note' => $h->note,
                'created_at' => optional($h->created_at)->toIso8601String(),
            ])->all();
    }

    // ============ تسجيل البيع (الجوهر) ============

    /**
     * تسجّل عملية بيع وقود.
     *
     * @param array $data {
     *   pump_id, fuel_product_id,
     *   sale_type: 'by_liters'|'by_amount',
     *   liters?: float (إن by_liters),
     *   amount?: float (إن by_amount),
     *   payment_method: 'cash'|'amial_pay'|'company_card',
     *   paid_transaction_id?: string,
     *   company_account_id?: int,
     *   company_card_id?: string,
     *   vehicle_plate?: string,
     *   driver_name?: string,
     *   meter_reading_after?: float (للميكانيكية),
     * }
     */
    public function recordSale(User $merchant, ?int $posUserId, array $data): FuelSale
    {
        // التحقّقات الأساسية
        $pump = FuelPump::with('station')->find($data['pump_id'] ?? null);
        if (!$pump || $pump->station->merchant_user_id !== $merchant->id) {
            throw new RuntimeException('المضخّة غير موجودة');
        }
        if (!$pump->is_active) {
            throw new RuntimeException('المضخّة غير نشطة');
        }

        $product = FuelProduct::find($data['fuel_product_id'] ?? null);
        if (!$product || $product->station_id !== $pump->station_id) {
            throw new RuntimeException('نوع الوقود غير موجود');
        }

        // ── الوردية شرطُ البيع ────────────────────────────────────────
        //
        // **ولا نقطةَ بيعٍ في العالم تبيع خارج وردية.** فالنقدُ الذي يدخل
        // الدرج بلا ورديّةٍ لا صاحبَ له: لا يُعدّ في إغلاق، ولا يُنسب إلى
        // صرّاف، ويظهر عجزاً في أوّل جردٍ بلا تفسير.
        //
        // وكان البيعُ يمرّ بلا هذا الشرط، والأرقامُ تُجمَع بنافذةٍ زمنيّة —
        // فبيعةٌ بين ورديّتين تسقط من الاثنتين.
        //
        // والزرُّ موجودٌ ومُوصَلٌ إليه: «الورديات ← فتح وردية»
        // (`POST /fuel/shifts/open` · `fuel_shifts_screen.dart`).
        $shift = FuelShift::where('station_id', $pump->station_id)
            ->where('status', 'open')
            ->first();

        if (!$shift) {
            throw new RuntimeException(
                'لا توجد وردية مفتوحة في المحطة — افتح وردية قبل البيع '
                . '(الورديات ← فتح وردية)',
            );
        }

        $saleType = $data['sale_type'] ?? null;
        if (!in_array($saleType, FuelSale::SALE_TYPES, true)) {
            throw new InvalidArgumentException('نوع البيع غير صحيح');
        }

        // احسب اللترات والإجمالي
        $pricePerLiter = (string) $product->price_per_liter;
        [$liters, $totalAmount] = $this->computeLitersAndAmount(
            $saleType,
            $data['liters'] ?? null,
            $data['amount'] ?? null,
            $pricePerLiter,
        );

        // طريقة الدفع
        $paymentMethod = $data['payment_method'] ?? null;
        if (!in_array($paymentMethod, FuelSale::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException('طريقة الدفع غير صحيحة');
        }

        // قراءة العدّاد (للميكانيكية فقط)
        $meterBefore = $pump->isMechanical() ? (string) $pump->current_meter_reading : null;
        $meterAfter = null;
        if ($pump->isMechanical()) {
            $meterAfter = isset($data['meter_reading_after'])
                ? (string) $data['meter_reading_after']
                : (string) (((float)$meterBefore) + ((float)$liters));
            // تحقّق منطقي: meterAfter ≥ meterBefore
            if ((float)$meterAfter < (float)$meterBefore) {
                throw new InvalidArgumentException('قراءة العدّاد بعد البيع أقل من قبله — خطأ');
            }
        }

        return DB::transaction(function () use (
            $merchant, $posUserId, $pump, $product, $saleType, $liters, $pricePerLiter,
            $totalAmount, $paymentMethod, $data, $meterBefore, $meterAfter, $shift,
        ) {
            // ====== التعامل مع طرق الدفع ======
            $companyAccount = null;
            $paidTxId = null;

            if ($paymentMethod === 'company_card') {
                $companyAccount = FuelCompanyAccount::where('id', $data['company_account_id'] ?? 0)
                    ->where('merchant_user_id', $merchant->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
                if (!$companyAccount) {
                    throw new RuntimeException('حساب الشركة غير موجود');
                }
                // أضف المبلغ للدَّيْن
                $newBalance = MoneyService::add(
                    (string)$companyAccount->current_balance,
                    $totalAmount
                );
                // تحقّق من الحد
                if ((float)$companyAccount->credit_limit > 0 &&
                    (float)$newBalance > (float)$companyAccount->credit_limit) {
                    throw new RuntimeException('تجاوزت الحد الائتماني المتاح للشركة');
                }
                $companyAccount->update(['current_balance' => $newBalance]);
            }

            if ($paymentMethod === 'amial_pay') {
                $paidTxId = $data['paid_transaction_id'] ?? null;
                // AMIAL-FUEL-PAY-001: الدفع الحقيقي في خطوة واحدة — التاجر يُدخل
                // هاتف العميل، فنشحن العميل مباشرةً عبر محرّك دفع التاجر (يحرّك
                // المال + الرسوم + دفتر القيود + الإيصال) ونربط المرجع بالبيع.
                // يبقى تمرير paid_transaction_id مدعوماً (دفع مسبق من تطبيق العميل).
                $customerPhone = trim((string) ($data['customer_phone'] ?? ''));
                if (empty($paidTxId) && $customerPhone !== '') {
                    $customer = User::whereIn('phone', \App\Support\Phone::variants($customerPhone))
                        ->where('type', CUSTOMER_TYPE)->first();
                    if (!$customer) {
                        throw new RuntimeException('لا يوجد عميل بهذا الرقم');
                    }
                    if ($customer->id === $merchant->id) {
                        throw new RuntimeException('لا يمكن الدفع لنفسك');
                    }
                    $paidTxId = $this->merchant_payment_transaction(
                        $customer->id,
                        $merchant->id,
                        $totalAmount,
                        'pos',
                        $posUserId,
                        'دفع وقود',
                        context: ['sector' => 'fuel'],
                    );
                    if (empty($paidTxId)) {
                        throw new RuntimeException('تعذّر تنفيذ دفع أميال باي');
                    }
                }
                if (empty($paidTxId)) {
                    throw new InvalidArgumentException('أدخل رقم هاتف العميل أو مرجع الدفع لـ أميال باي');
                }
            }

            // ====== أنشئ سجل البيع ======
            $sale = FuelSale::create([
                'sale_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'pos_user_id' => $posUserId,
                'station_id' => $pump->station_id,
                // **الانتماءُ يُكتب لحظةَ البيع لا يُستنتج بعده.**
                // والنافذةُ الزمنيّة كانت تترك بيعةَ ما بين ورديّتين يتيمة.
                'shift_id' => $shift->id,
                'pump_id' => $pump->id,
                'fuel_product_id' => $product->id,
                'sale_type' => $saleType,
                'liters' => $liters,
                'price_per_liter' => $pricePerLiter,
                'total_amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'paid_transaction_id' => $paidTxId,
                'company_account_id' => $companyAccount?->id,
                'company_card_id' => $data['company_card_id'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'meter_reading_before' => $meterBefore,
                'meter_reading_after' => $meterAfter,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            // حدّث عدّاد المضخّة
            if ($pump->isMechanical() && $meterAfter !== null) {
                $pump->update(['current_meter_reading' => $meterAfter]);
            }

            return $sale->fresh();
        });
    }

    /** يحسب اللترات والإجمالي حسب نوع البيع. */
    private function computeLitersAndAmount(
        string $saleType,
        $litersInput,
        $amountInput,
        string $pricePerLiter,
    ): array {
        $price = (float) $pricePerLiter;
        if ($price <= 0) {
            throw new InvalidArgumentException('السعر للّتر غير صحيح');
        }

        if ($saleType === 'by_liters') {
            $liters = (float) ($litersInput ?? 0);
            if ($liters <= 0) {
                throw new InvalidArgumentException('اللترات يجب أن تكون موجبة');
            }
            $total = $liters * $price;
            return [
                MoneyService::normalize((string) $liters),
                MoneyService::normalize((string) $total),
            ];
        }

        // by_amount
        $amount = (float) ($amountInput ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('المبلغ يجب أن يكون موجباً');
        }
        $liters = $amount / $price;
        return [
            MoneyService::normalize((string) $liters),
            MoneyService::normalize((string) $amount),
        ];
    }

    // ============ حسابات الشركات ============

    public function addCompanyAccount(User $merchant, array $data): FuelCompanyAccount
    {
        if (empty($data['company_name'])) {
            throw new InvalidArgumentException('اسم الشركة مطلوب');
        }
        return FuelCompanyAccount::create([
            'merchant_user_id' => $merchant->id,
            'company_name' => $data['company_name'],
            'contact_person' => $data['contact_person'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'credit_limit' => MoneyService::normalize($data['credit_limit'] ?? '0'),
            'monthly_limit' => MoneyService::normalize($data['monthly_limit'] ?? '0'),
            'current_balance' => '0.0000',
            'is_active' => true,
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);
    }

    /** سداد على حساب شركة. */
    public function recordCompanyPayment(FuelCompanyAccount $account, string $amount, ?string $note = null): array
    {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ السداد يجب أن يكون موجباً');
        }

        return DB::transaction(function () use ($account, $amount, $note) {
            $locked = FuelCompanyAccount::where('id', $account->id)->lockForUpdate()->first();
            $newBalance = MoneyService::sub((string)$locked->current_balance, $amount);
            if ((float)$newBalance < 0) {
                throw new RuntimeException('مبلغ السداد أكبر من المستحق');
            }
            $locked->update([
                'current_balance' => $newBalance,
                'last_payment_at' => now(),
            ]);
            return [
                'account' => $locked->fresh(),
                'amount' => $amount,
                'note' => $note,
            ];
        });
    }
}
