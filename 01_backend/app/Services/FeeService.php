<?php

namespace App\Services;

use App\Models\FeeChangeLog;
use App\Models\FeeScheme;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AMIAL-FEE-ENGINE-001 — المصدر المركزي الوحيد لحساب أي رسم/ربح.
 *
 * بدل الدوال المبعثرة (get_sendmoney_charge, get_cashout_charge,
 * get_agent_commission ...) — كل مسار مالي ينادي FeeService::calculate().
 *
 * يضمن:
 *   - دقة decimal (MoneyService / bcmath) لا float.
 *   - platform_profit + agent_commission = fee بالضبط (لا انحراف تقريب).
 *   - لا رسم سالب.
 *   - النسخة المستخدَمة تُرجَع (scheme_id/version) ليُخزّنها المُتصل (snapshot)
 *     فتبقى العملية التاريخية مفسَّرة حتى لو تغيّرت النسبة لاحقاً.
 */
class FeeService
{
    private const CACHE_TTL = 600; // ثوانٍ
    private const CACHE_PREFIX = 'fee_scheme:';

    // ============================================================
    // الحساب
    // ============================================================

    /**
     * حساب الرسم لعملية حقيقية. يقرأ النسخة النشطة من القاعدة.
     *
     * @param string $code   كود العملية (SEND_MONEY, CASH_OUT, ...)
     * @param string|int|float $amount  أصل المبلغ
     * @param array  $context ['zone_code' => 'SOUTH', 'applies_to' => 'customer']
     * @return array تفصيل الرسم
     */
    public function calculate(string $code, string|int|float $amount, array $context = []): array
    {
        $zone = $context['zone_code'] ?? 'SOUTH';
        $appliesTo = $context['applies_to'] ?? 'customer';

        $scheme = $this->activeScheme($code, $zone, $appliesTo);

        return $this->compute($scheme, $code, $amount, $zone);
    }

    /**
     * محاكاة رسم بنسخة افتراضية (غير محفوظة) — للوحة التحكم قبل الحفظ.
     */
    public function simulate(array $schemeData, string|int|float $amount): array
    {
        $this->validate($schemeData);
        $scheme = new FeeScheme($schemeData);

        return $this->compute(
            $scheme,
            $schemeData['code'] ?? 'SIMULATION',
            $amount,
            $schemeData['zone_code'] ?? 'SOUTH'
        );
    }

    /**
     * المنطق المشترك للحساب. $scheme قد يكون null (=> رسم صفر).
     */
    private function compute(?FeeScheme $scheme, string $code, string|int|float $amount, string $zone): array
    {
        $amount = MoneyService::normalize($amount);

        if (!MoneyService::isNonNegative($amount)) {
            throw new InvalidArgumentException("Amount cannot be negative: {$amount}");
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-FEE-TRUTH-006 — **غيابُ التسعير ليس مجّانيّة.**
        //
        // كان `null` يُقرأ «رسمٌ صفر» صامتاً، فيخلط حالتين لا تجتمعان:
        //
        //   **مجّانيٌّ عمداً**   قرارٌ تجاريٌّ اتُّخذ ووُثّق
        //   **إعدادٌ مفقود**    نسخةٌ لم تُنشأ، أو أُلغيت، أو خطأٌ في الرمز
        //
        // والثاني **يُسلّم الخدمةَ بلا ثمنٍ بلا أن يقرّر أحد**. وقد وقع
        // فعلاً: `AGENT_DEPOSIT` كان يُطلب بحروفٍ صغيرة ولا يُطابق شيئاً،
        // **فصارت كلُّ عمليّات الوكيل مجّانيّةً شهوراً** — وهو مكتوبٌ في
        // `CLAUDE.md` بثمنٍ دُفع.
        //
        // فالغيابُ الآن **يُرفع عطلاً في مركز الأعطال** ويُقال في الردّ،
        // ولا يُبتلع. والمجّانيُّ عمداً يُنشأ **نسخةً صريحةً بصفر** —
        // فيكون له سببٌ وسجلٌّ ومَن قرّره.
        if ($scheme === null) {
            app(\App\Services\OpsAlertService::class)->note(
                'fee.missing_scheme.'.$code.'.'.$zone,
                sprintf('تسعيرٌ مفقود: «%s»', $code),
                sprintf('لا نسخةَ نشطةٌ للرمز %s في المنطقة %s — '
                    . 'والعمليّةُ مرّت بلا رسم. إن كانت مجّانيّةً عمداً '
                    . 'فأنشئ نسخةً صريحةً بصفر.', $code, $zone),
            );

            return [
                'code' => $code,
                'amount' => $amount,
                'fee' => '0.0000',
                'platform_profit' => '0.0000',
                'agent_commission' => '0.0000',
                'bearer' => 'sender',
                'total_debit' => $amount,
                'net_credit' => $amount,
                'scheme_id' => null,
                'scheme_version' => null,
                'zone_code' => $zone,

                // **والحالةُ تُقال في الردّ** — فمن يقرأ الرقمَ يعرف
                // أهو قرارٌ أم نقص. (‏القاعدة السابعة: «غير معروف» ليس صفراً.)
                'pricing_state' => 'missing_config',
            ];
        }

        // والنسخةُ الموجودةُ تُعلن حالتَها كذلك — فحقلٌ يظهر أحياناً
        // ويغيب أحياناً يُقرأ غيابُه «سليم»، وهو أسوأُ من ألّا يوجد.
        $pricingState = MoneyService::gt((string) ($scheme->percent_rate ?? '0'), '0')
            || MoneyService::gt((string) ($scheme->fixed_amount ?? '0'), '0')
                ? 'priced' : 'explicit_zero';

        $feeType = $scheme->fee_type;

        // 1) الجزء النسبي = amount * percent / 100  (نضرب أولاً ثم نقسم لدقة أعلى)
        $percentPart = in_array($feeType, ['percent', 'percent_plus_fixed'], true)
            ? MoneyService::div(MoneyService::mul($amount, (string)$scheme->percent_rate), '100')
            : '0';

        // 2) الجزء الثابت
        $fixedPart = in_array($feeType, ['fixed', 'percent_plus_fixed'], true)
            ? MoneyService::normalize((string)$scheme->fixed_amount)
            : '0';

        $fee = MoneyService::add($percentPart, $fixedPart);

        // 3) تطبيق الحدّين (cap)
        if ($scheme->min_fee !== null && !MoneyService::gte($fee, (string)$scheme->min_fee)) {
            $fee = MoneyService::normalize((string)$scheme->min_fee);
        }
        if ($scheme->max_fee !== null && MoneyService::gt($fee, (string)$scheme->max_fee)) {
            $fee = MoneyService::normalize((string)$scheme->max_fee);
        }

        // 4) حصة الوكيل = fee * commission_percent/100 + commission_fixed، بحد أقصى = fee
        $agentCommission = MoneyService::add(
            MoneyService::div(MoneyService::mul($fee, (string)$scheme->agent_commission_percent), '100'),
            MoneyService::normalize((string)$scheme->agent_commission_fixed)
        );
        if (MoneyService::gt($agentCommission, $fee)) {
            // ══════════════════════════════════════════════════════════
            // AMIAL-FEE-TRUTH-013 — **والقصُّ يُقال، لا يُبتلع.**
            //
            // كان يُقصّ صامتاً. والقصُّ يعني شيئين معاً:
            //
            //   **ربحُ المنصّة صفر**  — كلُّ الرسم للوكيل
            //   **والوكيلُ يأخذ أقلَّ من الموعود** — حصّتُه قُصّت
            //
            // فطرفان خسرا، والإعدادُ الخاطئُ باقٍ يعمل على كلّ عمليّةٍ
            // بعدها **ولا سطرَ في أيّ سجلّ**. وهذا ما يقع بالضبط حين
            // تُضبط حصّةٌ ثابتةٌ (‏مثلاً ٥٠) فوق رسمٍ نسبيٍّ صغير: تعمل
            // على المبالغ الكبيرة وتُقصّ على الصغيرة، فالعطلُ متقطّع.
            app(\App\Services\OpsAlertService::class)->note(
                'fee.commission_clipped.'.$code,
                sprintf('حصّةُ وكيلٍ تفوق الرسم: «%s»', $code),
                sprintf('المبلغ %s · الرسم %s · الحصّةُ المحسوبة %s — قُصّت '
                    . 'إلى الرسم، فربحُ المنصّة صفرٌ والوكيلُ أخذ أقلَّ من '
                    . 'الموعود. راجع النسخة v%s.',
                    $amount, $fee, $agentCommission, (string) $scheme->version),
            );

            $agentCommission = $fee; // لا تتجاوز حصة الوكيل الرسم نفسه
        }

        // 5) ربح المنصّة = الرسم − حصة الوكيل (يضمن المجموع = fee بالضبط)
        $platformProfit = MoneyService::sub($fee, $agentCommission);

        // 6) من يتحمّل الرسم
        $bearer = $scheme->bearer ?? 'sender';
        if ($bearer === 'sender') {
            $totalDebit = MoneyService::add($amount, $fee); // الدافع يدفع المبلغ + الرسم
            $netCredit = $amount;                           // المستلم يأخذ المبلغ كاملاً
        } else {
            $totalDebit = $amount;                          // الدافع يدفع المبلغ
            $netCredit = MoneyService::sub($amount, $fee);  // الرسم يُخصم من المستلم

            // ══════════════════════════════════════════════════════════
            // AMIAL-FEE-TRUTH-007 — **لا صافيَ استلامٍ سالب.**
            //
            // حين يتحمّل **المستلمُ** الرسمَ ويكون الرسمُ أكبرَ من المبلغ
            // — رسمٌ ثابتٌ فوق مبلغٍ صغير، أو حدٌّ أدنى للرسم يتجاوزه —
            // يخرج `net_credit` سالباً. أي **حركةٌ ماليّةٌ تُنقص من رصيد
            // المستلم بدل أن تزيده**: يُرسَل له مالٌ فيَنقص.
            //
            // ولا يُقصّ الرقمُ صامتاً عند الصفر: ذاك يُخفي إعداداً خاطئاً
            // ويجعل الرسمَ المحصَّلَ غيرَ الرسم المُعلَن، فينكسر الدفتر.
            // **بل يُرفض الحساب** ويُقال السبب — والإعدادُ يُصلَح من
            // مركز الرسوم.
            if (! MoneyService::isNonNegative($netCredit)) {
                app(\App\Services\OpsAlertService::class)->note(
                    'fee.negative_net_credit.'.$code,
                    sprintf('رسمٌ يفوق المبلغ: «%s»', $code),
                    sprintf('المبلغ %s · الرسم %s · المتحمّل %s — '
                        . 'صافي الاستلام سالب. النسخة v%s.',
                        $amount, $fee, $bearer, (string) $scheme->version),
                );

                throw new InvalidArgumentException(sprintf(
                    'تسعيرٌ غيرُ صالح: الرسم (%s) يفوق المبلغ (%s) والمتحمّلُ هو '
                    . 'المستلم — فصافي الاستلام يصير سالباً. راجع نسخةَ الرسم v%s.',
                    $fee, $amount, (string) $scheme->version));
            }
        }

        return [
            'code' => $code,
            'amount' => $amount,
            'fee' => $fee,
            'platform_profit' => $platformProfit,
            'agent_commission' => $agentCommission,
            'bearer' => $bearer,
            'total_debit' => $totalDebit,
            'net_credit' => $netCredit,
            'scheme_id' => $scheme->id,
            'scheme_version' => $scheme->version,
            'zone_code' => $zone,
            'pricing_state' => $pricingState,
        ];
    }

    // ============================================================
    // قراءة النسخة النشطة (مع cache)
    // ============================================================

    public function activeScheme(string $code, string $zone = 'SOUTH', string $appliesTo = 'customer'): ?FeeScheme
    {
        $key = self::CACHE_PREFIX . "{$code}:{$zone}:{$appliesTo}";

        return Cache::remember($key, self::CACHE_TTL, function () use ($code, $zone, $appliesTo) {
            return FeeScheme::query()
                ->where('code', $code)
                ->where('zone_code', $zone)
                ->where('applies_to', $appliesTo)
                ->where('is_active', true)

                // ══════════════════════════════════════════════════════
                // AMIAL-FEE-TRUTH-014 — **`effective_from` كان حقلاً
                // مكتوباً لا يقرؤه أحد.**
                //
                // في الجدول عمودان — `effective_from` و`effective_to` —
                // ويُكتب الأوّلُ في كلّ نسخة. **ولم يكن يُقرأ إطلاقاً.**
                // فنسخةٌ مؤرّخةٌ للغد تسري **اليوم**، وواجهةٌ تعرض
                // «يسري من ...» تكذب على من يقرؤها.
                //
                // فصار يُقرأ. **ولا جدولةَ تُعرض في الشاشة بعد** — وذلك
                // مكتوبٌ في `createVersion` بسببه: الجدولةُ الكاملةُ
                // تحتاج أن تبقى النسخةُ القديمةُ نشطةً حتّى يحين موعدُ
                // الجديدة، وهو ما يكسر «نسخةٌ نشطةٌ واحدة». فالمعروضُ
                // اليومَ سريانٌ فوريٌّ، والحقلُ صادقٌ لا زينة.
                ->where(function ($q) {
                    $q->whereNull('effective_from')->orWhere('effective_from', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>', now());
                })
                ->orderByDesc('version')
                ->first();
        });
    }

    private function flushCache(string $code, string $zone, string $appliesTo): void
    {
        Cache::forget(self::CACHE_PREFIX . "{$code}:{$zone}:{$appliesTo}");
    }

    // ============================================================
    // إدارة النسخ (append-only)
    // ============================================================

    /**
     * إنشاء نسخة رسم جديدة. تُلغي النسخة النشطة السابقة لنفس
     * (code + zone + applies_to) وترفع رقم النسخة.
     */
    public function createVersion(array $data, ?int $adminId = null, ?string $ip = null): FeeScheme
    {
        $this->validate($data);

        $code = $data['code'];
        $zone = $data['zone_code'] ?? 'SOUTH';
        $appliesTo = $data['applies_to'] ?? 'customer';

        return DB::transaction(function () use ($data, $code, $zone, $appliesTo, $adminId, $ip) {
            $current = FeeScheme::query()
                ->where('code', $code)
                ->where('zone_code', $zone)
                ->where('applies_to', $appliesTo)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $newVersion = 1;
            if ($current) {
                $newVersion = $current->version + 1;
                $old = $current->toArray();
                $current->is_active = false;
                $current->effective_to = now();
                $current->save();
                $this->log($current->id, $code, 'deactivated', $old, null, $adminId, $ip);
            }

            $scheme = FeeScheme::create([
                'code' => $code,
                'label' => $data['label'] ?? null,
                'zone_code' => $zone,
                'applies_to' => $appliesTo,
                'fee_type' => $data['fee_type'],
                'percent_rate' => MoneyService::normalize((string)($data['percent_rate'] ?? 0)),
                'fixed_amount' => MoneyService::normalize((string)($data['fixed_amount'] ?? 0)),
                'min_fee' => isset($data['min_fee']) && $data['min_fee'] !== null && $data['min_fee'] !== ''
                    ? MoneyService::normalize((string)$data['min_fee']) : null,
                'max_fee' => isset($data['max_fee']) && $data['max_fee'] !== null && $data['max_fee'] !== ''
                    ? MoneyService::normalize((string)$data['max_fee']) : null,
                'agent_commission_percent' => MoneyService::normalize((string)($data['agent_commission_percent'] ?? 0)),
                'agent_commission_fixed' => MoneyService::normalize((string)($data['agent_commission_fixed'] ?? 0)),
                'bearer' => $data['bearer'] ?? 'sender',
                'version' => $newVersion,
                'is_active' => true,

                // ══════════════════════════════════════════════════════
                // AMIAL-FEE-TRUTH-014 — **السريانُ فوريٌّ، ولا جدولة.**
                //
                // والجدولةُ **لم تُبنَ عمداً لا سهواً**: نسخةٌ مؤجَّلةٌ
                // تقتضي بقاءَ القديمة نشطةً حتّى موعدِها، أي **نسختين
                // نشطتين معاً** — وهو ما يمنعه القيدُ الفريدُ في القاعدة،
                // وهو الذي يجعل «أيُّ تسعيرةٍ تسري الآن؟» سؤالاً له جوابٌ
                // واحد. فبناءُ الجدولة قرارُ بنيةٍ لا حقلُ تاريخٍ يُضاف.
                //
                // فيُفرَض `now()` هنا ولا يُقبل من الطلب: حقلٌ يُرسَل
                // ويُتجاهَل أسوأُ من حقلٍ لا وجودَ له.
                'effective_from' => now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $adminId,
            ]);

            $this->log($scheme->id, $code, 'created', null, $scheme->toArray(), $adminId, $ip);
            $this->flushCache($code, $zone, $appliesTo);

            return $scheme;
        });
    }

    /**
     * تعطيل نسخة (لإيقاف رسم عملية مؤقتاً).
     *
     * ══════════════════════════════════════════════════════════════════
     * **AMIAL-FEE-TRUTH-018 — والسببُ يُحفَظ في السجلّ لا في رأس فاعله.**
     *
     * فتعطيلُ نسخةٍ يجعل العمليّةَ **مجّانيّةً** حتّى تُضبط أخرى. ومن يقرأ
     * السجلَّ بعد شهر يرى «عُطّلت» ولا يعرف: أقرارٌ تجاريٌّ أم خطأ؟
     */
    public function deactivate(
        int $schemeId,
        ?int $adminId = null,
        ?string $ip = null,
        ?string $reason = null,
    ): void {
        DB::transaction(function () use ($schemeId, $adminId, $ip, $reason) {
            $scheme = FeeScheme::lockForUpdate()->findOrFail($schemeId);
            $old = $scheme->toArray();
            $scheme->is_active = false;
            $scheme->effective_to = now();
            $scheme->save();
            $this->log($scheme->id, $scheme->code, 'deactivated', $old,
                $reason !== null && $reason !== '' ? ['reason' => $reason] : null,
                $adminId, $ip);
            $this->flushCache($scheme->code, $scheme->zone_code, $scheme->applies_to);
        });
    }

    // ============================================================
    // تحقق + تدقيق
    // ============================================================

    private function validate(array $data): void
    {
        if (empty($data['code']) || !in_array($data['code'], FeeScheme::codes(), true)) {
            throw new InvalidArgumentException('Invalid fee code.');
        }
        if (empty($data['fee_type']) || !in_array($data['fee_type'], FeeScheme::FEE_TYPES, true)) {
            throw new InvalidArgumentException('Invalid fee_type.');
        }
        $appliesTo = $data['applies_to'] ?? 'customer';
        if (!in_array($appliesTo, FeeScheme::APPLIES_TO, true)) {
            throw new InvalidArgumentException('Invalid applies_to.');
        }
        $bearer = $data['bearer'] ?? 'sender';
        if (!in_array($bearer, FeeScheme::BEARERS, true)) {
            throw new InvalidArgumentException('Invalid bearer.');
        }

        $percent = (float)($data['percent_rate'] ?? 0);
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('percent_rate must be between 0 and 100.');
        }
        $commPercent = (float)($data['agent_commission_percent'] ?? 0);
        if ($commPercent < 0 || $commPercent > 100) {
            throw new InvalidArgumentException('agent_commission_percent must be between 0 and 100.');
        }
        if ((float)($data['fixed_amount'] ?? 0) < 0) {
            throw new InvalidArgumentException('fixed_amount cannot be negative.');
        }
        if ((float)($data['agent_commission_fixed'] ?? 0) < 0) {
            throw new InvalidArgumentException('agent_commission_fixed cannot be negative.');
        }

        $min = $data['min_fee'] ?? null;
        $max = $data['max_fee'] ?? null;
        if ($min !== null && $min !== '' && $max !== null && $max !== '' && (float)$min > (float)$max) {
            throw new InvalidArgumentException('min_fee cannot be greater than max_fee.');
        }

        $this->validateAgainstRegistry($data, $appliesTo, $bearer);
    }

    /**
     * AMIAL-FEE-TRUTH-012 — **والتحقّقُ يسأل سجلَّ العمليّات، لا القائمةَ وحدَها.**
     *
     * ══════════════════════════════════════════════════════════════════════
     * كان التحقّقُ يقبل **أيَّ** جهةٍ مع **أيّ** رمز: نسخةُ `SEND_MONEY`
     * بـ`applies_to = agent`، أو `MERCHANT_QR` بـ`bearer = receiver`.
     * وتُحفَظ ويراها الأدمنُ فعّالة — **ولا تُطابق نداءً واحداً** لأنّ
     * المسارَ الحيَّ يطلبها بجهةٍ أخرى. فيردّ المحرّكُ `missing_config`
     * والخدمةُ مجّانيّةٌ بلا أن يقرّر أحد. **وهو العطلُ نفسُه بثوبٍ ثانٍ.**
     *
     * **وأخطرُ منه حصّةُ الوكيل.** في التحويل بين المحفظتين لا يدَ بشريّةً
     * أصلاً، فرقمٌ في «حصّة الوكيل» هناك **يُقتطع من ربح المنصّة ويُقيَّد
     * لحصّةٍ لا صاحبَ لها** — والدفترُ متوازنٌ فلا يُنبّه أحد.
     */
    private function validateAgainstRegistry(array $data, string $appliesTo, string $bearer): void
    {
        $op = \App\Support\Fees\FeeOperationRegistry::find((string) $data['code']);

        if ($op === null) {
            return;   // الرمزُ فُحص أعلاه؛ وهذا لطمأنة المحلّل الساكن.
        }

        if (! in_array($appliesTo, $op->actors, true)) {
            throw new InvalidArgumentException(sprintf(
                'العمليّة «%s» لا تُطبَّق على «%s» — الجهاتُ المشروعة: %s. '
                . 'ونسخةٌ بجهةٍ لا تُطلب لا تُطابق نداءً واحداً، فتبقى العمليّةُ بلا تسعير.',
                $op->labelAr, $appliesTo, implode('، ', $op->actors)));
        }

        if (! in_array($bearer, $op->bearers, true)) {
            throw new InvalidArgumentException(sprintf(
                'العمليّة «%s» لا يتحمّل رسمَها «%s» — المتحمّلون المشروعون: %s.',
                $op->labelAr, $bearer, implode('، ', $op->bearers)));
        }

        if (! $op->agentCommission) {
            $hasCommission = MoneyService::gt(
                MoneyService::normalize((string) ($data['agent_commission_percent'] ?? 0)), '0')
                || MoneyService::gt(
                    MoneyService::normalize((string) ($data['agent_commission_fixed'] ?? 0)), '0');

            if ($hasCommission) {
                throw new InvalidArgumentException(sprintf(
                    'العمليّة «%s» لا وكيلَ فيها، فلا حصّةَ وكيلٍ لها. '
                    . 'ورقمٌ هنا يُقتطع من ربح المنصّة ويُقيَّد لمن لم يعمل.',
                    $op->labelAr));
            }
        }
    }

    private function log(?int $schemeId, string $code, string $action, ?array $old, ?array $new, ?int $adminId, ?string $ip): void
    {
        FeeChangeLog::create([
            'fee_scheme_id' => $schemeId,
            'code' => $code,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'admin_id' => $adminId,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
