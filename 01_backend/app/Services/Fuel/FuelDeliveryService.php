<?php

namespace App\Services\Fuel;

use App\Models\Fuel\FuelDelivery;
use App\Models\Fuel\FuelSupplier;
use App\Models\Fuel\FuelTank;
use App\Models\FuelStation;
use App\Models\User;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٢ — التوريدات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ أحوالٍ والمخزونُ لا يرتفع إلّا بالثالث:**
 *
 *     received → verified → posted
 *
 * ورفعُ المخزون عند الإدخال يجعل ورقةً مكتوبةً بالخطأ ترفع الرصيدَ
 * الدفتريَّ فوراً، **فتظهر مصالحةُ الليلة عجزاً لا وجود له** — ويُفتح
 * تحقيقٌ في سرقةٍ سببُها رقمٌ زائد.
 *
 * **والتحقّق يقارن الورقةَ بالخزّان:** قياسٌ قبل التفريغ وقياسٌ بعده،
 * والفرقُ يجب أن يطابق ما كُتب في الفاتورة. فناقلٌ يسلّم أقلَّ مِمّا
 * كُتب يُكتشف هنا لا بعد شهر.
 */
class FuelDeliveryService
{
    public function __construct(
        private readonly FuelTankService $tanks,
        private readonly AuditService $audit,
    ) {
    }

    /** نسبةُ التفاوت المقبولة بين الورقة والقياس — ٠٫٥٪ من الكميّة. */
    private const DIP_TOLERANCE_PERCENT = '0.5';

    // ── الموردون ──────────────────────────────────────────────────────

    public function addSupplier(User $merchant, array $data): FuelSupplier
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new DomainException('اسم المورد مطلوب');
        }

        return FuelSupplier::create([
            'merchant_user_id' => $merchant->id,
            'name' => $name,
            'phone' => $data['phone'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
        ]);
    }

    /** @return array<int,FuelSupplier> */
    public function suppliers(User $merchant): array
    {
        return FuelSupplier::where('merchant_user_id', $merchant->id)
            ->orderBy('name')->get()->all();
    }

    // ── التوريد ───────────────────────────────────────────────────────

    /** ① وصلت الشاحنة — تُسجَّل الورقة ولا يتحرّك المخزون. */
    public function receive(
        FuelStation $station, FuelTank $tank, User $actor, array $data,
    ): FuelDelivery {
        if ((int) $tank->station_id !== (int) $station->id) {
            throw new DomainException('الخزان لا يخصّ هذه المحطة');
        }

        $qty = (string) ($data['quantity_liters'] ?? '0');

        if (bccomp($qty, '0', 3) <= 0) {
            throw new DomainException('كمية التوريد يجب أن تكون موجبة');
        }

        // **سعةُ الخزّان حدٌّ ماديّ لا رقمٌ استرشاديّ.** توريدٌ يفيض عنها
        // إمّا خطأُ إدخالٍ وإمّا وقودٌ سيُسكب.
        $after = bcadd((string) $tank->book_liters, $qty, 3);

        if (bccomp($after, (string) $tank->capacity_liters, 3) > 0) {
            throw new DomainException(sprintf(
                'الكمية %s تتجاوز سعة الخزان: فيه %s والسعة %s',
                $qty, $tank->book_liters, $tank->capacity_liters,
            ));
        }

        $supplierId = $data['supplier_id'] ?? null;

        if ($supplierId) {
            $s = FuelSupplier::find($supplierId);

            if (! $s || (int) $s->merchant_user_id !== (int) $station->merchant_user_id) {
                throw new DomainException('المورد غير موجود');
            }
        }

        $unitCost = (string) ($data['unit_cost'] ?? '0');

        return FuelDelivery::create([
            'delivery_ulid' => (string) Str::ulid(),
            'station_id' => $station->id,
            'tank_id' => $tank->id,
            'fuel_product_id' => $tank->fuel_product_id,
            'supplier_id' => $supplierId,
            'delivery_number' => $data['delivery_number'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'quantity_liters' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => bcmul($qty, $unitCost, 4),
            'dip_before_liters' => $data['dip_before_liters'] ?? null,
            'status' => 'received',
            'received_by_user_id' => $actor->id,
            'received_at' => now(),
            'note' => $data['note'] ?? null,
            'zone_code' => $station->zone_code ?? 'SOUTH',
        ]);
    }

    /**
     * ② التحقّق — **يقارن الورقةَ بالخزّان**، والمخزونُ ما زال ساكناً.
     */
    public function verify(
        FuelDelivery $delivery, User $actor,
        ?string $dipBefore = null, ?string $dipAfter = null,
    ): FuelDelivery {
        if ($delivery->status !== 'received') {
            throw new DomainException('لا يُتحقَّق إلا من توريد بحالة «مستلم»');
        }

        $before = $dipBefore ?? (string) $delivery->dip_before_liters;
        $after = $dipAfter ?? (string) $delivery->dip_after_liters;

        if ($before === '' || $after === '' || $before === null || $after === null) {
            throw new DomainException(
                'التحقق يحتاج قياس الخزان قبل التفريغ وبعده — بلا قياسٍ لا تحقّق',
            );
        }

        $measured = bcsub($after, $before, 3);
        $gap = bcsub($measured, (string) $delivery->quantity_liters, 3);
        $abs = ltrim($gap, '-');

        $tolerance = bcdiv(
            bcmul((string) $delivery->quantity_liters, self::DIP_TOLERANCE_PERCENT, 5),
            '100', 3,
        );

        if (bccomp($abs, $tolerance, 3) > 0) {
            throw new DomainException(sprintf(
                "القياس يخالف الفاتورة: مقيس %s وفاتورة %s (فرق %s، والمسموح %s).\n"
                . 'راجع القراءتين أو صحّح الفاتورة — ولا يُرحَّل توريدٌ غير مطابق.',
                $measured, $delivery->quantity_liters, $gap, $tolerance,
            ));
        }

        $delivery->update([
            'dip_before_liters' => $before,
            'dip_after_liters' => $after,
            'status' => 'verified',
            'verified_by_user_id' => $actor->id,
            'verified_at' => now(),
        ]);

        return $delivery->fresh();
    }

    /**
     * ③ الترحيل — **وهنا وحدَه يرتفع المخزون**.
     *
     * ويُسجَّل قياسٌ من نوع `delivery` ليكون للحركة أثرٌ في خطّ القياسات،
     * فتقرأ المصالحةُ ما بعد التفريغ لا ما قبله.
     */
    public function post(FuelDelivery $delivery, User $actor): FuelDelivery
    {
        if ($delivery->status !== 'verified') {
            throw new DomainException(
                'لا يُرحَّل إلا توريدٌ مُتحقَّقٌ منه — التحقق أوّلاً',
            );
        }

        return DB::transaction(function () use ($delivery, $actor) {
            $tank = FuelTank::findOrFail($delivery->tank_id);

            $this->tanks->addFromDelivery($tank, (string) $delivery->quantity_liters);

            if ($delivery->dip_after_liters !== null) {
                $this->tanks->recordDip(
                    $tank, $actor, (string) $delivery->dip_after_liters,
                    'delivery', null,
                    "توريد {$delivery->delivery_ulid}",
                );
            }

            $delivery->update([
                'status' => 'posted',
                'posted_by_user_id' => $actor->id,
                'posted_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'merchant',
                'actor_user_id' => $actor->id,
                'subject_type' => 'fuel_delivery',
                'subject_id' => (string) $delivery->id,
                'action' => 'FUEL_DELIVERY_POSTED',
                'decision_code' => 'DELIVERY_POST',
                'severity' => 'critical',
                'context' => [
                    'tank_id' => $delivery->tank_id,
                    'liters' => (string) $delivery->quantity_liters,
                    'book_after' => (string) $tank->fresh()->book_liters,
                ],
            ]);

            return $delivery->fresh();
        });
    }

    public function reject(FuelDelivery $delivery, User $actor, string $reason): FuelDelivery
    {
        if ($delivery->status === 'posted') {
            // **المرحَّلُ لا يُرفض** — يُصحَّح بتوريدٍ عكسيٍّ موثَّق، كما
            // يُصحَّح القيدُ بقيدٍ لا بمحوٍ.
            throw new DomainException(
                'التوريد مُرحَّل ولا يُرفض — صحّحه بتوريد عكسيّ موثّق',
            );
        }

        $delivery->update([
            'status' => 'rejected',
            'note' => trim(((string) $delivery->note) . "\nرفض: " . $reason),
        ]);

        return $delivery->fresh();
    }

    /**
     * لتراتٌ رُحِّلت إلى خزّانٍ في مدّة — **مدخلُ المصالحة**.
     */
    public function postedLitersBetween(
        FuelTank $tank, \DateTimeInterface $from, \DateTimeInterface $to,
    ): string {
        $sum = FuelDelivery::where('tank_id', $tank->id)
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$from, $to])
            ->get()->reduce(
                static fn ($c, $d) => bcadd($c, (string) $d->quantity_liters, 3), '0');

        return (string) $sum;
    }
}
