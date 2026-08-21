<?php

namespace App\Support;

/**
 * AMIAL-AUDIT-ARABIC-001 — **معجمُ سجلّ التدقيق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** فتح صاحبُ المشروع «سجلّ تدقيق النظام» فرأى شاشةً
 * عنوانُها عربيٌّ ومحتواها كلُّه إنجليزيّ:
 *
 *     CHARITY_SETTLEMENT_GENERATED · agent.teller.counter_opened
 *     withdraw_execute · AGENT_CASH_DEPOSIT · agent.shift.close
 *     CRITICAL · WARNING · e_payment · user
 *
 * وسجلُّ تدقيقٍ لا يقرؤه المدقّق **ليس سجلَّ تدقيق** — هو مصفوفةُ رموز.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقاعدةُ الحاكمةُ هنا: لا يُخترَع معنى.**
 *
 * رمزٌ لا ترجمةَ له يُعرَض **كما هو** ويُوسَم «بلا ترجمة» — ولا يُخمَّن
 * من شكله. فترجمةٌ مخترَعةٌ في سجلِّ تدقيقٍ أسوأُ من رمزٍ إنجليزيّ:
 * الإنجليزيُّ يُوقِف القارئَ ليسأل، والمخترَعةُ تُمرّره واثقاً من معنىً
 * لم يقصده أحد. **وهي القاعدة السابعة بعينها: «غير معروف» ليس صفراً.**
 *
 * ولذلك `action()` تُعيد `translated: false` صراحةً، والقالبُ يُظهر ذلك.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وصيغتان للرموز في المشروع، وكلتاهما تُقرأ:**
 *
 *   · `SCREAMING_SNAKE` — قرارٌ مسمّىً (`WITHDRAW_APPROVED`).
 *   · `dotted.lower`     — حدثٌ في مجالٍ (`agent.shift.close`).
 *
 * والثانيةُ تُترجَم **بالتركيب**: مجالٌ ثمّ موضوعٌ ثمّ فعل. **ولا تُقبل
 * ترجمةٌ جزئيّة** — إن جهل مقطعٌ واحدٌ رُدَّ الرمزُ كلُّه بلا ترجمة، لأنّ
 * «الوكيل ← الورديّة ← reopen_forced» تُقرأ ترجمةً كاملةً وليست كذلك.
 */
class AuditVocabulary
{
    /**
     * الأفعالُ المسمّاة — بالمجال الذي تنتمي إليه.
     *
     * والمجالُ ليس زينة: هو ما تُبنى منه قائمةُ المرشِّح المنسدلة، فيصير
     * البحثُ «أرني كلَّ ما يخصّ الخير» بدل تذكُّرِ تهجئةِ رمز.
     *
     * @var array<string,array{0:string,1:string}>  الرمز => [العربيّة، المجال]
     */
    private const ACTIONS = [
        // ── المال المتحرّك ───────────────────────────────────────────
        'SEND_MONEY_COMPLETED' => ['تحويلُ مالٍ اكتمل', 'money'],
        'ADD_MONEY_COMPLETED' => ['شحنُ رصيدٍ اكتمل', 'money'],
        'ADD_MONEY_BLOCKED_BY_ZONE' => ['شحنٌ حُجب بحسب النطاق', 'money'],
        'CASH_IN_COMPLETED' => ['إيداعٌ نقديٌّ اكتمل', 'money'],
        'CASH_OUT_COMPLETED' => ['صرفٌ نقديٌّ اكتمل', 'money'],
        'TRANSFER_INITIATED' => ['تحويلٌ بُدئ', 'money'],
        'TRANSFER_CANCELLED' => ['تحويلٌ أُلغي', 'money'],
        'DEBIT_DENIED' => ['خصمٌ رُفض', 'money'],
        'TRANSACTION_BLOCKED_BY_HOLD' => ['معاملةٌ حُجبت بحجزٍ قائم', 'money'],
        'TRANSACTION_BLOCKED_BY_ZONE' => ['معاملةٌ حُجبت بحسب النطاق', 'money'],
        'EXTERNAL_ADJUSTMENT_POSTED' => ['تسويةٌ خارجيّةٌ رُحّلت', 'money'],
        'IDEMPOTENCY_BODY_MISMATCH' => ['تكرارٌ بمحتوىً مختلف', 'money'],
        'withdraw_request' => ['طلبُ سحب', 'money'],
        'withdraw_execute' => ['تنفيذُ سحب', 'money'],
        'WITHDRAW_APPROVED' => ['سحبٌ اعتُمد', 'money'],
        'WITHDRAW_DENIED' => ['سحبٌ رُفض', 'money'],
        'TOPUP_REQUESTED' => ['طلبُ تغذيةِ رصيد', 'money'],
        'TREASURY_FLOAT_ISSUED' => ['إصدارُ سيولةٍ من الخزينة', 'money'],
        'hold' => ['حجزُ رصيد', 'money'],

        // ── الفواتير والمدفوعات ─────────────────────────────────────
        'BILL_PAY_SUCCESS' => ['سدادُ فاتورةٍ نجح', 'payments'],
        'BILL_PAY_FAILED_REFUNDED' => ['سدادُ فاتورةٍ فشل ورُدَّ المبلغ', 'payments'],
        'PAYMENT_REQUEST_CREATED' => ['طلبُ دفعٍ أُنشئ', 'payments'],
        'PAYMENT_REQUEST_PAID' => ['طلبُ دفعٍ سُدّد', 'payments'],
        'PAYMENT_REQUEST_DECLINED' => ['طلبُ دفعٍ رُفض', 'payments'],
        'PAYMENT_REQUEST_CANCELLED' => ['طلبُ دفعٍ أُلغي', 'payments'],
        'SAFE_PAYMENT_CREATED' => ['دفعةٌ آمنةٌ أُنشئت', 'payments'],
        'SAFE_PAYMENT_DISPUTED' => ['دفعةٌ آمنةٌ نوزعت', 'payments'],
        'SAFE_PAYMENT_DISPUTE_VIEWED' => ['اطّلاعٌ على نزاع دفعةٍ آمنة', 'payments'],
        'SAFE_PAYMENT_EVIDENCE_VIEWED' => ['اطّلاعٌ على أدلّة نزاع', 'payments'],
        'DISPUTE_RESOLVED' => ['نزاعٌ حُسم', 'payments'],

        // ── التاجر ──────────────────────────────────────────────────
        'MERCHANT_PAYMENT_COMPLETED' => ['دفعةٌ لتاجرٍ اكتملت', 'merchant'],
        'MERCHANT_REFUND_COMPLETED' => ['استردادٌ من تاجرٍ اكتمل', 'merchant'],
        'MERCHANT_INVOICE_CANCELLED' => ['فاتورةُ تاجرٍ أُلغيت', 'merchant'],
        'MERCHANT_TIER_CHANGED' => ['تغيّرت شريحةُ التاجر', 'merchant'],
        'MERCHANT_RISK_CRITICAL' => ['خطرٌ حرجٌ على تاجر', 'merchant'],
        'CATALOG_IMPORTED' => ['استيرادُ كتالوج', 'merchant'],
        'CATALOG_ENTRY_REVIEWED' => ['مراجعةُ صنفٍ في الكتالوج', 'merchant'],
        'FUEL_DELIVERY_POSTED' => ['توريدُ وقودٍ سُجّل', 'merchant'],
        'FUEL_PRICE_APPROVED' => ['سعرُ وقودٍ اعتُمد', 'merchant'],
        'FUEL_STOCK_VARIANCE_OPENED' => ['فتحُ فرقِ مخزونِ وقود', 'merchant'],
        'FUEL_STOCK_VARIANCE_RESOLVED' => ['حسمُ فرقِ مخزونِ وقود', 'merchant'],
        'ADMIN_POS_STAFF_TOGGLE' => ['تفعيلُ موظّفِ نقطةِ بيعٍ أو تعطيله', 'merchant'],
        'open_plans_catalog' => ['فتحُ كتالوج الباقات', 'merchant'],
        'open_subscriptions_admin' => ['فتحُ إدارة الاشتراكات', 'merchant'],

        // ── الوكيل ──────────────────────────────────────────────────
        'AGENT_CASH_DEPOSIT' => ['إيداعٌ نقديٌّ لدى وكيل', 'agent'],
        'AGENT_CASH_WITHDRAW' => ['سحبٌ نقديٌّ لدى وكيل', 'agent'],
        'AGENT_CASH_OUTSIDE_ZONE' => ['حركةُ نقدٍ خارج النطاق', 'agent'],
        'AGENT_TILL_COUNT_DIFF' => ['فرقٌ في جرد الصندوق', 'agent'],
        'AGENT_BRANCH_CREATED' => ['أُنشئ فرعُ وكيل', 'agent'],
        'AGENT_BRANCH_FUNDED' => ['تمويلُ فرع', 'agent'],
        'AGENT_BRANCH_SWEPT' => ['تجميعٌ من فرع', 'agent'],
        'SETTLEMENT_APPROVED' => ['تسويةٌ اعتُمدت', 'agent'],
        'SETTLEMENT_REJECTED' => ['تسويةٌ رُفضت', 'agent'],

        // ── الخير ───────────────────────────────────────────────────
        'CHARITY_ORG_CREATED' => ['أُنشئت جهةٌ خيريّة', 'charity'],
        'CHARITY_ORG_VERIFIED' => ['وُثّقت جهةٌ خيريّة', 'charity'],
        'CHARITY_ORG_REJECTED' => ['رُفضت جهةٌ خيريّة', 'charity'],
        'CHARITY_ORG_SUSPENDED' => ['أُوقفت جهةٌ خيريّة', 'charity'],
        'CHARITY_CAMPAIGN_CREATED' => ['أُنشئت حملةٌ خيريّة', 'charity'],
        'CHARITY_CAMPAIGN_APPROVED' => ['اعتُمدت حملةٌ خيريّة', 'charity'],
        'CHARITY_CAMPAIGN_PAUSED' => ['أُوقفت حملةٌ مؤقّتاً', 'charity'],
        'CHARITY_SETTLEMENT_GENERATED' => ['أُنشئت تسويةُ جهةٍ خيريّة', 'charity'],
        'CHARITY_SETTLEMENT_PAID_OUT' => ['صُرفت تسويةُ جهةٍ خيريّة', 'charity'],
        'DONATION_COMPLETED' => ['تبرّعٌ اكتمل', 'charity'],
        'DONATION_REFUNDED' => ['تبرّعٌ رُدّ', 'charity'],
        'FAMILY_FUND_CREATED' => ['أُنشئ صندوقُ عائلة', 'charity'],
        'FAMILY_FUND_INVITED' => ['دعوةٌ إلى صندوق عائلة', 'charity'],
        'FAMILY_FUND_JOINED' => ['انضمامٌ إلى صندوق عائلة', 'charity'],
        'FAMILY_FUND_CONTRIBUTE' => ['مساهمةٌ في صندوق عائلة', 'charity'],
        'FAMILY_FUND_DISBURSEMENT_PROPOSED' => ['اقتراحُ صرفٍ من صندوق عائلة', 'charity'],
        'FAMILY_FUND_DISBURSEMENT_REJECTED' => ['رفضُ صرفٍ من صندوق عائلة', 'charity'],

        // ── الهويّة والحماية ────────────────────────────────────────
        'PIN_CHANGED' => ['غُيّر رمزُ الحماية', 'security'],
        'PIN_VERIFIED' => ['تُحقّق من رمز الحماية', 'security'],
        'PIN_VERIFY_BLOCKED' => ['حُجب التحقّقُ من الرمز', 'security'],
        'PIN_FALLBACK_USED' => ['استُعمل البديلُ عن الرمز', 'security'],
        'AUTH_LOCKOUT' => ['إقفالُ حسابٍ بعد محاولات', 'security'],
        'RECOVERY_INITIATED' => ['بُدئت استعادةُ حساب', 'security'],
        'RECOVERY_LOST_PHONE_SUBMITTED' => ['بلاغُ فقدِ هاتف', 'security'],
        'RECOVERY_OTP_FAILED' => ['فشلُ رمز التحقّق في الاستعادة', 'security'],
        'RECOVERY_PIN_FAILED' => ['فشلُ رمز الحماية في الاستعادة', 'security'],
        'RECOVERY_ADMIN_APPROVED' => ['اعتمدت الإدارةُ الاستعادة', 'security'],
        'RECOVERY_SELF_APPROVED' => ['استعادةٌ ذاتيّةٌ اعتُمدت', 'security'],
        'RECOVERY_REJECTED' => ['رُفضت الاستعادة', 'security'],
        'revoked' => ['سُحبت صلاحيّة', 'security'],
        'roles_changed' => ['تغيّرت الأدوار', 'security'],
        'ADMIN_CREATE_USER' => ['أنشأت الإدارةُ مستخدماً', 'security'],
        'ADMIN_OPERATOR_CREATED' => ['أُنشئ مشغّلٌ إداريّ', 'security'],

        // ── الامتثال ────────────────────────────────────────────────
        'KYC_DOCUMENT_UPLOADED' => ['رُفعت وثيقةُ تحقّق', 'compliance'],
        'KYC_DOCUMENT_APPROVED' => ['اعتُمدت وثيقةُ تحقّق', 'compliance'],
        'KYC_DOCUMENT_REJECTED' => ['رُفضت وثيقةُ تحقّق', 'compliance'],
        'KYC_FIELDS_CONFIRMED' => ['أُقرّت بياناتُ التحقّق', 'compliance'],
        'KYC_ACCOUNT_DECISION' => ['قرارٌ على حسابٍ في التحقّق', 'compliance'],
        'AML_INVESTIGATION_OPENED' => ['فُتح تحقيقُ غسلِ أموال', 'compliance'],
        'AML_INVESTIGATION_CLOSED' => ['أُغلق تحقيقُ غسلِ أموال', 'compliance'],
        'AML_STR_GENERATED' => ['أُنشئ بلاغُ اشتباه', 'compliance'],
        'AML_CTR_GENERATED' => ['أُنشئ بلاغُ معاملةٍ نقديّة', 'compliance'],
        'AML_REPORT_SUBMITTED' => ['أُرسل بلاغٌ رقابيّ', 'compliance'],
        'AML_COMPLIANCE_ACTION' => ['إجراءُ امتثال', 'compliance'],
        'AML_USER_OVERRIDE_SET' => ['استثناءُ امتثالٍ على مستخدم', 'compliance'],
        'SANCTION_MATCH_REVIEWED' => ['مراجعةُ مطابقةِ قائمةِ عقوبات', 'compliance'],
        'ZONE_ASSIGNED_FROM_KYC' => ['أُسند النطاقُ من وثائق التحقّق', 'compliance'],
        'ZONE_CHANGED' => ['تغيّر النطاق', 'compliance'],
        'ZONE_BULK_CHANGED' => ['تغييرُ نطاقٍ بالجملة', 'compliance'],
        'ZONE_POLICY_DENIED' => ['رفضٌ بسياسة النطاق', 'compliance'],
        'RESIDENCE_SET' => ['ضُبط مكانُ الإقامة', 'compliance'],
        'RESIDENCE_CHANGE_REQUESTED' => ['طلبُ تغييرِ الإقامة', 'compliance'],
        'TERMS_ACCEPTED' => ['قُبلت الشروط', 'compliance'],
        'TERMS_VERSION_PUBLISHED' => ['نُشرت نسخةٌ من الشروط', 'compliance'],

        // ── الاعتماد والتشغيل ───────────────────────────────────────
        'APPROVAL_REQUESTED' => ['طُلب اعتماد', 'ops'],
        'APPROVAL_GRANTED' => ['مُنح الاعتماد', 'ops'],
        'APPROVAL_REJECTED' => ['رُفض الاعتماد', 'ops'],
        'RECONCILIATION_CASE_OPENED' => ['فُتحت قضيّةُ مصالحة', 'ops'],
        'RECONCILIATION_CASE_SEEN_AGAIN' => ['تكرّرت قضيّةُ المصالحة', 'ops'],
        'SUPPORT_TICKET_CREATED' => ['أُنشئت تذكرةُ دعم', 'ops'],
        'FEATURE_ENABLED' => ['فُعّلت ميزة', 'ops'],
        'FEATURE_DISABLED' => ['عُطّلت ميزة', 'ops'],
        'ADMIN_SETTING_TOGGLE' => ['تبديلُ إعدادٍ إداريّ', 'ops'],
        'ADMIN_WALLET_TRANSFER' => ['تحويلُ محفظةٍ من الإدارة', 'ops'],
        'WA_LIMIT_CHANGED' => ['تغيّر حدُّ واتساب', 'ops'],
        'WA_LIMIT_BLOCKED' => ['حُجب بحدّ واتساب', 'ops'],
        'ops_retry' => ['إعادةُ محاولةٍ تشغيليّة', 'ops'],
        'FINANCIAL_DOCUMENT_RENDERED' => ['أُخرج مستندٌ ماليّ', 'ops'],
        'FINANCIAL_DOCUMENT_PRINTED' => ['طُبع مستندٌ ماليّ', 'ops'],
        'RECEIPT_BUSINESS_REFERENCE_ATTACHED' => ['رُبط مرجعٌ تجاريٌّ بإيصال', 'ops'],
    ];

    /** مقاطعُ الرموز المنقوطة — المجال. */
    private const SEGMENT_DOMAIN = [
        'agent' => 'الوكيل',
        'admin' => 'الإدارة',
        'merchant' => 'التاجر',
        'customer' => 'العميل',
    ];

    /** مقاطعُ الرموز المنقوطة — الموضوع. */
    private const SEGMENT_SUBJECT = [
        'teller' => 'الشبّاك',
        'teller_request' => 'طلبُ الشبّاك',
        'shift' => 'الورديّة',
        'staff' => 'الموظّفون',
        'branch' => 'الفرع',
        'daily_settlement' => 'التسويةُ اليوميّة',
        'payout' => 'الصرف',
        'panic' => 'الاستغاثة',
    ];

    /** مقاطعُ الرموز المنقوطة — الفعل. */
    private const SEGMENT_VERB = [
        'open' => 'فُتحت',
        'close' => 'أُغلقت',
        'submit' => 'رُفعت',
        'accept' => 'قُبلت',
        'reject' => 'رُفضت',
        'approve' => 'اعتُمد',
        'unlock' => 'فُكّ القفل',
        'review' => 'روجعت',
        'request' => 'طُلب',
        'hire' => 'تعيينُ موظّف',
        'limits' => 'تعديلُ الحدود',
        'thresholds' => 'تعديلُ العتبات',
        'reset_password' => 'إعادةُ ضبطِ كلمة المرور',
        'self_password' => 'تغييرُ كلمةِ المرور ذاتيّاً',
        'counter_opened' => 'فُتحت شاشةُ الشبّاك',
        'customer_searched' => 'بحثٌ عن عميل',
        'customer_viewed' => 'عرضُ بطاقةِ عميل',
        'receipt_printed' => 'طُبع إيصال',
        'statement_viewed' => 'عُرض كشفُ ورديّة',
        'risk_flag_shown' => 'ظهرت إشارةُ خطر',
        'risk_flag_overridden' => '⚠ تُوبع رغمَ إشارةِ الخطر',
        'started' => 'بُدئت',
        'resolved' => 'حُسمت',
        'cancelled' => 'أُلغيت',
    ];

    /** مجالاتُ التصنيف — تُبنى منها القائمةُ المنسدلة في المرشِّح. */
    public const DOMAINS = [
        'money' => 'المال المتحرّك',
        'payments' => 'المدفوعات والنزاعات',
        'merchant' => 'التجّار',
        'agent' => 'الوكلاء والشبابيك',
        'charity' => 'الخير وصناديق العائلة',
        'security' => 'الهويّة والحماية',
        'compliance' => 'الامتثال والنطاق',
        'ops' => 'التشغيل والاعتماد',
    ];

    /**
     * **الفعلُ بالعربيّة — أو مُصرَّحٌ بأنّه بلا ترجمة.**
     *
     * @return array{label:string,domain:?string,translated:bool,raw:string}
     */
    public static function action(?string $code): array
    {
        $raw = trim((string) $code);

        if ($raw === '') {
            return ['label' => 'بلا فعلٍ مسجَّل', 'domain' => null,
                'translated' => false, 'raw' => ''];
        }

        if (isset(self::ACTIONS[$raw])) {
            return ['label' => self::ACTIONS[$raw][0], 'domain' => self::ACTIONS[$raw][1],
                'translated' => true, 'raw' => $raw];
        }

        if (str_contains($raw, '.')) {
            $composed = self::composeDotted($raw);

            if ($composed !== null) {
                return $composed + ['raw' => $raw];
            }
        }

        // **ولا يُخمَّن.** رمزٌ مجهولٌ يُعرَض كما هو ويُقال إنّه بلا ترجمة.
        return ['label' => $raw, 'domain' => null, 'translated' => false, 'raw' => $raw];
    }

    /**
     * تركيبُ رمزٍ منقوط — **أو لا شيء**.
     *
     * ولا تُقبل ترجمةٌ جزئيّة: مقطعٌ مجهولٌ واحدٌ يُبطل التركيبَ كلَّه،
     * فـ«الوكيل ← الورديّة ← reopen_forced» تُقرأ كاملةً وليست كذلك.
     *
     * @return array{label:string,domain:?string,translated:bool}|null
     */
    private static function composeDotted(string $raw): ?array
    {
        $parts = explode('.', $raw);

        if (count($parts) < 2 || count($parts) > 3) {
            return null;
        }

        $domainKey = array_shift($parts);

        if (! isset(self::SEGMENT_DOMAIN[$domainKey])) {
            return null;
        }

        $verbKey = array_pop($parts);
        $subjectKey = $parts[0] ?? null;

        if (! isset(self::SEGMENT_VERB[$verbKey])) {
            return null;
        }

        if ($subjectKey !== null && ! isset(self::SEGMENT_SUBJECT[$subjectKey])) {
            return null;
        }

        $pieces = [self::SEGMENT_DOMAIN[$domainKey]];

        if ($subjectKey !== null) {
            $pieces[] = self::SEGMENT_SUBJECT[$subjectKey];
        }

        $pieces[] = self::SEGMENT_VERB[$verbKey];

        return [
            'label' => implode(' ← ', $pieces),
            'domain' => isset(self::DOMAINS[$domainKey]) ? $domainKey : null,
            'translated' => true,
        ];
    }

    /**
     * **رمزُ القرار.**
     *
     * و`UNKNOWN` هي القيمةُ الافتراضيّةُ في `AuditService` حين لا يُمرَّر
     * رمز — **وهي حالُ كلِّ حدثٍ مرصودٍ لا قرارَ فيه**: «عُرضت بطاقةُ
     * عميل» ليس قراراً بقبولٍ ولا رفض. فتُقال كما هي ولا تُترجَم «مجهول»،
     * لأنّ «مجهول» تُقرأ عطلاً وليست عطلاً.
     *
     * @return array{label:string,tone:string}
     */
    public static function decisionCode(?string $code): array
    {
        $raw = trim((string) $code);

        if ($raw === '' || $raw === 'UNKNOWN') {
            return ['label' => 'حدثٌ مرصود — لا قرار', 'tone' => 'muted'];
        }

        $exact = [
            'OK' => 'مقبول',
            'ACCEPTED' => 'مقبول',
            'APPROVED' => 'معتمَد',
            'COMPLETED' => 'مكتمل',
            'OBSERVED' => 'حدثٌ مرصود — لا قرار',
            'DENIED' => 'مرفوض',
            'BLOCKED' => 'محجوب',
            'REJECTED' => 'مرفوض',
            'FAILED' => 'فاشل',
            'PENDING' => 'معلَّق',
            'CANCELLED' => 'ملغىً',
        ];

        if (isset($exact[$raw])) {
            return ['label' => $exact[$raw], 'tone' => self::tone($raw)];
        }

        // رموزٌ مركّبةٌ مثل `TX_ZONE_BLOCKED` — تُقرأ بلاحقتها ويبقى الرمزُ
        // ظاهراً بجانبها، فلا يُستبدل الدقيقُ بالمقارب.
        foreach (['BLOCKED' => 'محجوب', 'DENIED' => 'مرفوض', 'REJECTED' => 'مرفوض',
            'INSUFFICIENT' => 'رصيدٌ غيرُ كافٍ', 'INVALID' => 'غيرُ صالح',
            'EXPIRED' => 'منتهي الصلاحيّة', 'LIMIT' => 'تجاوزُ حدّ',
            'APPROVED' => 'معتمَد', 'COMPLETED' => 'مكتمل', 'OK' => 'مقبول',
            'ACCEPTED' => 'مقبول'] as $needle => $label) {
            if (str_contains($raw, $needle)) {
                return ['label' => $label, 'tone' => self::tone($raw)];
            }
        }

        return ['label' => $raw, 'tone' => 'muted'];
    }

    private static function tone(string $code): string
    {
        foreach (['BLOCKED', 'DENIED', 'REJECTED', 'INSUFFICIENT', 'INVALID',
            'FAILED', 'EXPIRED', 'LIMIT'] as $bad) {
            if (str_contains($code, $bad)) {
                return 'danger';
            }
        }

        foreach (['OK', 'COMPLETED', 'ACCEPTED', 'APPROVED', 'GRANTED'] as $good) {
            if (str_contains($code, $good)) {
                return 'success';
            }
        }

        return 'muted';
    }

    /**
     * الدرجة — **بترتيبها**، فيُرتَّب المرشِّحُ من الأشدّ.
     *
     * @return array{label:string,class:string,rank:int}
     */
    public static function severity(?string $s): array
    {
        return match (trim((string) $s)) {
            'critical' => ['label' => 'حرِج', 'class' => 'bg-danger', 'rank' => 4],
            'warning' => ['label' => 'تحذير', 'class' => 'bg-warning text-dark', 'rank' => 3],
            'notice' => ['label' => 'ملاحظة', 'class' => 'bg-info', 'rank' => 2],
            'info' => ['label' => 'معلومة', 'class' => 'bg-light text-dark', 'rank' => 1],
            default => ['label' => trim((string) $s) ?: '—', 'class' => 'bg-light text-dark', 'rank' => 0],
        };
    }

    /** الدرجاتُ للقائمة المنسدلة — من الأشدّ. */
    public static function severities(): array
    {
        return ['critical' => 'حرِج', 'warning' => 'تحذير',
            'notice' => 'ملاحظة', 'info' => 'معلومة'];
    }

    /** نوعُ الموضوع — على مَن وقع القرار. */
    public static function subjectType(?string $t): string
    {
        return match (trim((string) $t)) {
            'user' => 'حساب',
            'transaction' => 'معاملة',
            'wallet' => 'محفظة',
            'merchant' => 'تاجر',
            'session' => 'جلسة',
            'pin' => 'رمزُ حماية',
            'support_ticket' => 'تذكرةُ دعم',
            'safe_payment' => 'دفعةٌ آمنة',
            'pending_transfer' => 'تحويلٌ معلَّق',
            'family_fund' => 'صندوقُ عائلة',
            'donation' => 'تبرّع',
            'e_payment' => 'دفعٌ إلكترونيّ',
            'agent_shift' => 'ورديّةُ وكيل',
            'agent_staff' => 'موظّفُ وكيل',
            'agent_branch' => 'فرعُ وكيل',
            'charity_org' => 'جهةٌ خيريّة',
            'charity_campaign' => 'حملةٌ خيريّة',
            'settlement' => 'تسوية',
            'invoice' => 'فاتورة',
            'device' => 'جهاز',
            '', null => '—',
            default => trim((string) $t),
        };
    }

    /** صفةُ المنفِّذ. */
    public static function actorType(?string $t): string
    {
        return match (trim((string) $t)) {
            'system' => 'النظام',
            'admin' => 'إداريّ',
            'agent' => 'وكيل',
            'merchant' => 'تاجر',
            'customer' => 'عميل',
            'user' => 'مستخدم',
            'job' => 'مهمّةٌ مجدولة',
            'api' => 'واجهةٌ برمجيّة',
            '', null => '—',
            default => trim((string) $t),
        };
    }

    /**
     * أفعالٌ معروفةٌ مرتّبةً بالمجال — لقائمة المرشِّح.
     *
     * @return array<string,array<string,string>>  المجال => [الرمز => العربيّة]
     */
    public static function actionsByDomain(): array
    {
        $out = [];

        foreach (self::ACTIONS as $code => [$label, $domain]) {
            $out[$domain][$code] = $label;
        }

        foreach (array_keys($out) as $d) {
            asort($out[$d]);
        }

        return $out;
    }
}
