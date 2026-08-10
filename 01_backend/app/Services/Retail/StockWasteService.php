<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\StockWaste;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٦ — **الهالكُ باعتماد**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * ومن يُتلف بلا مُعتمِدٍ يُخرج بضاعةً بلا أثر: «انتهت صلاحيتها» جملةٌ
 * تُخرج من المستودع ما شاء صاحبُها، **والجردُ بعدها لا يجد فرقاً** لأنّ
 * النظام صدّق الإتلاف.
 *
 * **ولا يُخصم المخزون إلّا عند الاعتماد** — والتسجيلُ نيّةٌ لا حركة.
 */
class StockWasteService
{
    public function __construct(private StockService $stock) {}

    public function record(User $merchant, array $data, ?int $actorId = null): StockWaste
    {
        $product = MerchantProduct::where('id', (int) ($data['product_id'] ?? 0))
            ->where('merchant_user_id', $merchant->id)->first();
        if (! $product) {
            throw new DomainException('الصنف غير موجود');
        }
        if ($product->is_variant_parent) {
            throw new DomainException('الصنف الأب لا يُتلف — يُتلف متغيّرُه');
        }

        $qty = (string) ($data['quantity'] ?? 0);
        if (bccomp($qty, '0', 3) <= 0) {
            throw new DomainException('كمّيّة الهالك يجب أن تكون موجبة');
        }

        $reason = (string) ($data['reason'] ?? '');
        if (! in_array($reason, StockWaste::REASONS, true)) {
            throw new DomainException('سبب الهالك مطلوب ومن القائمة');
        }

        $location = ! empty($data['location_id'])
            ? MerchantLocation::where('id', $data['location_id'])
                ->where('merchant_user_id', $merchant->id)->first()
            : null;
        $location ??= $this->stock->defaultLocation($merchant->id);

        $cost = $product->cost_price;
        $unitCost = ($cost !== null && bccomp((string) $cost, '0', 4) > 0)
            ? (string) $cost : null;

        return StockWaste::create([
            'uuid' => (string) Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'name' => $product->displayName(),
            'quantity' => $qty,
            'reason' => $reason,
            // **التكلفةُ الملتقَطة لحظةَ التسجيل** — لا قراءةُ الغد.
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost !== null ? bcmul($qty, $unitCost, 4) : null,
            'status' => 'pending',
            'recorded_by' => $actorId ?? $merchant->id,
            'note' => $data['note'] ?? null,
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);
    }

    public function approve(User $actor, StockWaste $waste): StockWaste
    {
        if ($waste->status !== 'pending') {
            throw new DomainException('هذا الهالك حُسم مسبقاً');
        }
        if ((int) $waste->recorded_by === (int) $actor->id
            && (int) $waste->merchant_user_id !== (int) $actor->id) {
            throw new DomainException('من سجّل الهالك لا يعتمده');
        }

        return DB::transaction(function () use ($actor, $waste) {
            $this->stock->move(
                product: MerchantProduct::findOrFail($waste->product_id),
                location: MerchantLocation::findOrFail($waste->location_id),
                delta: '-' . $waste->quantity,
                reason: 'waste',
                actor: $actor,
                unitCost: $waste->unit_cost,
                sourceType: 'stock_waste',
                sourceId: $waste->id,
                note: 'هالك · ' . $waste->reasonAr(),
                // **يُسمح بالسالب**: البضاعةُ أُتلفت فعلاً، ورفضُ تسجيلها
                // لأنّ اللقطة تقول صفراً يمحو الدليلَ الذي يكشف الانحراف.
                allowNegative: true,
            );

            $waste->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            return $waste->fresh();
        });
    }

    public function reject(User $actor, StockWaste $waste, string $reason): StockWaste
    {
        if ($waste->status !== 'pending') {
            throw new DomainException('هذا الهالك حُسم مسبقاً');
        }

        $waste->update([
            'status' => 'rejected',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'reject_reason' => trim($reason) ?: 'بلا سبب',
        ]);

        return $waste->fresh();
    }

    /** تقريرُ الهالك — **بالسبب والقيمة**، وهو ما يُقرأ منه القرار. */
    public function report(User $merchant, int $days = 30): array
    {
        $from = now()->subDays(max(1, min(365, $days)))->startOfDay();

        $rows = StockWaste::where('merchant_user_id', $merchant->id)
            ->where('created_at', '>=', $from)->get();

        $byReason = [];
        $totalCost = '0';
        $unknownCostLines = 0;

        foreach ($rows->where('status', 'approved') as $w) {
            $byReason[$w->reason]['reason_ar'] = $w->reasonAr();
            $byReason[$w->reason]['quantity'] = bcadd(
                (string) ($byReason[$w->reason]['quantity'] ?? '0'), (string) $w->quantity, 3);

            if ($w->total_cost === null) {
                $unknownCostLines++;
                continue;
            }
            $byReason[$w->reason]['cost'] = bcadd(
                (string) ($byReason[$w->reason]['cost'] ?? '0'), (string) $w->total_cost, 4);
            $totalCost = bcadd($totalCost, (string) $w->total_cost, 4);
        }

        return [
            'days' => $days,
            'pending_count' => $rows->where('status', 'pending')->count(),
            'approved_count' => $rows->where('status', 'approved')->count(),
            'rejected_count' => $rows->where('status', 'rejected')->count(),
            'total_cost' => $totalCost,
            // **«غير معروف» يُقال عدداً** ولا يُطوى في المجموع.
            'unknown_cost_lines' => $unknownCostLines,
            'by_reason' => collect($byReason)->map(fn ($v, $k) => [
                'reason' => $k,
                'reason_ar' => $v['reason_ar'],
                'quantity' => $v['quantity'] ?? '0',
                'cost' => $v['cost'] ?? '0',
            ])->values()->all(),
        ];
    }
}
