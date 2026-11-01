<?php

namespace App\Services\BusinessGateway;

use App\Models\BusinessGateway\BusinessCustomerDebt;
use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessPaymentIntent;
use App\Models\User;
use App\Services\MoneyService;
use App\Services\NotificationService;
use App\Services\PaymentRequestService;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-BUSINESS-CREDIT-001 — مرآة ديون عملاء التاجر في أميال أعمال.
 *
 * الواجهة: upsert (من أميال أعمال) · forCustomer (لتطبيق العميل) ·
 * settleFromApp (العميل يسدّد من محفظته) · sendDueReminders (المجدوِل).
 *
 * المرآة لا تُعدَّل هنا أبداً. الرصيد الذي يراه العميل = آخر نسخة من أميال أعمال
 * ناقص ما دفعه من التطبيق بعد تلك النسخة (`in_flight`)، فلا يرى ديناً سدّده
 * للتوّ، ولا يستطيع أن يدفعه مرتين قبل وصول النسخة الجديدة.
 */
class BusinessCustomerDebtService
{
    /** لا تذكير لنفس الدين قبل هذه المدة. */
    private const REMINDER_INTERVAL_DAYS = 3;

    public function __construct(
        private readonly PaymentRequestService $paymentRequests,
        private readonly BusinessPaymentIntentService $intents,
    ) {}

    /**
     * @param array{customer_name:string, customer_phone:?string, balance:string, overdue_amount:string,
     *              next_due_date:?string, reminders_enabled:bool, version:int, as_of:string} $data
     * @return array{applied: bool, debt: BusinessCustomerDebt}
     */
    public function upsert(BusinessLink $link, string $contactRef, array $data): array
    {
        return DB::transaction(function () use ($link, $contactRef, $data) {
            $existing = BusinessCustomerDebt::where('business_link_id', $link->id)
                ->where('contact_ref', $contactRef)->lockForUpdate()->first();

            // التسليم «مرة على الأقل» وبلا ترتيب مضمون: نسخة أقدم لا تستبدل أحدث.
            if ($existing && (int) $existing->version >= (int) $data['version']) {
                return ['applied' => false, 'debt' => $existing];
            }

            $phone = $data['customer_phone'] ?? null;
            $customer = $phone ? $this->paymentRequests->resolveRecipient($phone) : null;
            if ($customer && (int) $customer->id === (int) $link->merchant_user_id) {
                $customer = null; // التاجر لا يدين لنفسه.
            }

            $attributes = [
                'customer_user_id' => $customer?->id,
                'customer_phone' => $phone,
                'customer_name' => $data['customer_name'],
                'balance' => MoneyService::normalize($data['balance']),
                'overdue_amount' => MoneyService::normalize($data['overdue_amount']),
                'next_due_date' => $data['next_due_date'],
                'reminders_enabled' => (bool) $data['reminders_enabled'],
                'version' => (int) $data['version'],
                'as_of' => $data['as_of'],
            ];

            $debt = $existing
                ? tap($existing)->update($attributes)
                : BusinessCustomerDebt::create($attributes + ['business_link_id' => $link->id, 'contact_ref' => $contactRef]);

            return ['applied' => true, 'debt' => $debt->fresh()];
        }, 3);
    }

    /** ديون العميل لدى تجار أميال أعمال، مع ربط ما سُجّل برقمه قبل أن يُنشئ حسابه. */
    public function forCustomer(User $customer): Collection
    {
        try {
            BusinessCustomerDebt::whereNull('customer_user_id')
                ->whereIn('customer_phone', Phone::variants((string) $customer->phone))
                ->update(['customer_user_id' => $customer->id]);
        } catch (\Throwable $e) {
            Log::warning('Business debt back-link failed: '.$e->getMessage());
        }

        return BusinessCustomerDebt::with('link.merchant.merchant')
            ->where('customer_user_id', $customer->id)
            ->whereHas('link', fn ($q) => $q->where('status', BusinessLink::STATUS_ACTIVE))
            ->orderByDesc('balance')
            ->get();
    }

    /**
     * ما دُفع لهذا الدين بعد آخر نسخة وصلت من أميال أعمال، وما هو قيد الدفع الآن
     * (رمز معروض عند الكاشير أو سداد جارٍ من التطبيق). كلاهما يُخصم من المتبقّي
     * فلا يُدفع الدين نفسه مرتين في الفاصل قبل وصول النسخة الجديدة.
     */
    public function inFlight(BusinessCustomerDebt $debt): string
    {
        $sum = BusinessPaymentIntent::where('business_link_id', $debt->business_link_id)
            ->where('contact_ref', $debt->contact_ref)
            ->where('purpose', BusinessPaymentIntent::PURPOSE_DEBT_COLLECTION)
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('status', BusinessPaymentIntent::STATUS_SUCCEEDED)
                    // >= لا >: الطابعان بدقّة الثانية، ودفعٌ في ثانية اللقطة نفسها لا يُعرف
                    // أهو فيها أم بعدها. الخطأ المحافظ (متبقٍّ أقلّ حتى النسخة التالية) أسلم
                    // من دفع الدين مرّتين.
                    ->where('succeeded_at', '>=', $debt->as_of))
                ->orWhere(fn ($p) => $p->where('status', BusinessPaymentIntent::STATUS_PENDING)
                    ->where('expires_at', '>', now())))
            ->sum('amount');

        return MoneyService::normalize((string) $sum);
    }

    public function outstanding(BusinessCustomerDebt $debt): string
    {
        $left = MoneyService::sub((string) $debt->balance, $this->inFlight($debt));

        return MoneyService::compare($left, '0') > 0 ? MoneyService::normalize($left) : '0.0000';
    }

    public function present(BusinessCustomerDebt $debt): array
    {
        $merchant = $debt->link?->merchant;
        $store = $merchant?->merchant?->store_name
            ?? trim(($merchant->f_name ?? '').' '.($merchant->l_name ?? '')) ?: 'تاجر';

        return [
            'debt_id' => $debt->id,
            'source' => 'amial_business',
            'merchant_name' => $store,
            'current_balance' => $this->outstanding($debt),
            'ledger_balance' => MoneyService::normalize((string) $debt->balance),
            'pending_payments' => $this->inFlight($debt),
            'overdue_amount' => MoneyService::normalize((string) $debt->overdue_amount),
            'next_due_date' => $debt->next_due_date?->toDateString(),
            'currency' => $debt->currency,
            'as_of' => $debt->as_of?->toIso8601String(),
        ];
    }

    /**
     * العميل يسدّد من تطبيقه: نيّة تحصيل باسمه ثم الدفع فوراً عبر المسار المالي
     * القائم (PIN تحقّق منه المتحكّم). المبلغ لا يتجاوز المتبقّي، والقفل على صف
     * المرآة يمنع طلبين متزامنين من تجاوزه معاً.
     */
    public function settleFromApp(User $customer, BusinessCustomerDebt $debt, string $amount): array
    {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new BusinessGatewayException('AMOUNT_INVALID', 'المبلغ يجب أن يكون موجباً', 422);
        }

        $intent = DB::transaction(function () use ($customer, $debt, $amount) {
            $locked = BusinessCustomerDebt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->customer_user_id !== (int) $customer->id) {
                throw new BusinessGatewayException('NOT_FOUND', 'الدين غير موجود أو لا يخصّك', 404);
            }
            if (MoneyService::compare($amount, $this->outstanding($locked)) > 0) {
                throw new BusinessGatewayException('AMOUNT_EXCEEDS_DEBT', 'المبلغ أكبر من الدين المتبقّي', 422);
            }

            return $this->intents->create(
                BusinessLink::findOrFail($locked->business_link_id),
                'APP-'.Str::ulid(),
                $amount,
                (string) config('amial_business.wallet_currency', 'YER'),
                description: 'سداد دين آجل من تطبيق العميل',
                purpose: BusinessPaymentIntent::PURPOSE_DEBT_COLLECTION,
                contactRef: $locked->contact_ref,
                payerPhone: (string) $customer->phone,
                origin: BusinessPaymentIntent::ORIGIN_CUSTOMER_APP,
            );
        }, 3);

        $request = $intent->paymentRequest()->firstOrFail();
        try {
            $result = $this->paymentRequests->pay($customer, $request);
        } catch (\Throwable $e) {
            // لم يتحرّك مال: النيّة لا تبقى معلّقة يدفعها أحد لاحقاً.
            try {
                $this->intents->cancel(BusinessLink::findOrFail($intent->business_link_id), $intent->intent_ulid);
            } catch (\Throwable) {
            }
            throw $e;
        }

        return ['intent' => $this->intents->present($intent->fresh()), 'payment' => $result];
    }

    /**
     * تذكير العملاء بما استحقّ أو يستحقّ غداً. السياسة (تشغيل/إيقاف) من التاجر
     * في أميال أعمال عبر `reminders_enabled`؛ القناة (إشعار التطبيق) هنا.
     */
    public function sendDueReminders(int $limit = 500): int
    {
        $tomorrow = now()->addDay()->toDateString();
        $sent = 0;

        BusinessCustomerDebt::with('link.merchant.merchant')
            ->whereNotNull('customer_user_id')
            ->where('reminders_enabled', true)
            ->where('balance', '>', 0)
            ->where(fn ($q) => $q->where('overdue_amount', '>', 0)->orWhere('next_due_date', '<=', $tomorrow))
            ->where(fn ($q) => $q->whereNull('last_reminded_at')
                ->orWhere('last_reminded_at', '<=', now()->subDays(self::REMINDER_INTERVAL_DAYS)))
            ->whereHas('link', fn ($q) => $q->where('status', BusinessLink::STATUS_ACTIVE))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (BusinessCustomerDebt $debt) use (&$sent) {
                $customer = User::find($debt->customer_user_id);
                if (!$customer || !$customer->is_active) {
                    return;
                }
                $view = $this->present($debt);
                if (!MoneyService::isPositive($view['current_balance'])) {
                    return;
                }
                $overdue = MoneyService::isPositive($view['overdue_amount']);
                $body = $overdue
                    ? "لديك دين متأخر لدى {$view['merchant_name']} بمبلغ {$view['overdue_amount']} ر.ي. يمكنك سداده من «ديوني»."
                    : "يستحق دينك لدى {$view['merchant_name']} قريباً ({$view['next_due_date']}) والمتبقّي {$view['current_balance']} ر.ي.";

                try {
                    app(NotificationService::class)->dispatch(
                        $customer,
                        'business_debt_reminder',
                        $overdue ? 'تذكير بدين متأخر' : 'تذكير بموعد سداد',
                        $body,
                        data: ['debt_id' => $debt->id, 'amount' => $view['current_balance']],
                    );
                    $debt->forceFill(['last_reminded_at' => now()])->save();
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Business debt reminder failed', ['debt_id' => $debt->id, 'error' => $e->getMessage()]);
                }
            });

        return $sent;
    }
}
