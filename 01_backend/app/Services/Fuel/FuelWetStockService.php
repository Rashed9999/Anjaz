<?php

namespace App\Services\Fuel;

use App\Models\Fuel\FuelStockReconciliation;
use App\Models\Fuel\FuelTank;
use App\Models\FuelSale;
use App\Models\FuelStation;
use App\Models\User;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٣ — **مصالحةُ المخزون الرطب**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * وهذه هي الميزةُ التي تفصل نظامَ محطّةٍ حقيقيّاً عن تطبيق فواتير.
 *
 *     الافتتاحيّ + المورَّد − المباع = المتوقَّع
 *     المتوقَّع  − المقيس            = الفرق
 *
 * **وكلُّ حدٍّ يُحسب من مصدره:**
 *
 *   الافتتاحيّ ← آخرُ **قياسٍ** قبل بداية المدّة، لا `book_liters`.
 *   المورَّد   ← توريداتٌ **مُرحَّلة** وحدَها.
 *   المباع     ← لتراتُ مبيعاتٍ مربوطةٍ بهذا الخزّان عبر مسدسها.
 *   المقيس     ← قياسٌ فعليٌّ في نهاية المدّة.
 *
 * وميزانٌ يُبنى من عمودٍ مخزَّن يُثبت أنّ العمود يساوي نفسَه. (القاعدة
 * السادسة.)
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والنتيجةُ تحقيقٌ لا اتّهام.** فقدُ تسعين لتراً قد يكون قراءةً خاطئة،
 * أو توريداً لم يُرحَّل، أو عدّاداً معطوباً، أو مسدساً غيرَ مربوطٍ بخزّانه،
 * أو تسرُّباً. **والنظامُ يفتح ملفّاً ولا يحكم على أحد.**
 */
class FuelWetStockService
{
    public function __construct(
        private readonly FuelTankService $tanks,
        private readonly FuelDeliveryService $deliveries,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * حدُّ التسامح — **٠٫٥٪ من المنصرف**، لا رقمٌ ثابت.
     *
     * فخزّانٌ خرج منه عشرةُ آلاف لتر يختلف تسامحُه عن خزّانٍ خرج منه مئة:
     * التمدّدُ الحراريّ ودقّةُ العدّاد نسبيّان. وحدٌّ ثابتٌ يُغرق الصغيرَ
     * بالتنبيهات ويُخفي الكبير.
     */
    private const TOLERANCE_PERCENT = '0.5';

    /**
     * تُحسب المصالحة **ولا تُحفظ** — للعرض الحيّ.
     *
     * @return array<string,mixed>
     */
    public function compute(
        FuelTank $tank, \DateTimeInterface $from, \DateTimeInterface $to,
        ?string $actualClosing = null,
    ): array {
        $openingDip = $this->tanks->dipAt($tank, $from);
        $closingDip = $actualClosing !== null
            ? null
            : $this->tanks->dipAt($tank, $to);

        // **«غير معروف» ليس صفراً.** بلا قياسٍ افتتاحيّ لا مصالحة —
        // ولا تُفترض صفراً فتُنتج فرقاً بحجم الخزّان.
        if (! $openingDip) {
            return [
                'computable' => false,
                'reason' => 'لا يوجد قياس افتتاحي للخزان قبل بداية المدة — '
                    . 'سجّل قياساً أولاً (الخزانات ← تسجيل قياس)',
            ];
        }

        $actual = $actualClosing ?? ($closingDip ? (string) $closingDip->dip_liters : null);

        if ($actual === null) {
            return [
                'computable' => false,
                'reason' => 'لا يوجد قياس ختامي للخزان — سجّل قياساً في نهاية المدة',
            ];
        }

        $opening = (string) $openingDip->dip_liters;
        $delivered = $this->deliveries->postedLitersBetween($tank, $from, $to);
        $sold = $this->soldLitersBetween($tank, $from, $to);

        $expected = bcsub(bcadd($opening, $delivered, 3), $sold, 3);
        $variance = bcsub($actual, $expected, 3);

        // النسبةُ من **المنصرف** (المباع + المورَّد) لا من السعة.
        $throughput = bcadd($sold, $delivered, 3);
        $percent = bccomp($throughput, '0', 3) > 0
            ? bcdiv(bcmul(ltrim($variance, '-'), '100', 5), $throughput, 4)
            : '0';

        $tolerance = bcdiv(bcmul($throughput, self::TOLERANCE_PERCENT, 5), '100', 3);
        $withinTolerance = bccomp(ltrim($variance, '-'), $tolerance, 3) <= 0;

        return [
            'computable' => true,
            'tank_id' => (int) $tank->id,
            'period_start' => $from,
            'period_end' => $to,
            'opening_liters' => $opening,
            'delivered_liters' => $delivered,
            'sold_liters' => $sold,
            'expected_closing_liters' => $expected,
            'actual_closing_liters' => $actual,
            'variance_liters' => $variance,
            'variance_percent' => $percent,
            'tolerance_liters' => $tolerance,
            'within_tolerance' => $withinTolerance,
            'direction' => bccomp($variance, '0', 3) < 0 ? 'loss'
                : (bccomp($variance, '0', 3) > 0 ? 'gain' : 'exact'),
            'opening_dip_at' => $openingDip->taken_at?->toIso8601String(),
        ];
    }

    /**
     * تُحسب وتُحفظ — **وتفتح تحقيقاً إن تجاوزت التسامح**.
     */
    public function reconcile(
        FuelTank $tank, User $actor,
        \DateTimeInterface $from, \DateTimeInterface $to,
        ?string $actualClosing = null, ?int $shiftId = null,
    ): FuelStockReconciliation {
        $r = $this->compute($tank, $from, $to, $actualClosing);

        if (! $r['computable']) {
            throw new DomainException($r['reason']);
        }

        return DB::transaction(function () use ($r, $tank, $actor, $shiftId) {
            $recon = FuelStockReconciliation::create([
                'recon_ulid' => (string) Str::ulid(),
                'station_id' => $tank->station_id,
                'tank_id' => $tank->id,
                'shift_id' => $shiftId,
                'period_start' => $r['period_start'],
                'period_end' => $r['period_end'],
                'opening_liters' => $r['opening_liters'],
                'delivered_liters' => $r['delivered_liters'],
                'sold_liters' => $r['sold_liters'],
                'expected_closing_liters' => $r['expected_closing_liters'],
                'actual_closing_liters' => $r['actual_closing_liters'],
                'variance_liters' => $r['variance_liters'],
                'variance_percent' => $r['variance_percent'],
                'status' => $r['within_tolerance'] ? 'within_tolerance' : 'investigating',
                'created_by_user_id' => $actor->id,
                'zone_code' => $tank->station->zone_code ?? 'SOUTH',
            ]);

            // **يُصالَح الدفترُ مع القياس بعد التسجيل** — لا قبله.
            // فالقياسُ حقيقة، والدفترُ تقديرٌ يُصحَّح بها؛ والفرقُ محفوظٌ
            // في السجلّ فلا يضيع بالتصحيح.
            $tank->update(['book_liters' => $r['actual_closing_liters']]);

            if (! $r['within_tolerance']) {
                $this->audit->record([
                    'actor_type' => 'merchant',
                    'actor_user_id' => $actor->id,
                    'subject_type' => 'fuel_tank',
                    'subject_id' => (string) $tank->id,
                    'action' => 'FUEL_STOCK_VARIANCE_OPENED',
                    'decision_code' => 'WET_STOCK_VARIANCE',
                    'severity' => 'critical',
                    'context' => [
                        'variance_liters' => $r['variance_liters'],
                        'variance_percent' => $r['variance_percent'],
                        'tolerance_liters' => $r['tolerance_liters'],
                        'recon_id' => $recon->id,
                    ],
                ]);
            }

            return $recon->fresh();
        });
    }

    /**
     * إغلاقُ التحقيق **بسببٍ مكتوب** — لا بضغطة زرّ.
     *
     * فتحقيقٌ يُغلق بلا سببٍ يُعيد العطلَ نفسَه بعد شهر ولا أحدَ يعرف
     * ماذا وُجد في الأوّل.
     */
    public function resolve(
        FuelStockReconciliation $recon, User $actor, string $note, string $status = 'resolved',
    ): FuelStockReconciliation {
        if (! in_array($status, ['resolved', 'written_off'], true)) {
            throw new DomainException('حالة الإغلاق غير صحيحة');
        }

        if (mb_strlen(trim($note)) < 10) {
            throw new DomainException(
                'اكتب ما وُجد في التحقيق (١٠ أحرف على الأقل) — '
                . 'تحقيقٌ يُغلق بلا سبب يعود بعد شهرٍ ولا أحد يعرف ماذا وُجد',
            );
        }

        $recon->update([
            'status' => $status,
            'investigation_note' => $note,
            'resolved_by_user_id' => $actor->id,
            'resolved_at' => now(),
        ]);

        $this->audit->record([
            'actor_type' => 'merchant',
            'actor_user_id' => $actor->id,
            'subject_type' => 'fuel_stock_recon',
            'subject_id' => (string) $recon->id,
            'action' => 'FUEL_STOCK_VARIANCE_RESOLVED',
            'decision_code' => strtoupper($status),
            'severity' => 'warning',
            'context' => ['variance_liters' => (string) $recon->variance_liters, 'note' => $note],
        ]);

        return $recon->fresh();
    }

    /**
     * لتراتٌ بيعت من خزّانٍ في مدّة.
     *
     * **وتُقاس بالربط لا بنوع الوقود:** خزّانان بالنوع نفسه في محطّةٍ
     * واحدة أمرٌ عاديّ، ونسبةُ المبيعات بالنوع تخلطهما فتُخرج فائضاً في
     * واحدٍ وعجزاً في الآخر — ويبدو الأمرُ سرقةً وهو خطأُ نسبة.
     */
    private function soldLitersBetween(
        FuelTank $tank, \DateTimeInterface $from, \DateTimeInterface $to,
    ): string {
        return (string) FuelSale::where('tank_id', $tank->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->get()->reduce(
                static fn ($c, $s) => bcadd($c, (string) $s->liters, 3), '0');
    }

    /**
     * مبيعاتٌ لم تُنسب إلى خزّان — **تُقال ولا تُبتلع**.
     *
     * مسدسٌ غيرُ مربوطٍ بخزّانه يجعل لتراتِه خارج المعادلة كلِّها: لا
     * تُخصم من أحد، فيظهر **فائضٌ** يُقرأ خطأً أنّه ربح.
     */
    public function unattributedLiters(
        FuelStation $station, \DateTimeInterface $from, \DateTimeInterface $to,
    ): string {
        return (string) FuelSale::where('station_id', $station->id)
            ->whereNull('tank_id')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->get()->reduce(
                static fn ($c, $s) => bcadd($c, (string) $s->liters, 3), '0');
    }
}
