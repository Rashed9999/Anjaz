<?php

namespace App\Services;

use App\CentralLogics\Helpers;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Support\Phone;
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

    /** افتراضي صلاحية طلب الدفع: 7 أيام. */
    private const DEFAULT_EXPIRY_DAYS = 7;

    public function __construct(
        private readonly NotificationService $notif,
    ) {}

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
            ->first(['id', 'f_name', 'l_name', 'is_active', 'type']);
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
        if ($request->recipient_user_id !== $recipient->id) {
            throw new InvalidArgumentException('هذا الطلب ليس موجّهاً إليك');
        }
        if ($request->status !== 'pending') {
            throw new RuntimeException('لا يمكن رفض طلب غير نشط');
        }

        $request->update([
            'status' => 'declined',
            'note' => $reason ? mb_substr($reason, 0, 255) : $request->note,
        ]);

        // الطالب ينتظر جواباً؛ وصمتُ الرفض يجعله يعيد الإرسال ويتّصل.
        try {
            $requester = User::find($request->requester_user_id);
            if ($requester) {
                $this->notif->dispatch(
                    $requester,
                    'payment_request_declined',
                    'رُفض طلبك',
                    trim(($recipient->f_name ?? 'المستلم')) . ' رفض طلب '
                        . Helpers::money($request->amount) . ' ر.ي'
                        . ($reason ? " — {$reason}" : ''),
                    data: ['short_code' => $request->short_code],
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('decline notification failed', ['err' => $e->getMessage()]);
        }

        return $request->fresh();
    }

    /** يعيد الطلب بالرمز القصير (يستخدم في public endpoint). */
    public function findByCode(string $shortCode): ?PaymentRequest
    {
        return PaymentRequest::where('short_code', strtoupper($shortCode))->first();
    }

    /** الدافع ينفّذ الدفع. */
    public function pay(User $payer, PaymentRequest $request): array
    {
        if (!$request->isActive()) {
            throw new RuntimeException(match ($request->status) {
                'paid' => 'هذا الطلب مدفوع سابقاً',
                'cancelled' => 'هذا الطلب ملغى',
                'expired' => 'انتهت صلاحية الطلب',
                default => 'الطلب غير صالح',
            });
        }

        if ($payer->id === $request->requester_user_id) {
            throw new InvalidArgumentException('لا يمكنك دفع طلب أنشأته بنفسك');
        }

        // إن كان المستلم محدّداً، يجب أن يطابق الدافع
        if ($request->recipient_user_id && $request->recipient_user_id !== $payer->id) {
            throw new InvalidArgumentException('هذا الطلب موجّه لشخص آخر');
        }

        $amount = (string)$request->amount;
        $requesterId = $request->requester_user_id;

        return DB::transaction(function () use ($payer, $request, $amount, $requesterId) {
            // AMIAL-FIX (double-spend TOCTOU): أعِد فحص حالة الطلب تحت قفل الصفّ داخل
            // المعاملة. فحص isActive() أعلاه خارج المعاملة لا يكفي: نداءان متزامنان قد
            // يمرّان معاً. هنا نقفل صفّ الطلب ونتحقّق أنه ما زال pending قبل أي خصم.
            $locked = PaymentRequest::whereKey($request->getKey())->lockForUpdate()->first();
            if (!$locked || !$locked->isActive()) {
                throw new RuntimeException('هذا الطلب مدفوع أو غير صالح');
            }

            // قفل المحفظتين بترتيب ثابت
            $this->guard()->lockWalletsOrdered([$payer->id, $requesterId]);

            // خصم من الدافع
            $this->guard()->debit($payer->id, $amount, "payment_request:{$request->request_ulid}");
            // إضافة للطالب
            $this->guard()->credit($requesterId, $amount, "payment_request:{$request->request_ulid}");

            // حدّث الطلب
            $txId = (string) Str::ulid();
            $request->update([
                'status' => 'paid',
                'paid_by_user_id' => $payer->id,
                'paid_transaction_id' => $txId,
                'paid_at' => now(),
            ]);

            // AMIAL-LEDGER-REQUEST-001: قيد مزدوج داخل المعاملة.
            //
            // كان هذا المسار يُحرّك المال بـ`debit`/`credit` **ولا يُرحّل**.
            // وسببُ إعفاء هذه الخدمة في LedgerCoverageGuardTest كان مكتوباً
            // خطأً: «التنفيذ يمرّ بمسار الدفع المُرحِّل» — وهو لا يمرّ به، بل
            // يُحرّك المال هنا بيده.
            //
            // واكتُشف بقراءة الدالّة لا بفحص الحارس: الحارس يقبل السببَ
            // المكتوب ولا يتحقّق منه — وهذا حدّه الذي يجب أن يُعرف.
            $this->ledgerTransfer(
                fromUserId: $payer->id,
                toUserId: $requesterId,
                amount: $amount,
                sourceType: 'payment_request',
                sourceId: $request->request_ulid,
                description: 'دفع طلب مال',
            );

            // إشعارات
            $this->dispatchPaidNotifications($request, $payer);

            return [
                'request' => $request->fresh(),
                'transaction_id' => $txId,
                'amount' => $amount,
            ];
        });
    }

    /** الطالب يلغي طلبه. */
    public function cancel(User $requester, PaymentRequest $request): PaymentRequest
    {
        if ($request->requester_user_id !== $requester->id) {
            throw new InvalidArgumentException('لا يمكنك إلغاء طلب لم تنشئه');
        }
        if ($request->status !== 'pending') {
            throw new RuntimeException('لا يمكن إلغاء طلب غير نشط');
        }
        $request->update(['status' => 'cancelled']);
        return $request->fresh();
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

        return $q->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
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
}
