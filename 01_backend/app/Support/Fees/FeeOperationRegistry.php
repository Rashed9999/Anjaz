<?php

namespace App\Support\Fees;

/**
 * AMIAL-FEE-TRUTH-009 — **سجلُّ العمليّات المسعَّرة: قائمةٌ واحدةٌ لا ثلاث.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي أدخل هذا الملفّ:**
 *
 * كانت معرفةُ العمليّات المسعَّرة موزّعةً على ثلاثة مواضعَ لا يتحدّث
 * بعضُها إلى بعض:
 *
 *   `FeeScheme::CODES`          قائمةُ رموزٍ خام — لا أسماءَ عربيّة
 *   `FeeCodeReachabilityTest`   قائمةُ «غيرُ موصولةٍ بعد» وأسبابُها
 *   شاشةُ إنشاء النسخة          تعرض الرموزَ كما هي، وكلَّ الحقول لكلٍّ
 *
 * فالنتيجة:
 *
 * **① الأدمنُ يسعّر ما لا يعرف اسمَه.** قائمةٌ منسدلةٌ فيها
 * `FAMILY_FUND_CONTRIB` و`SPLIT_BILL` بحروفٍ لاتينيّة، ومن خمّن أيَّها
 * يريد أخطأ في المال.
 *
 * **② وحقولٌ تُملأ ولا أثرَ لها.** «حصّةُ الوكيل» معروضةٌ في نسخةِ
 * `SEND_MONEY` — **ولا وكيلَ في التحويل أصلاً**. فمن ملأها ظنّ أنّه
 * منح الوكلاءَ عمولةً على التحويلات، والرقمُ يُخصم من ربح المنصّة
 * ويُقيَّد لحصّةٍ لا صاحبَ لها.
 *
 * **③ و«لا رسمَ لها» تختلط بـ«لا نعرف».** رمزٌ بلا مستهلكٍ يُعرض في
 * الشاشة كإخوته، فيُضبَط ويُحفَظ ويُرى فعّالاً ولا يُخصم منه ريال.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **فصار لكلّ عمليّةٍ صفٌّ واحدٌ يقول كلَّ شيء**، ومنه تقرأ:
 *
 *   الشاشةُ    الاسمَ العربيَّ، وأيَّ الحقول تُعرض أصلاً
 *   المحرّكُ   الجهاتِ والمتحمّلين المشروعين
 *   الحارسُ    أنّ كلَّ رمزٍ مستهلَك، وكلَّ مستهلَكٍ قابلٌ للضبط
 *
 * **و`FeeScheme::codes()` تقرأ من هنا** — فما ليس في هذا الملفّ لا
 * يُسعَّر، وما فيه يُسعَّر. قائمةٌ واحدة.
 */
final class FeeOperationRegistry
{
    /** تبويباتُ مركز الرسوم — عربيّةٌ لأنّ الشاشةَ عربيّة. */
    public const CATEGORIES = [
        'transfer' => 'التحويلات',
        'cash' => 'النقد والشبّاك',
        'merchant' => 'مدفوعات التجّار',
        'payout' => 'السحب الخارجيّ',
        'bills' => 'الفواتير والخدمات',
        'other' => 'أخرى',
    ];

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? $category;
    }

    /**
     * **السجلُّ نفسُه.**
     *
     * @return array<string,FeeOperation> مفتاحُه الرمزُ نفسُه
     */
    public static function all(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $ops = [

            // ══════════════════════════════════════════════════════════
            // التحويلات
            // ══════════════════════════════════════════════════════════

            new FeeOperation(
                code: 'SEND_MONEY',
                labelAr: 'تحويل بين المحافظ',
                labelEn: 'Wallet transfer',
                category: 'transfer',
                actors: ['customer'],
                bearers: ['sender', 'receiver'],

                // **لا وكيلَ في التحويل.** القيمةُ تنتقل من محفظةٍ إلى
                // محفظةٍ بلا يدٍ بشريّة، فحصّةُ الوكيل هنا **تُقتطع من
                // ربح المنصّة وتُقيَّد لمن لم يعمل**.
                agentCommission: false,
                zoneScoped: true,
                consumers: [
                    'app/Http/Controllers/Api/V1/Customer/TransactionController.php',
                    'app/Traits/TransactionTrait.php',
                    'app/Services/PaymentRequestService.php',
                    'app/Services/Whatsapp/WhatsappBotService.php',
                ],
                owner: 'App\\Services\\FeeService عبر Customer\\TransactionController::sendMoney',
            ),

            // ══════════════════════════════════════════════════════════
            // النقد والشبّاك — وهنا وحدَها تُعقل حصّةُ الوكيل
            // ══════════════════════════════════════════════════════════

            new FeeOperation(
                code: 'CASH_OUT',
                labelAr: 'سحب نقديّ من وكيل',
                labelEn: 'Cash out at agent',
                category: 'cash',
                actors: ['customer'],
                bearers: ['sender', 'receiver'],

                // **الوكيلُ يسلّم نقداً من خزنته** — فله حصّة، وبلاها لا
                // نموذجَ عملٍ له.
                agentCommission: true,
                zoneScoped: true,
                consumers: [
                    'app/Http/Controllers/Api/V1/Customer/TransactionController.php',
                    'app/Services/CustomerWithdrawService.php',
                    'app/Traits/TransactionTrait.php',
                ],
                owner: 'App\\Services\\CustomerWithdrawService',
            ),

            new FeeOperation(
                code: 'AGENT_DEPOSIT',
                labelAr: 'إيداع من شبّاك الوكيل',
                labelEn: 'Agent counter deposit',
                category: 'cash',
                actors: ['agent'],
                bearers: ['sender', 'receiver'],
                agentCommission: true,
                zoneScoped: true,
                consumers: ['app/Services/AgentCounterService.php'],
                owner: 'App\\Services\\AgentCounterService::quote',
            ),

            new FeeOperation(
                code: 'AGENT_WITHDRAW',
                labelAr: 'سحب من شبّاك الوكيل',
                labelEn: 'Agent counter withdrawal',
                category: 'cash',
                actors: ['agent'],
                bearers: ['sender', 'receiver'],
                agentCommission: true,
                zoneScoped: true,
                consumers: ['app/Services/AgentCounterService.php'],
                owner: 'App\\Services\\AgentCounterService::quote',
            ),

            new FeeOperation(
                code: 'CASH_IN',
                labelAr: 'إيداع نقديّ (قناة مستقبليّة)',
                labelEn: 'Cash in',
                category: 'cash',
                actors: ['customer'],
                bearers: ['sender'],
                agentCommission: true,
                zoneScoped: true,
                consumers: [],
                owner: '—',
                notWiredReason: 'إيداعُ العميل عبر الوكيل يمرّ بـAGENT_DEPOSIT — وهذا '
                    . 'الرمزُ باقٍ لقناةٍ مستقبليّة (إيداعٌ بنكيٌّ مباشر) ولا يُحصَّل '
                    . 'منه شيءٌ اليوم.',
            ),

            // ══════════════════════════════════════════════════════════
            // مدفوعات التجّار
            // ══════════════════════════════════════════════════════════

            new FeeOperation(
                code: 'MERCHANT_QR',
                labelAr: 'دفعٌ لتاجرٍ برمز QR',
                labelEn: 'Merchant QR payment',
                category: 'merchant',
                actors: ['merchant'],

                // **التاجرُ يتحمّل** — هذا نموذجُ الشبكات: العميلُ يدفع
                // السعرَ المعروض، والرسمُ يُخصم من حصيلة التاجر.
                bearers: ['merchant', 'sender'],
                agentCommission: false,
                zoneScoped: true,
                consumers: [
                    'app/Http/Controllers/Api/V1/Amial/MerchantPaymentController.php',
                    'app/Traits/TransactionTrait.php',
                ],
                owner: 'App\\Http\\Controllers\\Api\\V1\\Amial\\MerchantPaymentController::pay',
            ),

            new FeeOperation(
                code: 'MERCHANT_POS',
                labelAr: 'دفعٌ لتاجرٍ من جهاز نقطة البيع',
                labelEn: 'Merchant POS payment',
                category: 'merchant',
                actors: ['merchant'],
                bearers: ['merchant', 'sender'],
                agentCommission: false,
                zoneScoped: true,
                consumers: [
                    'app/Http/Controllers/Api/V1/Amial/MerchantPaymentController.php',
                    'app/Traits/TransactionTrait.php',
                ],
                owner: 'App\\Http\\Controllers\\Api\\V1\\Amial\\MerchantPaymentController::pay',
            ),

            new FeeOperation(
                code: 'SAFE_PAYMENT',
                labelAr: 'الدفع الآمن (وسيط)',
                labelEn: 'Escrow payment',
                category: 'merchant',
                actors: ['customer', 'merchant'],
                bearers: ['sender', 'receiver'],
                agentCommission: false,
                zoneScoped: true,
                consumers: ['app/Services/SafePaymentService.php'],
                owner: 'App\\Services\\SafePaymentService',
            ),

            // ══════════════════════════════════════════════════════════
            // السحب الخارجيّ
            // ══════════════════════════════════════════════════════════

            new FeeOperation(
                code: 'WITHDRAW',
                labelAr: 'سحبٌ إلى وسيلةٍ خارجيّة',
                labelEn: 'Withdrawal to external method',
                category: 'payout',

                // **جهتان لهذه العمليّة**: العميلُ والوكيل. ولكلٍّ نسخةٌ
                // مستقلّة — ورسمُ سحبِ الوكيل ليس رسمَ سحبِ العميل.
                actors: ['customer', 'agent'],
                bearers: ['sender'],
                agentCommission: false,
                zoneScoped: true,
                consumers: [
                    'app/Http/Controllers/Api/V1/Customer/TransactionController.php',
                    'app/Http/Controllers/Api/V1/Agent/TransactionController.php',
                ],
                owner: 'Customer\\TransactionController::withdrawRequest · Agent\\TransactionController',
            ),

            // ══════════════════════════════════════════════════════════
            // الفواتير والخدمات — غيرُ موصولةٍ بعد، وأسبابُها مكتوبة
            // ══════════════════════════════════════════════════════════

            new FeeOperation(
                code: 'BILL_PAY',
                labelAr: 'سداد الفواتير',
                labelEn: 'Bill payment',
                category: 'bills',
                actors: ['customer'],
                bearers: ['sender'],
                agentCommission: false,
                zoneScoped: true,
                // AMIAL-FEE-BILLPAY-001 — **القرارُ اتُّخذ: فوق رسم المزوّد.**
                //
                // كان مكتوباً هنا «قرارٌ ينتظر». وقاله صاحبُ المشروع
                // صراحةً: **رسمُ المنصّة**. فرسمُ المزوّد تكلفةٌ تمرّ إليه،
                // ورسمُ المنصّة أجرُ الخدمة — وجعلُ الثاني بدلَ الأوّل
                // يجعل المنصّةَ تدفع تكلفةَ المزوّد من جيبها.
                //
                // **وحتّى تُضبَط نسخةٌ يبقى صفراً** ويُرفَع عطلُ «تسعيرٌ
                // مفقود» — فالسلوكُ اليومَ لا يتغيّر بحرف.
                consumers: ['app/Services/BillPayService.php'],
                owner: 'App\\Services\\BillPayService',
            ),

            new FeeOperation(
                code: 'SPLIT_BILL',
                labelAr: 'تقسيم الفاتورة',
                labelEn: 'Split bill',
                category: 'bills',
                actors: ['customer'],
                bearers: ['sender'],
                agentCommission: false,
                zoneScoped: true,
                consumers: [],
                owner: '—',
                notWiredReason: 'تقسيمُ الفاتورة يُنفَّذ بلا رسمٍ اليوم.',
            ),

            // ══════════════════════════════════════════════════════════
            // أخرى
            // ══════════════════════════════════════════════════════════

            new FeeOperation(
                code: 'REFUND',
                labelAr: 'استرجاع مبلغ',
                labelEn: 'Refund',
                category: 'other',
                actors: ['customer', 'merchant'],
                bearers: ['sender'],
                agentCommission: false,
                zoneScoped: true,
                consumers: [],
                owner: '—',
                notWiredReason: 'الاسترجاعُ بلا رسمٍ اليوم — وهو الأصلح للعميل.',
            ),

            new FeeOperation(
                code: 'FAMILY_FUND_CONTRIB',
                labelAr: 'مساهمة في صندوق العائلة',
                labelEn: 'Family fund contribution',
                category: 'other',
                actors: ['customer'],
                bearers: ['sender'],
                agentCommission: false,
                zoneScoped: true,
                consumers: [],
                owner: 'App\\Services\\FamilyFundService',
                notWiredReason: 'صندوقُ العائلة يكتب `fee => 0` صراحةً في '
                    . '`FamilyFundService`، فالمساهمةُ مجّانيّةٌ بقرار.',
            ),
        ];

        $cache = [];

        foreach ($ops as $op) {
            $cache[$op->code] = $op;
        }

        return $cache;
    }

    /** @return array<int,string> كلُّ الرموز — وهي ما يستطيع الأدمنُ ضبطَه. */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function find(string $code): ?FeeOperation
    {
        return self::all()[$code] ?? null;
    }

    /** الاسمُ العربيّ — و**الرمزُ نفسُه** لرمزٍ لا نعرفه، لا فراغ. */
    public static function label(string $code): string
    {
        return self::find($code)?->labelAr ?? $code;
    }

    /** @return array<int,FeeOperation> ما يُخصَم منه مالٌ فعلاً. */
    public static function live(): array
    {
        return array_values(array_filter(self::all(), fn (FeeOperation $o) => $o->isLive()));
    }

    /** @return array<string,string> الرمزُ ⇒ سببُ عدم الوصل. */
    public static function notWired(): array
    {
        $out = [];

        foreach (self::all() as $code => $op) {
            if (! $op->isLive()) {
                $out[$code] = (string) $op->notWiredReason;
            }
        }

        return $out;
    }

    /**
     * **مصنَّفاً للشاشة** — تبويبٌ لكلّ صنف، بترتيب `CATEGORIES`.
     *
     * @return array<string,array<int,FeeOperation>>
     */
    public static function grouped(): array
    {
        $out = [];

        foreach (array_keys(self::CATEGORIES) as $cat) {
            $in = array_values(array_filter(
                self::all(), fn (FeeOperation $o) => $o->category === $cat));

            if ($in !== []) {
                $out[$cat] = $in;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> للردّ JSON وللجافاسكربت في الشاشة. */
    public static function toArray(): array
    {
        return array_values(array_map(fn (FeeOperation $o) => $o->toArray(), self::all()));
    }
}
