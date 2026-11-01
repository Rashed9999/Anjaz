<?php

namespace App\Services\BusinessGateway;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessPaymentIntent;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MoneyService;
use App\Services\PaymentRequestService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — نوايا الدفع التي تطلبها أميال أعمال.
 *
 * الواجهة كلّها هنا: إنشاء · قراءة · إلغاء · تسجيل الدفع · إنهاء الصلاحية.
 *
 * الثوابت:
 *   1. لا ريال يتحرّك في هذا الصنف. المال يتحرّك في PaymentRequestService::settle()
 *      حين يدفع العميل، وهذا الصنف يُنادى من داخل معاملته ليسجّل أن الدفع
 *      يخصّ نيّةً ما.
 *   2. مرجع المنشأة فريد لكل رابط: إعادة الطلب نفسه تعيد النيّة نفسها، ومرجعٌ
 *      مكرر بمبلغ مختلف يُرفض (لا تُنشأ فاتورتان لمرجع واحد).
 *   3. النيّة لا تنجح إلا مرة: الحالة تُقرأ وتُكتب تحت قفل طلب الدفع.
 *   4. كل تغيّر حالة يكتب حدثاً في الـ outbox داخل المعاملة نفسها.
 */
class BusinessPaymentIntentService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(
        private readonly PaymentRequestService $paymentRequests,
        private readonly BusinessLinkService $links,
        private readonly BusinessOutbox $outbox,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param string $amount مبلغ عشري نصّي بحدّ أقصى منزلتين (يُتحقق منه في المتحكّم)
     */
    public function create(
        BusinessLink $link,
        string $businessReference,
        string $amount,
        string $currency,
        ?string $description = null,
        ?string $branchRef = null,
        ?string $deviceRef = null,
        ?int $ttlSeconds = null,
        string $purpose = BusinessPaymentIntent::PURPOSE_SALE,
        ?string $contactRef = null,
        ?string $payerPhone = null,
        string $origin = BusinessPaymentIntent::ORIGIN_BUSINESS,
    ): BusinessPaymentIntent {
        $merchant = $this->links->assertCanReceive($link);

        if (!in_array($purpose, [BusinessPaymentIntent::PURPOSE_SALE, BusinessPaymentIntent::PURPOSE_DEBT_COLLECTION], true)) {
            throw new BusinessGatewayException('PURPOSE_INVALID', 'غرض الدفع غير معروف');
        }
        if ($purpose === BusinessPaymentIntent::PURPOSE_DEBT_COLLECTION && ($contactRef === null || $contactRef === '')) {
            throw new BusinessGatewayException('CONTACT_REF_REQUIRED', 'تحصيل الدين يتطلب مرجع العميل في أميال أعمال');
        }

        $walletCurrency = (string) config('amial_business.wallet_currency', 'YER');
        if (strtoupper($currency) !== $walletCurrency) {
            throw new BusinessGatewayException(
                'CURRENCY_NOT_SUPPORTED',
                "الدفع بالمحفظة بعملة {$walletCurrency} فقط؛ حوّل المبلغ في أميال أعمال قبل الطلب",
            );
        }

        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new BusinessGatewayException('AMOUNT_INVALID', 'المبلغ يجب أن يكون موجباً');
        }

        $ttl = $this->resolveTtl($ttlSeconds);
        // الطلب نفسه = المبلغ والعملة والغرض والعميل والدافع نفسها. مرجعٌ مكرر
        // بأيٍّ منها مختلفاً تعارضٌ لا إعادة.
        $fingerprint = $purpose === BusinessPaymentIntent::PURPOSE_SALE && $payerPhone === null
            ? hash('sha256', $amount.'|'.$walletCurrency)
            : hash('sha256', implode('|', [$amount, $walletCurrency, $purpose, (string) $contactRef, (string) $payerPhone]));

        $existing = $this->findByReference($link, $businessReference);
        if ($existing) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            return DB::transaction(function () use (
                $link, $merchant, $businessReference, $amount, $walletCurrency,
                $fingerprint, $description, $branchRef, $deviceRef, $ttl,
                $purpose, $contactRef, $payerPhone, $origin,
            ) {
                // دافعٌ محدّد: يصل الطلب إلى تطبيقه، ولا يدفعه غيره (settle يفرض المستلم).
                $payer = null;
                if ($payerPhone !== null) {
                    $payer = $this->paymentRequests->resolveRecipient($payerPhone);
                    if (!$payer || !(bool) $payer->is_active) {
                        throw new BusinessGatewayException('PAYER_NOT_FOUND', 'لا يوجد حساب أميال باي نشط بهذا الرقم', 404);
                    }
                }
                $defaultNote = $purpose === BusinessPaymentIntent::PURPOSE_DEBT_COLLECTION
                    ? 'سداد دين آجل'
                    : "فاتورة {$businessReference}";
                $request = $this->paymentRequests->create(
                    $merchant,
                    $amount,
                    recipientPhone: $payer ? (string) $payer->phone : null,
                    note: $description ?: $defaultNote,
                    shareMethod: PaymentRequest::SHARE_QR,
                );
                if ($payer && (int) $request->recipient_user_id !== (int) $payer->id) {
                    throw new RuntimeException('لم يطابق طلب الدفع الدافع المحدد');
                }
                $expiresAt = now()->addSeconds($ttl);
                $request->update(['expires_at' => $expiresAt]);

                $intent = BusinessPaymentIntent::create([
                    'intent_ulid' => (string) Str::ulid(),
                    'business_link_id' => $link->id,
                    'business_reference' => $businessReference,
                    'purpose' => $purpose,
                    'origin' => $origin,
                    'contact_ref' => $contactRef,
                    'payer_user_id' => $payer?->id,
                    'payment_request_id' => $request->id,
                    'amount' => $amount,
                    'currency' => $walletCurrency,
                    'request_fingerprint' => $fingerprint,
                    'status' => BusinessPaymentIntent::STATUS_PENDING,
                    'expires_at' => $expiresAt,
                    'description' => $description,
                    'branch_ref' => $branchRef,
                    'device_ref' => $deviceRef,
                ]);

                $this->audit->record([
                    'actor_type' => 'system',
                    'actor_user_id' => $merchant->id,
                    'subject_type' => 'business_payment_intent',
                    'subject_id' => $intent->intent_ulid,
                    'action' => 'BUSINESS_PAYMENT_INTENT_CREATED',
                    'decision_code' => 'INTENT_OK',
                    'reason' => 'Amial Business requested a wallet payment QR',
                    'severity' => 'info',
                    'zone_code' => $merchant->zone_code ?? 'SOUTH',
                    'context' => [
                        'business_link' => $link->link_ulid,
                        'business_reference' => $businessReference,
                        'payment_request' => $request->request_ulid,
                        'amount' => $amount,
                    ],
                ]);

                return $intent;
            }, self::TX_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // طلبان متزامنان بالمرجع نفسه: الثاني يقرأ ما أنشأه الأول.
            $existing = $this->findByReference($link, $businessReference);
            if (!$existing) {
                throw new RuntimeException('BUSINESS_INTENT_RACE_UNRESOLVED');
            }

            return $this->replay($existing, $fingerprint);
        }
    }

    public function find(BusinessLink $link, string $intentUlid): BusinessPaymentIntent
    {
        $intent = BusinessPaymentIntent::where('business_link_id', $link->id)
            ->where('intent_ulid', $intentUlid)
            ->first();

        if (!$intent) {
            throw new BusinessGatewayException('INTENT_NOT_FOUND', 'نيّة الدفع غير موجودة', 404);
        }

        // نيّة انتهى وقتها ولم يمرّ عليها المجدوِل بعد تُغلق عند القراءة،
        // فلا تقرأ أميال أعمال «معلّقة» لرمزٍ لم يعد يُدفع.
        if ($intent->status === BusinessPaymentIntent::STATUS_PENDING && $intent->expires_at->isPast()) {
            $this->expire($intent->id);
            $intent->refresh();
        }

        return $intent;
    }

    public function cancel(BusinessLink $link, string $intentUlid): BusinessPaymentIntent
    {
        $intent = $this->find($link, $intentUlid);

        return DB::transaction(function () use ($intent, $link) {
            $request = PaymentRequest::whereKey($intent->payment_request_id)->lockForUpdate()->first();
            $locked = BusinessPaymentIntent::whereKey($intent->id)->lockForUpdate()->first();

            if ($locked->status === BusinessPaymentIntent::STATUS_SUCCEEDED) {
                throw new BusinessGatewayException(
                    'INTENT_ALREADY_SUCCEEDED',
                    'دُفعت هذه النيّة؛ الإلغاء بعد الدفع استرداد وليس إلغاء',
                    409,
                );
            }
            if ($locked->status !== BusinessPaymentIntent::STATUS_PENDING) {
                return $locked; // ملغاة أو منتهية سلفاً — الإلغاء متكرر الأمان.
            }

            if ($request && $request->status === 'pending') {
                $request->update(['status' => 'cancelled']);
            }

            $locked->update(['status' => BusinessPaymentIntent::STATUS_CANCELLED]);
            $this->outbox->record($link, 'payment_intent.cancelled', $this->eventData($locked));

            $this->audit->record([
                'actor_type' => 'system',
                'actor_user_id' => $link->merchant_user_id,
                'subject_type' => 'business_payment_intent',
                'subject_id' => $locked->intent_ulid,
                'action' => 'BUSINESS_PAYMENT_INTENT_CANCELLED',
                'decision_code' => 'INTENT_CANCELLED',
                'reason' => 'Amial Business cancelled an unpaid intent',
                'severity' => 'info',
            ]);

            return $locked->fresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * يُنادى من داخل معاملة settle() بعد أن صار طلب الدفع «مدفوعاً» وتحت قفله.
     *
     * ليس لكل طلب دفع نيّة؛ ما لا نيّة له يمرّ بلا أثر. أما طلبٌ له نيّة
     * ليست معلّقة فتناقضٌ لا يُمرَّر: يُرمى استثناء فتُرَدّ عملية الدفع
     * كلّها، لأن قبول المال لفاتورةٍ أُلغيت أسوأ من رفض الدفع.
     */
    /** @param string $merchantFee الرسم الذي تحمّله التاجر من هذه الدفعة (كما حسبه settle). */
    public function recordPaymentRequestPaid(PaymentRequest $request, string $transactionId, string $merchantFee = '0'): void
    {
        $intent = BusinessPaymentIntent::where('payment_request_id', $request->id)
            ->lockForUpdate()
            ->first();

        if (!$intent) {
            return;
        }

        if ($intent->status !== BusinessPaymentIntent::STATUS_PENDING) {
            throw new RuntimeException('نيّة الدفع المرتبطة بهذا الطلب لم تعد قائمة');
        }

        $intent->update([
            'status' => BusinessPaymentIntent::STATUS_SUCCEEDED,
            'paid_transaction_id' => $transactionId,
            'succeeded_at' => now(),
            'merchant_fee' => MoneyService::normalize($merchantFee),
        ]);

        $link = BusinessLink::find($intent->business_link_id);
        $this->outbox->record($link, 'payment_intent.succeeded', $this->eventData($intent->fresh()));
    }

    /**
     * AMIAL-BUSINESS-RECEIPT-001 — ما يُضاف إلى إيصالَي الدفع حين يكون الطلب نيّةً من أميال أعمال:
     * رقم الفاتورة هناك والمتجر، فيطابق العميلُ والتاجرُ السندَ بالفاتورة دون رقم معاملةٍ داخلي.
     * لا يحمل مبلغاً: المبالغ من المعاملة وحدها.
     *
     * @return array{reference_type:string, reference_id:int, metadata:array<string,string>}|null
     */
    public function receiptContext(PaymentRequest $request): ?array
    {
        $intent = BusinessPaymentIntent::where('payment_request_id', $request->id)->first();
        if (!$intent) {
            return null;
        }
        $merchant = BusinessLink::find($intent->business_link_id)?->merchant;
        $store = $merchant?->merchant?->store_name
            ?: trim(($merchant->f_name ?? '').' '.($merchant->l_name ?? ''));

        return [
            'reference_type' => 'amial_business_intent',
            'reference_id' => (int) $intent->id,
            'metadata' => array_filter([
                'business_reference' => $intent->purpose === BusinessPaymentIntent::PURPOSE_DEBT_COLLECTION
                    ? null : (string) $intent->business_reference,
                'business_purpose' => (string) $intent->purpose,
                'business_store' => $store ?: null,
                'business_contact_ref' => $intent->purpose === BusinessPaymentIntent::PURPOSE_DEBT_COLLECTION
                    ? (string) $intent->contact_ref : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /** يغلق النوايا المعلّقة التي انتهى وقتها. يُشغَّل من المجدوِل. */
    public function expireDue(int $limit = 200): int
    {
        $ids = BusinessPaymentIntent::where('status', BusinessPaymentIntent::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            if ($this->expire((int) $id)) {
                $count++;
            }
        }

        return $count;
    }

    /** الشكل الذي تراه أميال أعمال — في الردّ وفي الأحداث. */
    public function present(BusinessPaymentIntent $intent): array
    {
        $request = PaymentRequest::find($intent->payment_request_id);

        return [
            'intent_id' => $intent->intent_ulid,
            'business_reference' => $intent->business_reference,
            'status' => $intent->status,
            'amount' => MoneyService::normalize((string) $intent->amount),
            // AMIAL-BUSINESS-FEES-001 — ما يصل محفظة التاجر فعلاً، ورسمه، كما وقعا هنا.
            'fee' => MoneyService::normalize((string) ($intent->merchant_fee ?? '0')),
            'net_amount' => MoneyService::sub((string) $intent->amount, (string) ($intent->merchant_fee ?? '0')),
            'currency' => $intent->currency,
            'short_code' => $request?->short_code,
            'qr_payload' => $request?->publicUrl(),
            'expires_at' => $intent->expires_at?->toIso8601String(),
            'paid_transaction_id' => $intent->paid_transaction_id,
            'succeeded_at' => $intent->succeeded_at?->toIso8601String(),
            'branch_ref' => $intent->branch_ref,
            'device_ref' => $intent->device_ref,
            'purpose' => $intent->purpose ?? BusinessPaymentIntent::PURPOSE_SALE,
            'origin' => $intent->origin ?? BusinessPaymentIntent::ORIGIN_BUSINESS,
            'contact_ref' => $intent->contact_ref,
            'payer_masked_name' => $intent->payer_user_id
                ? CustomerIdentity::maskedName(User::find($intent->payer_user_id))
                : null,
        ];
    }

    private function expire(int $intentId): bool
    {
        return DB::transaction(function () use ($intentId) {
            $intent = BusinessPaymentIntent::whereKey($intentId)->first();
            if (!$intent) {
                return false;
            }
            // ترتيب القفل كما في settle(): طلب الدفع أولاً ثم النيّة.
            $request = PaymentRequest::whereKey($intent->payment_request_id)->lockForUpdate()->first();
            $locked = BusinessPaymentIntent::whereKey($intentId)->lockForUpdate()->first();

            if ($locked->status !== BusinessPaymentIntent::STATUS_PENDING) {
                return false;
            }
            if ($request && $request->status === 'paid') {
                return false; // دُفع في اللحظة نفسها؛ settle سجّل النجاح.
            }
            if ($locked->expires_at->isFuture() && $request?->status === 'pending') {
                return false;
            }

            if ($request && $request->status === 'pending') {
                $request->update(['status' => 'expired']);
            }
            $locked->update(['status' => BusinessPaymentIntent::STATUS_EXPIRED]);

            $link = BusinessLink::find($locked->business_link_id);
            $this->outbox->record($link, 'payment_intent.expired', $this->eventData($locked->fresh()));

            return true;
        }, self::TX_ATTEMPTS);
    }

    private function findByReference(BusinessLink $link, string $reference): ?BusinessPaymentIntent
    {
        return BusinessPaymentIntent::where('business_link_id', $link->id)
            ->where('business_reference', $reference)
            ->first();
    }

    private function replay(BusinessPaymentIntent $existing, string $fingerprint): BusinessPaymentIntent
    {
        if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
            throw new BusinessGatewayException(
                'BUSINESS_REFERENCE_CONFLICT',
                'هذا المرجع استُخدم لنيّة دفع بمبلغ أو عملة مختلفة',
                409,
            );
        }

        return $existing;
    }

    private function resolveTtl(?int $ttl): int
    {
        $bounds = (array) config('amial_business.intent_ttl_seconds');
        $ttl ??= (int) ($bounds['default'] ?? 300);

        if ($ttl < (int) ($bounds['min'] ?? 60) || $ttl > (int) ($bounds['max'] ?? 900)) {
            throw new BusinessGatewayException('TTL_OUT_OF_RANGE', 'مدة صلاحية الرمز خارج الحدود المسموحة');
        }

        return $ttl;
    }

    private function eventData(BusinessPaymentIntent $intent): array
    {
        $data = $this->present($intent);
        unset($data['qr_payload'], $data['short_code']);

        return $data;
    }
}
