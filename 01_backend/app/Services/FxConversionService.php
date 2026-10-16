<?php

namespace App\Services;

use App\Models\EMoney;
use App\Models\FxRate;
use App\Support\Money\Currencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-MULTI-CURRENCY-002 — **الصرفُ بين محافظ المستخدم الواحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذه ليست ميزةً جانبيّة — هي جوابُ الحاجز الذي قِيس قبل البناء.**
 *
 * الوكلاءُ في المنصّة يحملون **نقداً بالريال اليمنيّ**. فتاجرٌ رصيدُه
 * دولارٌ حقيقيٌّ لا يجد من يصرفه له، ومحفظةٌ لا تُصرَف التزامٌ لا يُسدَّد.
 * فالطريقُ إلى النقد: **يحوّل التاجرُ دولارَه إلى ريالٍ داخل التطبيق بسعر
 * المنصّة، ثمّ يُسوّى بالريال كما يُسوّى اليوم** — والوكيلُ لا يمسّ دولاراً
 * قطّ، ولا تتغيّر طبقةُ التمويل ذاتُ الطرفين (القاعدة العاشرة).
 *
 * ── ولمَ قيدان لا قيدٌ واحد ──────────────────────────────────────────
 *
 * قيدٌ واحدٌ «مدينٌ ١٠٠ دولار / دائنٌ ٥٣٬٠٠٠ ريال» **غيرُ متوازن** بأيّ
 * معنىً محاسبيّ، وقيدٌ «مدينٌ ١٠٠ / دائنٌ ١٠٠» عبر عملتين يجتاز فحصَ
 * التوازن العدديّ **وهو خلقُ مالٍ من فرق الصرف**. فيُقيَّد:
 *
 *     ① بالدولار كلُّه : محفظةُ التاجر (دولار)  ⟵ FX_POSITION_USD
 *     ② بالريال كلُّه  : FX_POSITION_YER        ⟵ محفظةُ التاجر (ريال)
 *
 * وكلُّ قيدٍ متوازنٌ **داخل عملته**، وحسابا `FX_POSITION_*` يحملان مركزَ
 * المنصّة الصرفيَّ فيُقرأ ويُدقَّق: كم دولاراً اشترت المنصّةُ من تجّارها،
 * وبكم من الريال. **وهو رقمٌ لا بدّ منه** — من يصرف عملةً يحمل مخاطرَها.
 */
class FxConversionService
{
    public function __construct(
        private readonly FxRateService $rates,
        private readonly FinancialGuardService $guard,
        private readonly LedgerService $ledger,
        private readonly AuditService $audit,
    ) {}

    /** حسابُ مركز الصرف لعملةٍ ما — واحدٌ لكلّ عملةٍ، ملكُ المنصّة. */
    private function positionAccount(string $currency): string
    {
        $cur = Currencies::normalize($currency);
        $code = "FX_POSITION_{$cur}";

        $this->ledger->getOrCreateSystemAccount(
            $code,
            'liability',
            'مركز الصرف — '.Currencies::nameAr($cur),
            'credit',
            $cur,
        );

        return $code;
    }

    /**
     * يحوّل مبلغاً من محفظةٍ إلى محفظةٍ للمستخدم نفسِه.
     *
     * @param  string  $amount  المبلغُ **بعملة المصدر**.
     * @return array{from: EMoney, to: EMoney, rate: string, converted: string, rate_source: string, rate_at: ?string, ref: string}
     *
     * @throws RuntimeException
     */
    public function convert(
        int $userId,
        string $fromCurrency,
        string $toCurrency,
        string $amount,
        ?string $idempotencyKey = null,
    ): array {
        $from = Currencies::normalize($fromCurrency);
        $to = Currencies::normalize($toCurrency);

        if ($from === $to) {
            throw new RuntimeException('لا صرفَ بين عملةٍ ونفسِها.');
        }
        if (bccomp(MoneyService::normalize($amount), '0', 4) <= 0) {
            throw new RuntimeException('مبلغُ الصرف يجب أن يكون موجباً.');
        }

        $amount = MoneyService::normalize($amount);

        // ── السعرُ يُقرأ مرّةً واحدةً ويُنسَخ في كلّ أثر ──────────────────
        //
        // **ولا يُقرأ مرّتين**: قراءتان بينهما تغييرُ سعرٍ من الإدارة تجعلان
        // الخصمَ بسعرٍ والإضافةَ بآخر — وهو مالٌ يُخلَق أو يُمحى بلا قيد.
        [$rate, $rateSource, $rateAt, $rateId] = $this->resolveRate($from, $to);
        $converted = bcmul($amount, $rate, 4);

        if (bccomp($converted, '0', 4) <= 0) {
            throw new RuntimeException(
                'المبلغُ أصغرُ من أن يُصرَف بهذا السعر — يُنتج صفراً بعد التقريب.'
            );
        }

        $ref = $idempotencyKey ?: (string) Str::ulid();

        return DB::transaction(function () use (
            $userId, $from, $to, $amount, $converted, $rate, $rateSource, $rateAt, $rateId, $ref
        ) {
            // **القفلُ بترتيبٍ ثابت** — محفظتان للمستخدم نفسِه، والترتيبُ
            // بالعملة أبجديّاً يمنع الجمودَ (deadlock) بين صرفين متعاكسين
            // يقعان في اللحظة نفسها.
            $order = [$from, $to];
            sort($order, SORT_STRING);
            foreach ($order as $c) {
                $this->guard->lockWallet($userId, $c);
            }

            $src = $this->guard->debit($userId, $amount, 'fx_convert_out', $from);
            $dst = $this->guard->credit($userId, $converted, 'fx_convert_in', $to);

            // ── القيدان ─────────────────────────────────────────────
            $walletFrom = $this->ledger->getOrCreateUserWallet($userId, $src->zone_code ?: 'SOUTH', $from);
            $walletTo = $this->ledger->getOrCreateUserWallet($userId, $dst->zone_code ?: 'SOUTH', $to);
            $posFrom = $this->positionAccount($from);
            $posTo = $this->positionAccount($to);

            $meta = [
                'fx' => true, 'ref' => $ref,
                'from_currency' => $from, 'to_currency' => $to,
                'from_amount' => $amount, 'to_amount' => $converted,
                'rate_to' => $rate, 'rate_source' => $rateSource, 'rate_at' => $rateAt,
                'fx_rate_id' => $rateId,
            ];

            // ① بعملة المصدر كلُّه — يخرج من محفظة التاجر إلى مركز المنصّة.
            $this->ledger->post(
                sourceType: 'fx_convert_out',
                sourceId: $ref,
                description: sprintf('صرف %s %s ← %s', $amount, $from, Currencies::nameAr($to)),
                lines: [
                    ['account' => $walletFrom->account_code, 'direction' => 'debit', 'amount' => $amount],
                    ['account' => $posFrom, 'direction' => 'credit', 'amount' => $amount],
                ],
                idempotencyKey: "fx_out:{$ref}",
                createdByUserId: $userId,
                metadata: $meta,
                zoneCode: $src->zone_code ?: 'SOUTH',
            );

            // ② بعملة الوجهة كلُّه — يدخل محفظةَ التاجر من مركز المنصّة.
            $this->ledger->post(
                sourceType: 'fx_convert_in',
                sourceId: $ref,
                description: sprintf('صرف %s %s ← %s', $converted, $to, Currencies::nameAr($from)),
                lines: [
                    ['account' => $posTo, 'direction' => 'debit', 'amount' => $converted],
                    ['account' => $walletTo->account_code, 'direction' => 'credit', 'amount' => $converted],
                ],
                idempotencyKey: "fx_in:{$ref}",
                createdByUserId: $userId,
                metadata: $meta,
                zoneCode: $dst->zone_code ?: 'SOUTH',
                // ══════════════════════════════════════════════════════
                // **والسالبُ هنا مقصودٌ وهو المعنى نفسُه.**
                //
                // مركزُ الصرف بالوجهة يُدان لأنّ المنصّة **دفعت** بتلك
                // العملة. فصيرورتُه سالباً تعني: المنصّةُ قصيرةٌ في الريال
                // وطويلةٌ في الدولار بقدرِ ما صرفت. **وهذا رقمٌ يجب أن
                // يُرى**، لا عطلٌ يُمنَع.
                //
                // **ولا يُرفَع الحارسُ عن محفظة التاجر**: هذا القيدُ يُدين
                // المركزَ **وحدَه** ويُدين لا غير — والطرفُ الآخرُ دائنٌ
                // (زيادة). أمّا القيدُ الأوّل، الذي يُدين محفظةَ التاجر
                // فعلاً، فيبقى محروساً (‏`allowNegative` فيه على الافتراضيّ
                // `false`)، ومعه فحصُ الكفاية في `guard->debit` قبله.
                // فطرفان يحرسان الخصمَ، ولا واحدَ منهما رُفع.
                // ══════════════════════════════════════════════════════
                allowNegative: true,
            );

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $userId,
                'subject_type' => 'wallet',
                'subject_id' => (string) $userId,
                'action' => 'FX_CONVERT',
                'decision_code' => 'FX_OK',
                'reason' => 'صرف بين محافظ المستخدم',
                'severity' => 'info',
                // **الأثرُ يحمل السعرَ ومصدرَه**: «صُرف ١٠٠ دولار» بلا سعرٍ
                // لا يُدقَّق، ولا يُعاد حسابُه بعد شهرٍ إلّا بسعرٍ آخر.
                'context' => $meta,
            ]);

            return [
                'from' => $src->refresh(),
                'to' => $dst->refresh(),
                'rate' => $rate,
                'converted' => $converted,
                'rate_source' => $rateSource,
                'rate_at' => $rateAt,
                'ref' => $ref,
            ];
        });
    }

    /**
     * سعرُ «١ من المصدر = كم من الوجهة»، ومعه مصدرُه ولحظتُه.
     *
     * **ويمرّ عبر الأساس دائماً**: الأسعارُ كلُّها مقابل الريال، فصرفُ
     * دولارٍ إلى درهمٍ حاصلُ قسمة. ولا سعرَ مباشرٌ يُخترَع بينهما.
     *
     * @return array{0:string,1:string,2:?string,3:?int}
     */
    private function resolveRate(string $from, string $to): array
    {
        // مصدرُهما لا يكون الأساسَ معاً — فُحص قبل النداء.
        if (Currencies::isBase($from)) {
            $r = $this->rates->rateAt($to);
            // ١ ريال = 1 / rate من الوجهة
            return [bcdiv('1', (string) $r->rate_to_base, 8), (string) $r->source,
                $r->effective_at?->toIso8601String(), (int) $r->id];
        }

        if (Currencies::isBase($to)) {
            $r = $this->rates->rateAt($from);

            return [(string) $r->rate_to_base, (string) $r->source,
                $r->effective_at?->toIso8601String(), (int) $r->id];
        }

        // عملةٌ إلى عملة — عبر الأساس، **وكلا السعرين مذكورٌ في الأثر**.
        $rf = $this->rates->rateAt($from);
        $rt = $this->rates->rateAt($to);

        return [
            bcdiv((string) $rf->rate_to_base, (string) $rt->rate_to_base, 8),
            sprintf('%s+%s', $rf->source, $rt->source),
            $rf->effective_at?->toIso8601String(),
            (int) $rf->id,
        ];
    }

    /**
     * عرضٌ قبل التنفيذ — **يُقرأ في نافذة التأكيد.**
     *
     * فتحويلٌ يقع ثمّ يُقرأ سعرُه بعده يجعل السعرَ مفاجأةً. وهو مالٌ.
     */
    public function quote(string $fromCurrency, string $toCurrency, string $amount): array
    {
        $from = Currencies::normalize($fromCurrency);
        $to = Currencies::normalize($toCurrency);

        if ($from === $to) {
            throw new RuntimeException('لا صرفَ بين عملةٍ ونفسِها.');
        }

        [$rate, $source, $at] = $this->resolveRate($from, $to);
        $amount = MoneyService::normalize($amount);

        return [
            'from_currency' => $from,
            'to_currency' => $to,
            'amount' => $amount,
            'rate' => $rate,
            'converted' => bcmul($amount, $rate, 4),
            'rate_source' => $source,
            'rate_at' => $at,
        ];
    }
}
