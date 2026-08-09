<?php

namespace App\Services\Whatsapp;

use App\Models\EMoney;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\WhatsappAuditLog;
use App\Services\BillPayService;
use App\Services\FeeService;
use App\Services\PaymentRequestService;
use App\Services\PendingTransferService;
use App\Services\RecipientVerificationService;
use App\Services\TransactionPinService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-WA-002 — القلب المنطقي للـ WhatsApp Bot (نسخة كاملة مُحدَّثة وفق
 * AMIAL PAY WHATSAPP BOT SPECIFICATION v1.0).
 *
 * التحديثات الجوهرية عن v1:
 *   - Section 4: بوّابة "ربط الحساب" — لا تنفيذ أمر واحد بلا جلسة موثوقة
 *   - Section 5: touchActivity() في كل تفاعل (30 دقيقة بدل 10)
 *   - Section 9: دعم البحث بالاسم + قائمة اختيار عند تعدّد المستفيدين
 *   - Section 26: ربط أساسي بـ Risk Score (bumpRisk عند محاولات مشبوهة)
 *   - Section 27: تسجيل كل أمر في whatsapp_audit_logs
 *   - Section 29: التواصل مع الدعم (تذكرة دعم مبسّطة)
 *
 * ما زال يستخدم نفس Services المالية الموجودة (لا منطق مالي جديد):
 *   PendingTransferService, FeeService, RecipientVerificationService
 */
class WhatsappBotService
{
    /** أقصى عدد مستفيدين يُعرَضون في قائمة الاختيار (Section 9). */
    private const MAX_RECIPIENT_CANDIDATES = 5;

    public function __construct(
        private readonly WhatsappSessionManager        $session,
        private readonly IntentParser                  $parser,
        private readonly WhatsappMessageFormatter       $fmt,
        private readonly WhatsappApiClient              $client,
        private readonly PendingTransferService         $transferSvc,
        private readonly RecipientVerificationService   $recipientSvc,
        private readonly FeeService                     $feeSvc,
        private readonly WhatsappAccountLinkingService  $linking,
        private readonly BillPayService                 $billPaySvc,
        private readonly PaymentRequestService          $paymentRequestSvc,
        private readonly WhatsappPaymentRequestResolver  $payReqResolver,
        private readonly TransactionPinService           $pinSvc,
        private readonly WhatsappMoneyLimit              $limit,
    ) {}

    /**
     * AMIAL-WA-LIMIT-001 — **سقفُ المال عبر البوت، من موضعٍ واحد.**
     *
     * وسقفٌ على مسارٍ من ثلاثةٍ ليس سقفاً: من بلغ الحدَّ في التحويل
     * يُقسّمه على فواتير. فتمرّ المسارات الثلاثة كلُّها من هنا.
     *
     * ويُفحص **قبل تنفيذ العمليّة** لا بعده — فالمنعُ بعد الخصم ليس منعاً.
     */
    private function refuseIfOverLimit(string $phone, string $amount, string $kind): ?array
    {
        $why = $this->limit->refuse($amount);

        if ($why === null) {
            return null;
        }

        \Illuminate\Support\Facades\Log::info('WhatsApp money blocked by cap', [
            'kind' => $kind, 'amount' => $amount, 'max' => $this->limit->max(),
        ]);

        // **ويُسجَّل المنعُ في التدقيق — لا في السجلّ النصّيّ وحده.**
        //
        // فشاشةُ الإدارة تعرض «عمليّات منعها السقف اليوم»، وعدّادٌ لا
        // يمكن أن يتحرّك **مؤشّرٌ يكذب**: يُقرأ صفرُه «لم يُمنع أحد» وهو
        // «لا أحصي». (القاعدة السابعة.)
        try {
            app(\App\Services\AuditService::class)->record([
                'actor_type' => 'customer',
                'actor_user_id' => null,
                'subject_type' => 'setting',
                'subject_id' => 0,
                'action' => 'WA_LIMIT_BLOCKED',
                'decision_code' => 'BLOCKED',
                'severity' => 'info',
                // **المفتاحُ `context` لا `data`** — `AuditService::record`
                // يقرأ `$payload['context']`، فـ`data` تُبتلع صامتةً ويبقى
                // صفُّ تدقيقٍ بلا تفصيل. كشفه اختبارٌ يقرأ الصفَّ نفسَه.
                'context' => ['kind' => $kind, 'amount' => $amount, 'max' => $this->limit->max()],
            ]);
        } catch (\Throwable $e) {
            Log::error('[WA Bot] limit audit failed', ['error' => $e->getMessage()]);
        }

        $this->session->clear($phone);
        $this->send($phone, $why);

        return ['success' => false, 'message' => $why];
    }

    /** هل رقم واتساب مربوط بحساب مُوثّق؟ (بوّابة auth قبل المعالجة الثقيلة). */
    public function isLinked(string $fromPhone): bool
    {
        return $this->linking->isLinked($fromPhone);
    }

    /**
     * نقطة الدخول الوحيدة — يعالج كل رسالة واردة.
     */
    public function handle(string $fromPhone, string $messageBody): void
    {
        Log::info('[WA Bot] Incoming', [
            'from'    => $this->maskPhone($fromPhone),
            'preview' => mb_substr($messageBody, 0, 50),
        ]);

        try {
            // **موظّفو شركات الصرافة أوّلاً.**
            //
            // الصرّاف ليس `User` ولا محفظة له، فمسارُ العميل كلّه لا يعنيه:
            // «رصيدي» عنده تعني نقدَ درجه لا رصيد محفظة. ولو مرّ من البوّابة
            // العامّة لطُلب منه ربطُ حسابٍ لا يملكه.
            $agentWa = app(AgentWhatsappService::class);

            // ربطٌ معلَّق: الرمز يأتي قبل أن يصير الرقم مرتبطاً.
            if ($pending = $agentWa->tryVerifyPending($fromPhone, $messageBody)) {
                $this->send($fromPhone, $pending);

                return;
            }

            $staff = $agentWa->resolveStaff($fromPhone);

            if ($staff) {
                $reply = $agentWa->handle($staff, $messageBody);
                $this->send($fromPhone, $reply ?? $agentWa->menu($staff));

                return;
            }

            $intent = $this->parser->parse($messageBody);
            $step   = $this->session->currentStep($fromPhone);

            // إلغاء فوري بغضّ النظر عن أيّ شيء آخر
            if (in_array($intent['type'], [
                IntentParser::INTENT_CANCEL, IntentParser::INTENT_REJECT,
            ])) {
                $this->session->clear($fromPhone);
                $this->send($fromPhone, $this->fmt->cancelConfirmed());
                return;
            }

            // ── Section 4: خطوات الربط تُعالَج حتى لو لم تكتمل الجلسة ──
            if (in_array($step, [
                WhatsappSessionManager::STEP_LINK_PHONE,
                WhatsappSessionManager::STEP_LINK_OTP,
            ], true)) {
                $this->handleLinkingStep($fromPhone, $step, $messageBody);
                return;
            }

            $linkedUser = $this->linking->resolveUser($fromPhone);

            // ── المستخدم غير مرتبط بعد ──
            if (!$linkedUser) {
                if ($intent['type'] === IntentParser::INTENT_LINK_ACCOUNT) {
                    $this->session->advance($fromPhone, WhatsappSessionManager::STEP_LINK_PHONE);
                    $this->send($fromPhone, $this->fmt->askLinkPhone());
                    return;
                }
                $this->auditCommand($fromPhone, null, $intent['type'], WhatsappAuditLog::OUTCOME_BLOCKED);
                $this->send($fromPhone, $this->fmt->notLinkedYet());
                return;
            }

            // ── المستخدم مرتبط — حدّث آخر نشاط (Section 5) ──
            $this->session->touchActivity($fromPhone);

            // معالجة حسب الخطوة الحالية (محادثة متعدّدة الخطوات)
            if ($step !== WhatsappSessionManager::STEP_MENU) {
                $this->handleStep($fromPhone, $linkedUser, $step, $intent, $messageBody);
                return;
            }

            $this->auditCommand($fromPhone, $linkedUser->id, $intent['type'], WhatsappAuditLog::OUTCOME_SUCCESS);
            $this->handleIntent($fromPhone, $linkedUser, $intent);

        } catch (\Throwable $e) {
            Log::error('[WA Bot] Unhandled exception', ['error' => $e->getMessage()]);
            $this->send($fromPhone, $this->fmt->error());
            $this->session->clear($fromPhone);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Section 4 — ربط الحساب
    // ══════════════════════════════════════════════════════════════

    private function handleLinkingStep(string $phone, string $step, string $raw): void
    {
        if ($step === WhatsappSessionManager::STEP_LINK_PHONE) {
            $accountPhone = trim($raw);
            $result = $this->linking->startLinking($phone, $accountPhone);

            if (!$result['success']) {
                $this->send($phone, $this->fmt->linkFailed($result['message']));
                // لا نمسح الجلسة لو كان السبب رقماً خاطئاً — نسمح بإعادة المحاولة
                if (str_contains($result['message'], 'تجاوزت')) {
                    $this->session->clear($phone);
                }
                return;
            }

            $this->session->advance($phone, WhatsappSessionManager::STEP_LINK_OTP);
            $this->send($phone, $result['message']);
            return;
        }

        if ($step === WhatsappSessionManager::STEP_LINK_OTP) {
            $otp = trim($raw);
            $result = $this->linking->verifyOtp($phone, $otp);

            if (!$result['success']) {
                $this->send($phone, $this->fmt->linkFailed($result['message']));
                if (str_contains($result['message'], 'تجاوزت') || str_contains($result['message'], 'انتهت')) {
                    $this->session->clear($phone);
                }
                return;
            }

            $this->session->clear($phone);
            $user = $result['user'];
            $name = trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''));
            $this->send($phone, $this->fmt->linkSuccess($name ?: 'عميلنا الكريم'));
        }
    }

    // ══════════════════════════════════════════════════════════════
    // معالجة القائمة الرئيسية
    // ══════════════════════════════════════════════════════════════

    private function handleIntent(string $phone, User $user, array $intent): void
    {
        match ($intent['type']) {
            IntentParser::INTENT_MENU      => $this->showMenu($phone, $user),
            IntentParser::INTENT_BALANCE   => $this->showBalance($phone, $user),
            IntentParser::INTENT_TRANSFER  => $this->startTransfer($phone, $user, $intent['params']),
            IntentParser::INTENT_STATEMENT => $this->showStatement($phone, $user),
            IntentParser::INTENT_AGENT     => $this->showAgents($phone, $user),
            IntentParser::INTENT_HELP      => $this->send($phone, $this->fmt->help()),
            IntentParser::INTENT_SUPPORT   => $this->startSupport($phone),
            IntentParser::INTENT_BILL_PAY  => $this->startBillPay($phone, $user),
            IntentParser::INTENT_PAY_CODE  => $this->startPaymentRequestFlow($phone, $user, $intent['params']['code'] ?? ''),
            default                        => $this->send($phone, $this->fmt->unknownCommand()),
        };
    }

    private function showMenu(string $phone, User $user): void
    {
        $name = trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''));
        $this->send($phone, $this->fmt->mainMenu($name));
    }

    private function showBalance(string $phone, User $user): void
    {
        $wallet  = EMoney::where('user_id', $user->id)->first();
        $balance = $wallet?->current_balance ?? '0';
        $pending = $wallet?->pending_balance ?? '0';
        $this->send($phone, $this->fmt->balance((string) $balance, (string) $pending));
    }

    // ══════════════════════════════════════════════════════════════
    // التحويل (مع دعم البحث بالاسم — Section 9)
    // ══════════════════════════════════════════════════════════════

    private function startTransfer(string $phone, User $user, array $params): void
    {
        if (!empty($params['to_phone']) && !empty($params['amount'])) {
            $this->session->advance($phone, WhatsappSessionManager::STEP_TRANSFER_PHONE);
            $this->resolveRecipientAndProceed($phone, $user, $params['to_phone'], $params['amount']);
            return;
        }

        $this->session->advance($phone, WhatsappSessionManager::STEP_TRANSFER_PHONE);
        $this->send($phone, $this->fmt->askTransferPhone());
    }

    private function handleStep(
        string $phone, User $user, string $step, array $intent, string $raw
    ): void {
        match ($step) {
            WhatsappSessionManager::STEP_TRANSFER_PHONE          => $this->handleRecipientInput($phone, $user, $intent, $raw),
            WhatsappSessionManager::STEP_TRANSFER_PICK_RECIPIENT => $this->handleRecipientPick($phone, $user, $raw),
            WhatsappSessionManager::STEP_TRANSFER_AMOUNT         => $this->handleTransferAmount($phone, $user, $intent, $raw),
            WhatsappSessionManager::STEP_TRANSFER_PIN_SENT       => $this->handlePinStep($phone, $user, $raw),
            WhatsappSessionManager::STEP_AGENT_SEARCH            => $this->handleIntent($phone, $user, $intent),
            WhatsappSessionManager::STEP_BILLPAY_PICK_SERVICE    => $this->handleBillPayServicePick($phone, $user, $raw),
            WhatsappSessionManager::STEP_BILLPAY_ACCOUNT         => $this->handleBillPayAccount($phone, $user, $raw),
            WhatsappSessionManager::STEP_BILLPAY_AMOUNT          => $this->handleBillPayAmount($phone, $user, $intent, $raw),
            WhatsappSessionManager::STEP_BILLPAY_PIN_SENT        => $this->handlePinStep($phone, $user, $raw),
            WhatsappSessionManager::STEP_PAYREQ_PIN_SENT         => $this->handlePinStep($phone, $user, $raw),
            'support_awaiting_message'                          => $this->finishSupport($phone, $user, $raw),
            default                                              => $this->handleIntent($phone, $user, $intent),
        };
    }

    /** يستقبل رقم هاتف *أو* اسماً — Section 8 + 9. */
    private function handleRecipientInput(string $phone, User $user, array $intent, string $raw): void
    {
        $toPhone = $intent['params']['phone'] ?? $this->parser->extractPhone($raw);

        if ($toPhone) {
            $this->resolveRecipientAndProceed($phone, $user, $toPhone, null);
            return;
        }

        // بحث بالاسم (Section 9) — اسم مكوّن من حرفين فأكثر
        $name = trim($raw);
        if (mb_strlen($name) < 2) {
            $this->send($phone, "❌ الرقم أو الاسم غير صالح.\n\n" . $this->fmt->askTransferPhone());
            return;
        }

        $candidates = $this->searchUsersByName($name, $user->id);

        if ($candidates->isEmpty()) {
            $this->send($phone, "❌ لم يُعثَر على مستفيد بهذا الاسم أو الرقم.\n\n"
                . $this->fmt->askTransferPhone());
            return;
        }

        if ($candidates->count() === 1) {
            $this->resolveRecipientAndProceed($phone, $user, $candidates->first()->phone, null);
            return;
        }

        // أكثر من نتيجة — Section 9: عرض قائمة اختيار
        $list = $candidates->take(self::MAX_RECIPIENT_CANDIDATES)->map(fn($u) => [
            'phone'       => $u->phone,
            'masked_name' => trim("{$u->f_name} {$u->l_name}"),
        ])->values()->toArray();

        $this->session->advance($phone, WhatsappSessionManager::STEP_TRANSFER_PICK_RECIPIENT, [
            'candidates' => $list,
        ]);
        $this->send($phone, $this->fmt->multipleRecipientsFound($list));
    }

    private function handleRecipientPick(string $phone, User $user, string $raw): void
    {
        $candidates = $this->session->getData($phone, 'candidates', []);
        $index      = (int) trim($raw) - 1;

        if (!isset($candidates[$index])) {
            $this->send($phone, "❌ اختيار غير صالح. اكتب رقماً من القائمة، أو *إلغاء*.");
            return;
        }

        $this->resolveRecipientAndProceed($phone, $user, $candidates[$index]['phone'], null);
    }

    private function resolveRecipientAndProceed(
        string $phone, User $user, string $toPhone, ?string $amount
    ): void {
        try {
            $result = $this->recipientSvc->verifyRecipient($toPhone, $user->id);
        } catch (\Throwable $e) {
            $this->bumpRiskOnFailure($phone, 'invalid_recipient');
            $this->send($phone, "❌ " . $this->safeErr($e));
            return;
        }

        $this->session->advance($phone, WhatsappSessionManager::STEP_TRANSFER_AMOUNT, [
            'to_phone'       => $toPhone,
            'to_masked_name' => $result['masked_name'] ?? '***',
            'to_user_id'     => $result['user_id']     ?? null,
            'verify_token'   => $result['token']       ?? null,
        ]);

        if ($amount) {
            $this->handleTransferAmount($phone, $user, ['params' => ['amount' => $amount]], $amount);
            return;
        }

        $this->send($phone, $this->fmt->confirmRecipient($result['masked_name'] ?? '***', $toPhone));
    }

    private function handleTransferAmount(string $phone, User $user, array $intent, string $raw): void
    {
        $amount = $intent['params']['amount'] ?? $this->parser->extractAmount($raw);

        if (!$amount) {
            $this->send($phone, "❌ المبلغ غير صالح.\n\nأدخل مبلغاً بالريال اليمني (مثال: 5000)");
            return;
        }

        $wallet  = EMoney::where('user_id', $user->id)->first();
        $balance = (string) ($wallet?->current_balance ?? '0');

        // AMIAL-TRUTH-003 — **الرسمُ نفسُه في كلّ قناة.**
        //
        // ══════════════════════════════════════════════════════════════
        // **عطلان في سطرٍ واحد، وكلاهما يُنتج صفراً صامتاً:**
        //
        //   ① الرمزُ كان `'send_money'` بحروفٍ صغيرة، ومطابقةُ
        //      `FeeService::activeScheme()` نصّيّةٌ حرفيّة والمخطّطُ
        //      `'SEND_MONEY'`. فلا يُوجد مخطّطٌ أبداً ويردّ المحرّكُ
        //      «لا رسم».
        //
        //   ② والمفتاحُ كان `fee_amount` والمحرّكُ يردّ `fee`. فحتّى لو
        //      طابق الرمزُ لقرأ مفتاحاً غير موجودٍ فصار صفراً.
        //
        // **والأثرُ مالٌ لا عرض:** الرسمُ المحسوب يُمرَّر إلى
        // `PendingTransferService::initiate(fee: …)` — وتلك **تستقبل ولا
        // تحسب**. فكلُّ تحويلٍ عبر واتساب كان **مجّانيّاً تماماً**، ونفسُه
        // في التطبيق يُرسَم. قناتان، ورسمان، لعمليّةٍ واحدة.
        //
        // ولا خطأ في أيّ سجلّ: الردُّ صحيحُ الشكل، والصفرُ يُقرأ «لا رسم
        // على هذه العمليّة».
        $feeData = $this->feeSvc->calculate('SEND_MONEY', $amount, [
            'zone_code' => $user->zone_code ?? 'SOUTH',
            'applies_to' => 'customer',
        ]);
        $fee     = (string) ($feeData['fee'] ?? '0');
        $total   = bcadd($amount, $fee, 4);

        if (bccomp($total, $balance, 2) > 0) {
            $this->send($phone, $this->fmt->insufficientBalance($balance, $total));
            $this->session->clear($phone);
            return;
        }

        $this->session->advance($phone, WhatsappSessionManager::STEP_TRANSFER_PIN_SENT, [
            'amount' => $amount, 'fee' => $fee, 'total' => $total,
        ]);

        $toMaskedName = $this->session->getData($phone, 'to_masked_name', '***');
        $toPhone      = $this->session->getData($phone, 'to_phone', '');

        $pinUrl = $this->createSecurePinUrl($phone, $user->id);

        $confirmMsg  = $this->fmt->confirmTransfer($toMaskedName, $toPhone, $amount, $fee, $total);
        $confirmMsg .= "\n" . $this->fmt->transferPinLink($pinUrl);
        $this->send($phone, $confirmMsg);
    }

    private function handlePinStep(string $phone, User $user, string $raw): void
    {
        $this->send($phone,
            "⚠️ لتأكيد الأمان، يجب إدخال PIN عبر الرابط المُرسَل.\n\n"
            . "إن انتهت صلاحيته، اكتب *إلغاء* وابدأ من جديد."
        );
    }

    public function executeTransferAfterPin(string $phone, User $user, string $pin): array
    {
        $data = $this->session->get($phone);
        if (!$data || $data['step'] !== WhatsappSessionManager::STEP_TRANSFER_PIN_SENT) {
            return ['success' => false, 'message' => 'انتهت صلاحية الجلسة'];
        }

        $amount       = $data['data']['amount']        ?? '0';
        $fee          = $data['data']['fee']            ?? '0';
        $toPhone      = $data['data']['to_phone']       ?? '';
        $toUserId     = $data['data']['to_user_id']     ?? null;
        $toMaskedName = $data['data']['to_masked_name'] ?? '***';
        $verifyToken  = $data['data']['verify_token']   ?? null;

        $recipient = User::find($toUserId);
        if (!$recipient) {
            $this->session->clear($phone);
            return ['success' => false, 'message' => 'لم يُعثَر على المستقبل'];
        }

        // السقفُ قبل التنفيذ — لا بعد الخصم.
        if ($blocked = $this->refuseIfOverLimit($phone, (string) $amount, 'transfer')) {
            return $blocked;
        }

        $this->auditTransfer($phone, $user->id, WhatsappAuditLog::EVENT_TRANSFER_ATTEMPT,
            WhatsappAuditLog::OUTCOME_SUCCESS, $amount);

        try {
            if ($verifyToken) {
                $this->recipientSvc->assertValidToken($user->id, $verifyToken, $recipient->id);
            }

            $pending = $this->transferSvc->initiate(
                sender: $user, recipient: $recipient, amount: $amount,
                pin: $pin, fee: $fee, note: 'تحويل عبر واتساب',
            );

            $newWallet  = EMoney::where('user_id', $user->id)->first();
            $newBalance = (string) ($newWallet?->current_balance ?? '0');

            $this->session->clear($phone);

            $this->auditTransfer($phone, $user->id, WhatsappAuditLog::EVENT_TRANSFER_SUCCESS,
                WhatsappAuditLog::OUTCOME_SUCCESS, $amount);

            $this->send($phone, $this->fmt->transferSuccess(
                $toMaskedName, $amount, $newBalance,
                $pending->transfer_ulid ?? 'TXN-' . strtoupper(Str::random(8)),
            ));

            return ['success' => true];

        } catch (\Throwable $e) {
            $this->session->clear($phone);
            $raw  = $e->getMessage();          // للتدقيق/السجل (داخلي)
            $safe = $this->safeErr($e);        // للعرض للمستخدم (بلا تسريب)

            $this->auditTransfer($phone, $user->id, WhatsappAuditLog::EVENT_TRANSFER_FAILED,
                WhatsappAuditLog::OUTCOME_FAILED, $amount, $raw);
            $this->bumpRiskOnFailure($phone, 'transfer_failed_after_pin');

            $this->send($phone, $this->fmt->transferFailed($safe));
            return ['success' => false, 'message' => $safe];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Section 22 — دفع الفواتير (AMIAL-WA-003)
    // ══════════════════════════════════════════════════════════════

    private function startBillPay(string $phone, User $user): void
    {
        $services = $this->activeBillServicesForUser($user);

        if ($services->isEmpty()) {
            $this->send($phone, $this->fmt->billPayServiceList([]));
            return;
        }

        // محاولة مطابقة مباشرة (مثل "فاتورة الكهرباء" → خدمة الكهرباء)
        // عبر جلسة الإدخال الأصلية — هنا لا نملك النصّ الخام، لذا نعرض
        // القائمة دائماً عند الدخول من القائمة الرئيسية. المطابقة
        // المباشرة تحدث فقط ضمن STEP_BILLPAY_PICK_SERVICE أدناه.
        $list = $services->map(fn($s) => [
            'id' => $s->id, 'display_name_ar' => $s->display_name_ar,
        ])->values()->toArray();

        $this->session->advance($phone, WhatsappSessionManager::STEP_BILLPAY_PICK_SERVICE, [
            'service_candidates' => $list,
        ]);
        $this->send($phone, $this->fmt->billPayServiceList($list));
    }

    private function handleBillPayServicePick(string $phone, User $user, string $raw): void
    {
        $candidates = $this->session->getData($phone, 'service_candidates', []);
        $raw        = trim($raw);

        // اختيار برقم من القائمة
        if (preg_match('/^\d+$/', $raw)) {
            $index = (int) $raw - 1;
            if (isset($candidates[$index])) {
                $this->proceedToBillPayAccount($phone, $candidates[$index]);
                return;
            }
        }

        // اختيار باسم الخدمة (مطابقة جزئية)
        $needle = mb_strtolower($raw);
        foreach ($candidates as $c) {
            if (mb_strpos(mb_strtolower($c['display_name_ar']), $needle) !== false) {
                $this->proceedToBillPayAccount($phone, $c);
                return;
            }
        }

        $this->send($phone, $this->fmt->billPayNoMatch($candidates));
    }

    private function proceedToBillPayAccount(string $phone, array $service): void
    {
        $this->session->advance($phone, WhatsappSessionManager::STEP_BILLPAY_ACCOUNT, [
            'service_id'   => $service['id'],
            'service_name' => $service['display_name_ar'],
        ]);
        $this->send($phone, $this->fmt->askSubscriberAccount($service['display_name_ar']));
    }

    private function handleBillPayAccount(string $phone, User $user, string $raw): void
    {
        $serviceId = $this->session->getData($phone, 'service_id');
        $serviceName = $this->session->getData($phone, 'service_name', '');
        $account = trim($raw);

        $service = \App\Models\BillService::with('provider')
            ->where('id', $serviceId)->where('is_active', true)->first();

        if (!$service || !$service->provider) {
            $this->send($phone, $this->fmt->billInquiryFailed('الخدمة غير متاحة حالياً'));
            $this->session->clear($phone);
            return;
        }

        if (empty($account)) {
            $this->send($phone, $this->fmt->billInquiryFailed('رقم الاشتراك غير صالح'));
            return;
        }

        try {
            $providerImpl = $this->billPaySvc->resolveProvider($service->provider);
            $response     = $providerImpl->inquire($account);
        } catch (\Throwable $e) {
            Log::error('[WA Bot] bill inquire failed', ['error' => $e->getMessage()]);
            $this->send($phone, $this->fmt->billInquiryFailed('تعذّر الاتصال بمزوّد الخدمة، حاول لاحقاً'));
            return;
        }

        if (!$response->success) {
            $this->send($phone, $this->fmt->billInquiryFailed($response->message ?? 'رقم الاشتراك غير صحيح'));
            return; // يبقى في نفس الخطوة للمحاولة مجدّداً
        }

        $balanceDue = (string) ($response->rawResponse['balance_due'] ?? '0');

        $this->session->advance($phone, WhatsappSessionManager::STEP_BILLPAY_AMOUNT, [
            'service_id'     => $service->id,
            'service_name'   => $serviceName,
            'provider_id'    => $service->provider->id,
            'account'        => $account,
        ]);

        // فاتورة آجلة (postpaid) بمبلغ معروف → تخطّي خطوة إدخال المبلغ
        if (bccomp($balanceDue, '0', 4) > 0) {
            $this->proceedToBillPayConfirm($phone, $user, $balanceDue);
            return;
        }

        // خدمة مسبقة الدفع (مثل شحن) → المستخدم يحدّد المبلغ
        $this->send($phone, $this->fmt->askBillAmount($serviceName));
    }

    private function handleBillPayAmount(string $phone, User $user, array $intent, string $raw): void
    {
        $amount = $intent['params']['amount'] ?? $this->parser->extractAmount($raw);
        if (!$amount) {
            $this->send($phone, "❌ المبلغ غير صالح.\n\nأدخل مبلغاً بالريال اليمني (مثال: 2000)");
            return;
        }
        $this->proceedToBillPayConfirm($phone, $user, $amount);
    }

    private function proceedToBillPayConfirm(string $phone, User $user, string $amount): void
    {
        $serviceName = $this->session->getData($phone, 'service_name', '');
        $account     = $this->session->getData($phone, 'account', '');

        $fee   = $this->billPaySvc->previewFee(null, $amount);
        $total = bcadd($amount, $fee, 4);

        $wallet  = EMoney::where('user_id', $user->id)->first();
        $balance = (string) ($wallet?->current_balance ?? '0');

        if (bccomp($total, $balance, 2) > 0) {
            $this->send($phone, $this->fmt->insufficientBalance($balance, $total));
            $this->session->clear($phone);
            return;
        }

        $this->session->advance($phone, WhatsappSessionManager::STEP_BILLPAY_PIN_SENT, [
            'amount' => $amount, 'fee' => $fee, 'total' => $total,
        ]);

        $pinUrl = $this->createSecurePinUrl($phone, $user->id, 'billpay');
        $msg    = $this->fmt->confirmBillPay($serviceName, $account, $amount, $fee, $total);
        $msg   .= "\n" . $this->fmt->transferPinLink($pinUrl);
        $this->send($phone, $msg);
    }

    /** يُستدعى من WhatsappPinController بعد تأكيد PIN لطلب دفع فاتورة. */
    public function executeBillPayAfterPin(string $phone, User $user, string $pin): array
    {
        $data = $this->session->get($phone);
        if (!$data || $data['step'] !== WhatsappSessionManager::STEP_BILLPAY_PIN_SENT) {
            return ['success' => false, 'message' => 'انتهت صلاحية الجلسة'];
        }

        if (!$this->pinSvc->verify($user, $pin)) {
            $this->bumpRiskOnFailure($phone, 'billpay_wrong_pin');
            return ['success' => false, 'message' => 'رمز PIN غير صحيح'];
        }

        $serviceId   = $data['data']['service_id']   ?? null;
        $serviceName = $data['data']['service_name'] ?? '';
        $providerId  = $data['data']['provider_id']  ?? null;
        $account     = $data['data']['account']      ?? '';
        $amount      = $data['data']['amount']        ?? '0';

        $service  = \App\Models\BillService::find($serviceId);
        $provider = \App\Models\BillProvider::find($providerId);

        if (!$service || !$provider) {
            $this->session->clear($phone);
            return ['success' => false, 'message' => 'تعذّر إيجاد الخدمة'];
        }

        if ($blocked = $this->refuseIfOverLimit($phone, (string) $amount, 'bill_pay')) {
            return $blocked;
        }

        try {
            $order = $this->billPaySvc->createAndExecute(
                user: $user, provider: $provider, service: $service,
                product: null, subscriberAccount: $account, amount: $amount,
            );

            $this->session->clear($phone);

            $message = match ($order->status) {
                'success' => $this->fmt->billPaySuccess($serviceName, $amount, $order->order_ulid),
                'pending_provider_confirmation' => $this->fmt->billPayPending($serviceName, $order->order_ulid),
                default   => $this->fmt->billPayFailed($order->provider_message ?? 'تعذّر إكمال الدفع'),
            };
            $this->send($phone, $message);

            return ['success' => $order->status !== 'failed'];

        } catch (\Throwable $e) {
            $this->session->clear($phone);
            $msg = $this->translateBillPayError($e->getMessage());
            $this->send($phone, $this->fmt->billPayFailed($msg));
            return ['success' => false, 'message' => $msg];
        }
    }

    /** يُرجع الخدمات المتاحة للمستخدم وفق منطقته (لا قائمة ثابتة). */
    private function activeBillServicesForUser(User $user)
    {
        return \App\Models\BillService::with('provider')
            ->where('is_active', true)
            ->whereHas('provider', function ($q) use ($user) {
                $q->where('is_active', true)
                  ->where('zone_code', $user->zone_code ?? 'SOUTH');
            })
            ->get();
    }

    private function translateBillPayError(string $message): string
    {
        return match (true) {
            str_contains($message, 'SOUTH')   => 'هذه الخدمة غير متاحة في منطقتك حالياً',
            str_contains($message, 'active')  => 'هذه الخدمة غير متاحة حالياً',
            default                            => $message,
        };
    }

    // ══════════════════════════════════════════════════════════════
    // Section 23 — طلبات الدفع عبر رابط/رمز/QR (AMIAL-WA-003)
    // ══════════════════════════════════════════════════════════════

    /** نقطة دخول واحدة لكل من: نصّ مكتوب، رابط، أو نتيجة فكّ صورة QR. */
    private function startPaymentRequestFlow(string $phone, User $user, string $code): void
    {
        if (empty($code)) {
            $this->send($phone, $this->fmt->paymentRequestNotFound());
            return;
        }

        $request = $this->paymentRequestSvc->findByCode($code);

        if (!$request || !$request->isActive()) {
            $this->send($phone, $this->fmt->paymentRequestNotFound());
            return;
        }

        if ($request->requester_user_id === $user->id) {
            $this->send($phone, $this->fmt->paymentRequestPayFailed('لا يمكنك دفع طلب أنشأته بنفسك'));
            return;
        }

        $requester = User::find($request->requester_user_id);
        $requesterName = $requester
            ? trim("{$requester->f_name} {$requester->l_name}")
            : 'تاجر أميال باي';

        $wallet  = EMoney::where('user_id', $user->id)->first();
        $balance = (string) ($wallet?->current_balance ?? '0');

        if (bccomp((string) $request->amount, $balance, 2) > 0) {
            $this->send($phone, $this->fmt->insufficientBalance($balance, (string) $request->amount));
            return;
        }

        $this->session->advance($phone, WhatsappSessionManager::STEP_PAYREQ_PIN_SENT, [
            'payment_request_id' => $request->id,
            'requester_name'     => $requesterName,
            'amount'             => (string) $request->amount,
        ]);

        $pinUrl = $this->createSecurePinUrl($phone, $user->id, 'payment_request');
        $msg    = $this->fmt->confirmPaymentRequest(
            $requesterName, (string) $request->amount, $request->note ?? null, $request->short_code,
        );
        $msg   .= "\n" . $this->fmt->transferPinLink($pinUrl);
        $this->send($phone, $msg);
    }

    /** يُستدعى من WhatsappPinController بعد تأكيد PIN لدفع طلب دفع. */
    public function executePaymentRequestAfterPin(string $phone, User $user, string $pin): array
    {
        $data = $this->session->get($phone);
        if (!$data || $data['step'] !== WhatsappSessionManager::STEP_PAYREQ_PIN_SENT) {
            return ['success' => false, 'message' => 'انتهت صلاحية الجلسة'];
        }

        if (!$this->pinSvc->verify($user, $pin)) {
            $this->bumpRiskOnFailure($phone, 'payreq_wrong_pin');
            return ['success' => false, 'message' => 'رمز PIN غير صحيح'];
        }

        $requestId     = $data['data']['payment_request_id'] ?? null;
        $requesterName = $data['data']['requester_name']      ?? 'تاجر';
        $amount        = $data['data']['amount']               ?? '0';

        $paymentRequest = PaymentRequest::find($requestId);
        if (!$paymentRequest) {
            $this->session->clear($phone);
            return ['success' => false, 'message' => 'تعذّر إيجاد طلب الدفع'];
        }

        // **والمبلغُ يُقرأ من الفاتورة لا من الجلسة** (القاعدة السادسة):
        // ما في الجلسة نصٌّ عُرض على المستعمل، وما في الفاتورة هو ما
        // سيُخصم. ولو اختلفا لمرّ الفحصُ على رقمٍ غيرِ الذي يُدفع.
        if ($blocked = $this->refuseIfOverLimit($phone, (string) $paymentRequest->amount, 'payment_request')) {
            return $blocked;
        }

        try {
            $result = $this->paymentRequestSvc->pay($user, $paymentRequest);
            $this->session->clear($phone);

            $txId = $result['request']->paid_transaction_id ?? ('TXN-' . strtoupper(Str::random(8)));
            $this->send($phone, $this->fmt->paymentRequestPaySuccess($requesterName, $amount, $txId));

            return ['success' => true];

        } catch (\Throwable $e) {
            $this->session->clear($phone);
            $safe = $this->safeErr($e);
            $this->send($phone, $this->fmt->paymentRequestPayFailed($safe));
            return ['success' => false, 'message' => $safe];
        }
    }

    /** يُستدعى من WhatsappWebhookController عند فشل فكّ صورة QR. */
    public function notifyQrDecodeFailed(string $phone): void
    {
        $this->send($phone, $this->fmt->qrDecodeFailed());
    }

    // ══════════════════════════════════════════════════════════════
    // كشف الحساب
    // ══════════════════════════════════════════════════════════════

    private function showStatement(string $phone, User $user): void
    {
        $wallet = EMoney::where('user_id', $user->id)->first();
        if (!$wallet) {
            $this->send($phone, $this->fmt->error('لم يُعثَر على محفظتك'));
            return;
        }

        $transactions = DB::table('transactions')
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)->orWhere('recipient_id', $user->id);
            })
            ->whereNotNull('confirmed_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($tx) => [
                'direction'   => $tx->sender_id == $user->id ? 'out' : 'in',
                'amount'      => $tx->amount,
                'description' => $tx->note ?? ($tx->sender_id == $user->id ? 'تحويل صادر' : 'تحويل وارد'),
                'created_at'  => $tx->confirmed_at ?? $tx->created_at,
            ])->toArray();

        $this->send($phone, $this->fmt->statement($transactions, (string) ($wallet->current_balance ?? '0')));
    }

    // ══════════════════════════════════════════════════════════════
    // أقرب وكيل
    // ══════════════════════════════════════════════════════════════

    private function showAgents(string $phone, User $user): void
    {
        // **ثلاثة أعطالٍ كانت هنا، وكلٌّ منها كافٍ وحده لإسقاط الأمر:**
        //
        //   `users.type = 2`            — والوكيل type = 1 (AGENT_TYPE).
        //                                  فالنتيجة صفرٌ دائماً.
        //   `agent_profiles.is_active`  — العمود اسمه `status`.
        //   `agent_profiles.display_name` — العمود اسمه `business_name`.
        //
        // والأخيران يُسقطان الاستعلام بـSQLSTATE[42S22]، فيرى العميل
        // «حدث خطأ» كلّما سأل عن أقرب وكيل. ولم يكشفه شيء لأنّ لا اختبار
        // ينادي هذا المسار.
        //
        // **والفروع تُستثنى**: الفرع حسابُ وكيلٍ ابن، وعرضُه للعميل يُرسله
        // إلى فرعٍ باسم شركةٍ لا يعرفه.
        $branchAccounts = \App\Models\Agent\AgentBranch::pluck('branch_user_id')->all();

        $agents = DB::table('users')
            ->join('agent_profiles', 'users.id', '=', 'agent_profiles.user_id')
            ->where('users.type', AGENT_TYPE)
            ->where('users.is_active', 1)
            ->where('agent_profiles.status', 'active')
            ->whereNotIn('users.id', $branchAccounts ?: [0])
            ->where('agent_profiles.zone_code', $user->zone_code ?? 'SOUTH')
            ->select('users.f_name', 'users.l_name', 'users.phone', 'agent_profiles.business_name')
            ->limit(5)
            ->get()
            ->map(fn($a) => [
                'display_name' => $a->business_name ?: trim("{$a->f_name} {$a->l_name}"),
                'phone'        => $a->phone,
            ])->toArray();

        $this->send($phone, $this->fmt->agentList($agents));
    }

    // ══════════════════════════════════════════════════════════════
    // Section 29 — دعم العملاء (نسخة مبسّطة)
    // ══════════════════════════════════════════════════════════════

    private function startSupport(string $phone): void
    {
        $this->session->advance($phone, 'support_awaiting_message');
        $this->send($phone, $this->fmt->askSupportMessage());
    }

    private function finishSupport(string $phone, User $user, string $message): void
    {
        $ticketRef = 'TKT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));

        try {
            WhatsappAuditLog::create([
                'whatsapp_number' => $phone,
                'user_id'         => $user->id,
                'event_type'      => WhatsappAuditLog::EVENT_COMMAND,
                'intent'          => 'support_ticket',
                'outcome'         => WhatsappAuditLog::OUTCOME_SUCCESS,
                'metadata'        => ['ticket_ref' => $ticketRef, 'message' => mb_substr($message, 0, 500)],
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[WA Bot] support ticket log failed', ['error' => $e->getMessage()]);
        }

        $this->session->clear($phone);
        $this->send($phone, $this->fmt->supportTicketCreated($ticketRef));
    }

    // ══════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════

    /** Section 9 — بحث بسيط بالاسم، يستثني المستخدم نفسه. */
    /**
     * رسالة خطأ آمنة للمستخدم: تُظهر رسائل النطاق (InvalidArgument/Runtime) فقط
     * — وهي عربية موجّهة أصلاً — وتُخفي تفاصيل داخلية (SQL/أنواع) وتُسجّلها فقط.
     */
    private function safeErr(\Throwable $e): string
    {
        if ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
            return $e->getMessage();
        }
        Log::error('[WA Bot] internal error', [
            'type'  => get_class($e),
            'error' => mb_substr($e->getMessage(), 0, 200),
        ]);
        return 'حدث خطأ غير متوقّع. حاول لاحقاً.';
    }

    private function searchUsersByName(string $name, int $excludeUserId)
    {
        // AMIAL-FIX (enumeration): حدّ أدنى لطول الاسم يمنع تعداد الحسابات بحرف واحد.
        $name = trim($name);
        if (mb_strlen($name) < 3) {
            return collect();
        }

        return User::query()
            ->whereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ['%' . $name . '%'])
            ->where('id', '!=', $excludeUserId)
            ->whereIn('type', [3, 4]) // عميل / تاجر فقط
            ->limit(self::MAX_RECIPIENT_CANDIDATES)
            ->get(['id', 'f_name', 'l_name', 'phone']);
    }

    private function createSecurePinUrl(string $phone, int $userId, string $purpose = 'transfer'): string
    {
        $token = Str::random(40);
        Cache::put("wa_pin_token:{$token}", [
            'phone' => $phone, 'user_id' => $userId, 'purpose' => $purpose,
        ], now()->addMinutes(2));
        return url("/wa/pin/{$token}");
    }

    /** Section 26 — رفع مخاطر مبسّط عند فشل متكرّر (heuristic أساسي، لا يستبدل AML). */
    private function bumpRiskOnFailure(string $phone, string $reason): void
    {
        $this->linking->bumpRisk($phone, 15, $reason);
    }

    private function auditCommand(string $phone, ?int $userId, string $intent, string $outcome): void
    {
        try {
            WhatsappAuditLog::create([
                'whatsapp_number' => $phone, 'user_id' => $userId,
                'event_type' => WhatsappAuditLog::EVENT_COMMAND, 'intent' => $intent,
                'outcome' => $outcome, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[WA Bot] audit failed', ['error' => $e->getMessage()]);
        }
    }

    private function auditTransfer(
        string $phone, int $userId, string $event, string $outcome, string $amount, ?string $err = null
    ): void {
        try {
            WhatsappAuditLog::create([
                'whatsapp_number' => $phone, 'user_id' => $userId,
                'event_type' => $event, 'intent' => 'transfer', 'outcome' => $outcome,
                'metadata' => ['amount' => $amount, 'error' => $err], 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[WA Bot] transfer audit failed', ['error' => $e->getMessage()]);
        }
    }

    private function send(string $phone, string $text): void
    {
        $this->client->sendText($phone, $text);
    }

    private function maskPhone(string $phone): string
    {
        return mb_strlen($phone) > 6 ? mb_substr($phone, 0, 4) . '****' : '****';
    }
}
