<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-FEE-ENGINE-001 — نسخة رسم لعملية مالية.
 *
 * append-only: لا تُعدَّل النسخة بعد تفعيلها؛ تُنشأ نسخة جديدة تُلغيها.
 */
class FeeScheme extends Model
{
    protected $table = 'fee_schemes';

    protected $fillable = [
        'code', 'label', 'zone_code', 'applies_to',
        'fee_type', 'percent_rate', 'fixed_amount', 'min_fee', 'max_fee',
        'agent_commission_percent', 'agent_commission_fixed', 'bearer',
        'version', 'is_active', 'effective_from', 'effective_to',
        'notes', 'created_by',
    ];

    protected $casts = [
        // نُبقي الأرقام كنصوص (string) عبر MoneyService — لا float.
        'is_active' => 'boolean',
        'version' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'created_by' => 'integer',
    ];

    /** الأكواد المعتمدة للعمليات. */
    /**
     * رموزُ الرسوم التي تُضبط من لوحة الإدارة.
     *
     * ══════════════════════════════════════════════════════════════════
     * **AMIAL-TRUTH-002 — ورمزٌ هنا بلا مستهلكٍ زرٌّ يكذب.**
     *
     * `FeeSchemeController` يتحقّق `in:CODES`، فهذه القائمةُ هي ما يستطيع
     * الأدمنُ تسعيرَه. **وما ليس فيها لا يُسعَّر أبداً.**
     *
     * وقد كُشف بتدقيق `amial-financial-truth` أنّ `AgentCounterService`
     * يطلب `'agent_deposit'` و`'agent_withdraw'` — **وليسا هنا**.
     * والمطابقةُ في `FeeService::activeScheme()` نصّيّةٌ حرفيّة، فلا يجد
     * مخطّطاً أبداً ويردّ صفراً.
     *
     * **وهذا هو سببُ ما في `CLAUDE.md`: «ضبط الرسوم — كلّ عمليات الوكيل
     * مجّانية الآن».** لم يكن نقصَ ضبطٍ من صاحب المشروع: كان اسمين لا
     * يلتقيان، والأدمنُ لا يملك حقلاً يكتبهما فيه.
     *
     * والحارسُ `FeeCodeReachabilityTest` يمسك الاتّجاهين: رمزٌ هنا بلا
     * مستهلك، ورمزٌ يُطلب وليس هنا.
     */
    public const CODES = [
        'SEND_MONEY', 'CASH_OUT', 'CASH_IN', 'MERCHANT_QR', 'MERCHANT_POS',
        'SAFE_PAYMENT', 'BILL_PAY', 'SPLIT_BILL', 'REFUND', 'FAMILY_FUND_CONTRIB',

        // عمليّاتُ شبّاك الوكيل — تُطلب في `AgentCounterService::quote()`.
        'AGENT_DEPOSIT', 'AGENT_WITHDRAW',

        // AMIAL-FEE-TRUTH-001 — **السحبُ إلى وسيلةٍ خارجيّة.**
        //
        // كان يُحسب من `business_settings.withdraw_charge_percent` — رسمٌ
        // يُخصَم من العميل فعلاً و**لا حقلَ له في مركز الرسوم إطلاقاً**.
        // فالأدمنُ لا يراه ولا يغيّره، ولا يظهر في تقرير الأرباح مسعَّراً.
        'WITHDRAW',
    ];

    public const FEE_TYPES = ['percent', 'fixed', 'percent_plus_fixed'];
    public const APPLIES_TO = ['customer', 'merchant', 'agent'];
    public const BEARERS = ['sender', 'receiver', 'merchant'];

    public function changeLogs()
    {
        return $this->hasMany(FeeChangeLog::class, 'fee_scheme_id');
    }
}
