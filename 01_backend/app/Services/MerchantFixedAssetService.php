<?php

namespace App\Services;

use App\Models\MerchantAssetDepreciation;
use App\Models\MerchantFixedAsset;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-MERCHANT-ASSETS-001 — سجل الأصول الثابتة وإهلاكها.
 *
 * هذه خدمة "sub-ledger" تشغيلية للأصل نفسه. لا تغيّر محفظة أميال ولا
 * درج POS، ولا تدّعي أن التسجيل اليدوي عملية شراء. أصلٌ قادم من أمر شراء
 * يرتبط بمستنده الأصلي، والأصل الافتتاحي يوصف صراحةً كرصيد تاريخي.
 *
 * الإهلاك append-only: كل شهر صف مستقل؛ لا نعيد كتابة تكلفة الأصل ولا
 * قيماً تاريخية عندما يُفتح التقرير مرة أخرى.
 */
class MerchantFixedAssetService
{
    public function __construct(private readonly AuditService $audit) {}

    /** تسجيل أصل موجود قبل تشغيل السجل — لا يحرك مالاً. */
    public function createOpening(User $merchant, array $data): MerchantFixedAsset
    {
        $cost = MoneyService::normalize((string) $data['acquisition_cost']);
        $salvage = MoneyService::normalize((string) ($data['salvage_value'] ?? '0'));
        $life = (int) $data['useful_life_months'];

        $this->assertAssetNumbers($cost, $salvage, $life);

        $asset = MerchantFixedAsset::create([
            'asset_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'name' => trim((string) $data['name']),
            'category' => $data['category'] ?? 'other',
            'quantity' => (string) ($data['quantity'] ?? '1'),
            'acquisition_cost' => $cost,
            'salvage_value' => $salvage,
            'useful_life_months' => $life,
            'depreciation_method' => 'straight_line',
            'acquired_on' => $data['acquired_on'],
            'depreciation_starts_on' => $data['depreciation_starts_on'] ?? $data['acquired_on'],
            'status' => 'active',
            'created_by' => $merchant->id,
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);

        $this->audit->record([
            'actor_type' => 'merchant',
            'actor_user_id' => $merchant->id,
            'subject_type' => 'merchant_fixed_asset',
            'subject_id' => $asset->asset_ulid,
            'action' => 'FIXED_ASSET_OPENING_REGISTERED',
            'decision_code' => 'REGISTERED',
            'severity' => 'info',
            'context' => [
                'name' => $asset->name,
                'cost' => $cost,
                'source' => 'opening_register',
            ],
        ]);

        return $asset;
    }

    /**
     * يُنشئ أصلاً من **الكمية المستلمة فعلاً** لا من أمر الشراء.
     * كل استلام جزئي يصبح دفعة أصل مستقلة، فلا يتغير تاريخ الدفعة الأولى.
     */
    public function registerPurchaseReceipt(
        User $merchant,
        PurchaseOrder $order,
        PurchaseOrderItem $item,
        string $receivedQuantity,
    ): ?MerchantFixedAsset {
        if ((string) ($item->item_type ?? 'inventory') !== 'fixed_asset') {
            return null;
        }

        $life = (int) ($item->useful_life_months ?? 0);
        $cost = MoneyService::normalize(
            bcmul($receivedQuantity, (string) $item->unit_cost, 4)
        );
        $salvage = MoneyService::normalize(
            bcmul($receivedQuantity, (string) ($item->salvage_value ?? '0'), 4)
        );

        $this->assertAssetNumbers($cost, $salvage, $life);

        $asset = MerchantFixedAsset::create([
            'asset_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'purchase_order_item_id' => $item->id,
            'name' => $item->name,
            'category' => $item->asset_category ?: 'other',
            'quantity' => $receivedQuantity,
            'acquisition_cost' => $cost,
            'salvage_value' => $salvage,
            'useful_life_months' => $life,
            'depreciation_method' => 'straight_line',
            'acquired_on' => now()->toDateString(),
            'depreciation_starts_on' => now()->toDateString(),
            'status' => 'active',
            'created_by' => $merchant->id,
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);

        $this->audit->record([
            'actor_type' => 'merchant',
            'actor_user_id' => $merchant->id,
            'subject_type' => 'merchant_fixed_asset',
            'subject_id' => $asset->asset_ulid,
            'action' => 'FIXED_ASSET_ACQUIRED_FROM_PURCHASE',
            'decision_code' => 'ACQUIRED',
            'severity' => 'info',
            'context' => [
                'purchase_order' => $order->po_number,
                'purchase_order_item_id' => $item->id,
                'quantity' => $receivedQuantity,
                'cost' => $cost,
            ],
        ]);

        return $asset;
    }

    /** @return array{assets:array<int,array>,totals:array<string,mixed>} */
    public function index(User $merchant): array
    {
        $assets = MerchantFixedAsset::where('merchant_user_id', $merchant->id)
            ->withSum('depreciations as accumulated_depreciation', 'amount')
            ->orderByDesc('id')->get();

        $rows = $assets->map(fn (MerchantFixedAsset $a) => $this->toArray($a))->all();

        $cost = $accumulated = $book = '0';
        foreach ($rows as $row) {
            $cost = MoneyService::add($cost, $row['acquisition_cost']);
            $accumulated = MoneyService::add($accumulated, $row['accumulated_depreciation']);
            $book = MoneyService::add($book, $row['book_value']);
        }

        return [
            'assets' => $rows,
            'totals' => [
                'acquisition_cost' => MoneyService::normalize($cost),
                'accumulated_depreciation' => MoneyService::normalize($accumulated),
                'book_value' => MoneyService::normalize($book),
                'active_count' => $assets->where('status', 'active')->count(),
                'disposed_count' => $assets->where('status', 'disposed')->count(),
            ],
        ];
    }

    public function show(User $merchant, int $id): array
    {
        $asset = MerchantFixedAsset::where('merchant_user_id', $merchant->id)
            ->with(['supplier:id,name', 'purchaseOrder:id,po_number,document_ulid', 'depreciations'])
            ->findOrFail($id);

        return [
            'asset' => $this->toArray($asset),
            'depreciations' => $asset->depreciations->map(fn ($d) => [
                'entry_ulid' => $d->entry_ulid,
                'period' => $d->period,
                'amount' => (string) $d->amount,
                'accumulated_after' => (string) $d->accumulated_after,
                'book_value_after' => (string) $d->book_value_after,
                'posted_at' => $d->posted_at?->toIso8601String(),
            ])->all(),
            'supplier' => $asset->supplier,
            'purchase_order' => $asset->purchaseOrder,
        ];
    }

    /**
     * يثبت الإهلاك حتى نهاية شهر محدد. إعادة الطلب آمنة: unique(asset,period)
     * + lock يمنعان قيد الشهر مرتين.
     */
    public function postDepreciationThrough(User $merchant, Carbon $through): array
    {
        $through = $through->copy()->endOfMonth();
        if ($through->gt(now()->endOfMonth())) {
            throw new RuntimeException('لا يمكن إثبات إهلاك شهر مستقبلي');
        }

        $posted = 0;
        $total = '0';

        $assetIds = MerchantFixedAsset::where('merchant_user_id', $merchant->id)
            ->whereIn('status', ['active', 'disposed'])->pluck('id');

        foreach ($assetIds as $assetId) {
            $result = DB::transaction(function () use ($merchant, $through, $assetId) {
                $asset = MerchantFixedAsset::where('id', $assetId)
                    ->where('merchant_user_id', $merchant->id)
                    ->lockForUpdate()->firstOrFail();

                $end = $through->copy();
                if ($asset->disposed_on && $asset->disposed_on->lt($end)) {
                    $end = $asset->disposed_on->copy()->endOfMonth();
                }

                $start = $asset->depreciation_starts_on->copy()->startOfMonth();
                if ($end->lt($start)) {
                    return ['count' => 0, 'total' => '0'];
                }

                $depreciable = bcsub(
                    (string) $asset->acquisition_cost,
                    (string) $asset->salvage_value,
                    4
                );
                if (bccomp($depreciable, '0', 4) <= 0) {
                    return ['count' => 0, 'total' => '0'];
                }

                $monthly = bcdiv($depreciable, (string) $asset->useful_life_months, 4);
                $existing = MerchantAssetDepreciation::where('asset_id', $asset->id)
                    ->orderBy('period')->get()->keyBy('period');
                $accumulated = '0';
                foreach ($existing as $row) {
                    $accumulated = bcadd($accumulated, (string) $row->amount, 4);
                }

                $count = 0;
                $sum = '0';
                for ($i = 0; $i < $asset->useful_life_months; $i++) {
                    $periodDate = $start->copy()->addMonthsNoOverflow($i);
                    if ($periodDate->gt($end)) {
                        break;
                    }
                    $period = $periodDate->format('Y-m');
                    if ($existing->has($period)) {
                        continue;
                    }

                    $remaining = bcsub($depreciable, $accumulated, 4);
                    if (bccomp($remaining, '0', 4) <= 0) {
                        break;
                    }
                    $amount = $i === $asset->useful_life_months - 1
                        ? $remaining
                        : (bccomp($monthly, $remaining, 4) > 0 ? $remaining : $monthly);

                    $accumulated = bcadd($accumulated, $amount, 4);
                    $book = bcsub((string) $asset->acquisition_cost, $accumulated, 4);
                    if (bccomp($book, (string) $asset->salvage_value, 4) < 0) {
                        $book = (string) $asset->salvage_value;
                    }

                    MerchantAssetDepreciation::create([
                        'entry_ulid' => (string) Str::ulid(),
                        'merchant_user_id' => $merchant->id,
                        'asset_id' => $asset->id,
                        'period' => $period,
                        'amount' => $amount,
                        'accumulated_after' => $accumulated,
                        'book_value_after' => $book,
                        'posted_by' => $merchant->id,
                        'posted_at' => now(),
                    ]);
                    $count++;
                    $sum = bcadd($sum, $amount, 4);
                }

                return ['count' => $count, 'total' => $sum];
            }, 3);

            $posted += $result['count'];
            $total = bcadd($total, $result['total'], 4);
        }

        if ($posted > 0) {
            $this->audit->record([
                'actor_type' => 'merchant',
                'actor_user_id' => $merchant->id,
                'subject_type' => 'merchant_fixed_asset',
                'subject_id' => 'portfolio',
                'action' => 'FIXED_ASSET_DEPRECIATION_POSTED',
                'decision_code' => 'POSTED',
                'severity' => 'info',
                'context' => [
                    'through' => $through->format('Y-m'),
                    'entries' => $posted,
                    'amount' => MoneyService::normalize($total),
                ],
            ]);
        }

        return [
            'through' => $through->format('Y-m'),
            'entries_posted' => $posted,
            'amount_posted' => MoneyService::normalize($total),
        ];
    }

    public function dispose(
        User $merchant,
        int $id,
        Carbon $date,
        ?string $proceeds,
        string $reason,
    ): MerchantFixedAsset {
        // إثبات الإهلاك حتى شهر الاستبعاد قبل تجميد الأصل.
        $this->postDepreciationThrough($merchant, $date);

        $asset = DB::transaction(function () use ($merchant, $id, $date, $proceeds, $reason) {
            $asset = MerchantFixedAsset::where('id', $id)
                ->where('merchant_user_id', $merchant->id)
                ->lockForUpdate()->firstOrFail();

            if ($asset->status === 'disposed') {
                throw new RuntimeException('الأصل مستبعد بالفعل');
            }
            if ($date->lt($asset->acquired_on)) {
                throw new RuntimeException('تاريخ الاستبعاد لا يسبق تاريخ اقتناء الأصل');
            }

            $asset->update([
                'status' => 'disposed',
                'disposed_on' => $date->toDateString(),
                'disposal_proceeds' => $proceeds === null ? null : MoneyService::normalize($proceeds),
                'disposal_reason' => mb_substr(trim($reason), 0, 500),
            ]);

            return $asset->fresh();
        });

        $this->audit->record([
            'actor_type' => 'merchant',
            'actor_user_id' => $merchant->id,
            'subject_type' => 'merchant_fixed_asset',
            'subject_id' => $asset->asset_ulid,
            'action' => 'FIXED_ASSET_DISPOSED',
            'decision_code' => 'DISPOSED',
            'severity' => 'notice',
            'reason' => $reason,
            'context' => [
                'disposed_on' => $date->toDateString(),
                'proceeds' => $proceeds,
            ],
        ]);

        return $asset;
    }

    public function toArray(MerchantFixedAsset $asset): array
    {
        $acc = property_exists($asset, 'accumulated_depreciation')
            ? (string) ($asset->accumulated_depreciation ?? '0')
            : (string) $asset->depreciations()->sum('amount');

        $book = bcsub((string) $asset->acquisition_cost, $acc, 4);
        if (bccomp($book, (string) $asset->salvage_value, 4) < 0) {
            $book = (string) $asset->salvage_value;
        }

        $depreciable = bcsub(
            (string) $asset->acquisition_cost,
            (string) $asset->salvage_value,
            4
        );

        return [
            'id' => $asset->id,
            'asset_ulid' => $asset->asset_ulid,
            'name' => $asset->name,
            'category' => $asset->category,
            'quantity' => (string) $asset->quantity,
            'acquisition_cost' => MoneyService::normalize((string) $asset->acquisition_cost),
            'salvage_value' => MoneyService::normalize((string) $asset->salvage_value),
            'depreciable_base' => MoneyService::normalize($depreciable),
            'useful_life_months' => (int) $asset->useful_life_months,
            'monthly_depreciation' => MoneyService::normalize(
                bcdiv($depreciable, (string) max(1, $asset->useful_life_months), 4)
            ),
            'accumulated_depreciation' => MoneyService::normalize($acc),
            'book_value' => MoneyService::normalize($book),
            'acquired_on' => $asset->acquired_on?->toDateString(),
            'depreciation_starts_on' => $asset->depreciation_starts_on?->toDateString(),
            'status' => $asset->status,
            'disposed_on' => $asset->disposed_on?->toDateString(),
            'disposal_proceeds' => $asset->disposal_proceeds === null
                ? null : MoneyService::normalize((string) $asset->disposal_proceeds),
            'disposal_reason' => $asset->disposal_reason,
            'source' => $asset->purchase_order_id ? 'purchase_order' : 'opening_register',
            'purchase_order_id' => $asset->purchase_order_id,
            'supplier_id' => $asset->supplier_id,
        ];
    }

    private function assertAssetNumbers(string $cost, string $salvage, int $life): void
    {
        if (! MoneyService::isPositive($cost)) {
            throw new RuntimeException('تكلفة الأصل يجب أن تكون أكبر من صفر');
        }
        if ($life < 1 || $life > 600) {
            throw new RuntimeException('العمر الإنتاجي يجب أن يكون بين شهر و600 شهر');
        }
        if (bccomp($salvage, '0', 4) < 0 || bccomp($salvage, $cost, 4) >= 0) {
            throw new RuntimeException('القيمة المتبقية يجب أن تكون صفراً أو أقل من تكلفة الأصل');
        }
    }
}
