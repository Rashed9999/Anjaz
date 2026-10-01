<?php

namespace App\Services;

use App\Models\MerchantAssetDepreciation;
use App\Models\MerchantAssetAdjustment;
use App\Models\MerchantFixedAsset;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Retail\ShiftCashMovement;
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
            ->withSum('depreciations as posted_depreciation', 'amount')
            ->withSum('adjustments as returned_quantity', 'quantity')
            ->withSum('adjustments as returned_cost', 'cost_amount')
            ->withSum('adjustments as returned_salvage', 'salvage_amount')
            ->withSum('adjustments as depreciation_reversed', 'depreciation_reversed')
            ->orderByDesc('id')->get();

        $rows = $assets->map(fn (MerchantFixedAsset $a) => $this->toArray($a))->all();

        $grossCost = $returnedCost = $carryingCost = $accumulated = $book = '0';
        foreach ($rows as $row) {
            $grossCost = MoneyService::add($grossCost, $row['gross_acquisition_cost']);
            $returnedCost = MoneyService::add($returnedCost, $row['returned_cost']);
            $carryingCost = MoneyService::add($carryingCost, $row['carrying_cost_basis']);
            $accumulated = MoneyService::add($accumulated, $row['accumulated_depreciation']);
            $book = MoneyService::add($book, $row['book_value']);
        }

        return [
            'assets' => $rows,
            'totals' => [
                'acquisition_cost' => MoneyService::normalize($carryingCost),
                'gross_acquisition_cost' => MoneyService::normalize($grossCost),
                'returned_cost' => MoneyService::normalize($returnedCost),
                'accumulated_depreciation' => MoneyService::normalize($accumulated),
                'book_value' => MoneyService::normalize($book),
                'active_count' => $assets->where('status', 'active')->count(),
                'disposed_count' => $assets->where('status', 'disposed')->count(),
                'returned_count' => $assets->where('status', 'returned_to_supplier')->count(),
            ],
        ];
    }

    public function show(User $merchant, int $id): array
    {
        $asset = MerchantFixedAsset::where('merchant_user_id', $merchant->id)
            ->with(['supplier:id,name', 'purchaseOrder:id,po_number,document_ulid', 'depreciations', 'adjustments'])
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
            'adjustments' => $asset->adjustments->map(fn ($a) => [
                'adjustment_ulid' => $a->adjustment_ulid,
                'type' => $a->type,
                'quantity' => (string) $a->quantity,
                'cost_amount' => (string) $a->cost_amount,
                'salvage_amount' => (string) $a->salvage_amount,
                'depreciation_reversed' => (string) $a->depreciation_reversed,
                'effective_on' => $a->effective_on?->toDateString(),
                'note' => $a->note,
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
        // الإهلاك الدوري لا يُثبت لشهر ما زال مفتوحاً؛ وإلا صار فتح شاشة
        // اليوم الأول من الشهر مصروفاً لشهر كامل. الاستبعاد له مساره الخاص.
        if ($through->gte(now()->startOfMonth())) {
            throw new RuntimeException('لا يمكن إثبات إهلاك شهر لم يُغلق بعد');
        }

        $posted = 0;
        $total = '0';

        $assetIds = MerchantFixedAsset::where('merchant_user_id', $merchant->id)
            ->whereIn('status', ['active', 'disposed'])->pluck('id');

        foreach ($assetIds as $assetId) {
            $result = $this->postOneAssetThrough($merchant, (int) $assetId, $through);
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

    /**
     * إهلاك أصل واحد. نستخدمه عند الاستبعاد حتى لا يؤدي استبعاد كرسي واحد
     * إلى ترحيل إهلاك كل أصول المنشأة للشهر الحالي قبل موعد الإغلاق.
     *
     * @return array{count:int,total:string}
     */
    private function postOneAssetThrough(User $merchant, int $assetId, Carbon $through): array
    {
        return DB::transaction(function () use ($merchant, $through, $assetId) {
            $asset = MerchantFixedAsset::where('id', $assetId)
                ->where('merchant_user_id', $merchant->id)
                ->lockForUpdate()->firstOrFail();

            $end = $through->copy()->endOfMonth();
            if ($asset->disposed_on && $asset->disposed_on->lt($end)) {
                $end = $asset->disposed_on->copy()->endOfMonth();
            }

            $start = $asset->depreciation_starts_on->copy()->startOfMonth();
            if ($end->lt($start)) {
                return ['count' => 0, 'total' => '0'];
            }

            $balance = $this->balanceAt($asset, $end);
            $depreciable = $balance['depreciable_base'];
            if (bccomp($depreciable, '0', 4) <= 0
                || bccomp($balance['active_quantity'], '0', 3) <= 0) {
                return ['count' => 0, 'total' => '0'];
            }

            $monthly = bcdiv($depreciable, (string) $asset->useful_life_months, 4);
            $existing = MerchantAssetDepreciation::where('asset_id', $asset->id)
                ->where('period', '<=', $end->format('Y-m'))
                ->orderBy('period')->get()->keyBy('period');
            $posted = '0';
            foreach ($existing as $row) {
                $posted = bcadd($posted, (string) $row->amount, 4);
            }
            $accumulated = bcsub($posted, $balance['depreciation_reversed'], 4);
            if (bccomp($accumulated, '0', 4) < 0) $accumulated = '0';

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
                $book = bcsub($balance['carrying_cost_basis'], $accumulated, 4);
                if (bccomp($book, $balance['carrying_salvage_value'], 4) < 0) {
                    $book = $balance['carrying_salvage_value'];
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
    }

    public function dispose(
        User $merchant,
        int $id,
        Carbon $date,
        ?string $proceeds,
        string $reason,
        ?int $cashierShiftId = null,
    ): MerchantFixedAsset {
        // الشهر الجاري مفتوح. للاستبعاد اليوم نثبت حتى آخر شهر مغلق فقط؛
        // أما استبعاد تاريخي في شهر مغلق فيثبت حتى شهر الاستبعاد.
        $closedThrough = $date->copy()->endOfMonth()->lt(now()->startOfMonth())
            ? $date->copy()->endOfMonth()
            : now()->subMonthNoOverflow()->endOfMonth();
        $assetBefore = MerchantFixedAsset::whereKey($id)
            ->where('merchant_user_id', $merchant->id)->firstOrFail();
        if ($closedThrough->gte($assetBefore->depreciation_starts_on->copy()->startOfMonth())) {
            $this->postOneAssetThrough($merchant, $id, $closedThrough);
        }

        $asset = DB::transaction(function () use ($merchant, $id, $date, $proceeds, $reason, $cashierShiftId) {
            $asset = MerchantFixedAsset::where('id', $id)
                ->where('merchant_user_id', $merchant->id)
                ->lockForUpdate()->firstOrFail();

            if ($asset->status === 'disposed') {
                throw new RuntimeException('الأصل مستبعد بالفعل');
            }
            if ($date->lt($asset->acquired_on)) {
                throw new RuntimeException('تاريخ الاستبعاد لا يسبق تاريخ اقتناء الأصل');
            }

            $balance = $this->balanceAt($asset, $date);
            $normalizedProceeds = $proceeds === null
                ? '0.0000' : MoneyService::normalize($proceeds);
            $gainLoss = bcsub($normalizedProceeds, $balance['book_value'], 4);

            $shift = null;
            if ($cashierShiftId !== null && MoneyService::gt($normalizedProceeds, '0')) {
                $shift = \App\Models\CashierShift::whereKey($cashierShiftId)
                    ->where('merchant_user_id', $merchant->id)
                    ->where('status', 'open')
                    ->lockForUpdate()->first();
                if (! $shift) {
                    throw new RuntimeException('وردية تحصيل بيع الأصل غير موجودة أو مغلقة');
                }

                app(\App\Services\Retail\MerchantShiftCashService::class)->record(
                    ShiftCashMovement::CASHIER,
                    $shift->id,
                    $merchant,
                    'in',
                    'asset_disposal_proceeds',
                    $normalizedProceeds,
                    'متحصلات استبعاد/بيع أصل: '.$asset->name,
                    'ASSET-'.$asset->asset_ulid,
                    $merchant->id,
                );
            }

            $asset->update([
                'status' => 'disposed',
                'disposed_on' => $date->toDateString(),
                'disposal_proceeds' => $normalizedProceeds,
                'disposal_book_value' => $balance['book_value'],
                'disposal_gain_loss' => $gainLoss,
                'disposal_payment_source' => MoneyService::gt($normalizedProceeds, '0')
                    ? ($shift ? 'cash_shift' : 'cash_external')
                    : 'none',
                'disposal_cashier_shift_id' => $shift?->id,
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
                'proceeds' => (string) $asset->disposal_proceeds,
                'book_value' => (string) $asset->disposal_book_value,
                'gain_loss' => (string) $asset->disposal_gain_loss,
                'payment_source' => $asset->disposal_payment_source,
                'cashier_shift_id' => $asset->disposal_cashier_shift_id,
            ],
        ]);

        return $asset;
    }

    /**
     * يسجل رد أصل ثابت للمورد كـ adjustment مستقل. لا نغيّر تكلفة الاقتناء
     * التاريخية؛ بل نقلّص أساس الأصل الجاري ونعكس حصة الإهلاك المتراكم
     * الخاصة بالكمية الخارجة.
     */
    public function applySupplierReturn(
        User $actor,
        PurchaseReturn $return,
        PurchaseReturnItem $returnItem,
        PurchaseOrderItem $poItem,
    ): void {
        if ((string) ($poItem->item_type ?? 'inventory') !== 'fixed_asset') return;

        $remaining = (string) $returnItem->quantity;
        $assets = MerchantFixedAsset::where('merchant_user_id', $return->merchant_user_id)
            ->where('purchase_order_item_id', $poItem->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($assets as $asset) {
            if (bccomp($remaining, '0', 3) <= 0) break;

            $balance = $this->balanceAt($asset, now());
            $available = $balance['active_quantity'];
            if (bccomp($available, '0', 3) <= 0) continue;

            $qty = bccomp($remaining, $available, 3) > 0 ? $available : $remaining;
            $costPerUnit = bcdiv((string) $asset->acquisition_cost, (string) $asset->quantity, 8);
            $salvagePerUnit = bcdiv((string) $asset->salvage_value, (string) $asset->quantity, 8);
            $cost = MoneyService::normalize(bcmul($costPerUnit, $qty, 8));
            $salvage = MoneyService::normalize(bcmul($salvagePerUnit, $qty, 8));

            $depReversed = '0.0000';
            if (bccomp($available, '0', 3) > 0
                && bccomp($balance['accumulated_depreciation'], '0', 4) > 0) {
                $depReversed = MoneyService::normalize(
                    bcmul(
                        bcdiv($balance['accumulated_depreciation'], $available, 8),
                        $qty,
                        8
                    )
                );
            }

            MerchantAssetAdjustment::create([
                'adjustment_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $return->merchant_user_id,
                'asset_id' => $asset->id,
                'purchase_return_id' => $return->id,
                'type' => 'supplier_return',
                'quantity' => $qty,
                'cost_amount' => $cost,
                'salvage_amount' => $salvage,
                'depreciation_reversed' => $depReversed,
                'effective_on' => now()->toDateString(),
                'note' => 'مرتجع شراء '.$return->return_ulid,
                'created_by' => $actor->id,
            ]);

            $remaining = bcsub($remaining, $qty, 3);
            $after = $this->balanceAt($asset->fresh(), now());
            if (bccomp($after['active_quantity'], '0', 3) <= 0) {
                $asset->update(['status' => 'returned_to_supplier']);
            }

            $this->audit->record([
                'actor_type' => 'merchant',
                'actor_user_id' => $actor->id,
                'subject_type' => 'merchant_fixed_asset',
                'subject_id' => $asset->asset_ulid,
                'action' => 'FIXED_ASSET_RETURNED_TO_SUPPLIER',
                'decision_code' => 'ADJUSTED',
                'severity' => 'notice',
                'context' => [
                    'purchase_return' => $return->return_ulid,
                    'quantity' => $qty,
                    'cost_reduction' => $cost,
                    'depreciation_reversed' => $depReversed,
                ],
            ]);
        }

        if (bccomp($remaining, '0', 3) > 0) {
            throw new RuntimeException(
                'سجل الأصول لا يغطي كامل كمية الأصل المراد ردها؛ أوقفت العملية لمنع اختلاف الأصول عن المورد'
            );
        }
    }

    public function toArray(MerchantFixedAsset $asset): array
    {
        $balance = $this->balanceAt($asset, now());

        return [
            'id' => $asset->id,
            'asset_ulid' => $asset->asset_ulid,
            'name' => $asset->name,
            'category' => $asset->category,
            'quantity' => MoneyService::normalize((string) $asset->quantity, 3),
            'active_quantity' => $balance['active_quantity'],
            'returned_quantity' => $balance['returned_quantity'],
            'gross_acquisition_cost' => MoneyService::normalize((string) $asset->acquisition_cost),
            'returned_cost' => $balance['returned_cost'],
            'carrying_cost_basis' => $balance['carrying_cost_basis'],
            // الاسم القديم يبقى الأصل التاريخي للسطر، وتعرض المجاميع أساس
            // التكلفة الجاري. لا نعيد كتابة تاريخ الاقتناء.
            'acquisition_cost' => MoneyService::normalize((string) $asset->acquisition_cost),
            'salvage_value' => MoneyService::normalize((string) $asset->salvage_value),
            'carrying_salvage_value' => $balance['carrying_salvage_value'],
            'depreciable_base' => $balance['depreciable_base'],
            'useful_life_months' => (int) $asset->useful_life_months,
            'monthly_depreciation' => MoneyService::normalize(
                bcdiv($balance['depreciable_base'], (string) max(1, $asset->useful_life_months), 4)
            ),
            'accumulated_depreciation' => $balance['accumulated_depreciation'],
            'depreciation_reversed' => $balance['depreciation_reversed'],
            'book_value' => $balance['book_value'],
            'acquired_on' => $asset->acquired_on?->toDateString(),
            'depreciation_starts_on' => $asset->depreciation_starts_on?->toDateString(),
            'status' => $asset->status,
            'disposed_on' => $asset->disposed_on?->toDateString(),
            'disposal_proceeds' => $asset->disposal_proceeds === null
                ? null : MoneyService::normalize((string) $asset->disposal_proceeds),
            'disposal_book_value' => $asset->disposal_book_value === null
                ? null : MoneyService::normalize((string) $asset->disposal_book_value),
            'disposal_gain_loss' => $asset->disposal_gain_loss === null
                ? null : MoneyService::normalize((string) $asset->disposal_gain_loss),
            'disposal_payment_source' => $asset->disposal_payment_source,
            'disposal_cashier_shift_id' => $asset->disposal_cashier_shift_id,
            'disposal_reason' => $asset->disposal_reason,
            'source' => $asset->purchase_order_id ? 'purchase_order' : 'opening_register',
            'purchase_order_id' => $asset->purchase_order_id,
            'supplier_id' => $asset->supplier_id,
        ];
    }

    /** @return array<string,string> */
    private function balanceAt(MerchantFixedAsset $asset, Carbon $at): array
    {
        $adjustments = MerchantAssetAdjustment::where('asset_id', $asset->id)
            ->whereDate('effective_on', '<=', $at->toDateString())->get();

        $returnedQuantity = '0';
        $returnedCost = '0';
        $returnedSalvage = '0';
        $depReversed = '0';
        foreach ($adjustments as $a) {
            $returnedQuantity = bcadd($returnedQuantity, (string) $a->quantity, 3);
            $returnedCost = bcadd($returnedCost, (string) $a->cost_amount, 4);
            $returnedSalvage = bcadd($returnedSalvage, (string) $a->salvage_amount, 4);
            $depReversed = bcadd($depReversed, (string) $a->depreciation_reversed, 4);
        }

        $posted = '0';
        $deps = MerchantAssetDepreciation::where('asset_id', $asset->id)
            ->where('period', '<=', $at->format('Y-m'))->get(['amount']);
        foreach ($deps as $d) $posted = bcadd($posted, (string) $d->amount, 4);

        $activeQuantity = bcsub((string) $asset->quantity, $returnedQuantity, 3);
        if (bccomp($activeQuantity, '0', 3) < 0) $activeQuantity = '0';
        $carryingCost = bcsub((string) $asset->acquisition_cost, $returnedCost, 4);
        if (bccomp($carryingCost, '0', 4) < 0) $carryingCost = '0';
        $carryingSalvage = bcsub((string) $asset->salvage_value, $returnedSalvage, 4);
        if (bccomp($carryingSalvage, '0', 4) < 0) $carryingSalvage = '0';

        $effectiveDep = bcsub($posted, $depReversed, 4);
        if (bccomp($effectiveDep, '0', 4) < 0) $effectiveDep = '0';
        $depreciable = bcsub($carryingCost, $carryingSalvage, 4);
        if (bccomp($depreciable, '0', 4) < 0) $depreciable = '0';
        if (bccomp($effectiveDep, $depreciable, 4) > 0) $effectiveDep = $depreciable;

        $book = bcsub($carryingCost, $effectiveDep, 4);
        if (bccomp($book, $carryingSalvage, 4) < 0) $book = $carryingSalvage;

        return [
            'active_quantity' => number_format((float) $activeQuantity, 3, '.', ''),
            'returned_quantity' => number_format((float) $returnedQuantity, 3, '.', ''),
            'returned_cost' => MoneyService::normalize($returnedCost),
            'carrying_cost_basis' => MoneyService::normalize($carryingCost),
            'carrying_salvage_value' => MoneyService::normalize($carryingSalvage),
            'depreciation_reversed' => MoneyService::normalize($depReversed),
            'accumulated_depreciation' => MoneyService::normalize($effectiveDep),
            'depreciable_base' => MoneyService::normalize($depreciable),
            'book_value' => MoneyService::normalize($book),
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
