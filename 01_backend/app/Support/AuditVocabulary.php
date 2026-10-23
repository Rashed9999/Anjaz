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
        'PAYMENT_REQUEST_CANCELLED_BY_REQUESTER' => ['ألغى صاحبُ الطلب طلبَ المال', 'payments'],
        'SAFE_PAYMENT_CREATED' => ['دفعةٌ آمنةٌ أُنشئت', 'payments'],
        'SAFE_PAYMENT_DISPUTED' => ['دفعةٌ آمنةٌ نوزعت', 'payments'],
        'SAFE_PAYMENT_DISPUTE_VIEWED' => ['اطّلاعٌ على نزاع دفعةٍ آمنة', 'payments'],
        'SAFE_PAYMENT_EVIDENCE_VIEWED' => ['اطّلاعٌ على أدلّة نزاع', 'payments'],
        'DISPUTE_RESOLVED' => ['نزاعٌ حُسم', 'payments'],

        // ── التاجر ──────────────────────────────────────────────────
        'MERCHANT_PAYMENT_COMPLETED' => ['دفعةٌ لتاجرٍ اكتملت', 'merchant'],
        // رمزٌ تاريخي ما زال يصدره بعض مسارات الدفع؛ بقاؤه بلا معجم
        // يعرِض للتاجر PAYMENT_REQUEST… بدلاً من معنى العملية.
        'MERCHANT_PAYMENT' => ['دفعةٌ لتاجر', 'merchant'],
        'MERCHANT_STAFF_CREATED' => ['أُنشئ حساب موظّف', 'merchant'],
        'MERCHANT_STAFF_BRANCH_ASSIGNED' => ['أُسند موظّف إلى فرع', 'merchant'],
        'MERCHANT_STAFF_TOGGLED' => ['تغيّرت حالة موظّف', 'merchant'],
        'MERCHANT_ROLE_CREATED' => ['أُنشئ دورٌ تشغيليّ للمنشأة', 'merchant'],
        'MERCHANT_BRANCH_CREATED' => ['أُنشئ فرع', 'merchant'],
        'MERCHANT_BRANCH_UPDATED' => ['عُدّلت بيانات فرع', 'merchant'],
        'MERCHANT_BRANCH_DEFAULTED' => ['تغيّر الفرع الافتراضي', 'merchant'],
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
        'CUSTOMER_POLICY_BLOCKED' => ['حارسُ سياسة العميل منع عملية', 'compliance'],
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

        // ── AMIAL-AUDIT-HUMAN-READABLE-001: إجراءات مركز العملاء والإدارة ──
        'CUSTOMER_FREEZE' => ['تجميدُ حساب عميل', 'security'],
        'CUSTOMER_UNFREEZE' => ['طلبُ فكّ تجميد حساب عميل', 'security'],
        'CUSTOMER_SUSPEND' => ['إيقافٌ مؤقّت لحساب عميل', 'security'],
        'CUSTOMER_ACTIVATE' => ['إعادةُ تفعيل حساب عميل', 'security'],
        'CUSTOMER_CLOSE' => ['طلبُ إغلاق حساب عميل نهائيّاً', 'security'],
        'CUSTOMER_MARK_DECEASED' => ['فتحُ معاملة تعليم حساب متوفّى', 'compliance'],
        'CUSTOMER_RESET_PIN' => ['طلبُ إعادة تعيين رمز PIN لعميل', 'security'],
        'CUSTOMER_REVOKE_SESSIONS' => ['إلغاءُ جلسات العميل وأجهزته النشطة', 'security'],
        'CUSTOMER_REQUIRE_KYC' => ['إلزامُ العميل بتحديث وثائق الهويّة', 'compliance'],
        'CUSTOMER_UPDATE_LIMITS' => ['تعديلُ سقوف وحدود تحويل العميل', 'money'],
        'CUSTOMER_ADD_NOTE' => ['إضافةُ ملاحظة إداريّة على ملفّ العميل', 'ops'],
        'CUSTOMER_ESCALATE_RISK' => ['تصعيدُ ملفّ العميل إلى تحقيقات المخاطر', 'compliance'],
        'ADMIN_FREEZE_ACCOUNT' => ['تجميدُ حساب من لوحة الإدارة', 'security'],
        'ADMIN_UNFREEZE_ACCOUNT' => ['فكُّ تجميد حساب من لوحة الإدارة', 'security'],
        'ADMIN_USER_PROFILE_UPDATED' => ['تعديلُ بيانات حساب مستخدم من الإدارة', 'ops'],

        // ── الدفع الآمن ومطالبات التحويل الخاطئ والائتمان ──
        'SAFE_PAYMENT_ADMIN_RELEASE' => ['حسمُ نزاع دفع آمن: إفراج المبلغ للبائع', 'payments'],
        'SAFE_PAYMENT_ADMIN_REFUND' => ['حسمُ نزاع دفع آمن: إعادة المبلغ للمشتري', 'payments'],
        'SAFE_PAYMENT_ADMIN_PARTIAL' => ['حسمُ نزاع دفع آمن: قسمة المبلغ بين الطرفين', 'payments'],
        'WRONG_TRANSFER_CLAIM_OPENED' => ['فتحُ مطالبة استرداد تحويلٍ خاطئ', 'payments'],
        'WRONG_TRANSFER_CLAIM_RESOLVED' => ['حسمُ مطالبة تحويلٍ خاطئ واسترداد المبلغ', 'payments'],
        'WRONG_TRANSFER_CLAIM_REJECTED' => ['رفضُ مطالبة تحويلٍ خاطئ', 'payments'],
        'WRONG_TRANSFER_CLAIM_EXPIRED' => ['انتهاءُ مهلة حجز مطالبة تحويلٍ خاطئ', 'payments'],
        'WRONG_TRANSFER_RECEIVABLE_COLLECTED' => ['تحصيلُ ذمّة من مطالبة تحويلٍ خاطئ', 'money'],
        'WRONG_TRANSFER_RECOVERED' => ['استردادُ مبلغ تحويلٍ خاطئ', 'money'],
        'HOLD_PARTIAL' => ['حجزٌ جزئيٌّ على الرصيد المتاح', 'money'],
        'DEBT_PAYMENT_COMPLETED' => ['سدادُ مديونيّةٍ اكتمل', 'money'],

        // ── الفواتير ومزوّدو الخدمة والعملات ──
        'BILL_PAY_PENDING_CONFIRMATION' => ['سدادُ فاتورةٍ بانتظار تأكيد المزوّد', 'payments'],
        'BILL_PAY_FAILED_RELEASED' => ['فشلُ سداد فاتورة وفكُّ الحجز للعميل', 'payments'],
        'BILL_PROVIDER_CONFIGURED' => ['ضبطُ إعدادات مزوّد فواتير', 'ops'],
        'BILL_PROVIDER_HEALTH_CHECK' => ['فحصُ اتّصال مزوّد الفواتير', 'ops'],
        'BILL_PROVIDER_ACTIVATED' => ['تفعيلُ مزوّد فواتير', 'ops'],
        'BILL_PROVIDER_DEACTIVATED' => ['تعطيلُ مزوّد فواتير', 'ops'],
        'BILL_PROVIDER_WEBHOOK_RECEIVED' => ['استلامُ إشعار من مزوّد الفواتير', 'payments'],
        'BILL_PROVIDER_WEBHOOK_DUPLICATE' => ['تجاهلُ إشعار مكرّر من مزوّد الفواتير', 'payments'],
        'BILL_SERVICE_ROUTING_SAVED' => ['حفظُ مسار توجيه خدمة الفواتير', 'ops'],
        'FX_RATE_SET' => ['تحديثُ سعر صرف العملة', 'money'],
        'FX_CONVERT' => ['تحويلُ عملة بين محافظ المستخدم', 'money'],

        // ── التجّار والاشتراكات والأصول الثابتة والمورّدون ──
        'MERCHANT_SUBSCRIPTION_BILLED' => ['تحصيلُ اشتراك باقة تاجر من المحفظة', 'merchant'],
        'SUBSCRIPTION_PRICING_UPDATED' => ['تحديثُ تسعيرة باقات التجّار', 'merchant'],
        'SUPPLIER_WALLET_PAYMENT_COMPLETED' => ['سدادُ مورّد من محفظة التاجر اكتمل', 'merchant'],
        'MERCHANT_PAYMENT_REJECTED' => ['رفضُ دفعةٍ لتاجر', 'merchant'],
        'MERCHANT_PAYOUT_REQUESTED' => ['طلبُ تسوية وصرف حصيلة تاجر', 'merchant'],
        'MERCHANT_PAYOUT_APPROVED' => ['اعتمادُ صرف تسوية تاجر', 'merchant'],
        'MERCHANT_PAYOUT_PAID' => ['صرفُ تسوية تاجر فعليّاً', 'merchant'],
        'MERCHANT_PAYOUT_REJECTED' => ['رفضُ طلب تسوية تاجر', 'merchant'],
        'MERCHANT_RECEIVE_LIMITS_UPDATED' => ['تعديلُ حدود استقبال المدفوعات للتاجر', 'merchant'],
        'MERCHANT_OVERRIDE_REQUESTED' => ['طلبُ استثناء تشغيليّ لتاجر', 'merchant'],
        'MERCHANT_OVERRIDE_GRANTED' => ['منحُ استثناء تشغيليّ لتاجر', 'merchant'],
        'MERCHANT_OVERRIDE_REJECTED' => ['رفضُ استثناء تشغيليّ لتاجر', 'merchant'],
        'MERCHANT_STAFF_ROLE_CHANGED' => ['تغييرُ الدَّور التشغيليّ لموظّف التاجر', 'merchant'],
        'MERCHANT_EXPENSE_RECORDED' => ['تسجيلُ مصروف لمنشأة التاجر', 'merchant'],
        'MERCHANT_EXPENSE_VOIDED' => ['إلغاءُ مصروف لمنشأة التاجر', 'merchant'],
        'MERCHANT_DATA_EXPORTED' => ['تصديرُ بيانات التاجر', 'merchant'],
        'MERCHANT_DATA_IMPORTED' => ['استيرادُ بيانات التاجر', 'merchant'],
        'WHOLESALE_RETURN_REFUND_PAID' => ['صرفُ استرداد مرتجع جملة', 'merchant'],
        'FIXED_ASSET_OPENING_REGISTERED' => ['تسجيلُ أصل ثابت افتتاحيّ للمنشأة', 'merchant'],
        'FIXED_ASSET_ACQUIRED_FROM_PURCHASE' => ['إضافةُ أصل ثابت من فاتورة شراء', 'merchant'],
        'FIXED_ASSET_DEPRECIATION_POSTED' => ['ترحيلُ قسط إهلاك أصل ثابت', 'merchant'],
        'FIXED_ASSET_DISPOSED' => ['استبعادُ أو بيع أصل ثابت', 'merchant'],
        'FIXED_ASSET_RETURNED_TO_SUPPLIER' => ['إرجاعُ أصل ثابت إلى المورّد', 'merchant'],
        'DOCUMENT_PRINT_COMPLETED' => ['اكتمالُ طباعة مستند تجاريّ', 'merchant'],
        'DOCUMENT_PRINT_FAILED' => ['فشلُ طباعة مستند تجاريّ', 'merchant'],

        // ── الهويّة والتوثيق (KYC / موثَّق) والامتيازات ──
        'ACCOUNT_OPENING_SUBMITTED' => ['تقديمُ طلب فتح حسابٍ جديد', 'compliance'],
        'ACCOUNT_OPENING_DOSSIER_ARCHIVED' => ['أرشفةُ ملفّ فتح الحساب', 'compliance'],
        'REGISTRATION_DOSSIER_CREATED' => ['إنشاءُ إضبارة تسجيل العميل', 'compliance'],
        'CUSTOMER_VERIFICATION_DOSSIER_ARCHIVED' => ['أرشفةُ إضبارة توثيق العميل', 'compliance'],
        'ADMIN_KYC_DOCUMENT_UPLOADED' => ['رفعُ وثيقة هويّة للعميل بواسطة الإدارة', 'compliance'],
        'KYC_TIER2_SUBMITTED' => ['تقديمُ طلب ترقية التوثيق للمستوى الثاني', 'compliance'],
        'KYC_FULL_PROFILE_UPDATED' => ['تحديثُ ملفّ التوثيق الكامل للعميل', 'compliance'],
        'KYC_IN_PERSON_OWNERSHIP_VERIFIED' => ['توثيقُ ملكيّة الحساب حضوريّاً', 'compliance'],
        'KYC_BIOMETRIC_ATTEMPT_STARTED' => ['بدءُ فحص التحقّق الحيويّ (البصمة/الوجه)', 'compliance'],
        'KYC_BIOMETRIC_START_FAILED' => ['تعذّرُ بدء فحص التحقّق الحيويّ', 'compliance'],
        'KYC_BIOMETRIC_WEBHOOK_PROCESSED' => ['معالجةُ نتيجة فحص التحقّق الحيويّ', 'compliance'],
        'KYC_AI_ADVISORY_CREATED' => ['إصدارُ تقييم استشاريّ آليّ لوثائق الهويّة', 'compliance'],
        'KYC_IDENTITY_EXPIRING' => ['تنبيهُ قرب انتهاء صلاحيّة وثيقة الهويّة', 'compliance'],
        'KYC_IDENTITY_EXPIRED' => ['انتهاءُ صلاحيّة وثيقة الهويّة', 'compliance'],
        'KYC_TIER_LIMITS_UPDATED' => ['تحديثُ حدود مستويات التوثيق (KYC)', 'compliance'],
        'KYC_ZONE_UNRESOLVED' => ['تعذّرُ حسم النطاق الجغرافيّ من الهويّة', 'compliance'],
        'LEGAL_NAME_IDENTITY_VERIFIED' => ['مطابقةُ الاسم القانونيّ مع الهويّة', 'compliance'],
        'LEGAL_NAME_RESIDENCE_COMPARED' => ['مقارنةُ الاسم القانونيّ مع إثبات السكن', 'compliance'],
        'RESIDENCE_EVIDENCE_SUBMITTED' => ['تقديمُ وثيقة إثبات السكن', 'compliance'],
        'RESIDENCE_VERIFICATION_DECIDED' => ['البَتُّ في وثيقة إثبات السكن', 'compliance'],
        'PHONE_OWNERSHIP_VERIFIED' => ['توثيقُ ملكيّة رقم الهاتف', 'security'],
        'MUWATHAQ_ACCOUNT_PROVISIONED' => ['تجهيزُ وربط الحساب عبر منصّة موثَّق', 'compliance'],
        'MUWATHAQ_EVIDENCE_VIEWED' => ['اطّلاعٌ على وثائق منصّة موثَّق', 'compliance'],

        // ── البريد والرموز السريّة وصلاحيّات المشغّلين والرقابة ──
        'EMAIL_CHANGE_OTP_REQUESTED' => ['طلبُ رمز تحقّق لتغيير البريد الإلكترونيّ', 'security'],
        'EMAIL_IDENTITY_CHANGED_BY_OTP' => ['تغييرُ البريد الإلكترونيّ بعد التحقّق بالرمز', 'security'],
        'EMAIL_OTP_CHALLENGE_REVOKED' => ['إلغاءُ رمز تحقّق البريد الإلكترونيّ', 'security'],
        'EMAIL_CENTER_VIEWED' => ['فتحُ مركز التحقّق البريديّ', 'security'],
        'EMAIL_CENTER_PII_VIEWED' => ['كشفُ بيانات البريد في مركز التحقّق', 'security'],
        'EMAIL_CENTER_EXPORTED' => ['تصديرُ سجلّات مركز التحقّق البريديّ', 'security'],
        'PASSWORD_CHANGED' => ['تغييرُ كلمة المرور', 'security'],
        'PASSWORD_CHANGE_REFUSED' => ['رفضُ محاولة تغيير كلمة المرور', 'security'],
        'PASSWORD_RECOVERED_BY_EMAIL_OTP' => ['استعادةُ كلمة المرور عبر رمز البريد', 'security'],
        'PIN_RECOVERED_BY_EMAIL_OTP' => ['استعادةُ رمز PIN عبر رمز البريد', 'security'],
        'PIN_INITIAL_SETUP_REFUSED' => ['رفضُ إعداد رمز PIN الأوّليّ', 'security'],
        'PIN_FAILED' => ['إدخالٌ خاطئ لرمز PIN', 'security'],
        'PIN_LOCKED' => ['قفلُ الحساب بسبب تكرار خطأ رمز PIN', 'security'],
        'PLATFORM_LOGIN_PIN_CHANGED' => ['تغييرُ رمز دخول المشغّل للوحة الإدارة', 'security'],
        'PLATFORM_LOGIN_PIN_RESET' => ['إعادةُ إصدار رمز دخول المشغّل للوحة الإدارة', 'security'],
        'OPERATOR_TAB_ACCESS_CHANGED' => ['تعديلُ صلاحيّات تبويبات المشغّل الإداريّ', 'security'],
        'PROFILE_CHANGE_REQUESTED' => ['طلبُ تعديل بيانات الملفّ الشخصيّ', 'ops'],
        'PROFILE_CHANGE_DECIDED' => ['البَتُّ في طلب تعديل الملفّ الشخصيّ', 'ops'],
        'AGENT_OPERATIONAL_LIMITS_UPDATED' => ['تحديثُ الحدود التشغيليّة للوكيل', 'agent'],
        'OPERATIONAL_GOVERNORATES_UPDATED' => ['تحديثُ قائمة المحافظات المسموح العمل بها', 'compliance'],
        'TRANSACTION_BLOCKED_BY_OPERATIONAL_COVERAGE' => ['حجبُ معاملة خارج التغطية التشغيليّة للمحافظات', 'compliance'],
        'AML_RULE_UPDATED' => ['تحديثُ قاعدة مكافحة غسل الأموال', 'compliance'],
        'AML_RULE_TOGGLED' => ['تفعيلُ أو تعطيل قاعدة مكافحة غسل الأموال', 'compliance'],
        'AML_RULE_TO_SHADOW' => ['تحويلُ قاعدة غسل الأموال إلى وضع المراقبة الصامتة', 'compliance'],
        'AML_RULE_ENFORCED' => ['تفعيلُ الإنفاذ الفوريّ لقاعدة غسل الأموال', 'compliance'],
        'SUPPORT_BLOCK_DEVICE' => ['حظرُ جهاز مستخدم من لوحة الدعم', 'security'],
        'SUPPORT_UNBLOCK_DEVICE' => ['رفعُ الحظر عن جهاز مستخدم من لوحة الدعم', 'security'],
        'ADMIN_VERTICAL_CREATE' => ['إنشاءُ قطاع تجاريّ جديد في المنصّة', 'ops'],
        'ADMIN_VERTICAL_UPDATE' => ['تحديثُ إعدادات قطاع تجاريّ', 'ops'],
        'ADMIN_VERTICAL_TOGGLE' => ['تفعيلُ أو تعطيل قطاع تجاريّ', 'ops'],
        'ADMIN_VERTICAL_DELETE' => ['حذفُ قطاع تجاريّ غير مستخدم', 'ops'],
        'SAHER_FINDING_HELD' => ['تعليقُ بلاغ رادار ساهر للمراجعة', 'ops'],
        'SAHER_FINDING_REOPENED' => ['إعادةُ فتح بلاغ رادار ساهر', 'ops'],
        'REPORTING_CENTER_VIEWED' => ['فتحُ مركز التقارير الرقابيّة', 'ops'],
        'REPORT_VIEWED' => ['عرضُ تقرير تشغيليّ أو ماليّ', 'ops'],
        'CUSTOMER_REPORT_SUMMARY_VIEWED' => ['عرضُ ملخّص تقرير العميل الماليّ', 'ops'],
        'CHARITY_CAMPAIGN_DELETED' => ['حذفُ حملة خيريّة تجريبيّة بلا تبرّعات', 'charity'],
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
        'enable' => 'تفعيلُ حساب',
        'disable' => 'تعطيلُ حساب',
        'panic' => 'أُطلقت استغاثةٌ طارئة',
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
            'critical' => [
                'label' => 'حرِج',
                'class' => 'bg-danger',
                'rank' => 4,
                'hint' => 'يمسّ أرصدةً أو تجميد/إغلاق حساب أو صلاحيّات حسّاسة — يستوجب المراجعة والتثبّت من السبب',
            ],
            'warning' => [
                'label' => 'تحذير',
                'class' => 'bg-warning text-dark',
                'rank' => 3,
                'hint' => 'عمليّةٌ مرفوضة أو تجاوزُ حدّ أو إشارةُ خطر — يُراجَع عند تكراره على نفس الحساب',
            ],
            'notice' => [
                'label' => 'ملاحظة',
                'class' => 'bg-info',
                'rank' => 2,
                'hint' => 'إجراءٌ إداريّ أو إشرافيّ موثَّق للمتابعة والرجوع إليه',
            ],
            'info' => [
                'label' => 'معلومة',
                'class' => 'bg-light text-dark',
                'rank' => 1,
                'hint' => 'عمليّةٌ اعتياديّةٌ أو حدثٌ مرصود تمّ وفق السياسة المعتمدة',
            ],
            default => [
                'label' => trim((string) $s) ?: '—',
                'class' => 'bg-light text-dark',
                'rank' => 0,
                'hint' => 'غير مصنّف',
            ],
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
            'merchant_profile' => 'ملفّ تاجر',
            'merchant_role' => 'دور موظّف تاجر',
            'merchant_refund' => 'استرداد تاجر',
            'merchant_invoice' => 'فاتورة تاجر',
            'merchant_expense' => 'مصروف تاجر',
            'merchant_fixed_asset' => 'أصل ثابت لتاجر',
            'merchant_payout_request' => 'طلب تسوية تاجر',
            'merchant_override' => 'استثناء تاجر',
            'merchant_risk' => 'ملفّ مخاطر تاجر',
            'merchant_vertical' => 'قطاع تجاريّ',
            'merchant_bulk_export' => 'تصدير بيانات تاجر',
            'merchant_bulk_import' => 'استيراد بيانات تاجر',
            'session' => 'جلسة',
            'pin' => 'رمزُ حماية',
            'password' => 'كلمةُ مرور',
            'otp_challenge' => 'تحقّق برمز OTP',
            'email_identity' => 'هويّة بريد إلكترونيّ',
            'email_verification_center' => 'مركز التحقّق البريديّ',
            'support_ticket' => 'تذكرةُ دعم',
            'safe_payment' => 'دفعةٌ آمنة',
            'pending_transfer' => 'تحويلٌ معلَّق',
            'payment_request' => 'طلبُ دفع',
            'wrong_transfer_claim' => 'مطالبةُ تحويلٍ خاطئ',
            'family_fund' => 'صندوقُ عائلة',
            'family_fund_member' => 'عضو صندوق عائلة',
            'family_fund_transaction' => 'حركة صندوق عائلة',
            'donation' => 'تبرّع',
            'charity_org', 'charity_organization' => 'جهةٌ خيريّة',
            'charity_campaign' => 'حملةٌ خيريّة',
            'charity_settlement' => 'تسويةُ جهةٍ خيريّة',
            'e_payment' => 'دفعٌ إلكترونيّ',
            'bill_payment_order' => 'طلبُ سداد فاتورة',
            'bill_provider' => 'مزوّدُ فواتير',
            'bill_service' => 'خدمةُ فواتير',
            'bill_provider_webhook_event' => 'إشعارُ مزوّد فواتير',
            'agent_shift' => 'ورديّةُ وكيل',
            'agent_staff' => 'موظّفُ وكيل',
            'agent_branch' => 'فرعُ وكيل',
            'agent_profile' => 'ملفّ وكيل',
            'agent_settlement', 'agent_daily_settlement' => 'تسويةُ وكيل',
            'agent_teller_request' => 'طلبُ شبّاك وكيل',
            'agent_panic_alert' => 'بلاغُ استغاثة وكيل',
            'settlement' => 'تسوية',
            'treasury_issuance' => 'إصدارُ خزينة',
            'reconciliation_case' => 'قضيّةُ مصالحة',
            'supplier_payment' => 'سدادُ مورّد',
            'customer_credit_account' => 'حسابُ آجل لعميل',
            'wholesale_return' => 'مرتجعُ جملة',
            'fuel_delivery' => 'توريدُ وقود',
            'fuel_product' => 'منتجُ وقود',
            'fuel_tank' => 'خزّانُ وقود',
            'fuel_stock_recon' => 'جردُ مخزون وقود',
            'fx_rate' => 'سعرُ صرف',
            'kyc_document' => 'وثيقةُ هويّة (KYC)',
            'kyc_tier_limits' => 'حدودُ مستويات التوثيق',
            'kyc_biometric_attempt' => 'فحصٌ حيويّ للهويّة',
            'registration_dossier' => 'إضبارةُ تسجيل',
            'residence_verification' => 'توثيقُ السكن',
            'muwathaq_opening_evidence' => 'وثيقةُ منصّة موثَّق',
            'aml_investigation' => 'تحقيقُ غسل أموال',
            'aml_report' => 'بلاغُ اشتباه رقابيّ',
            'aml_risk_profile' => 'ملفّ مخاطر AML',
            'aml_rule' => 'قاعدةُ رصد AML',
            'sanction_screening' => 'فحصُ قوائم العقوبات',
            'operator' => 'مشغّلٌ إداريّ',
            'platform', 'system' => 'النظام والمنصّة',
            'setting' => 'إعداداتُ النظام',
            'subscription_pricing' => 'تسعيرةُ باقات التجّار',
            'report', 'reporting_center', 'customer_report' => 'تقريرٌ رقابيّ',
            'saher_finding' => 'بلاغُ رادار ساهر',
            'print_job' => 'مهمّةُ طباعة',
            'receipt' => 'إيصالٌ ماليّ',
            'failed_job' => 'مهمّةُ خلفيّة متعثّرة',
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

    /**
     * ترجمةُ الأسباب البرمجيّة الإنجليزيّة الشائعة إلى عربيّة مفهومة،
     * مع الإبقاء على الأسباب العربيّة المكتوبة من الموظّفين كما هي.
     */
    public static function reason(?string $raw): string
    {
        $r = trim((string) $raw);
        if ($r === '') {
            return '—';
        }

        $exact = [
            'customer → customer send money' => 'تحويل رصيد بين محفظتَي عميلين',
            'requester cancelled pending money request' => 'ألغى صاحب الطلب طلب المال المعلّق',
            'recipient approved money request' => 'وافق المستلم على طلب الدفع وسدّده',
            'direct money request delivered to verified recipient' => 'طلب دفع مباشر وُجّه إلى مستلم موثَّق',
            'withdraw_request' => 'طلب سحب قيد المراجعة',
            'Account is in PIN lock' => 'الحساب مقفل مؤقّتاً بسبب تكرار إدخال رمز PIN خاطئ',
            'Lost-phone recovery submitted for admin review' => 'قُدّم بلاغ فقدان الهاتف لمراجعة الإدارة',
            'Same key, different body' => 'مفتاح منع تكرار مطابق مع اختلاف في بيانات الطلب',
            'Self-service phone change initiated' => 'بدأ المستخدم إجراءات تغيير رقم هاتفه ذاتيّاً',
            'current password mismatch' => 'كلمة المرور الحاليّة المدخلة غير صحيحة',
            'pending_balance < amount + charge' => 'الرصيد المعلّق أقلّ من المبلغ مع الرسم',
            'self-service change' => 'تعديل ذاتيّ من صاحب الحساب',
            'transaction_pin not set, fell back to password' => 'رمز العمليات غير مضبوط فتمّ التحقّق بكلمة المرور',
            'debit' => 'خصم من المحفظة',
            'credit' => 'إيداع في المحفظة',
        ];

        if (isset($exact[$r])) {
            return $exact[$r];
        }

        if (str_starts_with($r, 'User is in security hold until ')) {
            $until = trim(substr($r, strlen('User is in security hold until ')));
            return 'الحساب تحت حجزٍ أمنيٍّ مؤقّت حتّى ' . $until;
        }

        if (preg_match('/^donation:campaign_(\d+)$/', $r, $m)) {
            return 'تبرّع للحملة الخيريّة رقم #' . $m[1];
        }

        if (preg_match('/^donation_refund:(.+)$/', $r, $m)) {
            return 'استرداد تبرّع برقم مرجع ' . $m[1];
        }

        if (preg_match('/^merchant_subscription:([^:]+):?(.*)$/', $r, $m)) {
            $plan = \App\Support\Access\AccessConstants::PLAN_LABELS[$m[1]] ?? $m[1];
            $period = ($m[2] ?? '') === 'annual' ? 'سنوي' : 'شهري';
            return "خصم اشتراك باقة التاجر ({$plan} — {$period})";
        }

        return $r;
    }

    /**
     * ترجمةُ مفاتيح `context` إلى عناوين عربيّة مفهومة للمدقّق.
     */
    public static function contextKeyLabel(string $key): string
    {
        return match ($key) {
            'amount' => 'المبلغ',
            'charge', 'fee', 'platform_fee' => 'رسم المنصّة',
            'agent_commission' => 'عمولة الوكيل',
            'net', 'recipient_credit', 'to_seller_net' => 'الصافي للمستفيد',
            'to_seller_gross' => 'إجمالي حصّة البائع',
            'to_buyer' => 'المستردّ للمشتري',
            'required', 'requested' => 'المبلغ المطلوب',
            'available' => 'الرصيد المتاح وقت الطلب',
            'held', 'held_amount', 'held_amount_before' => 'المبلغ المحجوز',
            'shortfall' => 'مقدار العجز / النقص',
            'balance_before' => 'الرصيد قبل العمليّة',
            'balance_after' => 'الرصيد بعد العمليّة',
            'opening_float' => 'عهدة بداية الورديّة',
            'expected' => 'الرصيد المتوقّع',
            'counted' => 'النقد المعدود فعليّاً',
            'diff', 'difference', 'variance' => 'الفرق / الانحراف',
            'currency', 'balance_currency' => 'العملة',
            'from', 'from_user_id', 'requester_user_id', 'claimant_user_id' => 'الطرف الأوّل / المرسِل',
            'to', 'to_user_id', 'receiver_id', 'recipient_user_id', 'beneficiary_id' => 'الطرف الثاني / المستلِم',
            'customer_id', 'customer_user_id', 'subject_user_id', 'user_id', 'wallet_user_id' => 'الحساب المتأثّر',
            'merchant_user_id' => 'حساب التاجر',
            'agent_id', 'agent_user_id' => 'حساب الوكيل',
            'staff_id', 'teller_staff_id', 'by_staff_id', 'pos_user_id' => 'الموظّف المنفِّذ',
            'employee_code' => 'رمز الموظّف',
            'maker_admin_id' => 'الموظّف المُنشئ للطلب (Maker)',
            'checker_admin_id' => 'المشرف المُعتمِد (Checker)',
            'branch_id' => 'الفرع',
            'branch_name' => 'اسم الفرع',
            'old_branch_id' => 'الفرع السابق',
            'new_branch_id' => 'الفرع الجديد',
            'old_zone' => 'النطاق السابق',
            'new_zone' => 'النطاق الجديد',
            'zone', 'request_zone', 'account_zone' => 'النطاق الجغرافي',
            'governorate', 'verified_governorate' => 'المحافظة',
            'old_value', 'before' => 'القيمة السابقة (قبل)',
            'new_value', 'after' => 'القيمة الجديدة (بعد)',
            'old_roles' => 'الأدوار السابقة',
            'role', 'role_code', 'role_name', 'new_role_code' => 'الدور الوظيفي',
            'plan' => 'الباقة',
            'period' => 'دورة الاشتراك',
            'amount_yer' => 'المبلغ بالريال اليمني',
            'reference_sar' => 'السعر المرجعي بالريال السعودي',
            'transaction_id', 'transaction_no' => 'رقم المعاملة',
            'journal_entry_ulid', 'ledger_entry_ulid', 'journal_entry_id' => 'معرّف قيد الدفتر',
            'approval_request_number', 'request_number' => 'رقم طلب الاعتماد',
            'case_number' => 'رقم قضيّة التحقيق',
            'payment_ulid' => 'معرّف الدفعة الآمنة',
            'settlement_ulid' => 'معرّف التسوية',
            'campaign_id', 'campaign_ulid' => 'الحملة الخيريّة',
            'org_id', 'organization_id' => 'الجهة الخيريّة',
            'fund_id', 'fund_ulid' => 'صندوق العائلة',
            'invoice_no', 'order_id' => 'رقم الفاتورة / الطلب',
            'receipt_number' => 'رقم الإيصال',
            'device_id', 'pos_device_id', 'device_name', 'device' => 'الجهاز',
            'ip', 'ip_address' => 'عنوان الشبكة (IP)',
            'channel', 'method' => 'القناة / الطريقة',
            'status', 'operation_status', 'delivery_status' => 'الحالة',
            'stage' => 'المرحلة',
            'reason', 'note' => 'ملاحظة إضافيّة',
            'revoked_access_tokens' => 'جلسات الدخول الملغاة',
            'revoked_refresh_tokens' => 'رموز التجديد الملغاة',
            'deactivated_device_rows' => 'الأجهزة المعطَّلة',
            'evidence_count' => 'عدد الأدلّة المرفقة',
            'dispute_reason_code' => 'سبب النزاع',
            'delivery_code_verified' => 'التحقّق من رمز التسليم',
            'is_anonymous' => 'تبرّع غير معلن الاسم',
            'limits' => 'الحدود المعدَّلة',
            default => $key,
        };
    }

    /**
     * يحوّل مصفوفة `context` إلى قائمة صفوف عربيّة منسّقة للعرض في النافذة والجدول.
     *
     * @param array<string,mixed> $context
     * @param array<int,array{id:int,name:string,phone:?string,role_ar:string}> $usersById
     * @return list<array{key:string,label:string,value:string,is_user:bool,user_id:?int}>
     */
    public static function formatContextEntries(array $context, array $usersById = []): array
    {
        $userKeys = [
            'from_user_id', 'to_user_id', 'receiver_id', 'recipient_user_id',
            'customer_id', 'customer_user_id', 'subject_user_id', 'user_id',
            'wallet_user_id', 'merchant_user_id', 'agent_id', 'agent_user_id',
            'requester_user_id', 'claimant_user_id', 'disputed_user_id',
            'maker_admin_id', 'checker_admin_id', 'beneficiary_id',
        ];

        $moneyKeys = [
            'amount', 'charge', 'fee', 'platform_fee', 'agent_commission',
            'net', 'recipient_credit', 'to_seller_net', 'to_seller_gross',
            'to_buyer', 'required', 'requested', 'available', 'held',
            'held_amount', 'held_amount_before', 'shortfall', 'balance_before',
            'balance_after', 'opening_float', 'expected', 'counted', 'diff',
            'difference', 'variance', 'amount_yer',
        ];

        $rows = [];
        foreach ($context as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }

            $keyStr = (string) $k;
            $label = self::contextKeyLabel($keyStr);
            $isUser = false;
            $userId = null;

            if (in_array($keyStr, $userKeys, true) && is_numeric($v)) {
                $uid = (int) $v;
                if (isset($usersById[$uid])) {
                    $u = $usersById[$uid];
                    $valStr = sprintf('%s (#%d — %s%s)',
                        $u['name'],
                        $uid,
                        $u['role_ar'],
                        !empty($u['phone']) ? ' · ' . $u['phone'] : ''
                    );
                    $isUser = true;
                    $userId = $uid;
                } else {
                    $valStr = 'حساب #' . $uid;
                    $isUser = true;
                    $userId = $uid;
                }
            } elseif (in_array($keyStr, $moneyKeys, true) && is_numeric($v)) {
                $cur = (string) ($context['currency'] ?? $context['balance_currency'] ?? 'ر.ي');
                if ($cur === 'YER') {
                    $cur = 'ر.ي';
                } elseif ($cur === 'SAR') {
                    $cur = 'ر.س';
                }
                $valStr = number_format((float) $v, 2) . ' ' . $cur;
            } elseif (is_bool($v)) {
                $valStr = $v ? 'نعم' : 'لا';
            } elseif (is_array($v)) {
                $flat = array_filter($v, fn ($x) => is_scalar($x));
                if (count($flat) === count($v) && count($flat) <= 8) {
                    $valStr = implode(' ، ', array_map('strval', $flat));
                } else {
                    $valStr = (string) json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            } else {
                $valStr = (string) $v;
            }

            $rows[] = [
                'key' => $keyStr,
                'label' => $label,
                'value' => $valStr,
                'is_user' => $isUser,
                'user_id' => $userId,
            ];
        }

        return $rows;
    }

    /**
     * يبني «القصّة الكاملة» في جملة عربيّة واضحة تجيب فوراً:
     * «مَن عمل ماذا ← على أيّ حساب/موضوع ← بأيّ نتيجة أو مبلغ ← وما درجة الخطورة».
     *
     * @param array{name:string,role_ar:string,id:?int,phone:?string}|null $actorInfo
     * @param array{name:string,role_ar:string,id:?int,phone:?string,subject_label:string}|null $targetInfo
     * @param array<string,mixed> $context
     */
    public static function narrative(
        string $actionCode,
        ?string $decisionCode,
        ?string $severityCode,
        ?string $actorType,
        ?array $actorInfo,
        ?array $targetInfo,
        array $context = [],
        ?string $reason = null,
    ): string {
        $act = self::action($actionCode);
        $dec = self::decisionCode($decisionCode);
        $sev = self::severity($severityCode);

        $actorPhrase = $actorInfo
            ? sprintf('%s «%s» (#%d)', $actorInfo['role_ar'], $actorInfo['name'], $actorInfo['id'])
            : self::actorType($actorType);

        $targetPhrase = '';
        if ($targetInfo) {
            if (!empty($targetInfo['id']) && !empty($targetInfo['name'])) {
                $targetPhrase = sprintf(' على حساب %s «%s» (#%d)',
                    $targetInfo['role_ar'], $targetInfo['name'], $targetInfo['id']);
            } elseif (!empty($targetInfo['subject_label']) && $targetInfo['subject_label'] !== '—') {
                $targetPhrase = ' على «' . $targetInfo['subject_label'] . '»';
            }
        }

        $moneyParts = [];
        if (isset($context['amount']) && is_numeric($context['amount'])) {
            $moneyParts[] = 'بمبلغ ' . number_format((float) $context['amount'], 2) . ' ر.ي';
        } elseif (isset($context['required']) && is_numeric($context['required'])) {
            $avail = isset($context['available']) && is_numeric($context['available'])
                ? ' (والمتاح ' . number_format((float) $context['available'], 2) . ' ر.ي)'
                : '';
            $moneyParts[] = 'لمبلغ مطلوب ' . number_format((float) $context['required'], 2) . ' ر.ي' . $avail;
        } elseif (isset($context['amount_yer']) && is_numeric($context['amount_yer'])) {
            $moneyParts[] = 'بمبلغ ' . number_format((float) $context['amount_yer'], 2) . ' ر.ي';
        }

        $moneyStr = $moneyParts !== [] ? ' (' . implode(' · ', $moneyParts) . ')' : '';

        return sprintf(
            'قام %s بتنفيذ «%s»%s%s — النتيجة: %s · الخطورة: %s.',
            $actorPhrase,
            $act['label'],
            $targetPhrase,
            $moneyStr,
            $dec['label'],
            $sev['label']
        );
    }
}
