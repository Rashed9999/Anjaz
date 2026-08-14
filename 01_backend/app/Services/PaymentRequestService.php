<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Jobs\SendTransactionNotificationJob;

use App\Models\PaymentRequest;
use App\Models\TransactionLimit;
use App\Models\User;
use App\Support\Phone;
use App\Traits\EnforcesFinancialPolicy;
use App\Traits\TransactionTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-PAYMENT-REQUESTS-001 — خدمة طلبات الدفع.
 *
 * عمليات:
 *   - create()      : ينشئ طلباً + short_code
 *   - findByCode()  : يعيد الطلب من الرمز (الـ public endpoint)
 *   - pay()         : يحرّك المال من الدافع للطالب (يستخدم guard للقفل)
 *   - cancel()      : يلغي طلب
 *   - listForUser() : قائمة المستخدم (مفلتر)
 *   - expireStale() : ينتهي صلاحية الطلبات (cron)
 */
class PaymentRequestService
{
    use TransactionTrait;
    use EnforcesFinancialPolicy;

    /** افتراضي صلاحية طلب الدفع: 7 أيام. */
    private const DEFAULT_EXPIRY_DAYS = 7;

    public function __construct(
        private readonly NotificationService $notif,
        private readonly RecipientVerificationService $recipientVerification,
        private readonly FeeService $fees,
    ) {}

    /**
     * إنشاء «طلب مال من عميل» بعد أن عاين الطالب هوية المستلم.
     *
     * هذا عقد مختلف عمداً عن create(): ذاك يبقى لفواتير التجار وروابط QR،
     * أما هذا فلا يهبط إلى رابط عند خطأ الرقم ولا يقبل مستلماً غير مؤكّد.
     */
    public function createDirect(
        User $requester,
        int $recipientId,
        string $verificationToken,
        string $amount,
        ?string $note = null,
    ): PaymentRequest {
        if ((int) (Helpers::get_business_settings('send_money_request_status') ?? 1) !== 1) {
            throw new RuntimeException('خدمة طلب المال غير مفعّلة حالياً');
        }

        $this->assertFinancialEligibility($requester->id);
        $this->enforceSanction($requester);

        // رمز أحادي الاستخدام يربط الطالب بالمستلم الذي عاينه في الشاشة.
        // لا يكفي إرسال recipient_id من الهاتف: يمكن تعديله خارج التطبيق.
        $this->recipientVerification->assertValidToken(
            $requester->id, $verificationToken, $recipientId,
        );

        $recipient = User::find($recipientId);
        $this->assertDirectParties($requester, $recipient);

        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('المبلغ يجب أن يكون موجباً');
        }

        // لا نُنشئ طلباً لا يستطيع أحد طرفيه إتمامه من الأصل.
        $this->enforceKycTier($recipient, 'send_money', $amount);
        $this->enforceKycTier($requester, 'receive_money', $amount);

        return DB::transaction(function () use ($requester, $recipient, $amount, $note) {
            $this->assertAndRecordRequestLimit($requester, $amount);

            $request = $this->create(
                requester: $requester,
                amount: $amount,
                recipientPhone: $recipient->phone,
                recipientName: $this->recipientVerification->maskName($recipient),
                note: $note,
                shareMethod: PaymentRequest::SHARE_DIRECT,
            );

            if (!$request->isDirect() || $request->recipient_user_id !== $recipient->id) {
                throw new RuntimeException('تعذّر تثبيت المستلم — أعد التحقق من الرقم');
            }

            $this->audit()->record([
                'actor_type' => 'user',
                'actor_user_id' => $requester->id,
                'subject_type' => 'payment_request',
                'subject_id' => $request->request_ulid,
                'action' => 'PAYMENT_REQUEST_CREATED',
                'decision_code' => 'REQUEST_PENDING_RECIPIENT_APPROVAL',
                'reason' => 'direct money request delivered to verified recipient',
                'idempotency_key' => "payment_request_create_{$request->request_ulid}",
                'zone_code' => $requester->zone_code ?? 'SOUTH',
                'severity' => 'info',
                'context' => [
                    'recipient_user_id' => $recipient->id,
                    'amount' => $amount,
                ],
            ]);

            return $request;
        }, 3);
    }

    /** إنشاء طلب جديد. */
    public function create(
        User $requester,
        string $amount,
        ?string $recipientPhone = null,
        ?string $recipientName = null,
        ?string $note = null,
        string $shareMethod = 'link',
        bool $isRecurring = false,
        ?string $recurringPeriod = null,
    ): PaymentRequest {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('المبلغ يجب أن يكون موجباً');
        }
        if (!in_array($shareMethod, PaymentRequest::SHARE_METHODS, true)) {
            throw new InvalidArgumentException('share_method غير صحيح');
        }
        if ($isRecurring && !in_array($recurringPeriod, PaymentRequest::PERIODS, true)) {
            throw new InvalidArgumentException('فترة التكرار غير صحيحة');
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-REQUEST-DIRECT-002 — **لماذا بقي «طلبُ المال» رابطاً.**
        //
        // كان السطر: `User::where('phone', $recipientPhone)->value('id')`
        // — **تطابقٌ حرفيّ**. والرقمُ الواحد يصل بأربع صيغ:
        // `+967777100001` و`967777100001` و`00967777100001` و`777100001`.
        //
        // فمن يكتب `777…` وحسابُ صاحبه مسجَّلٌ `+967777…` لا يُطابَق:
        // يُرجع البحثُ `null`، ويُنشأ الطلبُ **بلا مستلم**، فلا إشعارَ
        // يُرسَل ولا يظهر في «الطلبات الواردة» عند أحد — ولا يبقى للطالب
        // إلّا الرابط.
        //
        // والميزةُ المباشرةُ كانت مبنيّةً كاملةً خلف هذا السطر. **مبنيٌّ
        // ولا يُوصَل إليه** — ولا خطأَ في أيّ سجلّ: الطلبُ يُنشأ بنجاح،
        // والاستجابةُ ٢٠٠، والرقمُ مكتوبٌ صحيحاً في `recipient_phone`.
        //
        // (وهو الدرسُ المكتوب في `OtpPolicy`: «مقارنةٌ حرفيّةٌ تجعل الحساب
        // يعمل من شاشةٍ ويُرفض من أخرى» — وقع مرّتين.)
        $recipientId = null;

        if ($recipientPhone) {
            $recipientId = $this->resolveRecipient($recipientPhone)?->id;

            // **ولا يطلب المرءُ من نفسه.** بلا هذا الفحص يُنشأ طلبٌ يظهر
            // في صندوقَي الوارد والصادر معاً، ودفعُه يخصم ويودع في المحفظة
            // نفسها — عمليّةٌ صافيها صفرٌ ورسمُها حقيقيّ.
            if ($recipientId === (int) $requester->id) {
                throw new InvalidArgumentException('لا يمكنك طلب مبلغ من نفسك');
            }
        }

        // **والطريقةُ تتبع الواقع لا النيّة**: من وصل طلبُه إلى حسابٍ في
        // أميال فطلبُه «مباشر»، ومن لم يصل فرابطٌ يُشارَك. ولا يُترك
        // للواجهة أن تُعلن «مباشر» ثمّ لا يصل شيء.
        $shareMethod = $recipientId !== null && $shareMethod !== PaymentRequest::SHARE_QR
            ? PaymentRequest::SHARE_DIRECT
            : $shareMethod;

        $request = PaymentRequest::create([
            'request_ulid' => (string) Str::ulid(),
            'short_code' => $this->generateShortCode(),
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipientId,
            'recipient_phone' => $recipientPhone,
            'recipient_name' => $recipientName,
            'amount' => $amount,
            'note' => $note,
            'share_method' => $shareMethod,
            'status' => 'pending',
            'expires_at' => now()->addDays(self::DEFAULT_EXPIRY_DAYS),
            'is_recurring' => $isRecurring,
            'recurring_period' => $isRecurring ? $recurringPeriod : null,
            'zone_code' => $requester->zone_code ?? 'SOUTH',
        ]);

        // AMIAL-REQUEST-DIRECT-001: يُخطَر المستلم فور الإنشاء.
        //
        // **العطل الذي يعالجه:** كان الطلب يُربَط بالمستلم (`recipient_user_id`)
        // ثم **لا يُشعَر بشيء**. الإشعار الوحيد كان بعد الدفع — أي بعد فوات
        // الأوان. فيُنشئ الطالبُ طلباً ويظنّ أنه وصل، والمستلم لا يعلم بشيء،
        // ولا مكان في التطبيق يعرض «طلبات واردة».
        //
        // ولذلك بدا للمستخدم أن «طلب المال» صار رابطاً يُشارَك يدوياً بينما
        // الطريقة المعتادة — يصل ويوافق أو يرفض — قائمةٌ في الخلفية ومقطوعة
        // عن الواجهة.
        $this->notifyRecipientOfNewRequest($request, $requester);

        return $request;
    }

    /**
     * **مَن يستقبل الطلبَ لهذا الرقم — بابٌ واحدٌ للإنشاء وللفحص المسبق.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وُلدت هذه الدالّة من عطلٍ صنعتُه أنا: كتبتُ نقطةَ فحصٍ تسأل «أهذا
     * الرقم مشترك؟» وقيّدتُها بـ`type = CUSTOMER_TYPE`، بينما `create()`
     * لا تُقيّد بشيء. فكانت الشاشةُ تقول «غير مشترك — يُشارَك برابط»
     * والخادمُ يربط الطلبَ بصاحبه ويُشعره.
     *
     * **وهو نمطُ العطل نفسُه الذي أُصلحه هنا**: قاعدتان لسؤالٍ واحد.
     * فصارت القاعدةُ في موضعٍ واحد، والسائلان يمرّان به.
     *
     * ولا يُستثنى إلّا حسابُ الإدارة: المنصّةُ ليست طرفاً يُطلب منه مال.
     */
    public function resolveRecipient(string $phone): ?User
    {
        return User::whereIn('phone', Phone::variants($phone))
            ->where('type', '!=', ADMIN_TYPE)
            ->first([
                'id', 'f_name', 'l_name', 'phone', 'is_active',
                'is_kyc_verified', 'type', 'role', 'zone_code',
            ]);
    }

    /**
     * إخطار المستلم بطلبٍ جديد — إن كان مسجَّلاً.
     *
     * غير مُعطِّل عمداً: فشلُ إشعارٍ لا يجوز أن يمنع إنشاء الطلب. والطلب يبقى
     * ظاهراً للمستلم في «الطلبات الواردة» ولو ضاع الإشعار — فالقائمة هي
     * مصدر الحقيقة، والإشعار تنبيهٌ عليها.
     */
    private function notifyRecipientOfNewRequest(PaymentRequest $request, User $requester): void
    {
        if (!$request->recipient_user_id) {
            return; // رقمٌ غير مسجَّل — يُشارَك بالرابط
        }

        try {
            $recipient = User::find($request->recipient_user_id);
            if (!$recipient) {
                return;
            }

            $this->notif->dispatch(
                $recipient,
                'payment_request_received',
                'طلب دفع جديد',
                trim(($requester->f_name ?? 'أحدهم')) . ' يطلب منك '
                    . Helpers::money($request->amount) . ' ر.ي'
                    . ($request->note ? " — {$request->note}" : ''),
                data: [
                    'amount' => (string) $request->amount,
                    'short_code' => $request->short_code,
                    'request_ulid' => $request->request_ulid,
                    'requester_name' => $requester->f_name,
                    'requester_phone' => $requester->phone,
                ],
            );

            // الإشعار الداخلي هو مصدر الحقيقة، وFCM هو التنبيه الفوري.
            // يخرج بعد نجاح المعاملة حتى لا يرن هاتفٌ لطلبٍ تراجع تخزينه.
            DB::afterCommit(function () use ($recipient, $request): void {
                SendTransactionNotificationJob::dispatch(
                    userId: $recipient->id,
                    amount: (string) $request->amount,
                    transactionType: 'request_money',
                    notificationType: 'payment_request_received',
                    transactionId: $request->request_ulid,
                );
            });
        } catch (\Throwable $e) {
            \Log::warning('notifyRecipientOfNewRequest failed', [
                'request' => $request->id, 'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * المستلم يرفض الطلب.
     *
     * ويُفصل الرفض عن الإلغاء: الإلغاء يفعله **الطالب** فيسحب طلبه، والرفض
     * يفعله **المستلم**. والطالب يحتاج أن يعرف أيّهما وقع — فالمرفوض لا
     * يُعاد إرساله، والملغى قد يُعاد.
     */
    public function decline(User $recipient, PaymentRequest $request, ?string $reason = null): PaymentRequest
    {
        // الدفع والرفض قد يصلان في اللحظة نفسها من هاتفين/إعادتَي ضغط.
        // قفل الصف يمنع أن يكتب الرفض فوق حالة paid بعد تحرّك المال.
        $declined = DB::transaction(function () use ($recipient, $request, $reason) {
            $locked = PaymentRequest::whereKey($request->getKey())->lockForUpdate()->first();
            if (!$locked || $locked->recipient_user_id !== $recipient->id) {
                throw new InvalidArgumentException('هذا الطلب ليس موجّهاً إليك');
            }
            if ($locked->status !== 'pending' || !$locked->isActive()) {
                throw new RuntimeException('لا يمكن رفض طلب غير نشط');
            }

            // لا نكتب سبب الرفض فوق ملاحظة الطالب؛ كلاهما معلومة مختلفة.
            $locked->update(['status' => 'declined']);

            $this->audit()->record([
                'actor_type' => 'user',
                'actor_user_id' => $recipient->id,
                'subject_type' => 'payment_request',
                'subject_id' => $locked->request_ulid,
                'action' => 'PAYMENT_REQUEST_DECLINED',
                'decision_code' => 'REQUEST_DECLINED_BY_RECIPIENT',
                'reason' => $reason ? mb_substr($reason, 0, 255) : 'recipient declined',
                'zone_code' => $recipient->zone_code ?? 'SOUTH',
                'severity' => 'info',
            ]);

            return $locked->fresh();
        }, 3);

        // الطالب ينتظر جواباً؛ وصمتُ الرفض يجعله يعيد الإرسال ويتّصل.
        try {
            $requester = User::find($declined->requester_user_id);
            if ($requester) {
                $this->notif->dispatch(
                    $requester,
                    'payment_request_declined',
                    'رُفض طلبك',
                    trim(($recipient->f_name ?? 'المستلم')) . ' رفض طلب '
                        . Helpers::money($declined->amount) . ' ر.ي'
                        . ($reason ? " — {$reason}" : ''),
                    data: ['short_code' => $declined->short_code],
                );

                SendTransactionNotificationJob::dispatch(
                    userId: $requester->id,
                    amount: (string) $declined->amount,
                    transactionType: 'denied_money',
                    notificationType: 'payment_request_declined',
                    transactionId: $declined->request_ulid,
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('decline notification failed', ['err' => $e->getMessage()]);
        }

        return $declined;
    }

    /** يعيد الطلب بالرمز القصير (يستخدم في public endpoint). */
    public function findByCode(string $shortCode): ?PaymentRequest
    {
        return PaymentRequest::where('short_code', strtoupper($shortCode))->first();
    }

    /** دفع فاتورة/رابط عام — يحافظ على العقد القديم بلا رسم مفاجئ. */
    public function pay(User $payer, PaymentRequest $request): array
    {
        return $this->settle($payer, $request, applySendMoneyFee: false);
    }

    /**
     * المستلم المحدد يوافق على طلب المال الوارد.
     *
     * هذا المسار أشد من دفع رابط عام: الطرفان عميلان موثقان، والطلب موجّه
     * إلى الدافع نفسه، ورسم التحويل وحدوده يطبّقان كما في «إرسال المال».
     */
    public function payDirect(User $payer, PaymentRequest $request): array
    {
        if ((int) (Helpers::get_business_settings('send_money_status') ?? 1) !== 1) {
            throw new RuntimeException('خدمة تحويل المال غير مفعّلة حالياً');
        }

        if (!$request->isDirect()) {
            throw new InvalidArgumentException('هذا ليس طلب مال مباشراً');
        }

        $requester = User::find($request->requester_user_id);
        $this->assertDirectParties($requester, $payer);

        if ($request->recipient_user_id !== $payer->id) {
            throw new InvalidArgumentException('هذا الطلب موجّه لشخص آخر');
        }

        return $this->settle($payer, $request, applySendMoneyFee: true);
    }

    /** الرسم والإجمالي اللذان يجب أن يراهما المستلم قبل الموافقة. */
    public function directQuote(User $payer, PaymentRequest $request): array
    {
        if (!$request->isDirect() || $request->recipient_user_id !== $payer->id) {
            throw new InvalidArgumentException('هذا الطلب ليس موجّهاً إليك');
        }

        $fee = $this->fees->calculate('SEND_MONEY', (string) $request->amount, [
            'zone_code' => $payer->zone_code ?? 'SOUTH',
            'applies_to' => 'customer',
        ]);

        return [
            'fee' => (string) $fee['fee'],
            'total_due' => (string) $fee['total_debit'],
            'recipient_credit' => (string) $fee['net_credit'],
            'fee_bearer' => (string) $fee['bearer'],
        ];
    }

    /** تسوية ذرية: حالة الطلب + المحافظ + سجل العمليات + الدفتر + التدقيق. */
    private function settle(
        User $payer,
        PaymentRequest $request,
        bool $applySendMoneyFee,
    ): array {
        $this->assertPayableBy($payer, $request);

        $amount = MoneyService::normalize((string) $request->amount);
        $requesterId = (int) $request->requester_user_id;
        $requester = User::find($requesterId);

        if (!$requester || !(bool) $requester->is_active) {
            throw new RuntimeException('حساب طالب المال غير متاح حالياً');
        }
        if (!(bool) $payer->is_active) {
            throw new RuntimeException('حسابك موقوف حالياً');
        }

        // حراسة كل باب يصل إلى المال، لا باب واجهة التطبيق وحده.
        $this->assertFinancialEligibility($payer->id);
        $this->enforceSanction($payer);
        if ($applySendMoneyFee) {
            $this->enforceKycTier($payer, 'send_money', $amount);
        }
        $this->enforceZone($requester);
        $this->enforceSanction($requester);
        $this->screenAml(
            $payer->id,
            $requesterId,
            SEND_MONEY,
            $amount,
            $request->request_ulid,
            ['source' => 'payment_request'],
        );

        $feeData = $applySendMoneyFee
            ? $this->fees->calculate('SEND_MONEY', $amount, [
                'zone_code' => $payer->zone_code ?? 'SOUTH',
                'applies_to' => 'customer',
            ])
            : [
                'fee' => '0.0000',
                'total_debit' => $amount,
                'net_credit' => $amount,
                'bearer' => 'sender',
                'scheme_id' => null,
                'scheme_version' => null,
            ];

        $fee = MoneyService::normalize((string) $feeData['fee']);
        $totalDebit = MoneyService::normalize((string) $feeData['total_debit']);
        $recipientCredit = MoneyService::normalize((string) $feeData['net_credit']);
        $feeBearer = (string) ($feeData['bearer'] ?? 'sender');
        if (!MoneyService::isPositive($recipientCredit)) {
            throw new RuntimeException('الرسوم المطبّقة تستنفد مبلغ الطلب');
        }
        $idempotencyKey = "payment_request_pay_{$request->request_ulid}";

        $result = DB::transaction(function () use (
            $payer, $request, $requester, $requesterId, $amount, $fee, $totalDebit,
            $recipientCredit, $feeBearer, $feeData, $idempotencyKey,
            $applySendMoneyFee,
        ) {
            $locked = PaymentRequest::whereKey($request->getKey())->lockForUpdate()->first();
            if (!$locked || !$locked->isActive()) {
                throw new RuntimeException('هذا الطلب مدفوع أو غير صالح');
            }

            if ($locked->recipient_user_id && $locked->recipient_user_id !== $payer->id) {
                throw new InvalidArgumentException('هذا الطلب موجّه لشخص آخر');
            }

            if ($applySendMoneyFee) {
                $this->assertAndRecordPaymentLimit($payer, $amount);
            }

            $this->guard()->lockWalletsOrdered([$payer->id, $requesterId]);
            $payerWallet = $this->guard()->debit(
                $payer->id, $totalDebit, "payment_request:{$locked->request_ulid}",
            );
            $requesterWallet = $this->guard()->credit(
                $requesterId, $recipientCredit, "payment_request:{$locked->request_ulid}",
            );
            if ($applySendMoneyFee) {
                app(KycTierService::class)->assertBalanceAllowed(
                    $requester, (string) $requesterWallet->current_balance,
                );
            }

            $txId = $this->newTransactionId();

            $this->recordTransaction([
                'user_id' => $payer->id,
                'transaction_id' => $txId,
                'transaction_type' => SEND_MONEY,
                'debit' => $amount,
                'credit' => '0',
                'charge' => $feeBearer === 'sender' ? $fee : '0',
                'amount' => $amount,
                'balance' => (string) $payerWallet->current_balance,
                'from_user_id' => $payer->id,
                'to_user_id' => $requesterId,
                'note' => $locked->note,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $payer->zone_code ?? 'SOUTH',
                'fee_scheme_id' => $feeData['scheme_id'] ?? null,
                'fee_scheme_version' => $feeData['scheme_version'] ?? null,
            ]);

            $this->recordTransaction([
                'user_id' => $requesterId,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $txId,
                'transaction_type' => RECEIVED_MONEY,
                'debit' => '0',
                'credit' => $recipientCredit,
                'charge' => $feeBearer === 'receiver' ? $fee : '0',
                'amount' => $amount,
                'balance' => (string) $requesterWallet->current_balance,
                'from_user_id' => $payer->id,
                'to_user_id' => $requesterId,
                'note' => $locked->note,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $requesterWallet->zone_code ?? 'SOUTH',
            ]);

            if (MoneyService::isPositive($fee)) {
                $adminId = Helpers::get_admin_id();
                $adminWallet = $this->guard()->creditAdminCharge($adminId, $fee, [
                    'source_type' => 'payment_request',
                    'transaction_id' => $txId,
                    'from_user_id' => $payer->id,
                    'zone_code' => $payer->zone_code ?? 'SOUTH',
                ]);

                $this->recordTransaction([
                    'user_id' => $adminId,
                    'transaction_id' => $this->newTransactionId(),
                    'ref_trans_id' => $txId,
                    'transaction_type' => ADMIN_CHARGE,
                    'debit' => '0',
                    'credit' => $fee,
                    'charge' => '0',
                    'amount' => $fee,
                    'balance' => (string) $adminWallet->charge_earned,
                    'from_user_id' => $payer->id,
                    'to_user_id' => $adminId,
                    'idempotency_key' => $idempotencyKey,
                    'decision_code' => 'TX_OK',
                    'zone_code' => 'SOUTH',
                ]);
            }

            $locked->update([
                'status' => 'paid',
                'paid_by_user_id' => $payer->id,
                'paid_transaction_id' => $txId,
                'paid_at' => now(),
            ]);

            $this->ledgerTransferWithFee(
                fromUserId: $payer->id,
                toUserId: $requesterId,
                grossAmount: $totalDebit,
                feeAmount: $fee,
                sourceType: 'payment_request',
                sourceId: $locked->request_ulid,
                description: 'دفع طلب مال',
                idempotencyKey: $idempotencyKey,
                metadata: ['transaction_id' => $txId],
            );

            $this->audit()->record([
                'actor_type' => 'user',
                'actor_user_id' => $payer->id,
                'subject_type' => 'payment_request',
                'subject_id' => $locked->request_ulid,
                'action' => 'PAYMENT_REQUEST_PAID',
                'decision_code' => 'TX_OK',
                'reason' => 'recipient approved money request',
                'transaction_id' => $txId,
                'idempotency_key' => $idempotencyKey,
                'zone_code' => $payer->zone_code ?? 'SOUTH',
                'severity' => 'info',
                'context' => [
                    'requester_user_id' => $requesterId,
                    'amount' => $amount,
                    'fee' => $fee,
                    'fee_bearer' => $feeBearer,
                    'recipient_credit' => $recipientCredit,
                ],
            ]);

            return [
                'request' => $locked->fresh(),
                'transaction_id' => $txId,
                'amount' => $amount,
                'fee' => $fee,
                'total_debited' => $totalDebit,
                'recipient_credited' => $recipientCredit,
                'fee_bearer' => $feeBearer,
            ];
        }, 3);

        $this->safeIssueReceipts([
            'from_user_id' => $payer->id,
            'to_user_id' => $requesterId,
            'reference_transaction_id' => $result['transaction_id'],
            'receipt_type' => 'send_money',
            'amount' => $amount,
            'fee' => $fee,
            'fee_bearer' => $result['fee_bearer'],
            'zone_code' => $payer->zone_code ?? 'SOUTH',
        ]);
        $this->maybeAnalyzeMerchantRisk($requesterId, $payer->id, $amount);
        $this->dispatchPaidNotifications($result['request'], $payer);
        $this->dispatchPaidPushNotifications($result['request'], $payer, $result['transaction_id']);

        return $result;
    }

    /** @param User|null $requester */
    private function assertDirectParties(?User $requester, ?User $recipient): void
    {
        if (!$requester || (int) $requester->type !== CUSTOMER_TYPE) {
            throw new InvalidArgumentException('طلب المال المباشر متاح للعملاء فقط');
        }
        if (!$recipient || (int) $recipient->type !== CUSTOMER_TYPE) {
            throw new InvalidArgumentException('رقم العميل غير موجود');
        }
        if ((int) $requester->id === (int) $recipient->id) {
            throw new InvalidArgumentException('لا يمكنك طلب مبلغ من نفسك');
        }
        if (!(bool) $requester->is_active || !(bool) $recipient->is_active) {
            throw new RuntimeException('أحد الحسابين موقوف حالياً');
        }
        if ((int) $requester->is_kyc_verified !== 1) {
            throw new RuntimeException('أكمل توثيق حسابك قبل طلب المال');
        }
        if ((int) $recipient->is_kyc_verified !== 1) {
            throw new RuntimeException('حساب العميل غير موثّق لاستقبال الطلب');
        }
        if (($requester->zone_code ?? 'UNKNOWN') !== 'SOUTH'
            || ($recipient->zone_code ?? 'UNKNOWN') !== 'SOUTH') {
            throw new RuntimeException('الخدمة غير متاحة لأحد الحسابين في منطقته الحالية');
        }
    }

    private function assertPayableBy(User $payer, PaymentRequest $request): void
    {
        if (!$request->isActive()) {
            throw new RuntimeException(match ($request->status) {
                'paid' => 'هذا الطلب مدفوع سابقاً',
                'cancelled' => 'هذا الطلب ملغى',
                'declined' => 'هذا الطلب مرفوض',
                'expired' => 'انتهت صلاحية الطلب',
                default => 'الطلب غير صالح',
            });
        }

        if ($payer->id === $request->requester_user_id) {
            throw new InvalidArgumentException('لا يمكنك دفع طلب أنشأته بنفسك');
        }
        if ($request->recipient_user_id && $request->recipient_user_id !== $payer->id) {
            throw new InvalidArgumentException('هذا الطلب موجّه لشخص آخر');
        }
    }

    private function assertAndRecordRequestLimit(User $user, string $amount): void
    {
        $this->assertAndRecordLegacyLimit(
            $user, $amount, 'send_money_request', 'customer_send_money_request_limit',
        );
    }

    private function assertAndRecordPaymentLimit(User $user, string $amount): void
    {
        $this->assertAndRecordLegacyLimit(
            $user, $amount, 'send_money', 'customer_send_money_limit',
        );
    }

    /**
     * يحافظ على حدود 6cash المنشورة، لكن تحت قفل الصف وداخل المعاملة.
     * غياب/تعطيل الإعداد يعني أن هذا الحد القديم غير مفروض.
     */
    private function assertAndRecordLegacyLimit(
        User $user,
        string $amount,
        string $type,
        string $settingKey,
    ): void {
        $config = Helpers::get_business_settings($settingKey);
        if (!is_array($config) || (int) ($config['status'] ?? 0) !== 1) {
            return;
        }

        // لا يوجد unique قديم على (user_id,type). قفل المستخدم يجعل
        // firstOrCreate آمناً بين طلبين متزامنين من المسار الجديد.
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();

        TransactionLimit::firstOrCreate(
            ['user_id' => $user->id, 'type' => $type],
            [
                'todays_count' => 0,
                'todays_amount' => 0,
                'this_months_count' => 0,
                'this_months_amount' => 0,
            ],
        );

        $limit = TransactionLimit::where([
            'user_id' => $user->id,
            'type' => $type,
        ])->lockForUpdate()->firstOrFail();

        $check = Helpers::check_customer_transaction_limit(
            $user, (float) $amount, $type, $config,
        );
        if (!($check['status'] ?? false)) {
            $message = match ($check['message'] ?? '') {
                'maximum amount per transaction exceeded' => 'المبلغ يتجاوز الحد المسموح للعملية',
                'transaction limit per day exceeded' => 'تجاوزت عدد العمليات المسموح اليوم',
                'total transaction amount per day exceeded' => 'تجاوزت إجمالي المبلغ المسموح اليوم',
                'transaction limit per month exceeded' => 'تجاوزت عدد العمليات المسموح هذا الشهر',
                'total transaction amount per month exceeded' => 'تجاوزت إجمالي المبلغ المسموح هذا الشهر',
                default => 'تجاوزت حد العملية المسموح',
            };
            throw new RuntimeException($message);
        }

        $limit->refresh();
        $limit->update([
            'todays_count' => $limit->todays_count + 1,
            'todays_amount' => (float) $limit->todays_amount + (float) $amount,
            'this_months_count' => $limit->this_months_count + 1,
            'this_months_amount' => (float) $limit->this_months_amount + (float) $amount,
        ]);
    }

    /** الطالب يلغي طلبه. */
    public function cancel(User $requester, PaymentRequest $request): PaymentRequest
    {
        return DB::transaction(function () use ($requester, $request) {
            $locked = PaymentRequest::whereKey($request->getKey())->lockForUpdate()->first();
            if (!$locked || $locked->requester_user_id !== $requester->id) {
                throw new InvalidArgumentException('لا يمكنك إلغاء طلب لم تنشئه');
            }
            if ($locked->status !== 'pending' || !$locked->isActive()) {
                throw new RuntimeException('لا يمكن إلغاء طلب غير نشط');
            }

            $locked->update(['status' => 'cancelled']);
            $this->audit()->record([
                'actor_type' => 'user',
                'actor_user_id' => $requester->id,
                'subject_type' => 'payment_request',
                'subject_id' => $locked->request_ulid,
                'action' => 'PAYMENT_REQUEST_CANCELLED',
                'decision_code' => 'REQUEST_CANCELLED_BY_REQUESTER',
                'reason' => 'requester cancelled pending money request',
                'zone_code' => $requester->zone_code ?? 'SOUTH',
                'severity' => 'info',
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** قائمة الطلبات الخاصة بالمستخدم (صادرة أو واردة). */
    public function listForUser(
        User $user,
        string $direction = 'outgoing', // outgoing | incoming
        ?string $status = null,
        int $page = 1,
        int $perPage = 20,
    ) {
        $q = PaymentRequest::query();
        if ($direction === 'incoming') {
            $q->where('recipient_user_id', $user->id);
        } else {
            $q->where('requester_user_id', $user->id);
        }
        if ($status) $q->where('status', $status);

        $paginated = $q->with(['requester:id,f_name,l_name,phone', 'recipient:id,f_name,l_name,phone'])
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // ══════════════════════════════════════════════════════════════
        //  **اسمُ الطرف الآخر — AMIAL-REQUEST-DIRECT-003.**
        //
        //  كانت الصفوفُ تُرجَع خاماً من الجدول، وشاشةُ «الطلبات الواردة»
        //  تقرأ `requester_name` — **وهو ليس عموداً**. فكان كلُّ طلبٍ
        //  واردٍ يظهر باسم «مستخدم يطلب منك»: لا اسم، ولا رقم.
        //
        //  ومن يُطلب منه مالٌ ولا يعرف مَن يطلبه لا يوافق — فيبقى الطلبُ
        //  معلّقاً، ويعود الطالبُ إلى واتساب. **وهكذا يعود الرابطُ من
        //  بابٍ آخر.**
        // ══════════════════════════════════════════════════════════════
        $paginated->getCollection()->transform(function (PaymentRequest $r) use ($user, $direction) {
            $r->setAttribute('requester_label', $this->partyLabel(
                $r->requester, null, null));

            // الطلب الصارم خزّن الاسم المقنّع الذي وافق عليه الطالب؛ لا
            // نكشف الاسم الكامل لاحقاً عبر قائمة الصادرة فنُبطل التقنيع.
            $recipientForLabel = $direction === 'outgoing'
                && trim((string) $r->recipient_name) !== ''
                    ? null
                    : $r->recipient;
            $r->setAttribute('recipient_label', $this->partyLabel(
                $recipientForLabel, $r->recipient_name, $r->recipient_phone));

            $r->setAttribute('requester_phone', $r->requester?->phone);

            if ($direction === 'incoming' && $r->isDirect() && $r->status === 'pending') {
                try {
                    $quote = $this->directQuote($user, $r);
                    $r->setAttribute('fee', $quote['fee']);
                    $r->setAttribute('total_due', $quote['total_due']);
                    $r->setAttribute('recipient_credit', $quote['recipient_credit']);
                    $r->setAttribute('fee_bearer', $quote['fee_bearer']);
                } catch (\Throwable $e) {
                    // لا نكذب بصفر: غياب التسعير يُقال صراحةً للواجهة.
                    $r->setAttribute('fee', null);
                    $r->setAttribute('total_due', null);
                    $r->setAttribute('recipient_credit', null);
                    $r->setAttribute('fee_bearer', null);
                }
            }

            return $r;
        });

        return $paginated;
    }

    /**
     * اسمُ طرفٍ كما يُعرض: حسابُه، ثمّ ما كُتب يدويّاً، ثمّ رقمُه.
     * ولا يُرجَع فارغاً — «مستخدم» بلا هويّة لا يُوافَق عليها.
     */
    private function partyLabel(?User $user, ?string $typed, ?string $phone): string
    {
        if ($user) {
            $name = trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''));

            if ($name !== '') {
                return $name;
            }

            if (trim((string) $user->phone) !== '') {
                return (string) $user->phone;
            }
        }

        if (trim((string) $typed) !== '') {
            return (string) $typed;
        }

        return trim((string) $phone) !== '' ? (string) $phone : 'مستخدم أميال';
    }

    /** ينتهي صلاحية الطلبات (يُستدعى من cron). */
    public function expireStale(int $limit = 200): int
    {
        $count = 0;
        PaymentRequest::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->limit($limit)
            ->get()
            ->each(function ($req) use (&$count) {
                $req->update(['status' => 'expired']);
                $count++;
            });
        return $count;
    }

    // ============ Private ============

    /**
     * يولّد رمزاً قصيراً فريداً (6 أحرف، A-Z 0-9 بلا I,O,0,1 لتجنّب الالتباس).
     */
    private function generateShortCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 حرف بلا التباس
        for ($i = 0; $i < 12; $i++) {
            $code = '';
            for ($j = 0; $j < 6; $j++) {
                $code .= $alphabet[random_int(0, 31)];
            }
            if (!PaymentRequest::where('short_code', $code)->exists()) {
                return $code;
            }
        }
        throw new RuntimeException('تعذّر توليد رمز فريد');
    }

    /** إشعارات الطرفين عند الدفع. */
    private function dispatchPaidNotifications(PaymentRequest $request, User $payer): void
    {
        try {
            // إشعار الطالب: استلم المال
            $requester = User::find($request->requester_user_id);
            if ($requester) {
                $this->notif->dispatch(
                    $requester,
                    'transfer_received',
                    'استلمت دفعة لطلبك',
                    "تم دفع " . Helpers::money($request->amount) . " ر.ي من {$payer->f_name} لطلبك (#{$request->short_code})",
                    data: [
                        'amount' => (string)$request->amount,
                        'short_code' => $request->short_code,
                        'payer_phone' => $payer->phone,
                    ],
                );
            }

            // إشعار الدافع: نجاح الدفع
            $this->notif->dispatch(
                $payer,
                'transfer_sent',
                'دفعت طلباً',
                "دفعت " . Helpers::money($request->amount) . " ر.ي إلى {$requester?->f_name}",
                data: [
                    'amount' => (string)$request->amount,
                    'short_code' => $request->short_code,
                ],
            );
        } catch (\Throwable $e) {
            logger()->warning('Payment request notifications failed: ' . $e->getMessage());
        }
    }

    /** تنبيه FCM للطرفين بعد أن استقر المال فعلاً. */
    private function dispatchPaidPushNotifications(
        PaymentRequest $request,
        User $payer,
        string $transactionId,
    ): void {
        try {
            SendTransactionNotificationJob::dispatch(
                userId: $payer->id,
                amount: (string) $request->amount,
                transactionType: SEND_MONEY,
                notificationType: 'transfer_sent',
                transactionId: $transactionId,
            );
            SendTransactionNotificationJob::dispatch(
                userId: (int) $request->requester_user_id,
                amount: (string) $request->amount,
                transactionType: RECEIVED_MONEY,
                notificationType: 'transfer_received',
                transactionId: $transactionId,
            );
        } catch (\Throwable $e) {
            // المال استقرّ والإشعار قناة ثانوية. لا نعيد 500 بعد الخصم
            // فيظن العميل أن الدفع فشل ويحاول مرة أخرى.
            \Log::warning('Payment request push dispatch failed (non-fatal)', [
                'request' => $request->request_ulid,
                'transaction_id' => $transactionId,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }
}
