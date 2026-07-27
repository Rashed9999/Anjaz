<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\SafePayment;
use App\Models\SafePaymentEvent;
use App\Models\SafePaymentEvidence;
use App\Models\User;
use App\Services\FeeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1)
 *
 * إدارة دورة حياة الدفع الآمن.
 *
 * **مبادئ التصميم:**
 *   1. كل state transition داخل DB::transaction + lockForUpdate
 *   2. المال يُحجز عند الإنشاء (لا انتظار للبائع)
 *      → بناء الثقة + تجميد البيع
 *   3. كل event مُسجَّل في append-only log
 *   4. كل money movement له wallet_transaction_id واضح
 *   5. الـ admin هو القرار الوحيد للنزاعات
 *   6. لا "تلقائي" — كل تحرك يحتاج actor صريح (v1)
 *   7. كل عملية ناجحة تصدر receipt
 *
 * **رسوم المنصة:**
 *   تخصم فقط عند `released_to_seller` (الوثيقة: "الرسوم تخصم عند الإفراج للبائع").
 *   نسبة configurable في `config('amial.safe_payment.fee_percent')` (افتراضياً 1%).
 */
class SafePaymentService
{
    use \App\Traits\PostsToLedger;

    /** بيانات قيود ledger مؤجلة للنشر بعد commit */
    private array $pendingLedgerPosts = [];

    public function __construct(
        private readonly FinancialGuardService $guard,
        private readonly AuditService $audit,
        private readonly ReceiptService $receipts,
        private readonly FeeService $fees,
    ) {}

    /**
     * AMIAL-FEE-ENGINE-001: حساب رسم الدفع الآمن من المحرّك المركزي.
     * إذا لم توجد نسخة SAFE_PAYMENT نشطة → fallback للسلوك القديم (config).
     * يرجّع [fee, scheme_id, scheme_version].
     */
    private function resolveSafePaymentFee(string $amount): array
    {
        $breakdown = $this->fees->calculate('SAFE_PAYMENT', $amount);

        if ($breakdown['scheme_id'] === null) {
            // fallback: السلوك القديم (نسبة config)
            $feePercent = (string)config('amial.safe_payment.fee_percent', '1');
            $fee = bcmul($amount, bcdiv($feePercent, '100', 6), 4);
            return ['fee' => $fee, 'scheme_id' => null, 'scheme_version' => null];
        }

        return [
            'fee' => $breakdown['fee'],
            'scheme_id' => $breakdown['scheme_id'],
            'scheme_version' => $breakdown['scheme_version'],
        ];
    }

    /**
     * نشر قيود الـ ledger المؤجلة بعد نجاح الـ DB::transaction.
     * يُستدعى في نهاية كل method عام يقوم بـ release/refund.
     */
    private function flushPendingLedgerPosts(): void
    {
        $posts = $this->pendingLedgerPosts;
        $this->pendingLedgerPosts = [];

        foreach ($posts as $post) {
            $this->safeLedgerPost(function () use ($post) {
                if ($post['type'] === 'release') {
                    $this->ledgerReleaseEscrow(
                        toSellerUserId: $post['seller_id'],
                        grossAmount: $post['gross'],
                        feeAmount: $post['fee'],
                        sourceId: $post['source_id'],
                        description: "إفراج دفع آمن للبائع: {$post['title']}",
                    );
                } elseif ($post['type'] === 'refund') {
                    $this->ledgerRefundEscrow(
                        toBuyerUserId: $post['buyer_id'],
                        amount: $post['amount'],
                        sourceId: $post['source_id'],
                        description: "استرداد دفع آمن للمشتري: {$post['title']}",
                    );
                }
            });
        }
    }

    // ============================================================
    // 1. Create + Fund
    // ============================================================

    /**
     * المشتري ينشئ طلب دفع آمن + يدفع المبلغ فوراً (يُحجز).
     *
     * **التدفق:**
     *   1. validate (buyer, seller, amount, zone)
     *   2. DB::transaction:
     *      a. خصم من المشتري (lockForUpdate)
     *      b. إنشاء safe_payment record بحالة pending_seller_acceptance
     *      c. تسجيل event 'created'
     *   3. (post-commit) إيصال للمشتري + إشعار للبائع
     */
    public function createAndFund(
        User $buyer,
        User $seller,
        string $title,
        string $description,
        float|string $amount,
        ?string $deliveryTerms = null,
        ?array $attachments = null,
    ): SafePayment {
        if ($buyer->id === $seller->id) {
            throw new \RuntimeException('Buyer and seller cannot be the same user');
        }
        if ($buyer->zone_code !== 'SOUTH' || $seller->zone_code !== 'SOUTH') {
            throw new \RuntimeException('Both parties must be in SOUTH zone');
        }

        $amountNormalized = MoneyService::normalize($amount);
        if (bccomp($amountNormalized, '1.0000', 4) < 0) {
            throw new \RuntimeException('Minimum safe payment amount is 1.00');
        }

        // فحص الحد الأقصى من الـ config
        $maxAmount = (string)config('amial.safe_payment.max_amount', '100000.0000');
        if (bccomp($amountNormalized, $maxAmount, 4) > 0) {
            throw new \RuntimeException("Amount exceeds maximum {$maxAmount}");
        }

        $payment = DB::transaction(function () use ($buyer, $seller, $title, $description, $amountNormalized, $deliveryTerms, $attachments) {
            // 1) خصم من محفظة المشتري (مع lock + balance check)
            $debitTxId = (string) Str::ulid();
            $this->guard->debit(
                userId: $buyer->id,
                amount: $amountNormalized,
                reason: 'safe_payment_fund',
            );

            // 2) إنشاء الـ safe_payment record
            $payment = SafePayment::create([
                'payment_ulid' => (string) Str::ulid(),
                'buyer_user_id' => $buyer->id,
                'seller_user_id' => $seller->id,
                'title' => mb_substr($title, 0, 200),
                'description' => mb_substr($description, 0, 5000),
                'delivery_terms' => $deliveryTerms ? mb_substr($deliveryTerms, 0, 5000) : null,
                'attachments' => $attachments,
                'amount' => $amountNormalized,
                'platform_fee' => '0', // يُحتسب عند الـ release
                'held_amount' => $amountNormalized,
                'status' => 'pending_seller_acceptance',
                'buyer_debit_tx_id' => $debitTxId,
                'seller_response_deadline' => now()->addHours(
                    (int)config('amial.safe_payment.seller_response_hours', 72),
                ),
                'zone_code' => 'SOUTH',
            ]);

            // 3) سجل event
            $this->recordEvent($payment, 'created', null, 'pending_seller_acceptance',
                actorType: 'buyer', actorUserId: $buyer->id,
                note: "إنشاء طلب دفع آمن: {$title}",
                context: ['amount' => $amountNormalized],
            );

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $buyer->id,
                'subject_type' => 'safe_payment',
                'subject_id' => (string)$payment->id,
                'action' => 'SAFE_PAYMENT_CREATED',
                'decision_code' => 'CREATED',
                'severity' => 'info',
                'context' => [
                    'seller_id' => $seller->id,
                    'amount' => $amountNormalized,
                ],
            ]);

            return $payment;
        });

        // post-commit: receipt للمشتري عن الخصم
        $this->safeIssueReceipt($payment, 'debit', $buyer->id, $seller->id, 'safe_payment_funded');

        // post-commit: ledger hold escrow (AMIAL-LEDGER-001 v1.9)
        $this->safeLedgerPost(fn() => $this->ledgerHoldEscrow(
            fromUserId: $buyer->id,
            amount: $amountNormalized,
            sourceId: $payment->payment_ulid,
            description: "حجز دفع آمن: {$title}",
        ));

        return $payment;
    }

    // ============================================================
    // 2. Seller Response
    // ============================================================

    /**
     * AMIAL-SAFEPAY-CODE-001 — الرمز الصريح بعد التوليد، للمشتري وحده.
     *
     * لا يُخزَّن صريحاً في أي مكان: قاعدة البيانات تحمل تعميته فقط. تسريب
     * القاعدة لا يجوز أن يمنح أحداً القدرة على تأكيد تسليم لم يحدث.
     */
    public ?string $deliveryCodeForBuyer = null;

    /**
     * AMIAL-SAFEPAY-DISPUTE-001 — أسباب النزاع منظّمة.
     *
     * النصّ الحرّ وحده يجعل كل نزاع حالةً فريدة يقرؤها الموظّف من الصفر،
     * ويمنع أي إحصاء يكشف السبب المتكرّر. القائمة تُصنّف، والنصّ الحرّ
     * يبقى مطلوباً للتفصيل — التصنيف لا يُغني عن الرواية.
     */
    public const DISPUTE_REASONS = [
        'not_received' => 'لم أستلم البضاعة',
        'not_as_described' => 'البضاعة تخالف الوصف',
        'damaged' => 'وصلت تالفة',
        'incomplete' => 'ناقصة أو غير مكتملة',
        'counterfeit' => 'مقلّدة أو غير أصلية',
        'wrong_item' => 'صنف مختلف عمّا طلبت',
        'seller_unreachable' => 'البائع لا يردّ',
        'other' => 'سبب آخر',
    ];


    /**
     * الرمز الصريح بفكّ التشفير — للمشتري وللتحقّق.
     * يعيد null إن تعذّر الفكّ (مفتاح تغيّر) بدل أن يرمي ويوقف العملية.
     */
    public function plainDeliveryCode(SafePayment $payment): ?string
    {
        if (empty($payment->delivery_code_hash)) {
            return null;
        }

        try {
            return Crypt::decryptString($payment->delivery_code_hash);
        } catch (\Throwable) {
            return null;
        }
    }

    /** ستّة أرقام: يُملى صوتاً ويُكتب بلا خطأ. */
    private function generateDeliveryCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * يتحقّق البائع من رمز التسليم فيؤكّد الاستلام بشهادة الطرفين.
     *
     * ثلاث محاولات ثم يُقفل. بلا حدّ يستطيع بائع سيّئ النيّة تجريب مليون
     * احتمال في دقائق فيؤكّد تسليماً لم يقع.
     */
    public function verifyDeliveryCode(SafePayment $payment, User $seller, string $code): SafePayment
    {
        $this->assertSeller($payment, $seller);

        if (!in_array($payment->status, ['funded', 'in_delivery'], true)) {
            throw new \RuntimeException('لا يمكن تأكيد التسليم في الحالة الراهنة');
        }

        if (empty($payment->delivery_code_hash)) {
            throw new \RuntimeException('لا يوجد رمز تسليم لهذه العملية');
        }

        if ((int) $payment->delivery_code_attempts >= 3) {
            throw new \RuntimeException(
                'تجاوزت عدد المحاولات. تواصل مع الدعم أو أكمل بالأدلّة المرفوعة.'
            );
        }

        $clean = preg_replace('/[^0-9]/', '', $code);

        $expected = $this->plainDeliveryCode($payment);

        if ($expected === null || !hash_equals($expected, (string) $clean)) {
            $payment->increment('delivery_code_attempts');
            $this->recordEvent($payment, 'delivery_code_failed', $payment->status, $payment->status,
                actorType: 'seller', actorUserId: $seller->id,
                context: ['attempts' => $payment->delivery_code_attempts]);

            throw new \RuntimeException('رمز التسليم غير صحيح');
        }

        DB::transaction(function () use ($payment, $seller) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            $from = $locked->status;

            $locked->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'delivery_code_verified_at' => now(),
                'in_delivery_at' => $locked->in_delivery_at ?? now(),
            ]);

            $this->recordEvent($locked, 'delivery_code_verified', $from, 'delivered',
                actorType: 'seller', actorUserId: $seller->id,
                note: 'أكّد البائع التسليم برمز المشتري');
        });

        return $payment->fresh();
    }

    public function sellerAccept(SafePayment $payment, User $seller, ?string $note = null): SafePayment
    {
        $this->assertSeller($payment, $seller);
        $this->assertStatus($payment, 'pending_seller_acceptance');

        DB::transaction(function () use ($payment, $seller, $note) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'pending_seller_acceptance') {
                throw new \RuntimeException('Payment is no longer pending acceptance');
            }

            // AMIAL-SAFEPAY-CODE-001: رمز التسليم يُولَّد عند القبول لا عند
            // الإنشاء — قبل قبول البائع لا صفقة، ورمز لصفقة قد تُرفض تشويش.
            $plainCode = $this->generateDeliveryCode();

            $locked->update([
                'status' => 'funded',
                'seller_accepted_at' => now(),
                'delivery_code_hash' => Crypt::encryptString($plainCode),
                'delivery_code_attempts' => 0,
            ]);

            $this->recordEvent($locked, 'seller_accepted', 'pending_seller_acceptance', 'funded',
                actorType: 'seller', actorUserId: $seller->id, note: $note);

            // الرمز يصل المشتري وحده. لا يُسجَّل في الأحداث ولا في التدقيق:
            // سجلّ يقرؤه موظّف ويحوي رمزاً يُنهي الصفقة يُبطل الغرض منه.
            $this->deliveryCodeForBuyer = $plainCode;
        });

        return $payment->fresh();
    }

    public function sellerReject(SafePayment $payment, User $seller, string $reason): SafePayment
    {
        $this->assertSeller($payment, $seller);
        $this->assertStatus($payment, 'pending_seller_acceptance');

        DB::transaction(function () use ($payment, $seller, $reason) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'pending_seller_acceptance') {
                throw new \RuntimeException('Payment is no longer pending');
            }

            // إعادة المال للمشتري
            $this->refundBuyerInternal($locked, fullAmount: true);

            $locked->update([
                'status' => 'seller_rejected',
                'seller_rejected_at' => now(),
            ]);

            $this->recordEvent($locked, 'seller_rejected', 'pending_seller_acceptance', 'seller_rejected',
                actorType: 'seller', actorUserId: $seller->id, note: $reason);
        });

        $payment = $payment->fresh();
        // receipt للمشتري عن الاسترداد
        $this->safeIssueReceipt($payment, 'credit', $payment->buyer_user_id, $payment->seller_user_id, 'safe_payment_refunded');

        return $payment;
    }

    // ============================================================
    // 3. Delivery flow (seller actions)
    // ============================================================

    public function sellerMarkInDelivery(SafePayment $payment, User $seller, ?string $note = null): SafePayment
    {
        $this->assertSeller($payment, $seller);
        if (!$payment->canSellerMarkInDelivery()) {
            throw new \RuntimeException('Cannot transition to in_delivery from current status');
        }

        DB::transaction(function () use ($payment, $seller, $note) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'funded') {
                throw new \RuntimeException('Payment is not in funded status');
            }

            $locked->update([
                'status' => 'in_delivery',
                'in_delivery_at' => now(),
            ]);

            $this->recordEvent($locked, 'in_delivery_marked', 'funded', 'in_delivery',
                actorType: 'seller', actorUserId: $seller->id, note: $note);
        });

        return $payment->fresh();
    }

    public function sellerMarkDelivered(SafePayment $payment, User $seller, ?string $note = null): SafePayment
    {
        $this->assertSeller($payment, $seller);
        if (!$payment->canSellerMarkDelivered()) {
            throw new \RuntimeException('Cannot transition to delivered from current status');
        }

        DB::transaction(function () use ($payment, $seller, $note) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'in_delivery') {
                throw new \RuntimeException('Payment is not in_delivery');
            }

            $locked->update([
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);

            $this->recordEvent($locked, 'delivered_marked', 'in_delivery', 'delivered',
                actorType: 'seller', actorUserId: $seller->id, note: $note);
        });

        return $payment->fresh();
    }

    // ============================================================
    // 4. Buyer confirm → release to seller
    // ============================================================

    public function buyerConfirm(SafePayment $payment, User $buyer): SafePayment
    {
        $this->assertBuyer($payment, $buyer);
        if (!$payment->canBuyerConfirm()) {
            throw new \RuntimeException('Cannot confirm at current status');
        }

        DB::transaction(function () use ($payment, $buyer) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'delivered') {
                throw new \RuntimeException('Payment is not delivered');
            }

            // تأكيد المشتري — انتقالي
            $locked->update([
                'status' => 'buyer_confirmed',
                'buyer_confirmed_at' => now(),
            ]);
            $this->recordEvent($locked, 'buyer_confirmed', 'delivered', 'buyer_confirmed',
                actorType: 'buyer', actorUserId: $buyer->id);

            // فوراً: release للبائع
            $this->releaseToSellerInternal($locked, actorType: 'system', actorUserId: null);
        });

        $payment = $payment->fresh();
        $this->safeIssueReceipt($payment, 'credit', $payment->seller_user_id, $payment->buyer_user_id, 'safe_payment_released');
        $this->flushPendingLedgerPosts();
        return $payment;
    }

    // ============================================================
    // 5. Buyer cancel (قبل in_delivery)
    // ============================================================

    public function buyerCancel(SafePayment $payment, User $buyer, string $reason): SafePayment
    {
        $this->assertBuyer($payment, $buyer);
        if (!$payment->canBuyerCancel()) {
            throw new \RuntimeException('Cannot cancel at current status (already in delivery or terminal)');
        }

        DB::transaction(function () use ($payment, $buyer, $reason) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if (!in_array($locked->status, ['pending_seller_acceptance', 'funded'], true)) {
                throw new \RuntimeException('Cannot cancel at current status');
            }

            $this->refundBuyerInternal($locked, fullAmount: true);

            $fromStatus = $locked->status;
            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            $this->recordEvent($locked, 'buyer_cancelled', $fromStatus, 'cancelled',
                actorType: 'buyer', actorUserId: $buyer->id, note: $reason);
        });

        $payment = $payment->fresh();
        $this->safeIssueReceipt($payment, 'credit', $payment->buyer_user_id, $payment->seller_user_id, 'safe_payment_refunded');
        $this->flushPendingLedgerPosts();
        return $payment;
    }

    // ============================================================
    // 6. Dispute
    // ============================================================

    public function buyerDispute(SafePayment $payment, User $buyer, string $reason, ?array $attachments = null, ?string $reasonCode = null): SafePayment
    {
        $this->assertBuyer($payment, $buyer);
        if (!$payment->canBuyerDispute()) {
            throw new \RuntimeException('Cannot open dispute at current status');
        }
        if (mb_strlen($reason) < 10) {
            throw new \RuntimeException('Dispute reason must be at least 10 characters');
        }

        // AMIAL-SAFEPAY-DISPUTE-001: رمز غير معروف يقع على 'other' بدل أن
        // يُرفض — لا نمنع مشترياً من فتح نزاع لأن تطبيقه أقدم من القائمة.
        $code = array_key_exists((string) $reasonCode, self::DISPUTE_REASONS)
            ? $reasonCode : 'other';

        DB::transaction(function () use ($payment, $buyer, $reason, $attachments, $code) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->is_disputed) {
                throw new \RuntimeException('Already disputed');
            }

            $fromStatus = $locked->status;
            $locked->update([
                'status' => 'disputed',
                'is_disputed' => true,
                'disputed_at' => now(),
                'dispute_reason_code' => $code,
            ]);

            $this->recordEvent($locked, 'buyer_disputed', $fromStatus, 'disputed',
                actorType: 'buyer', actorUserId: $buyer->id, note: $reason,
                attachments: $attachments,
                context: ['reason_code' => $code,
                          'reason_label' => self::DISPUTE_REASONS[$code]]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $buyer->id,
                'subject_type' => 'safe_payment',
                'subject_id' => (string)$locked->id,
                'action' => 'SAFE_PAYMENT_DISPUTED',
                'decision_code' => 'DISPUTE_OPENED',
                'reason' => mb_substr($reason, 0, 255),
                'severity' => 'warning',
            ]);
        });

        return $payment->fresh();
    }

    // ============================================================
    // 7. Admin resolutions (للنزاعات)
    // ============================================================

    /**
     * AMIAL-SAFEPAY-AUDIT-001 — قرار الموظّف في سجلّ لا يُحذف.
     *
     * قرار حسم النزاع هو الموضع الوحيد في النظام كلّه الذي ينقل فيه موظّفٌ
     * مالاً بين عميلين بإرادته. كان يُكتب في `safe_payment_events` وحده —
     * جدول عاديّ يُحذف منه من ملك القاعدة ولا يترك أثراً. أما
     * `audit_decisions` فسلسلة تجزئة: كل سجلّ يحمل بصمة سابقه، فحذف واحد
     * أو تعديله يكسر السلسلة ويكشفه `amial:audit-verify`.
     *
     * **الأهمّ: القرار يُقيَّد بالأدلّة التي كانت أمامه لحظة اتّخاذه** —
     * معرّفاتها وبصماتها. بدون ذلك يستطيع من أراد التحايل أن يقرّر أوّلاً
     * ثم يرفع دليلاً يُبرّر قراره، ولا يُظهر السجلّ الترتيب. وبها يصير
     * السؤال قابلاً للإجابة: على أيّ شيء بالضبط بنى فلانٌ حكمه؟
     */
    private function auditAdminResolution(
        SafePayment $payment,
        User $admin,
        string $action,
        string $decisionCode,
        string $reason,
        array $amounts,
    ): void {
        $evidence = SafePaymentEvidence::where('safe_payment_id', $payment->id)
            ->orderBy('id')
            ->get(['id', 'role', 'stage', 'sha256']);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'safe_payment',
            'subject_id' => (string) $payment->id,
            'action' => $action,
            'decision_code' => $decisionCode,
            'reason' => mb_substr($reason, 0, 255),
            // قرار موظّف يحرّك مال عميلين: أعلى درجة، يظهر في أعلى اللوحة.
            'severity' => 'critical',
            'zone_code' => $payment->zone_code,
            'context' => [
                'payment_ulid' => $payment->payment_ulid,
                'buyer_user_id' => (int) $payment->buyer_user_id,
                'seller_user_id' => (int) $payment->seller_user_id,
                'held_amount_before' => (string) $payment->held_amount,
                'dispute_reason_code' => $payment->dispute_reason_code,
                'delivery_code_verified' => $payment->delivery_code_verified_at !== null,
                'evidence_at_decision' => $evidence->map(fn ($e) => [
                    'id' => $e->id,
                    'role' => $e->role,
                    'stage' => $e->stage,
                    'fingerprint' => substr((string) $e->sha256, 0, 12),
                ])->all(),
                'evidence_count' => $evidence->count(),
            ] + $amounts,
        ]);
    }

    public function adminResolveRelease(SafePayment $payment, User $admin, string $reason): SafePayment
    {
        $this->assertDisputed($payment);
        $this->assertFourEyes($payment, $admin);

        DB::transaction(function () use ($payment, $admin, $reason) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'disputed') {
                throw new \RuntimeException('Not in disputed status');
            }

            $this->releaseToSellerInternal($locked, actorType: 'admin', actorUserId: $admin->id);

            $locked->update([
                'admin_resolved_by' => $admin->id,
                'admin_resolved_at' => now(),
                'admin_resolution_note' => $reason,
            ]);

            $this->recordEvent($locked, 'admin_resolved_release', 'disputed', 'released_to_seller',
                actorType: 'admin', actorUserId: $admin->id, note: $reason);
        });

        $this->auditAdminResolution(
            $payment, $admin, 'SAFE_PAYMENT_ADMIN_RELEASE', 'RESOLVED_RELEASE', $reason,
            ['to_seller' => (string) $payment->held_amount, 'to_buyer' => '0'],
        );

        $payment = $payment->fresh();
        $this->safeIssueReceipt($payment, 'credit', $payment->seller_user_id, $payment->buyer_user_id, 'safe_payment_released');
        $this->flushPendingLedgerPosts();
        return $payment;
    }

    public function adminResolveRefund(SafePayment $payment, User $admin, string $reason): SafePayment
    {
        $this->assertDisputed($payment);
        $this->assertFourEyes($payment, $admin);

        DB::transaction(function () use ($payment, $admin, $reason) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'disputed') {
                throw new \RuntimeException('Not in disputed status');
            }

            $this->refundBuyerInternal($locked, fullAmount: true);

            $locked->update([
                'status' => 'refunded_to_buyer',
                'refunded_at' => now(),
                'admin_resolved_by' => $admin->id,
                'admin_resolved_at' => now(),
                'admin_resolution_note' => $reason,
            ]);

            $this->recordEvent($locked, 'admin_resolved_refund', 'disputed', 'refunded_to_buyer',
                actorType: 'admin', actorUserId: $admin->id, note: $reason);
        });

        $this->auditAdminResolution(
            $payment, $admin, 'SAFE_PAYMENT_ADMIN_REFUND', 'RESOLVED_REFUND', $reason,
            ['to_buyer' => (string) $payment->held_amount, 'to_seller' => '0'],
        );

        $payment = $payment->fresh();
        $this->safeIssueReceipt($payment, 'credit', $payment->buyer_user_id, $payment->seller_user_id, 'safe_payment_refunded');
        $this->flushPendingLedgerPosts();
        return $payment;
    }

    /**
     * استرداد جزئي: المشتري يأخذ buyerAmount، البائع يأخذ الباقي.
     */
    public function adminResolvePartial(
        SafePayment $payment,
        User $admin,
        string $buyerRefundAmount,
        string $reason,
    ): SafePayment {
        $this->assertDisputed($payment);
        $this->assertFourEyes($payment, $admin);

        $buyerAmount = MoneyService::normalize($buyerRefundAmount);
        if (bccomp($buyerAmount, '0', 4) < 0) {
            throw new \RuntimeException('Buyer refund amount cannot be negative');
        }
        if (bccomp($buyerAmount, (string)$payment->held_amount, 4) > 0) {
            throw new \RuntimeException('Buyer refund cannot exceed held amount');
        }

        $sellerAmount = MoneyService::sub((string)$payment->held_amount, $buyerAmount);

        DB::transaction(function () use ($payment, $admin, $buyerAmount, $sellerAmount, $reason) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'disputed') {
                throw new \RuntimeException('Not disputed');
            }

            // 1) refund للمشتري (الجزء البخس)
            if (bccomp($buyerAmount, '0', 4) > 0) {
                $refundTxId = (string) Str::ulid();
                $this->guard->credit(
                    userId: $locked->buyer_user_id,
                    amount: $buyerAmount,
                    reason: 'safe_payment_partial_refund',
                );
                $locked->buyer_refund_tx_id = $refundTxId;
                $locked->refunded_to_buyer_amount = $buyerAmount;
            }

            // 2) release للبائع (الجزء الآخر، minus fees)
            if (bccomp($sellerAmount, '0', 4) > 0) {
                // AMIAL-FEE-ENGINE-001: رسم من المحرّك المركزي على الجزء المُفرَج
                $feeInfo = $this->resolveSafePaymentFee($sellerAmount);
                $platformFee = $feeInfo['fee'];
                $sellerCredit = MoneyService::sub($sellerAmount, $platformFee);

                $creditTxId = (string) Str::ulid();
                $this->guard->credit(
                    userId: $locked->seller_user_id,
                    amount: $sellerCredit,
                    reason: 'safe_payment_partial_release',
                );
                $locked->seller_credit_tx_id = $creditTxId;
                $locked->released_to_seller_amount = $sellerCredit;
                $locked->platform_fee = $platformFee;
                $locked->fee_scheme_id = $feeInfo['scheme_id'];
                $locked->fee_scheme_version = $feeInfo['scheme_version'];
            }

            $locked->held_amount = '0';
            $locked->status = 'partially_refunded';
            $locked->refunded_at = now();
            $locked->released_at = bccomp($sellerAmount, '0', 4) > 0 ? now() : null;
            $locked->admin_resolved_by = $admin->id;
            $locked->admin_resolved_at = now();
            $locked->admin_resolution_note = $reason;
            $locked->save();

            $this->recordEvent($locked, 'admin_resolved_partial', 'disputed', 'partially_refunded',
                actorType: 'admin', actorUserId: $admin->id, note: $reason,
                context: ['buyer_amount' => $buyerAmount, 'seller_amount' => $sellerAmount]);
        });

        // التسوية الجزئية أخطر الثلاثة: المبلغ يُقسَم برأي الموظّف وحده،
        // فتُسجَّل الحصّتان كما أدخلهما لا كما آلتا.
        $this->auditAdminResolution(
            $payment, $admin, 'SAFE_PAYMENT_ADMIN_PARTIAL', 'RESOLVED_PARTIAL', $reason,
            ['to_buyer' => $buyerAmount, 'to_seller' => $sellerAmount],
        );

        $payment = $payment->fresh();
        if (bccomp((string)$payment->refunded_to_buyer_amount, '0', 4) > 0) {
            $this->safeIssueReceipt($payment, 'credit', $payment->buyer_user_id, $payment->seller_user_id, 'safe_payment_refunded',
                customAmount: (string)$payment->refunded_to_buyer_amount);
        }
        if (bccomp((string)$payment->released_to_seller_amount, '0', 4) > 0) {
            $this->safeIssueReceipt($payment, 'credit', $payment->seller_user_id, $payment->buyer_user_id, 'safe_payment_released',
                customAmount: (string)$payment->released_to_seller_amount);
        }

        return $payment;
    }

    // ============================================================
    // 8. Expiration handler (يُستدعى من scheduler)
    // ============================================================

    public function expireUnresponsive(SafePayment $payment): SafePayment
    {
        if ($payment->status !== 'pending_seller_acceptance') {
            return $payment;
        }
        if ($payment->seller_response_deadline > now()) {
            return $payment;
        }

        DB::transaction(function () use ($payment) {
            $locked = SafePayment::lockForUpdate()->find($payment->id);
            if ($locked->status !== 'pending_seller_acceptance') return;
            if ($locked->seller_response_deadline > now()) return;

            $this->refundBuyerInternal($locked, fullAmount: true);

            $locked->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);

            $this->recordEvent($locked, 'expired', 'pending_seller_acceptance', 'expired',
                actorType: 'system', actorUserId: null,
                note: 'انتهت مهلة قبول البائع');
        });

        $payment = $payment->fresh();
        $this->safeIssueReceipt($payment, 'credit', $payment->buyer_user_id, $payment->seller_user_id, 'safe_payment_refunded');
        $this->flushPendingLedgerPosts();
        return $payment;
    }

    // ============================================================
    // Internal helpers
    // ============================================================

    /**
     * إفراج المبلغ للبائع (داخل DB::transaction المُفتوحة بالفعل).
     *
     * يحسب fees ويخصم منها، الباقي للبائع.
     */
    private function releaseToSellerInternal(SafePayment $locked, string $actorType, ?int $actorUserId): void
    {
        if (bccomp((string)$locked->held_amount, '0', 4) <= 0) {
            throw new \RuntimeException('No funds held to release');
        }

        // حسب الـ fee — AMIAL-FEE-ENGINE-001: من المحرّك المركزي (مع fallback للـ config)
        $feeInfo = $this->resolveSafePaymentFee((string)$locked->held_amount);
        $platformFee = $feeInfo['fee'];
        $sellerCredit = MoneyService::sub((string)$locked->held_amount, $platformFee);

        $creditTxId = (string) Str::ulid();
        $this->guard->credit(
            userId: $locked->seller_user_id,
            amount: $sellerCredit,
            reason: 'safe_payment_release',
        );

        // (اختياري) credit الـ admin/system بالـ fee — تركتها للـ admin
        // accounting إذا كانت موجودة. حالياً نسجلها فقط في الـ record.

        $locked->update([
            'status' => 'released_to_seller',
            'released_at' => now(),
            'released_to_seller_amount' => $sellerCredit,
            'platform_fee' => $platformFee,
            'fee_scheme_id' => $feeInfo['scheme_id'],
            'fee_scheme_version' => $feeInfo['scheme_version'],
            'seller_credit_tx_id' => $creditTxId,
            'held_amount' => '0',
        ]);

        $this->recordEvent($locked, 'released_to_seller', 'buyer_confirmed', 'released_to_seller',
            actorType: $actorType, actorUserId: $actorUserId,
            context: ['amount' => $sellerCredit, 'platform_fee' => $platformFee]);

        // سجّل بيانات الـ ledger للنشر بعد الـ commit (AMIAL-LEDGER-001 v1.9)
        $this->pendingLedgerPosts[] = [
            'type' => 'release',
            'seller_id' => $locked->seller_user_id,
            'gross' => (string)$locked->amount,
            'fee' => $platformFee,
            'source_id' => $locked->payment_ulid,
            'title' => $locked->title,
        ];
    }

    /**
     * استرداد للمشتري داخل DB::transaction المفتوحة.
     */
    private function refundBuyerInternal(SafePayment $locked, bool $fullAmount = true, ?string $partialAmount = null): void
    {
        $refundAmount = $fullAmount ? (string)$locked->held_amount : $partialAmount;
        if (!$refundAmount || bccomp($refundAmount, '0', 4) <= 0) {
            return;
        }

        $refundTxId = (string) Str::ulid();
        $this->guard->credit(
            userId: $locked->buyer_user_id,
            amount: $refundAmount,
            reason: 'safe_payment_refund',
        );

        $locked->buyer_refund_tx_id = $refundTxId;
        $locked->refunded_to_buyer_amount = MoneyService::add(
            (string)$locked->refunded_to_buyer_amount, $refundAmount,
        );
        $locked->held_amount = MoneyService::sub((string)$locked->held_amount, $refundAmount);
        $locked->save();

        // سجّل بيانات الـ ledger للنشر بعد الـ commit (AMIAL-LEDGER-001 v1.9)
        $this->pendingLedgerPosts[] = [
            'type' => 'refund',
            'buyer_id' => $locked->buyer_user_id,
            'amount' => $refundAmount,
            'source_id' => $locked->payment_ulid . '_' . $refundTxId,
            'title' => $locked->title,
        ];
    }

    private function recordEvent(
        SafePayment $payment,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        string $actorType,
        ?int $actorUserId,
        ?string $note = null,
        ?array $attachments = null,
        ?array $context = null,
    ): void {
        SafePaymentEvent::create([
            'safe_payment_id' => $payment->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'note' => $note,
            'attachments' => $attachments,
            'context' => $context,
            'ip_address' => request()?->ip(),
            'user_agent' => mb_substr(request()?->userAgent() ?? '', 0, 500),
            'created_at' => now(),
        ]);
    }

    private function safeIssueReceipt(
        SafePayment $payment,
        string $direction,
        int $userId,
        ?int $counterpartyId,
        string $receiptType,
        ?string $customAmount = null,
    ): void {
        try {
            $method = $direction === 'debit' ? 'issueDebit' : 'issueCredit';
            $this->receipts->{$method}([
                'user_id' => $userId,
                'counterparty_user_id' => $counterpartyId,
                'reference_transaction_id' => $payment->payment_ulid,
                'receipt_type' => $receiptType,
                'amount' => $customAmount ?? (string)$payment->amount,
                'fee' => $receiptType === 'safe_payment_released' ? (string)$payment->platform_fee : '0',
                'reference_type' => 'safe_payment',
                'reference_id' => $payment->id,
                'metadata' => [
                    'title' => $payment->title,
                    'status' => $payment->status,
                ],
                'zone_code' => 'SOUTH',
            ]);
        } catch (\Throwable $e) {
            Log::warning('SafePayment receipt issuance failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ============================================================
    // Assertions
    // ============================================================

    private function assertBuyer(SafePayment $payment, User $user): void
    {
        if ($payment->buyer_user_id !== $user->id) {
            throw new \RuntimeException('Action allowed only for the buyer');
        }
    }

    private function assertSeller(SafePayment $payment, User $user): void
    {
        if ($payment->seller_user_id !== $user->id) {
            throw new \RuntimeException('Action allowed only for the seller');
        }
    }

    private function assertStatus(SafePayment $payment, string $expected): void
    {
        if ($payment->status !== $expected) {
            throw new \RuntimeException("Expected status '{$expected}', got '{$payment->status}'");
        }
    }

    private function assertDisputed(SafePayment $payment): void
    {
        if ($payment->status !== 'disputed') {
            throw new \RuntimeException('Action requires disputed status');
        }
    }

    /**
     * AMIAL-FOUR-EYES-001 — من تعامل مع هذا النزاع لا يفصل فيه.
     *
     * الفصل في النزاع يُحرّك مالاً بين طرفين، وكان بلا أي ضابط: من عالج
     * الأدلّة يستطيع الفصل، ومن فصل يستطيع إعادة الفصل. والرقابة الذاتية
     * ليست رقابة — من أخطأ لا يرى خطأه، ومن تعمّد يُغطّي أثره.
     *
     * والمصدر سجلّ التدقيق لا حقل `admin_resolved_by`: الحقل يُكتب فوقه عند
     * أوّل قرار ثانٍ فيضيع التاريخ، والسجلّ لا يُحذف ولا يُعدَّل.
     */
    private function assertFourEyes(SafePayment $payment, User $admin): void
    {
        app(\App\Services\FourEyesService::class)->assertNotPreviousActor(
            'safe_payment', $payment->id, $admin,
        );
    }
}
