<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\StockTransfer;
use App\Models\Retail\StockTransferItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٤ — **التحويلُ بمراحله**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحركةُ الوحيدةُ عند الإرسال، والثانيةُ عند الاستلام** — وبينهما
 * البضاعةُ في الطريق: خرجت من المصدر ولم تدخل الوجهةَ بعد.
 *
 * فمن نقص وزاد في لحظةٍ أظهر في الوجهة مخزوناً لم يصل، فبِيع ما ليس في
 * الرفّ. **ومن نقص ولم يُسجّل الطريق أضاع البضاعةَ بين موقعين.**
 *
 * والمستلَمُ قد يقلّ عن المرسَل — **فيُسجَّل الفرقُ باسمه** ولا يُساوى
 * الرقمان بالقوّة: كسرٌ أو نقصٌ أو سرقةٌ في الطريق، ولكلٍّ علاجٌ مختلف.
 */
class StockTransferService
{
    public function __construct(private StockService $stock) {}

    /** طلبُ تحويل — **مسوّدةٌ لا تمسّ مخزوناً**. */
    public function request(User $merchant, array $data): StockTransfer
    {
        $from = $this->location($merchant, $data['from_location_id'] ?? null);
        $to = $this->location($merchant, $data['to_location_id'] ?? null);

        if ($from->id === $to->id) {
            throw new DomainException('لا يُحوَّل الموقع إلى نفسه');
        }

        $items = $data['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new DomainException('لا أصناف في التحويل');
        }

        return DB::transaction(function () use ($merchant, $from, $to, $items, $data) {
            $transfer = StockTransfer::create([
                'uuid' => (string) Str::uuid(),
                'merchant_user_id' => $merchant->id,
                'code' => $this->code($merchant->id),
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => StockTransfer::REQUESTED,
                'requested_by' => $data['actor_id'] ?? $merchant->id,
                'requested_at' => now(),
                'note' => $data['note'] ?? null,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            foreach ($items as $raw) {
                $pid = (int) ($raw['product_id'] ?? 0);
                $qty = (string) ($raw['quantity'] ?? 0);
                if ($pid <= 0 || bccomp($qty, '0', 3) <= 0) {
                    continue;
                }

                $product = MerchantProduct::where('id', $pid)
                    ->where('merchant_user_id', $merchant->id)->first();
                if (! $product) {
                    throw new DomainException("الصنف #{$pid} غير موجود");
                }
                if ($product->is_variant_parent) {
                    throw new DomainException("«{$product->name}» صنفٌ أب — يُحوَّل متغيّرُه");
                }

                StockTransferItem::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'name' => $product->displayName(),
                    'requested_quantity' => $qty,
                    'unit_cost' => $product->cost_price,
                ]);
            }

            if ($transfer->items()->count() === 0) {
                throw new DomainException('لا أصناف صالحة في التحويل');
            }

            return $transfer->fresh('items');
        });
    }

    public function approve(User $actor, StockTransfer $transfer): StockTransfer
    {
        $this->assertFlow($transfer, StockTransfer::APPROVED);

        // **الطالبُ ليس المعتمِد** — وإلّا صار الاعتمادُ ختماً على نفسه.
        if ((int) $transfer->requested_by === (int) $actor->id
            && (int) $transfer->merchant_user_id !== (int) $actor->id) {
            throw new DomainException('من طلب التحويل لا يعتمده');
        }

        $transfer->update([
            'status' => StockTransfer::APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        return $transfer->fresh();
    }

    /**
     * الإرسال — **هنا وحدَها تخرج البضاعةُ من المصدر**.
     *
     * @param  array  $shipped  [item_id => quantity] — والمرسَلُ قد يقلّ
     *                          عن المطلوب: لا يوجد في المستودع ما طُلب.
     */
    public function ship(User $actor, StockTransfer $transfer, array $shipped = []): StockTransfer
    {
        $this->assertFlow($transfer, StockTransfer::SHIPPED);

        return DB::transaction(function () use ($actor, $transfer, $shipped) {
            $from = MerchantLocation::findOrFail($transfer->from_location_id);
            $any = false;

            foreach ($transfer->items()->get() as $item) {
                $qty = (string) ($shipped[$item->id] ?? $item->requested_quantity);
                if (bccomp($qty, '0', 3) <= 0) {
                    $item->update(['shipped_quantity' => '0']);
                    continue;
                }
                if (bccomp($qty, (string) $item->requested_quantity, 3) > 0) {
                    throw new DomainException(
                        "المرسَل من «{$item->name}» أكثر من المطلوب");
                }

                $product = MerchantProduct::findOrFail($item->product_id);

                // **ولا يُسمح بالسالب هنا**: البيعُ يُسمح لأنّ البضاعة خرجت
                // من الرفّ فعلاً، والتحويلُ قرارٌ يُتّخذ قبل التحميل —
                // فمن حوّل ما لا يملك اخترع بضاعةً في الوجهة.
                $this->stock->move(
                    product: $product,
                    location: $from,
                    delta: '-' . $qty,
                    reason: 'transfer_out',
                    actor: $actor,
                    unitCost: $item->unit_cost,
                    sourceType: 'stock_transfer',
                    sourceId: $transfer->id,
                    note: 'تحويل ' . $transfer->code,
                );

                $item->update(['shipped_quantity' => $qty]);
                $any = true;
            }

            if (! $any) {
                throw new DomainException('لا كمّيّة أُرسلت — التحويل لا يُشحن فارغاً');
            }

            $transfer->update([
                'status' => StockTransfer::SHIPPED,
                'shipped_by' => $actor->id,
                'shipped_at' => now(),
            ]);

            return $transfer->fresh('items');
        });
    }

    /**
     * الاستلام — **والفرقُ يُسجَّل ولا يُساوى**.
     *
     * @param  array  $received  [item_id => ['quantity' => .., 'reason' => ..]]
     */
    public function receive(User $actor, StockTransfer $transfer, array $received = []): StockTransfer
    {
        $this->assertFlow($transfer, StockTransfer::RECEIVED);

        return DB::transaction(function () use ($actor, $transfer, $received) {
            $to = MerchantLocation::findOrFail($transfer->to_location_id);
            $shortAny = false;

            foreach ($transfer->items()->get() as $item) {
                $shipped = (string) ($item->shipped_quantity ?? '0');
                $row = $received[$item->id] ?? [];
                $qty = array_key_exists('quantity', $row) ? (string) $row['quantity'] : $shipped;

                if (bccomp($qty, '0', 3) < 0) {
                    throw new DomainException('كمّيّة مستلَمة سالبة');
                }
                if (bccomp($qty, $shipped, 3) > 0) {
                    throw new DomainException(
                        "المستلَم من «{$item->name}» أكثر من المرسَل — البضاعة لا تتكاثر في الطريق");
                }

                if (bccomp($qty, '0', 3) > 0) {
                    $this->stock->move(
                        product: MerchantProduct::findOrFail($item->product_id),
                        location: $to,
                        delta: $qty,
                        reason: 'transfer_in',
                        actor: $actor,
                        unitCost: $item->unit_cost,
                        sourceType: 'stock_transfer',
                        sourceId: $transfer->id,
                        note: 'استلام ' . $transfer->code,
                    );
                }

                $short = bccomp($qty, $shipped, 3) < 0;
                if ($short) {
                    $shortAny = true;
                }

                $item->update([
                    'received_quantity' => $qty,
                    // **الفرقُ بلا سببٍ رقمٌ يُقفَل ولا يُصلَح.**
                    'variance_reason' => $short
                        ? (trim((string) ($row['reason'] ?? '')) ?: 'فرق بلا سبب مذكور')
                        : null,
                ]);
            }

            $transfer->update([
                'status' => $shortAny
                    ? StockTransfer::PARTIALLY_RECEIVED
                    : StockTransfer::RECEIVED,
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);

            return $transfer->fresh('items');
        });
    }

    /**
     * الإلغاء — **ولا يُلغى ما شُحن**.
     *
     * فبضاعةٌ في الطريق لا تعود بضغطة: إمّا تصل فتُستلم، وإمّا تُستلم
     * صفراً بسببٍ مكتوب. وإلغاءٌ بعد الشحن يترك مخزوناً خرج ولم يعد.
     */
    public function cancel(User $actor, StockTransfer $transfer, string $reason): StockTransfer
    {
        if (! $transfer->canMoveTo(StockTransfer::CANCELLED)) {
            throw new DomainException(
                'لا يُلغى تحويلٌ حالتُه «' . $transfer->statusAr() . '»');
        }

        $transfer->update([
            'status' => StockTransfer::CANCELLED,
            'cancel_reason' => trim($reason) ?: 'بلا سبب',
        ]);

        return $transfer->fresh();
    }

    /** **البضاعةُ في الطريق** — حالةٌ تُقال في التقارير لا تُخفى. */
    public function inTransit(User $merchant): array
    {
        return StockTransfer::where('merchant_user_id', $merchant->id)
            ->where('status', StockTransfer::SHIPPED)
            ->with(['items', 'fromLocation:id,name', 'toLocation:id,name'])
            ->get()->map(fn (StockTransfer $t) => [
                'code' => $t->code,
                'uuid' => $t->uuid,
                'from' => $t->fromLocation->name ?? '—',
                'to' => $t->toLocation->name ?? '—',
                'shipped_at' => $t->shipped_at?->toIso8601String(),
                'days_in_transit' => $t->shipped_at ? $t->shipped_at->diffInDays(now()) : null,
                'lines' => $t->items->count(),
                'quantity' => (string) $t->items->sum(fn ($i) => (float) $i->shipped_quantity),
            ])->all();
    }

    // ── داخليّ ────────────────────────────────────────────────────────

    private function assertFlow(StockTransfer $transfer, string $to): void
    {
        if (! $transfer->canMoveTo($to)) {
            throw new DomainException(
                'انتقال غير مسموح: التحويل «' . $transfer->statusAr() . '»');
        }
    }

    private function location(User $merchant, $id): MerchantLocation
    {
        $loc = MerchantLocation::where('id', (int) $id)
            ->where('merchant_user_id', $merchant->id)->first();
        if (! $loc) {
            throw new DomainException('الموقع غير موجود');
        }
        if (! $loc->is_active) {
            throw new DomainException("الموقع «{$loc->name}» معطَّل");
        }

        return $loc;
    }

    private function code(int $merchantId): string
    {
        $n = StockTransfer::where('merchant_user_id', $merchantId)->count() + 1;

        return 'TR' . now()->format('ymd') . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
