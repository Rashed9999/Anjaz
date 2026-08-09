<?php

namespace App\Services;

use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelShift;
use App\Models\FuelShiftPumpSummary;
use App\Models\FuelStation;
use App\Models\FuelVarianceRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-FUEL-002 — خدمة النوبات والعجز/الفائض.
 *
 * Workflow:
 *   1) openShift(): يفتح نوبة + يلتقط قراءات العدّاد الافتتاحية لكل المضخّات.
 *   2) (خلال النوبة): الموظف يبيع عادياً عبر FuelStationService.
 *   3) closeShift(): يطلب قراءات العدّاد النهائية + النقد الفعلي.
 *                  يحسب: expected_cash، variance، expected_liters لكل مضخّة.
 *                  ينشئ FuelVarianceRecord إن كان variance > threshold.
 *
 * threshold العجز/الفائض:
 *   - نقد: > 500 ر.ي أو > 2% من المبيعات → يحتاج موافقة إدارية.
 *   - لترات: > 5 لتر → يحتاج موافقة (قد يعني فقد/تسريب).
 */
class FuelShiftService
{
    /** عتبة العجز النقدي للموافقة الإدارية. */
    private const CASH_VARIANCE_THRESHOLD = '500';
    private const CASH_VARIANCE_PERCENT_THRESHOLD = 2.0; // %

    /** عتبة فرق اللترات. */
    private const LITERS_VARIANCE_THRESHOLD = '5';

    public function __construct(
        private readonly NotificationService $notif,
    ) {}

    /**
     * فتح نوبة. ينشئ سجل نوبة + ملخّص لكل مضخّة بقراءة العدّاد الافتتاحية.
     */
    public function openShift(
        FuelStation $station,
        User $opener,
        string $openingCash = '0',
        ?string $notes = null,
    ): FuelShift {
        // تأكّد لا توجد نوبة مفتوحة لنفس المحطة
        $existing = FuelShift::where('station_id', $station->id)
            ->where('status', 'open')
            ->first();
        if ($existing) {
            throw new RuntimeException('توجد نوبة مفتوحة سابقاً — أغلقها أوّلاً');
        }

        $openingCash = MoneyService::normalize($openingCash);

        return DB::transaction(function () use ($station, $opener, $openingCash, $notes) {
            $shift = FuelShift::create([
                'shift_ulid' => (string) Str::ulid(),
                'station_id' => $station->id,
                'opened_by_user_id' => $opener->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'status' => 'open',
                'opening_notes' => $notes,
                'zone_code' => $station->zone_code ?? 'SOUTH',
            ]);

            // ملخّص لكل مضخّة بقراءة العدّاد الحالية
            $pumps = FuelPump::where('station_id', $station->id)
                ->where('is_active', true)
                ->get();

            foreach ($pumps as $pump) {
                FuelShiftPumpSummary::create([
                    'shift_id' => $shift->id,
                    'pump_id' => $pump->id,
                    'opening_meter' => (string) $pump->current_meter_reading,
                ]);
            }

            return $shift->fresh('pumpSummaries');
        });
    }

    /**
     * إغلاق نوبة.
     *
     * @param array $pumpClosings [pump_id => closing_meter] للميكانيكية فقط
     */
    public function closeShift(
        FuelShift $shift,
        User $closer,
        string $actualCash,
        array $pumpClosings = [],
        ?string $varianceReason = null,
        ?string $closingNotes = null,
    ): FuelShift {
        if ($shift->status !== 'open') {
            throw new RuntimeException('النوبة ليست مفتوحة');
        }

        $actualCash = MoneyService::normalize($actualCash);

        return DB::transaction(function () use ($shift, $closer, $actualCash, $pumpClosings, $varianceReason, $closingNotes) {
            // ===== 1) جمع كل المبيعات في فترة النوبة =====
            // **بالانتماء لا بالنافذة الزمنيّة.**
            //
            // كان الجمعُ `created_at >= opened_at` بلا حدٍّ أعلى، فبيعةٌ تقع
            // بين ورديّتين تسقط من الاثنتين — **نقدٌ في الدرج بلا وردية**
            // يظهر عجزاً في أوّل جردٍ بلا تفسير.
            //
            // والآن `shift_id` يُكتب لحظةَ البيع، والبيعُ يشترط ورديّةً
            // مفتوحة. (AMIAL-FUEL-VERTICAL-001 · المرحلة ٠)
            $sales = FuelSale::where('shift_id', $shift->id)
                ->where('status', 'completed')
                ->get();

            $totalCash = '0';
            $totalAmial = '0';
            $totalCompany = '0';
            $totalLiters = '0';

            foreach ($sales as $sale) {
                $totalLiters = MoneyService::add($totalLiters, (string)$sale->liters);
                $totalAmount = (string)$sale->total_amount;
                match ($sale->payment_method) {
                    'cash' => $totalCash = MoneyService::add($totalCash, $totalAmount),
                    'amial_pay' => $totalAmial = MoneyService::add($totalAmial, $totalAmount),
                    'company_card' => $totalCompany = MoneyService::add($totalCompany, $totalAmount),
                    default => null,
                };
            }

            // ===== 2) حساب expected_cash و variance =====
            //
            // **والمعادلةُ تشمل حركةَ النقد** — AMIAL-FUEL-VERTICAL-001 · ٧.
            //
            // كانت: الافتتاح + المبيعات النقديّة = المتوقَّع. **فكلُّ ريالٍ
            // يخرج للمصروفات يظهر عجزاً** في وجه الكاشير: اشترى ماءً
            // للمحطّة بألفين فيُطالَب بألفين آخرَ الوردية، أو يُسجَّل عليه
            // فرقٌ يُقرأ ريبةً.
            $cash = app(\App\Services\Fuel\FuelShiftCashService::class)->summarise($shift);

            $expectedCash = MoneyService::add(
                MoneyService::add((string) $shift->opening_cash, $totalCash),
                $cash['net'],   // الداخل − الخارج (سالبٌ إن غلب الخارج)
            );

            $variance = MoneyService::sub($actualCash, $expectedCash); // موجب = فائض، سالب = عجز

            // ===== 3) حدّث ملخّصات المضخّات =====
            foreach ($shift->pumpSummaries as $summary) {
                $recordedLiters = '0';
                $pumpSales = $sales->where('pump_id', $summary->pump_id);
                $pumpTotal = '0';
                foreach ($pumpSales as $s) {
                    $recordedLiters = MoneyService::add($recordedLiters, (string)$s->liters);
                    $pumpTotal = MoneyService::add($pumpTotal, (string)$s->total_amount);
                }

                $closingMeter = $pumpClosings[$summary->pump_id] ?? null;
                $expectedLiters = null;
                $litersVariance = null;

                if ($closingMeter !== null) {
                    $closingMeter = MoneyService::normalize((string)$closingMeter);
                    $expectedLiters = MoneyService::sub($closingMeter, (string)$summary->opening_meter);

                    if ((float)$expectedLiters < 0) {
                        throw new InvalidArgumentException(
                            "قراءة عدّاد المضخّة {$summary->pump_id} الأخيرة أقل من الافتتاحية"
                        );
                    }
                    $litersVariance = MoneyService::sub($recordedLiters, $expectedLiters);

                    // حدّث عدّاد المضخّة
                    FuelPump::where('id', $summary->pump_id)
                        ->update(['current_meter_reading' => $closingMeter]);
                }

                $summary->update([
                    'closing_meter' => $closingMeter,
                    'expected_liters' => $expectedLiters,
                    'recorded_liters' => $recordedLiters,
                    'liters_variance' => $litersVariance,
                    'total_amount' => $pumpTotal,
                    'sales_count' => $pumpSales->count(),
                ]);
            }

            // ===== 4) قيِّم variance ===== 
            $hasVariance = MoneyService::compare($variance, '0') !== 0;
            $requiresReview = $this->cashVarianceNeedsReview($variance, $totalCash);

            $newStatus = $hasVariance ? 'closed_with_variance' : 'closed';

            $shift->update([
                'closed_by_user_id' => $closer->id,
                'closed_at' => now(),
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'variance' => $variance,
                'total_cash_sales' => $totalCash,
                'total_amial_pay_sales' => $totalAmial,
                'total_company_sales' => $totalCompany,
                'total_liters' => $totalLiters,
                'total_sales_count' => $sales->count(),
                'status' => $newStatus,
                'variance_reason' => $varianceReason,
                'closing_notes' => $closingNotes,
                'requires_admin_review' => $requiresReview,
            ]);

            // ===== 5) أنشئ variance records =====
            $this->createVarianceRecords($shift->fresh(), $closer, $variance, $varianceReason);

            return $shift->fresh(['pumpSummaries', 'varianceRecords']);
        });
    }

    /** يقرّر إن كان العجز/الفائض النقدي يحتاج موافقة إدارية. */
    private function cashVarianceNeedsReview(string $variance, string $totalCash): bool
    {
        $absVariance = abs((float)$variance);
        if ($absVariance < (float)self::CASH_VARIANCE_THRESHOLD) {
            return false;
        }
        // > الحد المطلق → يحتاج مراجعة دائماً
        if ($absVariance >= (float)self::CASH_VARIANCE_THRESHOLD) {
            // وإن كان > 2% من المبيعات النقدية، اعتبره كبيراً
            $cashSales = (float)$totalCash;
            if ($cashSales > 0) {
                $percent = ($absVariance / $cashSales) * 100;
                return $percent >= self::CASH_VARIANCE_PERCENT_THRESHOLD;
            }
            return true; // لا مبيعات نقدية لكن variance > الحد → مراجعة
        }
        return false;
    }

    /** ينشئ سجلات العجز/الفائض النقدي + اللترات. */
    private function createVarianceRecords(
        FuelShift $shift, User $reporter, string $cashVariance, ?string $reason,
    ): void {
        // نقد
        if (MoneyService::compare($cashVariance, '0') !== 0) {
            $direction = (float)$cashVariance < 0 ? 'shortage' : 'surplus';
            $amount = MoneyService::normalize((string)abs((float)$cashVariance));
            FuelVarianceRecord::create([
                'record_ulid' => (string) Str::ulid(),
                'shift_id' => $shift->id,
                'station_id' => $shift->station_id,
                'reported_by_user_id' => $reporter->id,
                'variance_type' => 'cash_variance',
                'direction' => $direction,
                'amount' => $amount,
                'reason' => $reason,
                'resolution_status' => 'pending',
                'zone_code' => $shift->zone_code,
            ]);
        }

        // لترات (لكل مضخّة تجاوزت الحد)
        foreach ($shift->pumpSummaries as $summary) {
            if ($summary->liters_variance === null) continue;
            $absV = abs((float)$summary->liters_variance);
            if ($absV < (float)self::LITERS_VARIANCE_THRESHOLD) continue;

            // اللتر variance: recorded - expected
            //   موجب = recorded > expected → فائض في التسجيل (سُجِّل أكثر مما اِستهلك)
            //   سالب = recorded < expected → عجز (استهلكت لترات لم تُسجَّل) ← مشكلة!
            $direction = (float)$summary->liters_variance < 0 ? 'shortage' : 'surplus';
            FuelVarianceRecord::create([
                'record_ulid' => (string) Str::ulid(),
                'shift_id' => $shift->id,
                'station_id' => $shift->station_id,
                'reported_by_user_id' => $reporter->id,
                'variance_type' => 'liters_variance',
                'direction' => $direction,
                'amount' => MoneyService::normalize((string)$absV),
                'reason' => "مضخّة {$summary->pump_id}: " . ($reason ?? ''),
                'resolution_status' => 'pending',
                'zone_code' => $shift->zone_code,
            ]);
        }
    }

    /** Admin: حلّ سجل variance. */
    public function resolveVariance(
        FuelVarianceRecord $record,
        int $adminId,
        string $resolution,
        ?string $note = null,
    ): FuelVarianceRecord {
        if (!in_array($resolution, FuelVarianceRecord::RESOLUTIONS, true)
            || $resolution === 'pending') {
            throw new InvalidArgumentException('قرار حل غير صحيح');
        }
        $record->update([
            'resolution_status' => $resolution,
            'resolved_by_admin_id' => $adminId,
            'resolved_at' => now(),
            'admin_note' => $note,
        ]);
        return $record->fresh();
    }

    /** النوبة المفتوحة حالياً للمحطة (إن وُجدت). */
    public function currentOpenShift(FuelStation $station): ?FuelShift
    {
        return FuelShift::where('station_id', $station->id)
            ->where('status', 'open')
            ->with('pumpSummaries')
            ->first();
    }

    /** آخر نوبات (للسجل). */
    public function recentShifts(FuelStation $station, int $limit = 10)
    {
        return FuelShift::where('station_id', $station->id)
            ->with('pumpSummaries.pump')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
