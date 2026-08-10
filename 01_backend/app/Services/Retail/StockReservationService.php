<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\Retail\StockReservation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٩ — **الحجزُ حتّى ينجح الدفع**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * الدفعُ بأميال باي غيرُ متزامن: يُعرض رمزٌ وينتظر الكاشيرُ المسح. وكان
 * المخزونُ يُنقص عند تسجيل البيعة:
 *
 * | الحالة | ما كان يقع |
 * |---|---|
 * | الزبونُ لم يدفع وانصرف | **نقص المخزونُ لبيعةٍ لم تقع** |
 * | ولو لم يُنقص أصلاً | بِيعت آخرُ حبّةٍ لزبونين في الدقيقة نفسها |
 *
 * **فالحجزُ هو الجواب**: `on_hand` لا يتغيّر و`reserved` يزيد. البضاعةُ
 * موجودةٌ على الرفّ وغيرُ متاحةٍ لبيعةٍ أخرى.
 *
 * **ولكلّ حجزٍ أجل.** وحجزٌ بلا انتهاءٍ يترك بضاعةً محجوزةً إلى الأبد،
 * فيُقرأ المتجرُ فارغاً وأرففُه ملأى — وهو أسوأ من ألّا يُحجز أصلاً.
 */
class StockReservationService
{
    /** مهلةُ الحجز الافتراضيّة — دقيقةَ عرضِ الرمز ثمّ بعضُ الصبر. */
    public const DEFAULT_TTL_MINUTES = 15;

    public function __construct(private StockService $stock) {}

    /**
     * حجزُ أسطر بيعةٍ معلّقة.
     *
     * @return array<int,StockReservation>
     */
    public function holdForSale(
        MerchantSale $sale, ?MerchantLocation $location = null,
        ?int $ttlMinutes = null, ?User $actor = null,
    ): array {
        $location ??= $this->stock->defaultLocation($sale->merchant_user_id);
        $expires = now()->addMinutes($ttlMinutes ?? self::DEFAULT_TTL_MINUTES);

        return DB::transaction(function () use ($sale, $location, $expires, $actor) {
            $made = [];

            foreach ($sale->lines()->whereNotNull('product_id')->get() as $line) {
                $product = MerchantProduct::where('id', $line->product_id)
                    ->where('merchant_user_id', $sale->merchant_user_id)->first();
                if (! $product || ! $product->track_stock) {
                    continue;
                }

                $qty = (string) $line->quantity;
                if (bccomp($qty, '0', 3) <= 0) {
                    continue;
                }

                $row = ProductStock::where('product_id', $product->id)
                    ->where('location_id', $location->id)->lockForUpdate()->first();

                // **لا صفَّ = لا حجز، ولا يُخترع رصيد.** أوّلُ حركةٍ حقيقيّة
                // تتبنّى الرقمَ القديم؛ والحجزُ ليس حركة.
                if (! $row) {
                    continue;
                }

                $row->update([
                    'reserved' => bcadd((string) $row->reserved, $qty, 3),
                ]);

                $made[] = StockReservation::create([
                    'uuid' => (string) Str::uuid(),
                    'merchant_user_id' => $sale->merchant_user_id,
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'sale_id' => $sale->id,
                    'sale_ulid' => $sale->sale_ulid,
                    'quantity' => $qty,
                    'status' => StockReservation::HELD,
                    'expires_at' => $expires,
                    'actor_user_id' => $actor?->id,
                ]);
            }

            return $made;
        });
    }

    /**
     * نجح الدفع — **الحجزُ يصير حركةَ بيعٍ حقيقيّة**.
     *
     * ويُفكّ الحجزُ ويُخصم الموجود في المعاملة نفسِها: فكٌّ بلا خصمٍ يُتيح
     * البضاعةَ لحظةً وهي مبيعة.
     */
    public function consumeForSale(MerchantSale $sale, ?User $actor = null): int
    {
        return DB::transaction(function () use ($sale, $actor) {
            $held = StockReservation::where('sale_id', $sale->id)
                ->where('status', StockReservation::HELD)
                ->lockForUpdate()->get();

            $done = 0;

            foreach ($held as $res) {
                $product = MerchantProduct::find($res->product_id);
                $location = MerchantLocation::find($res->location_id);
                if (! $product || ! $location) {
                    continue;
                }

                $this->releaseRow($res, 'consumed');

                $line = $sale->lines()->where('product_id', $res->product_id)->first();

                $this->stock->move(
                    product: $product,
                    location: $location,
                    delta: '-' . $res->quantity,
                    reason: 'sale',
                    actor: $actor,
                    unitCost: $line?->unit_cost,
                    sourceType: 'merchant_sale',
                    sourceId: $sale->id,
                    note: 'بيع بعد نجاح الدفع',
                    allowNegative: true,
                );

                $res->update([
                    'status' => StockReservation::CONSUMED,
                    'resolved_at' => now(),
                ]);
                $done++;
            }

            return $done;
        });
    }

    /** أُلغيت البيعةُ أو انتهت مهلتُها — **البضاعةُ تعود متاحة**. */
    public function releaseForSale(MerchantSale $sale, string $reason = 'cancelled'): int
    {
        return DB::transaction(function () use ($sale, $reason) {
            $held = StockReservation::where('sale_id', $sale->id)
                ->where('status', StockReservation::HELD)
                ->lockForUpdate()->get();

            foreach ($held as $res) {
                $this->releaseRow($res, $reason);
                $res->update([
                    'status' => StockReservation::RELEASED,
                    'resolved_at' => now(),
                    'release_reason' => $reason,
                ]);
            }

            return $held->count();
        });
    }

    /**
     * كنسُ الحجوزات المنتهية — **يُنادى من أمرٍ مجدول**.
     *
     * ولولاه لبقي أثرُ كلّ زبونٍ انصرف محجوزاً إلى الأبد.
     */
    public function releaseExpired(int $limit = 500): int
    {
        $expired = StockReservation::where('status', StockReservation::HELD)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->limit($limit)->get();

        $n = 0;
        foreach ($expired as $res) {
            DB::transaction(function () use ($res, &$n) {
                $locked = StockReservation::lockForUpdate()->find($res->id);
                if (! $locked || $locked->status !== StockReservation::HELD) {
                    return;
                }
                $this->releaseRow($locked, 'expired');
                $locked->update([
                    'status' => StockReservation::RELEASED,
                    'resolved_at' => now(),
                    'release_reason' => 'expired',
                ]);
                $n++;
            });
        }

        return $n;
    }

    /** المحجوزُ الفعليّ لصنفٍ في موقع — **محسوباً من الحجوزات لا من العمود**. */
    public function computedReserved(int $productId, int $locationId): string
    {
        return (string) (StockReservation::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('status', StockReservation::HELD)
            ->sum('quantity') ?? '0');
    }

    // ── داخليّ ────────────────────────────────────────────────────────

    /** إنقاصُ `reserved` — **ولا ينزل تحت الصفر مهما تكرّر النداء**. */
    private function releaseRow(StockReservation $res, string $why): void
    {
        $row = ProductStock::where('product_id', $res->product_id)
            ->where('location_id', $res->location_id)->lockForUpdate()->first();

        if (! $row) {
            return;
        }

        $next = bcsub((string) $row->reserved, (string) $res->quantity, 3);
        if (bccomp($next, '0', 3) < 0) {
            // محجوزٌ سالبٌ يجعل المتاح أكبر من الموجود — **ويبيع الوهم**.
            $next = '0';
        }

        $row->update(['reserved' => $next]);

        unset($why);
    }
}
