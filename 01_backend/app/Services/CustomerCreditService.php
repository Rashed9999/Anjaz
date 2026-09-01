<?php

namespace App\Services;

use App\CentralLogics\Helpers;

use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-CUSTOMER-CREDIT-001 — نظام الديون المتقدّم.
 *
 * يدير حسابات العملاء المدينين عند التاجر:
 *   - بيع آجل → يزيد الدَّيْن.
 *   - سداد دفعة → ينقصه.
 *   - مرتجع → ينقصه.
 *   - تعديل يدوي → موجب/سالب.
 *
 * كل تعديل على الرصيد داخل DB::transaction + lockForUpdate لمنع race.
 * كل قيد يحفظ snapshot لـ balance_after — مفيد لكشف الحساب وعكس القيد.
 */
class CustomerCreditService
{
    /**
     * يجد حساب العميل عند التاجر، أو يُنشئه إن لم يوجد.
     * يربط customer_user_id تلقائياً إن كان رقم الهاتف يطابق مستخدم مسجّل.
     */
    public function findOrCreateAccount(
        int $merchantId,
        string $customerPhone,
        string $customerName,
        ?string $creditLimit = null,
    ): CustomerCreditAccount {
        $customerPhone = trim($customerPhone);
        if ($customerPhone === '' || $customerName === '') {
            throw new InvalidArgumentException('رقم العميل واسمه مطلوبان');
        }

        // ابحث عن حساب موجود
        $account = CustomerCreditAccount::where('merchant_user_id', $merchantId)
            ->whereIn('customer_phone', \App\Support\Phone::variants($customerPhone))
            ->first();

        if ($account) {
            // حدّث الاسم إن أرسله التاجر، والحد إن أرسله. والأهم: الحساب
            // الذي نشأ قبل تسجيل العميل أو قبل إصلاح صيغ رقم الهاتف يجب أن
            // يُربط عند أول بيع جديد؛ وإلا يبقى الدين في لوحة التاجر فقط
            // ولا يظهر في «فواتيري الآجلة» لصاحب المحفظة.
            $updates = [];
            if ($account->customer_name !== $customerName) {
                $updates['customer_name'] = $customerName;
            }
            if ($creditLimit !== null) {
                $updates['credit_limit'] = MoneyService::normalize($creditLimit);
            }
            if ($account->customer_user_id === null) {
                $linkedUserId = User::whereIn('phone', \App\Support\Phone::variants($customerPhone))
                    ->value('id');
                if ($linkedUserId !== null) {
                    $updates['customer_user_id'] = $linkedUserId;
                }
            }
            if ($updates) $account->update($updates);
            return $account;
        }

        // AMIAL-CREDIT-LINK-001 — اربط بمستخدم أميال باي إن وُجد.
        //
        // كان الربط مطابقة نصّية حرفية على `phone`: التاجر يكتب «777100001»
        // والمستخدمون مخزّنون «+967777100001» — فلا يُطابق أبداً، ويبقى
        // customer_user_id فارغاً، فلا تظهر الفاتورة في «فواتيري الآجلة» عند
        // العميل ولا يستطيع سدادها. نستعمل Phone::variants الموجود أصلاً
        // لهذا الغرض بالضبط.
        $linkedUserId = User::whereIn('phone', \App\Support\Phone::variants($customerPhone))
            ->value('id');

        return CustomerCreditAccount::create([
            'merchant_user_id' => $merchantId,
            'customer_phone' => $customerPhone,
            'customer_user_id' => $linkedUserId,
            'customer_name' => $customerName,
            'credit_limit' => $creditLimit !== null ? MoneyService::normalize($creditLimit) : '0.0000',
            'current_balance' => '0.0000',
            'classification' => 'bronze',
            'is_active' => true,
        ]);
    }

    /** بيع آجل — يزيد الدَّيْن. يرجع الـ movement. */
    public function recordSale(
        CustomerCreditAccount $account,
        string $amount,
        ?string $dueDate = null,
        ?string $note = null,
        ?int $createdBy = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $referenceNumber = null,
    ): CustomerCreditMovement {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ البيع يجب أن يكون موجباً');
        }

        return $this->postMovement(
            account: $account,
            type: 'sale',
            signedAmount: $amount, // موجب
            dueDate: $dueDate,
            note: $note,
            createdBy: $createdBy,
            referenceType: $referenceType,
            referenceId: $referenceId,
            referenceNumber: $referenceNumber,
        );
    }

    /** سداد — ينقص الدَّيْن. لا يمكن أن يجعل الرصيد سالباً. */
    public function recordPayment(
        CustomerCreditAccount $account,
        string $amount,
        ?string $note = null,
        ?int $createdBy = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): CustomerCreditMovement {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ السداد يجب أن يكون موجباً');
        }

        return $this->postMovement(
            account: $account,
            type: 'payment',
            signedAmount: '-' . $amount, // سالب
            note: $note,
            createdBy: $createdBy,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    /** مرتجع مبيعات — ينقص الدَّيْن. */
    public function recordReturn(
        CustomerCreditAccount $account,
        string $amount,
        ?string $note = null,
        ?int $createdBy = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): CustomerCreditMovement {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ المرتجع يجب أن يكون موجباً');
        }

        return $this->postMovement(
            account: $account,
            type: 'return',
            signedAmount: '-' . $amount,
            note: $note,
            createdBy: $createdBy,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    /** تعديل يدوي — قد يكون موجباً (يزيد دين) أو سالباً (يقلّله). */
    public function recordAdjustment(
        CustomerCreditAccount $account,
        string $signedAmount,
        string $note,
        ?int $createdBy = null,
    ): CustomerCreditMovement {
        $signedAmount = trim($signedAmount);
        if ($signedAmount === '' || $note === '') {
            throw new InvalidArgumentException('التعديل يحتاج مبلغاً (موجب أو سالب) وسبباً');
        }
        return $this->postMovement(
            account: $account,
            type: 'adjustment',
            signedAmount: $signedAmount,
            note: $note,
            createdBy: $createdBy,
        );
    }

    /**
     * كشف حساب لفترة. يعيد:
     *   - opening_balance (الرصيد عند بداية الفترة)
     *   - movements (قيود الفترة بترتيب زمني)
     *   - totals (إجمالي مدين/دائن خلال الفترة)
     *   - closing_balance (الرصيد الحالي)
     */
    public function getStatement(
        CustomerCreditAccount $account,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $account->refresh();
        $from = $from ? Carbon::parse($from)->startOfDay() : null;
        $to = $to ? Carbon::parse($to)->endOfDay() : null;

        $q = $account->movements()->orderBy('id', 'asc');
        if ($from) $q->where('created_at', '>=', $from);
        if ($to) $q->where('created_at', '<=', $to);
        $movements = $q->get();

        // الرصيد الافتتاحي = balance_after لآخر قيد قبل الفترة (أو 0)
        $openingBalance = '0';
        if ($from) {
            $previous = $account->movements()
                ->where('created_at', '<', $from)
                ->orderBy('id', 'desc')
                ->first();
            if ($previous) $openingBalance = (string) $previous->balance_after;
        }

        $debitSum = '0';   // ما زاد عليه (sales + adjustments الموجبة)
        $creditSum = '0';  // ما نزل (payments + returns + adjustments السالبة)
        foreach ($movements as $m) {
            $a = (string) $m->amount;
            if (str_starts_with($a, '-')) {
                $creditSum = MoneyService::add($creditSum, ltrim($a, '-'));
            } else {
                $debitSum = MoneyService::add($debitSum, $a);
            }
        }

        return [
            'account' => $account,
            'opening_balance' => MoneyService::normalize($openingBalance),
            'closing_balance' => (string) $account->current_balance,
            'totals' => [
                'debit' => $debitSum,
                'credit' => $creditSum,
            ],
            'movements' => $movements,
            'period' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
        ];
    }

    /**
     * يحسب تصنيف العميل من سلوكه ويحدّث الحساب.
     *   gold:   سدّد خلال 30 يوم آخر  +  استهلاك < 60%
     *   silver: سدّد خلال 90 يوم آخر  +  استهلاك < 90%
     *   bronze: غير ذلك (افتراضي/تجاوز/متأخّر).
     */
    private function calculateClassification(CustomerCreditAccount $account): string
    {
        $util = $account->utilizationPercent();
        $lastPay = $account->last_payment_at;

        $classification = 'bronze';
        if ($lastPay && $lastPay->gte(now()->subDays(30)) && $util < 60) {
            $classification = 'gold';
        } elseif ($lastPay && $lastPay->gte(now()->subDays(90)) && $util < 90) {
            $classification = 'silver';
        }

        if ($account->classification !== $classification) {
            $account->update(['classification' => $classification]);
        }
        return $classification;
    }

    /** ملخّص dashboard للتاجر. */
    public function dashboardSummary(int $merchantId): array
    {
        $base = CustomerCreditAccount::where('merchant_user_id', $merchantId)
            ->where('is_active', true);

        // إجمالي الديون المستحقّة = sum(current_balance > 0)
        $totalDue = (string) (clone $base)->where('current_balance', '>', 0)->sum('current_balance');

        // العملاء المدينون = من عليهم رصيد موجب
        $debtorsCount = (clone $base)->where('current_balance', '>', 0)->count();

        // من تجاوز الحد
        $overLimit = (clone $base)
            ->where('credit_limit', '>', 0)
            ->whereColumn('current_balance', '>', 'credit_limit')
            ->count();

        // تصنيف العملاء
        $byClass = (clone $base)
            ->selectRaw('classification, COUNT(*) as c')
            ->groupBy('classification')
            ->pluck('c', 'classification')->toArray();

        return [
            'total_due' => MoneyService::normalize($totalDue),
            'debtors_count' => $debtorsCount,
            'over_limit_count' => $overLimit,
            'by_classification' => [
                'gold' => (int) ($byClass['gold'] ?? 0),
                'silver' => (int) ($byClass['silver'] ?? 0),
                'bronze' => (int) ($byClass['bronze'] ?? 0),
            ],
        ];
    }

    /** قائمة عملاء التاجر مع فلترة بحث وحالة الدين. */
    public function listCustomers(
        int $merchantId,
        ?string $search = null,
        ?string $filter = null, // 'debtors' | 'over_limit' | 'paid_up' | null=الكل
    ) {
        $q = CustomerCreditAccount::where('merchant_user_id', $merchantId)
            ->where('is_active', true);

        if ($search) {
            $q->where(function (Builder $w) use ($search) {
                $w->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }
        if ($filter === 'debtors') $q->where('current_balance', '>', 0);
        if ($filter === 'paid_up') $q->where('current_balance', '<=', 0);
        if ($filter === 'over_limit') {
            $q->where('credit_limit', '>', 0)
              ->whereColumn('current_balance', '>', 'credit_limit');
        }

        return $q->orderByDesc('current_balance')->paginate(20);
    }

    // ============ القلب: نشر قيد بأمان ============

    /**
     * ينشر قيداً (sale/payment/return/adjustment) داخل transaction مع قفل الحساب.
     * يحدّث current_balance ويسجّل balance_after في القيد.
     * $signedAmount: نصّ بـ "+N" أو "-N".
     */
    private function postMovement(
        CustomerCreditAccount $account,
        string $type,
        string $signedAmount,
        ?string $dueDate = null,
        ?string $note = null,
        ?int $createdBy = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $referenceNumber = null,
    ): CustomerCreditMovement {
        if (!in_array($type, CustomerCreditMovement::TYPES, true)) {
            throw new InvalidArgumentException("نوع قيد غير صحيح: {$type}");
        }

        return DB::transaction(function () use (
            $account, $type, $signedAmount, $dueDate, $note,
            $createdBy, $referenceType, $referenceId, $referenceNumber,
        ) {
            // اقفل الحساب لمنع race على current_balance
            $locked = CustomerCreditAccount::where('id', $account->id)
                ->lockForUpdate()
                ->first();
            if (!$locked) {
                throw new RuntimeException('الحساب غير موجود');
            }

            // طبّع المبلغ الموقّع
            $signed = trim($signedAmount);
            $isNeg = str_starts_with($signed, '-');
            $abs = MoneyService::normalize(ltrim($signed, '+-'));
            if (!MoneyService::isPositive($abs)) {
                throw new InvalidArgumentException('المبلغ صفر — لا يمكن نشره');
            }

            // احسب الرصيد الجديد
            $current = (string) $locked->current_balance;
            $newBalance = $isNeg
                ? MoneyService::sub($current, $abs)
                : MoneyService::add($current, $abs);

            // لا نسمح للسداد بجعل الرصيد سالباً (حماية من خطأ)
            if ($type === 'payment' && (float) $newBalance < 0) {
                throw new RuntimeException('مبلغ السداد أكبر من الدَّيْن المستحقّ');
            }

            // أنشئ القيد
            $movement = CustomerCreditMovement::create([
                'movement_ulid' => (string) Str::ulid(),
                'account_id' => $locked->id,
                'type' => $type,
                'amount' => ($isNeg ? '-' : '') . $abs,
                'balance_after' => MoneyService::normalize($newBalance),
                'due_date' => $dueDate,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
                'note' => $note,
                'created_by_user_id' => $createdBy,
                'zone_code' => $locked->zone_code,
            ]);

            // حدّث الحساب
            $updates = ['current_balance' => MoneyService::normalize($newBalance)];
            if ($type === 'payment') {
                $updates['last_payment_at'] = now();
            }
            $locked->update($updates);

            // أعد حساب التصنيف
            $this->calculateClassification($locked->fresh());

            // AMIAL-NOTIFICATIONS-001 — إشعارات تلقائية
            $this->maybeNotify($locked->fresh(), $movement);

            return $movement;
        });
    }

    /**
     * أرسل إشعاراً للعميل (إن كان مستخدم أميال باي) عند البيع الآجل،
     * وإشعاراً للتاجر عند تجاوز الحد.
     * الإشعارات best-effort — أيّ خلل لا يكسر القيد.
     */
    private function maybeNotify(CustomerCreditAccount $account, CustomerCreditMovement $movement): void
    {
        try {
            $notif = app(NotificationService::class);

            // 1) إشعار العميل (لو هو مستخدم أميال باي مسجّل) عند البيع الآجل أو السداد
            if ($account->customer_user_id) {
                $customerUser = \App\Models\User::find($account->customer_user_id);
                if ($customerUser) {
                    if ($movement->type === 'sale') {
                        $notif->dispatch(
                            $customerUser,
                            'credit_sale',
                            'بيع آجل جديد',
                            "تمّ تسجيل بيع آجل بمبلغ " . Helpers::money($movement->amount) . " ر.ي عليك",
                            data: [
                                'account_id' => $account->id,
                                'movement_id' => $movement->id,
                                'amount' => (string)$movement->amount,
                                'balance_after' => (string)$movement->balance_after,
                            ],
                        );
                    } elseif ($movement->type === 'payment') {
                        $notif->dispatch(
                            $customerUser,
                            'credit_payment',
                            'تم تسجيل سداد',
                            "تم تسجيل سداد بمبلغ " . ltrim($movement->amount, '-') . " ر.ي. الرصيد المتبقّي: " . Helpers::money($movement->balance_after) . " ر.ي",
                            data: ['account_id' => $account->id, 'movement_id' => $movement->id],
                        );
                    }
                }
            }

            // 2) إشعار التاجر عند تجاوز العميل الحد
            if ($account->isOverLimit() && $movement->type === 'sale') {
                $merchant = \App\Models\User::find($account->merchant_user_id);
                if ($merchant) {
                    $notif->dispatch(
                        $merchant,
                        'credit_over_limit',
                        'تجاوز حد ائتمان',
                        "العميل {$account->customer_name} تجاوز حد الائتمان ({$account->current_balance} / " . Helpers::money($account->credit_limit) . " ر.ي)",
                        data: ['account_id' => $account->id],
                    );
                }
            }
        } catch (\Throwable $e) {
            // لا نوقف القيد بسبب فشل إشعار
            logger()->warning('Notification dispatch failed in credit movement: ' . $e->getMessage());
        }
    }
}
