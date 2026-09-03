<?php

namespace App\Services;

use App\Models\MerchantProfile;
use App\Models\MerchantRiskEvent;
use App\Models\MerchantRiskProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AMIAL-MERCHANT-RISK-001 (v2.9)
 *
 * MerchantRiskService — تصنيف ومراقبة مخاطر التجار.
 *
 * **يعالج 3 أنماط غسيل أموال:**
 *   1. حجم غير طبيعي: استلام يتجاوز المعلن/المعتاد بكثير
 *   2. عملاء جدد فجأة: قفزة في عدد العملاء المختلفين
 *   3. pass-through: استلام ثم تحويل فوري (المال يمرّ ولا يستقر)
 *
 * **الفلسفة:**
 *   - الفحص في الخلفية (non-blocking) — لا يبطئ العميل
 *   - يحدّث risk_score بـ EMA (يعطي وزناً أكبر للأحداث الحديثة)
 *   - عند تجاوز عتبة → flag للإدارة (لا إيقاف تلقائي إلا critical)
 */
class MerchantRiskService
{
    private const EMA_ALPHA = 0.3; // وزن الحدث الجديد

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    // ============================================================
    // التصنيف والحدود
    // ============================================================

    /**
     * فحص حد الاستلام قبل قبول دفعة للتاجر.
     * @throws RuntimeException
     */
    /**
     * @param  string  $currency  عملةُ المبلغ. **والحدُّ يُقاس بالمكافئ الأساس.**
     */
    public function assertReceiveAllowed(
        int $merchantUserId,
        string $amount,
        string $currency = \App\Support\Money\Currencies::BASE,
    ): void {
        $profile = MerchantProfile::where('user_id', $merchantUserId)->first();

        // ══════════════════════════════════════════════════════════════════
        // AMIAL-MERCHANT-VERIFY-RECEIVE-001 — **القبضُ الماليّ محروسٌ في
        // الخادم، لا بحبس الواجهة.**
        //
        // قرارُ صاحب المشروع: «دخولٌ محدود فوراً» — يدخل التاجرُ ويعمل من
        // اللحظة الأولى (بيعٌ نقديّ، آجل، جردٌ، طباعة)، لكنّ **استلامَ المال
        // الحقيقيّ عبر المنصّة** يبقى مقفلاً حتّى تعتمده الإدارة. فرفعُ حبس
        // الواجهة وحدَه (AMIAL-VERIFY-GATE) كان سيترك تاجراً غيرَ موثّقٍ
        // يقبض مالاً حقيقيّاً — و«إخفاءُ الواجهة ليس أماناً» (amial-rbac).
        //
        // وهذا البابُ الوحيدُ يُنادى من مدخلي القبض معاً: QR/POS
        // (`TransactionTrait::merchant_payment_transaction`) ورابطُ الدفع/
        // الفاتورة (`PaymentRequestService::settle`). فحارسٌ واحدٌ هنا يغطّي
        // كلَّ القطاعات وكلَّ مسارات القبض — لا وسيطٌ يُنسى على مسارٍ جديد.
        //
        // والهويّةُ من الصفّ المخزَّن لا من الطلب (القاعدة الثامنة). وتاجرٌ
        // بلا profile يُعامَل غيرَ موثّق (يُقفل)، لا كـ micro يقبض بحدٍّ أدنى:
        // «غير معروف» ليس «مسموحٌ بحذر» حين يكون المالُ حقيقيّاً (القاعدة ٧).
        // ══════════════════════════════════════════════════════════════════
        $status = $profile?->verification_status;
        if ($status === 'verification_suspended') {
            throw new RuntimeException('حساب التاجر موقوف للمراجعة');
        }
        if ($status !== 'verified') {
            throw new RuntimeException(
                'حساب المتجر قيد المراجعة — لا يمكن استقبال المدفوعات حتى اعتماد الإدارة'
            );
        }

        // وصل هنا ⇒ الـprofile موجودٌ وموثّق.
        $singleLimit = (string) $profile->single_receive_limit;
        $dailyLimit = (string) $profile->daily_receive_limit;

        // ══════════════════════════════════════════════════════════════════
        // AMIAL-MULTI-CURRENCY-002 — **الحدُّ يُقاس بالمكافئ الأساس.**
        //
        // حدودُ التاجر (`single_receive_limit` · `daily_receive_limit`)
        // مضبوطةٌ بالريال اليمنيّ منذ كُتبت. فمقارنةُ مبلغٍ **بالدولار**
        // بحدٍّ **بالريال** ليست مقارنةً أصلاً: تاجرٌ حدُّه خمسةُ ملايين
        // ريال يقبض عشرةَ آلاف دولار — أي **خمسةَ ملايينَ وثلاثمئة ألف** —
        // ويمرّ، لأنّ ١٠٬٠٠٠ أصغرُ من ٥٬٠٠٠٬٠٠٠ عدداً.
        //
        // **وهذا التفافٌ كاملٌ على حدود مكافحة الغسيل بتغيير قائمةٍ منسدلة**،
        // ولا يُخرج خطأً في أيّ سجلّ: الرقمان يُقارنان، والحارسُ يمرّ، وهو
        // لم يقس شيئاً. (وهو نفسُ صنف عطل «٣٥ ر.س تُعرَض ٣٥ ر.ي».)
        //
        // فالمكافئُ يُحسب أوّلاً، **وبسعرٍ مذكورِ المصدر**، ثمّ يُقارَن.
        // وعملةٌ بلا سعرٍ مضبوطٍ تُرفَض ولا تُمرَّر بسعر ١ (القاعدة السابعة).
        // ══════════════════════════════════════════════════════════════════
        $cur = \App\Support\Money\Currencies::normalize($currency);
        $baseAmount = \App\Support\Money\Currencies::isBase($cur)
            ? $amount
            : app(\App\Services\FxRateService::class)->toBase($amount, $cur);

        // **والرسالةُ تقول العملتين معاً** — «المبلغ يتجاوز الحدّ (٥٠٠٠٠٠٠)»
        // على تاجرٍ أدخل ١٠٠ دولارٍ رسالةٌ لا تُفهَم ولا تُصحَّح.
        $shown = \App\Support\Money\Currencies::isBase($cur)
            ? ''
            : sprintf(' — %s %s = %s ر.ي', $amount, \App\Support\Money\Currencies::symbol($cur), $baseAmount);

        // حد العملية الواحدة
        if (bccomp($baseAmount, $singleLimit, 4) > 0) {
            throw new RuntimeException("المبلغ يتجاوز حد الاستلام للعملية ({$singleLimit}){$shown}");
        }

        // الحد اليومي
        $todayReceived = $this->getTodayReceived($merchantUserId);
        $newTotal = bcadd($todayReceived, $baseAmount, 4);
        if (bccomp($newTotal, $dailyLimit, 4) > 0) {
            throw new RuntimeException("هذه الدفعة ستتجاوز حد الاستلام اليومي ({$dailyLimit}){$shown}");
        }
    }

    /**
     * تغيير تصنيف التاجر (يحدّث الحدود تلقائياً).
     */
    public function setTier(User $merchant, string $tier, User $admin): MerchantProfile
    {
        if (!in_array($tier, ['micro', 'small', 'medium', 'large'], true)) {
            throw new RuntimeException('تصنيف غير صالح');
        }

        $profile = MerchantProfile::firstOrNew(['user_id' => $merchant->id]);
        $limits = MerchantProfile::defaultLimitsForTier($tier);

        $profile->fill(array_merge($limits, [
            'tier' => $tier,
            'verified_by_admin_id' => $admin->id,
        ]));
        $profile->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'merchant_profile',
            'subject_id' => (string)$merchant->id,
            'action' => 'MERCHANT_TIER_CHANGED',
            'decision_code' => 'OK',
            'severity' => 'info',
            'context' => ['tier' => $tier, 'limits' => $limits],
        ]);

        return $profile;
    }

    // ============================================================
    // المراقبة (3 أنماط غسيل)
    // ============================================================

    /**
     * تحليل عملية استلام للتاجر (يُستدعى في الخلفية بعد الدفعة).
     * يكشف الأنماط الثلاثة ويحدّث risk score.
     */
    public function analyzeReceived(int $merchantUserId, string $amount, int $fromCustomerId): void
    {
        $risk = $this->getRiskProfile($merchantUserId);
        $profile = MerchantProfile::where('user_id', $merchantUserId)->first();
        $addedRisk = 0;

        // ===== النمط 1: حجم غير طبيعي =====
        $todayVolume = $this->getTodayReceived($merchantUserId);
        $avgVolume = (float)$risk->avg_daily_volume;
        if ($avgVolume > 0 && (float)$todayVolume > $avgVolume * 3) {
            // الحجم اليوم 3x المتوسط
            $addedRisk += 25;
            $this->recordEvent($merchantUserId, 'volume_spike', 25,
                "حجم اليوم ({$todayVolume}) يتجاوز 3x المتوسط ({$avgVolume})",
                ['today' => $todayVolume, 'avg' => $avgVolume]);
            $risk->volume_anomaly_count++;
        }

        // مقارنة بالمعلن
        if ($profile && $profile->declared_monthly_volume) {
            $monthlyReceived = $this->getMonthReceived($merchantUserId);
            $declared = (float)$profile->declared_monthly_volume;
            if ($declared > 0 && (float)$monthlyReceived > $declared * 1.5) {
                $addedRisk += 20;
                $this->recordEvent($merchantUserId, 'exceeds_declared', 20,
                    "الاستلام الشهري يتجاوز المعلن بـ 50%+",
                    ['declared' => $declared, 'actual' => $monthlyReceived]);
            }
        }

        // ===== النمط 2: عملاء جدد فجأة =====
        $newCustomersToday = $this->getDistinctCustomersToday($merchantUserId);
        $avgCustomers = (int)$risk->avg_daily_customers;
        if ($avgCustomers > 0 && $newCustomersToday > $avgCustomers * 3) {
            $addedRisk += 20;
            $this->recordEvent($merchantUserId, 'new_customers_surge', 20,
                "عملاء اليوم ({$newCustomersToday}) يتجاوزون 3x المعتاد ({$avgCustomers})",
                ['today' => $newCustomersToday, 'avg' => $avgCustomers]);
        }

        // ===== النمط 3: pass-through (استلام ثم تحويل فوري) =====
        $ratio = $risk->passThroughRatio();
        if ($ratio > 0.8) {
            // أكثر من 80% مما يستلمه يُحوّل خارجاً → مؤشر غسيل قوي
            $addedRisk += 35;
            $this->recordEvent($merchantUserId, 'pass_through', 35,
                "نسبة pass-through عالية: " . round($ratio * 100) . "% مما يُستلَم يُحوَّل",
                ['ratio' => $ratio]);
        }

        // تحديث الإحصاءات (EMA)
        $this->updateStatistics($risk, (float)$todayVolume, $newCustomersToday, (float)$amount);

        // تحديث risk score + level
        if ($addedRisk > 0) {
            $newScore = min(100, (float)$risk->current_risk_score + $addedRisk);
            $risk->current_risk_score = $newScore;
            $risk->risk_level = $this->scoreToLevel($newScore);
            $risk->aml_flags_count++;
            $risk->last_flagged_at = now();

            // critical → تنبيه عاجل
            if ($risk->risk_level === 'critical') {
                $this->escalateToCritical($merchantUserId, $newScore);
            }
        }

        $risk->save();
    }

    /**
     * تسجيل تحويل خارج من التاجر (لحساب pass-through).
     */
    public function recordTransferOut(int $merchantUserId, string $amount): void
    {
        $risk = $this->getRiskProfile($merchantUserId);
        $risk->total_transferred_out = bcadd(($risk->total_transferred_out ?: '0'), $amount, 4);
        $risk->save();
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * ما قبضه التاجرُ منذ بدء اليوم — **بالمكافئ الأساس.**
     *
     * AMIAL-MULTI-CURRENCY-002: `SUM(credit)` كان يجمع ريالاً ودولاراً في
     * رقمٍ واحدٍ يُقارَن بحدٍّ بالريال. فمئةُ دولارٍ كانت تُحسَب «مئة» من
     * الحدّ اليوميّ وهي **ثلاثةٌ وخمسون ألفاً**. فالجمعُ صار على
     * `base_amount` — وهو المكافئُ المجمَّدُ لحظةَ العمليّة، لا محسوباً
     * بسعرِ اليومِ على معاملاتِ أمس.
     *
     * و`COALESCE(base_amount, credit)` تحوطاً لصفوفٍ سبقت الهجرة.
     */
    private function baseReceivedSince(int $merchantUserId, Carbon $since): string
    {
        $sum = DB::table('transactions')
            ->where('to_user_id', $merchantUserId)
            ->where('created_at', '>=', $since)
            ->where('credit', '>', 0)
            ->selectRaw('COALESCE(SUM(COALESCE(base_amount, credit)), 0) as s')
            ->value('s');

        return (string) ($sum ?? '0');
    }

    private function getTodayReceived(int $merchantUserId): string
    {
        return $this->baseReceivedSince($merchantUserId, Carbon::today());
    }

    private function getMonthReceived(int $merchantUserId): string
    {
        return $this->baseReceivedSince($merchantUserId, Carbon::now()->startOfMonth());
    }

    private function getDistinctCustomersToday(int $merchantUserId): int
    {
        return DB::table('transactions')
            ->where('to_user_id', $merchantUserId)
            ->where('created_at', '>=', Carbon::today())
            ->distinct('from_user_id')
            ->count('from_user_id');
    }

    private function getRiskProfile(int $merchantUserId): MerchantRiskProfile
    {
        return MerchantRiskProfile::firstOrCreate(
            ['merchant_user_id' => $merchantUserId],
            ['current_risk_score' => 0, 'risk_level' => 'low']
        );
    }

    private function updateStatistics(MerchantRiskProfile $risk, float $todayVolume, int $todayCustomers, float $amount): void
    {
        // EMA للمتوسطات
        $a = self::EMA_ALPHA;
        $risk->avg_daily_volume = (string)((float)$risk->avg_daily_volume * (1 - $a) + $todayVolume * $a);
        $risk->avg_daily_customers = (int)round((float)$risk->avg_daily_customers * (1 - $a) + $todayCustomers * $a);

        if ($todayVolume > (float)$risk->peak_daily_volume) {
            $risk->peak_daily_volume = (string)$todayVolume;
        }
        $risk->total_received_lifetime = bcadd(($risk->total_received_lifetime ?: '0'), (string)$amount, 4);
    }

    private function scoreToLevel(float $score): string
    {
        if ($score >= 70) return 'critical';
        if ($score >= 40) return 'high';
        if ($score >= 20) return 'medium';
        return 'low';
    }

    private function escalateToCritical(int $merchantUserId, float $score): void
    {
        $this->audit->record([
            'actor_type' => 'system',
            'actor_user_id' => $merchantUserId,
            'subject_type' => 'merchant_risk',
            'subject_id' => (string)$merchantUserId,
            'action' => 'MERCHANT_RISK_CRITICAL',
            'decision_code' => 'CRITICAL',
            'severity' => 'critical',
            'context' => ['risk_score' => $score, 'requires_review' => true],
        ]);
        // ملاحظة: لا إيقاف تلقائي — الإدارة تراجع (تجنب إيقاف تاجر شرعي خطأً)
    }

    private function recordEvent(int $merchantUserId, string $type, float $risk, string $desc, array $context = []): void
    {
        MerchantRiskEvent::create([
            'merchant_user_id' => $merchantUserId,
            'event_type' => $type,
            'risk_contribution' => $risk,
            'description' => $desc,
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    /**
     * لوحة مخاطر التاجر (للإدارة).
     */
    public function getRiskDashboard(int $merchantUserId): array
    {
        $risk = $this->getRiskProfile($merchantUserId);
        $profile = MerchantProfile::where('user_id', $merchantUserId)->first();
        $recentEvents = MerchantRiskEvent::where('merchant_user_id', $merchantUserId)
            ->orderByDesc('created_at')->limit(20)->get();

        return [
            'risk_score' => (string)$risk->current_risk_score,
            'risk_level' => $risk->risk_level,
            'tier' => $profile?->tier,
            'verification_status' => $profile?->verification_status,
            // **ولا يُقرأ المجهولُ صفراً** (القاعدةُ السابعة).
            'pass_through_ratio' => $risk->hasOutboundRecord()
                ? round($risk->passThroughRatio() * 100, 1)
                : null,
            'avg_daily_volume' => (string)$risk->avg_daily_volume,
            'peak_daily_volume' => (string)$risk->peak_daily_volume,
            'aml_flags_count' => $risk->aml_flags_count,
            'recent_events' => $recentEvents,
        ];
    }
}
