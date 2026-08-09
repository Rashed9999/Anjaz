<?php

namespace App\Services\Fuel;

use App\Models\Fuel\FuelNozzle;
use App\Models\Fuel\FuelTank;
use App\Models\Fuel\FuelTankDip;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelStation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ١ — الخزّاناتُ والمسدسات.
 *
 * **ولمَ المسدسُ كيانٌ لا عمودٌ في جدولٍ وسيط:** لأنّه حاملُ العدّاد
 * وحاملُ الربط بالخزّان. ومضخّةٌ بمسدسَي بنزينٍ وديزل لها عدّادٌ واحدٌ
 * في التصميم القديم — فلا يُعرف كم خرج من أيّ نوع، ولا تُنسب المبيعات
 * إلى خزّاناتها، فتستحيل المصالحة.
 */
class FuelTankService
{
    // ── الخزّانات ──────────────────────────────────────────────────────

    public function addTank(FuelStation $station, array $data): FuelTank
    {
        $number = (int) ($data['tank_number'] ?? 0);

        if ($number <= 0) {
            throw new DomainException('رقم الخزان مطلوب');
        }

        if (FuelTank::where('station_id', $station->id)
            ->where('tank_number', $number)->exists()) {
            throw new DomainException("رقم الخزان {$number} مستعمل في هذه المحطة");
        }

        $product = FuelProduct::find($data['fuel_product_id'] ?? null);

        if (! $product || (int) $product->station_id !== (int) $station->id) {
            throw new DomainException('نوع الوقود غير موجود في هذه المحطة');
        }

        $capacity = (string) ($data['capacity_liters'] ?? '0');

        if (bccomp($capacity, '0', 3) <= 0) {
            throw new DomainException('سعة الخزان يجب أن تكون موجبة');
        }

        return FuelTank::create([
            'station_id' => $station->id,
            'tank_number' => $number,
            'name' => $data['name'] ?? "خزان {$number}",
            'fuel_product_id' => $product->id,
            'capacity_liters' => $capacity,
            // **الرصيدُ الافتتاحيّ قياسٌ لا رقمٌ يُكتب.** فإن أُعطي، سُجِّل
            // قياساً حتّى يكون له أثرٌ ومُدخِلٌ ووقت.
            'book_liters' => '0',
            'min_alert_liters' => $data['min_alert_liters'] ?? '0',
            'is_active' => true,
        ]);
    }

    public function updateTank(FuelTank $tank, array $data): FuelTank
    {
        // **`book_liters` ليست قابلةً للتعديل من هنا.** تتحرّك بالتوريد
        // المرحَّل والبيع والقياس وحدها — وتعديلُها يدويّاً يمحو الفرق
        // الذي وُجد النظامُ لكشفه.
        $tank->update(array_intersect_key($data, array_flip([
            'name', 'capacity_liters', 'min_alert_liters', 'is_active',
        ])));

        return $tank->fresh();
    }

    /**
     * قياسُ خزّان — **الحقيقةُ التي يُقاس عليها الدفتر**.
     *
     * ولا يُصحَّح `book_liters` تلقائيّاً بعد القياس: الفرقُ هو المطلوب،
     * ومساواتُهما تجعل الجردَ يُخرج صفراً دائماً. (القاعدة السادسة.)
     */
    public function recordDip(
        FuelTank $tank, User $actor, string $liters, string $type = 'spot',
        ?int $shiftId = null, ?string $note = null, ?string $temperature = null,
    ): FuelTankDip {
        if (! in_array($type, FuelTankDip::TYPES, true)) {
            throw new DomainException('نوع القياس غير صحيح');
        }

        if (bccomp($liters, '0', 3) < 0) {
            throw new DomainException('القياس لا يكون سالباً');
        }

        if (bccomp($liters, (string) $tank->capacity_liters, 3) > 0) {
            throw new DomainException(sprintf(
                'القياس %s يتجاوز سعة الخزان %s — راجع القراءة',
                $liters, $tank->capacity_liters,
            ));
        }

        return FuelTankDip::create([
            'tank_id' => $tank->id,
            'shift_id' => $shiftId,
            'dip_type' => $type,
            'dip_liters' => $liters,
            'temperature_c' => $temperature,
            'taken_by_user_id' => $actor->id,
            'note' => $note,
            'taken_at' => now(),
        ]);
    }

    /** آخرُ قياسٍ قبل لحظةٍ معيّنة — أساسُ «الافتتاحيّ» في المصالحة. */
    public function dipAt(FuelTank $tank, \DateTimeInterface $moment): ?FuelTankDip
    {
        return FuelTankDip::where('tank_id', $tank->id)
            ->where('taken_at', '<=', $moment)
            ->orderByDesc('taken_at')->orderByDesc('id')
            ->first();
    }

    // ── المسدسات ──────────────────────────────────────────────────────

    public function addNozzle(FuelPump $pump, array $data): FuelNozzle
    {
        $number = (int) ($data['nozzle_number'] ?? 0);

        if ($number <= 0) {
            throw new DomainException('رقم المسدس مطلوب');
        }

        if (FuelNozzle::where('pump_id', $pump->id)
            ->where('nozzle_number', $number)->exists()) {
            throw new DomainException("رقم المسدس {$number} مستعمل في هذه المضخة");
        }

        $product = FuelProduct::find($data['fuel_product_id'] ?? null);

        if (! $product || (int) $product->station_id !== (int) $pump->station_id) {
            throw new DomainException('نوع الوقود غير موجود في هذه المحطة');
        }

        $tank = null;

        if (! empty($data['tank_id'])) {
            $tank = $this->assertTankFits($pump, (int) $data['tank_id'], $product->id);
        }

        return FuelNozzle::create([
            'pump_id' => $pump->id,
            'nozzle_number' => $number,
            'fuel_product_id' => $product->id,
            'tank_id' => $tank?->id,
            'current_meter_reading' => $data['initial_meter_reading'] ?? '0',
            'is_active' => true,
        ]);
    }

    public function linkNozzleToTank(FuelNozzle $nozzle, int $tankId): FuelNozzle
    {
        $tank = $this->assertTankFits(
            $nozzle->pump, $tankId, (int) $nozzle->fuel_product_id,
        );

        $nozzle->update(['tank_id' => $tank->id]);

        return $nozzle->fresh();
    }

    /**
     * الخزّانُ في المحطّة نفسِها **ونوعُ وقوده هو نوعُ المسدس**.
     *
     * وربطُ مسدسِ بنزينٍ بخزّان ديزل يجعل كلَّ بيعةٍ تخصم من الخزّان الخطأ:
     * فيظهر فائضٌ في واحدٍ وعجزٌ في الآخر، **ويبدو الأمرَ سرقةً وهو خطأُ
     * ربطٍ وقع مرّةً**.
     */
    private function assertTankFits(FuelPump $pump, int $tankId, int $productId): FuelTank
    {
        $tank = FuelTank::find($tankId);

        if (! $tank || (int) $tank->station_id !== (int) $pump->station_id) {
            throw new DomainException('الخزان غير موجود في هذه المحطة');
        }

        if ((int) $tank->fuel_product_id !== $productId) {
            throw new DomainException(
                'نوع وقود الخزان يخالف نوع وقود المسدس — الربط سيخصم من خزان خاطئ',
            );
        }

        return $tank;
    }

    /**
     * **يُنقص الخزّانَ دفتريّاً عند البيع.**
     *
     * ولا يُمنع البيعُ عند نفاد الرصيد الدفتريّ: الدفترُ تقديرٌ والقياسُ
     * حَكَم، ومنعُ صرّافٍ من البيع لأنّ رقماً في جدولٍ نفد — والوقودُ في
     * الخزّان — عطلٌ لا حماية. **يُسجَّل ويُنبَّه ولا يُوقَف.**
     */
    public function deductForSale(FuelTank $tank, string $liters): void
    {
        DB::transaction(function () use ($tank, $liters) {
            $locked = FuelTank::where('id', $tank->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $locked->book_liters = bcsub((string) $locked->book_liters, $liters, 3);
            $locked->save();
        });
    }

    /** يزيد الخزّانَ دفتريّاً — **من التوريد المرحَّل وحدَه**. */
    public function addFromDelivery(FuelTank $tank, string $liters): void
    {
        DB::transaction(function () use ($tank, $liters) {
            $locked = FuelTank::where('id', $tank->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $locked->book_liters = bcadd((string) $locked->book_liters, $liters, 3);
            $locked->save();
        });
    }

    /** خزّاناتُ المحطّة بحالتها — لمركز العمليّات. */
    public function overview(FuelStation $station): array
    {
        return FuelTank::where('station_id', $station->id)
            ->with('product:id,name')->orderBy('tank_number')->get()
            ->map(function (FuelTank $t) {
                $lastDip = $this->dipAt($t, now());

                return [
                    'id' => (int) $t->id,
                    'tank_number' => (int) $t->tank_number,
                    'name' => $t->name,
                    'product' => $t->product->name ?? '—',
                    'capacity_liters' => (string) $t->capacity_liters,
                    'book_liters' => (string) $t->book_liters,
                    'fill_percent' => $t->fillPercent(),
                    'is_low' => $t->isLow(),
                    // **«لم يُقَس» ليس صفراً** — يُقال صراحةً.
                    'last_dip_liters' => $lastDip ? (string) $lastDip->dip_liters : null,
                    'last_dip_at' => $lastDip?->taken_at?->toIso8601String(),
                    'dip_vs_book' => $lastDip
                        ? bcsub((string) $lastDip->dip_liters, (string) $t->book_liters, 3)
                        : null,
                ];
            })->all();
    }
}
