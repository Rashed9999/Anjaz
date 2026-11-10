<?php

namespace App\Services\BusinessGateway;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessPaymentIntent;
use App\Models\BusinessGateway\BusinessRefund;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Services\MoneyService;
use App\Services\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-BUSINESS-REFUND-001 — استرداد دفعة «أميال أعمال» إلى محفظة الدافع.
 *
 * الواجهة: request (من القناة) · approve · reject (التاجر برمز PIN) ·
 * setMandate (التاجر برمز PIN) · expireDue (المجدوِل) · present.
 *
 * الثوابت:
 *   1. **توقيع القناة يطلب ولا يُخرج مالاً.** المال يخرج من محفظة التاجر بموافقته هو:
 *      برمز PIN عند كل طلب، أو ضمن تفويضٍ وضعه بنفسه برمز PIN (حدٌّ للعملية وحدٌّ يومي).
 *   2. المال يعود لمن دفع النيّة الأصلية فقط، ولا يتجاوز مجموع الاستردادات مبلغها.
 *      المعلّق يحجز من المتبقّي، فلا يُطلب الشيء نفسه مرتين.
 *   3. التنفيذ مرة واحدة: الحالة تُقرأ تحت قفل صف الاسترداد، والقيد بمفتاح تكرار.
 *   4. كل حالة نهائية تكتب حدثاً في الـ outbox داخل المعاملة نفسها.
 */
class BusinessRefundService
{
    use \App\Traits\TransactionTrait;

    /** مهلة موافقة التاجر قبل أن ينتهي الطلب. */
    private const APPROVAL_HOURS = 72;

    public function __construct(
        private readonly BusinessLinkService $links,
        private readonly BusinessOutbox $outbox,
        private readonly LedgerService $ledger,
        private readonly AuditService $audit,
    ) {}

    public function request(BusinessLink $link, string $businessReference, string $intentUlid, string $amount, ?string $reason = null): BusinessRefund
    {
        $merchant = $this->links->assertCanReceive($link);
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new BusinessGatewayException('AMOUNT_INVALID', 'المبلغ يجب أن يكون موجباً');
        }
        $fingerprint = hash('sha256', "{$intentUlid}|{$amount}");

        $existing = BusinessRefund::where('business_link_id', $link->id)->where('business_reference', $businessReference)->first();
        if ($existing) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            $refund = DB::transaction(function () use ($link, $merchant, $businessReference, $intentUlid, $amount, $reason, $fingerprint) {
                $intent = BusinessPaymentIntent::where('business_link_id', $link->id)->where('intent_ulid', $intentUlid)->lockForUpdate()->first();
                if (!$intent) {
                    throw new BusinessGatewayException('INTENT_NOT_FOUND', 'نيّة الدفع غير موجودة', 404);
                }
                if ($intent->status !== BusinessPaymentIntent::STATUS_SUCCEEDED) {
                    throw new BusinessGatewayException('INTENT_NOT_PAID', 'لا يُسترد إلا دفعٌ ناجح', 409);
                }
                if ((string) $intent->currency !== (string) config('amial_business.wallet_currency', 'YER')) {
                    throw new BusinessGatewayException('CURRENCY_NOT_SUPPORTED', 'عملة الدفع غير مدعومة للاسترداد');
                }
                $payer = (int) PaymentRequest::whereKey($intent->payment_request_id)->value('paid_by_user_id');
                if ($payer <= 0) {
                    throw new BusinessGatewayException('PAYER_UNKNOWN', 'تعذّر تحديد الدافع الأصلي', 409);
                }
                if (MoneyService::compare($amount, $this->refundable($intent)) > 0) {
                    throw new BusinessGatewayException('REFUND_EXCEEDS_PAYMENT', 'المبلغ أكبر من المتبقّي القابل للاسترداد من هذا الدفع', 409);
                }

                return BusinessRefund::create([
                    'refund_ulid' => (string) Str::ulid(), 'business_link_id' => $link->id,
                    'business_payment_intent_id' => $intent->id, 'business_reference' => $businessReference,
                    'amount' => $amount, 'currency' => $intent->currency, 'request_fingerprint' => $fingerprint,
                    'customer_user_id' => $payer, 'status' => BusinessRefund::PENDING,
                    'reason' => $reason ? mb_substr($reason, 0, 255) : null,
                    'expires_at' => now()->addHours(self::APPROVAL_HOURS),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $existing = BusinessRefund::where('business_link_id', $link->id)->where('business_reference', $businessReference)->firstOrFail();

            return $this->replay($existing, $fingerprint);
        }

        // ضمن تفويض التاجر ⇒ يُنفَّذ الآن. رصيدٌ لا يكفي ⇒ يبقى بانتظار التاجر ولا يُفقد.
        if ($this->withinMandate($link, $refund)) {
            try {
                return $this->execute($refund, $merchant, 'mandate');
            } catch (\App\Exceptions\InsufficientBalanceException) {
                Log::info('BUSINESS_REFUND_MANDATE_INSUFFICIENT', ['refund' => $refund->refund_ulid]);
            } catch (\App\Exceptions\AmlHeldException|\App\Exceptions\AmlBlockedException $e) {
                // التفويض لا يتجاوز الفحص: يبقى الطلب بانتظار التاجر، والقرار مسجّل للمراجعة.
                Log::warning('BUSINESS_REFUND_AML_STOPPED', ['refund' => $refund->refund_ulid, 'reason' => $e->getMessage()]);
            }
        }

        $this->notifyMerchant($merchant, $refund);

        return $refund->fresh();
    }

    /** موافقة التاجر نفسه (تحقّق المتحكّم من PIN). */
    public function approve(User $merchant, BusinessRefund $refund): BusinessRefund
    {
        $this->assertOwner($merchant, $refund);

        return $this->execute($refund, $merchant, 'merchant_pin');
    }

    public function reject(User $merchant, BusinessRefund $refund, ?string $note = null): BusinessRefund
    {
        $this->assertOwner($merchant, $refund);

        return DB::transaction(function () use ($merchant, $refund, $note) {
            $locked = BusinessRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== BusinessRefund::PENDING) {
                throw new BusinessGatewayException('REFUND_NOT_PENDING', 'هذا الطلب لم يعد بانتظار الموافقة', 409);
            }
            $locked->update([
                'status' => BusinessRefund::REJECTED, 'decided_by_user_id' => $merchant->id, 'decided_at' => now(),
                'decision_note' => $note ? mb_substr($note, 0, 255) : null,
            ]);
            $this->outbox->record($locked->link, 'business_refund.rejected', $this->present($locked));

            return $locked;
        });
    }

    /** تفويض الاسترداد التلقائي. يضعه التاجر بنفسه (تحقّق المتحكّم من PIN). */
    public function setMandate(User $merchant, BusinessLink $link, string $perRefund, string $daily): BusinessLink
    {
        if ((int) $link->merchant_user_id !== (int) $merchant->id) {
            throw new BusinessGatewayException('NOT_FOUND', 'الرابط غير موجود', 404);
        }
        $perRefund = MoneyService::normalize($perRefund);
        $daily = MoneyService::normalize($daily);
        // إمّا إيقافٌ (صفران) أو تفويضٌ كامل: كلاهما موجب وحدّ العملية لا يتجاوز اليومي.
        $off = !MoneyService::isPositive($perRefund) && !MoneyService::isPositive($daily) && !str_starts_with($perRefund, '-') && !str_starts_with($daily, '-');
        $on = MoneyService::isPositive($perRefund) && MoneyService::isPositive($daily) && MoneyService::compare($perRefund, $daily) <= 0;
        if (!$off && !$on) {
            throw new BusinessGatewayException('MANDATE_INVALID', 'حدّ العملية لا يتجاوز الحدّ اليومي ولا يكون سالباً', 422);
        }
        $link->update(['refund_auto_limit' => $perRefund, 'refund_daily_limit' => $daily, 'refund_mandate_set_at' => now()]);
        $this->audit->record([
            'actor_type' => 'merchant', 'actor_user_id' => $merchant->id,
            'subject_type' => 'business_link', 'subject_id' => $link->link_ulid,
            'action' => 'BUSINESS_REFUND_MANDATE_SET', 'decision_code' => 'MANDATE_OK',
            'reason' => 'Merchant set automatic refund limits for Amial Business', 'severity' => 'warning',
            'context' => ['per_refund' => $perRefund, 'daily' => $daily],
        ]);

        return $link->fresh();
    }

    public function expireDue(int $limit = 200): int
    {
        $count = 0;
        foreach (BusinessRefund::where('status', BusinessRefund::PENDING)->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id) {
                $locked = BusinessRefund::whereKey($id)->lockForUpdate()->first();
                if ($locked?->status !== BusinessRefund::PENDING) {
                    return 0;
                }
                $locked->update(['status' => BusinessRefund::EXPIRED, 'decided_at' => now()]);
                $this->outbox->record($locked->link, 'business_refund.expired', $this->present($locked));

                return 1;
            });
        }

        return $count;
    }

    public function refundable(BusinessPaymentIntent $intent): string
    {
        $used = (string) BusinessRefund::where('business_payment_intent_id', $intent->id)->whereIn('status', BusinessRefund::COUNTED)->sum('amount');

        return MoneyService::sub((string) $intent->amount, MoneyService::normalize($used ?: '0'));
    }

    public function present(BusinessRefund $refund): array
    {
        return [
            'refund_id' => $refund->refund_ulid,
            'business_reference' => $refund->business_reference,
            'intent_id' => $refund->intent?->intent_ulid,
            'status' => $refund->status,
            'approval' => $refund->approval,
            'amount' => MoneyService::normalize((string) $refund->amount),
            // الاسترداد بلا رسم اليوم؛ الحقلان ثابتا الشكل في كل حدث مالي.
            'fee' => '0.0000',
            'net_amount' => MoneyService::normalize((string) $refund->amount),
            'currency' => $refund->currency,
            'customer_masked_name' => CustomerIdentity::maskedName(User::find($refund->customer_user_id)),
            'decision_note' => $refund->decision_note,
            'decided_at' => $refund->decided_at?->toIso8601String(),
            'expires_at' => $refund->expires_at?->toIso8601String(),
        ];
    }

    /** القيد المزدوج + المحفظتان، مرة واحدة، تحت قفل صف الاسترداد. */
    private function execute(BusinessRefund $refund, User $merchant, string $approval): BusinessRefund
    {
        // AMIAL-BUSINESS-AML-001 — قبل أي حركة: حجز أمني أو منطقة لا تسمح بالاسترداد،
        // ثم فحص الغسل (التاجر يرسل، الدافع الأصلي يستلم). المعلّق يبقى معلّقاً إن حُجز.
        $this->assertFinancialEligibility($merchant->id, 'refund');
        $payerId = (int) $refund->customer_user_id;
        $refundAmount = MoneyService::normalize((string) $refund->amount);
        $amlContext = ['business_reference' => $refund->business_reference];
        // النداءُ في سطرٍ واحد: `AmlCoverageGuardTest` يقرأ النوعَ منه.
        $this->screenAml($merchant->id, $payerId, 'business_refund', $refundAmount, $refund->refund_ulid, $amlContext);

        return DB::transaction(function () use ($refund, $merchant, $approval) {
            $locked = BusinessRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BusinessRefund::COMPLETED) {
                return $locked;
            }
            if ($locked->status !== BusinessRefund::PENDING) {
                throw new BusinessGatewayException('REFUND_NOT_PENDING', 'هذا الطلب لم يعد بانتظار الموافقة', 409);
            }
            if ($locked->expires_at->isPast()) {
                throw new BusinessGatewayException('REFUND_EXPIRED', 'انتهت مهلة هذا الطلب', 409);
            }

            $amount = MoneyService::normalize((string) $locked->amount);
            $customerId = (int) $locked->customer_user_id;
            $merchantWallet = $this->ledger->getOrCreateUserWallet($merchant->id);
            $customerWallet = $this->ledger->getOrCreateUserWallet($customerId);

            $guard = app(\App\Services\FinancialGuardService::class);
            $guard->lockWalletsOrdered([$merchant->id, $customerId]);
            $guard->debit($merchant->id, $amount, "business_refund:{$locked->refund_ulid}");
            $guard->credit($customerId, $amount, "business_refund:{$locked->refund_ulid}");

            $entry = $this->ledger->post(
                sourceType: 'business_refund',
                sourceId: $locked->refund_ulid,
                description: "استرداد أميال أعمال ({$locked->business_reference})",
                lines: [
                    ['account' => $merchantWallet->account_code, 'direction' => 'debit', 'amount' => $amount],
                    ['account' => $customerWallet->account_code, 'direction' => 'credit', 'amount' => $amount],
                ],
                idempotencyKey: "business_refund_{$locked->refund_ulid}",
                createdByUserId: $merchant->id,
            );

            $locked->update([
                'status' => BusinessRefund::COMPLETED, 'approval' => $approval,
                'decided_by_user_id' => $merchant->id, 'decided_at' => now(), 'ledger_entry_ulid' => $entry->entry_ulid,
            ]);
            $this->outbox->record($locked->link, 'business_refund.completed', $this->present($locked));
            $this->audit->record([
                'actor_type' => 'merchant', 'actor_user_id' => $merchant->id,
                'subject_type' => 'business_refund', 'subject_id' => $locked->refund_ulid,
                'action' => 'BUSINESS_REFUND_COMPLETED', 'decision_code' => strtoupper($approval),
                'reason' => 'Refund of an Amial Business wallet payment', 'severity' => 'info',
                'context' => ['amount' => $amount, 'customer_user_id' => $customerId, 'reference' => $locked->business_reference],
            ]);
            $this->notifyCustomer($locked, $merchant);

            return $locked->fresh();
        }, 3);
    }

    private function withinMandate(BusinessLink $link, BusinessRefund $refund): bool
    {
        $per = MoneyService::normalize((string) $link->refund_auto_limit);
        $daily = MoneyService::normalize((string) $link->refund_daily_limit);
        if (!MoneyService::isPositive($per) || !MoneyService::isPositive($daily) || MoneyService::compare((string) $refund->amount, $per) > 0) {
            return false;
        }
        $today = (string) BusinessRefund::where('business_link_id', $link->id)->where('status', BusinessRefund::COMPLETED)
            ->where('approval', 'mandate')->where('decided_at', '>=', today())->sum('amount');

        return MoneyService::compare(MoneyService::add(MoneyService::normalize($today ?: '0'), (string) $refund->amount), $daily) <= 0;
    }

    private function replay(BusinessRefund $existing, string $fingerprint): BusinessRefund
    {
        if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
            throw new BusinessGatewayException('BUSINESS_REFERENCE_CONFLICT', 'هذا المرجع استُخدم لاسترداد آخر', 409);
        }

        return $existing;
    }

    private function assertOwner(User $merchant, BusinessRefund $refund): void
    {
        if ((int) $refund->link?->merchant_user_id !== (int) $merchant->id) {
            throw new BusinessGatewayException('NOT_FOUND', 'الطلب غير موجود', 404);
        }
    }

    private function notifyMerchant(User $merchant, BusinessRefund $refund): void
    {
        try {
            app(NotificationService::class)->dispatch($merchant, 'business_refund_request', 'طلب استرداد من أميال أعمال',
                'طلب استرداد '.MoneyService::normalize((string) $refund->amount).' ر.ي للعميل. وافق أو ارفض من «طلبات الاسترداد».',
                data: ['refund_id' => $refund->refund_ulid]);
        } catch (\Throwable $e) {
            Log::warning('Business refund merchant notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function notifyCustomer(BusinessRefund $refund, User $merchant): void
    {
        DB::afterCommit(function () use ($refund, $merchant) {
            try {
                $customer = User::find($refund->customer_user_id);
                if ($customer) {
                    $store = $merchant->merchant?->store_name ?: trim(($merchant->f_name ?? '').' '.($merchant->l_name ?? ''));
                    app(NotificationService::class)->dispatch($customer, 'refund_received', 'تم استرداد مبلغ',
                        'استلمت '.MoneyService::normalize((string) $refund->amount)." ر.ي إلى محفظتك من {$store}",
                        data: ['refund_id' => $refund->refund_ulid, 'amount' => (string) $refund->amount]);
                }
            } catch (\Throwable $e) {
                Log::warning('Business refund customer notification failed', ['error' => $e->getMessage()]);
            }
        });
    }
}
