<?php

namespace App\Services;

use App\Models\MerchantProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Retail\MerchantLocation;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Services\Retail\StockService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-DAILY-MOVEMENT-001 — **البضاعةُ تعود إلى المورد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * لم يكن في أميال بابٌ واحدٌ لردّ بضاعةٍ إلى مورّد. فمن استلم تالفاً لم
 * يجد إلّا «تسويةً يدويّة» في دفتر المورد:
 *
 *   · **المخزونُ لا ينقص** — فيبقى التالفُ معروضاً ويُباع لزبون.
 *   · **والقيمةُ بلا نسبة** — نقصُ الدين يُقرأ سداداً، فتقريرُ المشتريات
 *     يقول إنّ التاجرَ اشترى ما ردَّه.
 *
 * **وثلاثةُ حدودٍ لا تُخترَق:**
 *
 *   ① **لا يُردُّ إلّا ما استُلم.** `received_quantity` هو السقف، ناقصَ
 *      ما رُدّ سابقاً — وإلّا رُدّت مئةٌ من عشرةٍ استُلمت، فصار المخزونُ
 *      سالباً ودينُ المورد له لا عليه.
 *   ② **البضاعةُ لا تتحرّك قبل الاعتماد.** الطلبُ يُنشأ فيُراجَع، ثمّ
 *      يخرج من الرفّ بحركةٍ سببها `purchase_return`.
 *   ③ **المالُ وجهان لا يُجمعان**: خصمٌ من دين المورد (`credit_note`)،
 *      أو استردادٌ نقديّ (`cash_refund`). وخلطُهما يُنقص الدينَ **ويقبض
 *      النقدَ معاً** — أي ردٌّ يُحاسَب مرّتين.
 *
 * **ولا يُنقَص دينٌ لا وجودَ له**: إن كان الدينُ أقلَّ من قيمة المرتجع
 * فالفائضُ لا يُحوَّل إلى دينٍ سالب — يُخصَم ما يُمكن، ويُقال الباقي
 * صراحةً في سطر الدفتر. (والدينُ السالبُ يُقرأ «المورد مدينٌ لنا» وهو
 * معنىً لم يقصده أحد.)
 */
class PurchaseReturnService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * @param  array  $lines  [['purchase_order_item_id'=>, 'product_id'=>, 'name'=>,
     *                         'quantity'=>, 'unit_cost'=>]]
     */
    public function create(User $merchant, int $supplierId, array $lines, array $opts = []): PurchaseReturn
    {
        $supplier = Supplier::where('id', $supplierId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (! $supplier) {
            throw new DomainException('المورد غير موجود');
        }

        if ($lines === []) {
            throw new DomainException('لا أسطر في المرتجع');
        }

        $settlement = (string) ($opts['settlement_type'] ?? PurchaseReturn::SETTLE_CREDIT_NOTE);
        if (! in_array($settlement, PurchaseReturn::SETTLEMENTS, true)) {
            throw new DomainException('طريقة تسوية المرتجع غير معروفة');
        }

        $po = null;
        if (! empty($opts['purchase_order_id'])) {
            $po = PurchaseOrder::where('id', (int) $opts['purchase_order_id'])
                ->where('merchant_user_id', $merchant->id)->first();
            if (! $po) {
                throw new DomainException('أمر الشراء غير موجود');
            }
            if ((int) $po->supplier_id !== (int) $supplier->id) {
                throw new DomainException('أمر الشراء لا يخصّ هذا المورد');
            }
        }

        return DB::transaction(function () use ($merchant, $supplier, $po, $lines, $opts, $settlement) {
            $location = ! empty($opts['location_id'])
                ? MerchantLocation::where('id', (int) $opts['location_id'])
                    ->where('merchant_user_id', $merchant->id)->first()
                : null;
            $location ??= $this->stock->defaultLocation($merchant->id);

            $return = PurchaseReturn::create([
                'return_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $po?->id,
                'location_id' => $location->id,
                'status' => 'pending',
                'settlement_type' => $settlement,
                'total_amount' => '0',
                'reason' => $opts['reason'] ?? null,
                'created_by' => $opts['actor_id'] ?? $merchant->id,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            $total = '0';

            foreach ($lines as $raw) {
                $qty = (string) ($raw['quantity'] ?? '0');
                if (bccomp($qty, '0', 3) <= 0) {
                    continue;
                }

                $poItem = null;
                if (! empty($raw['purchase_order_item_id'])) {
                    $poItem = PurchaseOrderItem::where('id', (int) $raw['purchase_order_item_id'])
                        ->when($po, fn ($q) => $q->where('purchase_order_id', $po->id))
                        ->first();
                    if (! $poItem) {
                        throw new DomainException('بند أمر الشراء غير موجود');
                    }

                    // ① **السقفُ هو المُستلَم ناقصَ ما رُدّ.**
                    $returnable = $poItem->returnableQuantity();
                    if (bccomp($qty, $returnable, 3) > 0) {
                        throw new DomainException(sprintf(
                            'المتاح للردّ من «%s» هو %s فقط', $poItem->name, $returnable));
                    }
                }

                $productId = $raw['product_id'] ?? $poItem?->product_id;
                $name = (string) ($raw['name'] ?? $poItem?->name ?? 'صنف');
                $unitCost = (string) ($raw['unit_cost'] ?? $poItem?->unit_cost ?? '0');

                // **والصنفُ يُتحقَّق أنّه لهذا التاجر** — معرّفٌ يأتي من
                // الطلب يمكن تغييره. (القاعدة الثامنة.)
                if ($productId) {
                    $owned = MerchantProduct::where('id', (int) $productId)
                        ->where('merchant_user_id', $merchant->id)->exists();
                    if (! $owned) {
                        throw new DomainException('الصنف غير موجود في متجرك');
                    }
                }

                $lineTotal = bcmul($qty, $unitCost, 4);
                $total = bcadd($total, $lineTotal, 4);

                PurchaseReturnItem::create([
                    'return_id' => $return->id,
                    'product_id' => $productId,
                    'purchase_order_item_id' => $poItem?->id,
                    'name' => $name,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ]);
            }

            if ($return->items()->count() === 0) {
                throw new DomainException('لا أسطر صالحة في المرتجع');
            }

            $return->update(['total_amount' => $total]);

            return $return->fresh('items');
        });
    }

    /**
     * الاعتماد — **وهنا تخرج البضاعةُ من الرفّ ويتحرّك حسابُ المورد.**
     */
    public function approve(User $actor, PurchaseReturn $return): PurchaseReturn
    {
        if ($return->status !== 'pending') {
            throw new DomainException('هذا المرتجع حُسم مسبقاً');
        }

        return DB::transaction(function () use ($actor, $return) {
            $location = MerchantLocation::find($return->location_id)
                ?? $this->stock->defaultLocation($return->merchant_user_id);

            foreach ($return->items()->get() as $item) {
                // ① يُقفَل بندُ الأمر أوّلاً — **فلا يُردُّ مرّتين** ولو
                //    تكرّر النداء أو وصل اعتمادان متزامنان.
                if ($item->purchase_order_item_id) {
                    $poItem = PurchaseOrderItem::lockForUpdate()
                        ->find($item->purchase_order_item_id);
                    if ($poItem) {
                        $newReturned = bcadd(
                            (string) ($poItem->returned_quantity ?? '0'),
                            (string) $item->quantity, 3);
                        if (bccomp($newReturned, (string) $poItem->received_quantity, 3) > 0) {
                            throw new DomainException(
                                "«{$poItem->name}» رُدّ بالكامل قبل اعتماد هذا المرتجع");
                        }
                        $poItem->update(['returned_quantity' => $newReturned]);
                    }
                }

                // ② البضاعةُ تخرج من الرفّ — **بسببها المعرَّف**.
                if ($item->product_id) {
                    $product = MerchantProduct::find($item->product_id);
                    if ($product) {
                        $this->stock->move(
                            product: $product,
                            location: $location,
                            delta: '-' . $item->quantity,
                            reason: 'purchase_return',
                            actor: $actor,
                            unitCost: (string) $item->unit_cost,
                            sourceType: 'purchase_return',
                            sourceId: $return->id,
                            note: 'مرتجع شراء ' . $return->return_ulid,
                        );
                    }
                }
            }

            $this->settle($return);

            $return->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            return $return->fresh('items');
        });
    }

    public function reject(User $actor, PurchaseReturn $return, string $reason): PurchaseReturn
    {
        if ($return->status !== 'pending') {
            throw new DomainException('هذا المرتجع حُسم مسبقاً');
        }

        $return->update([
            'status' => 'rejected',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'reason' => trim($reason) ?: $return->reason,
        ]);

        return $return->fresh();
    }

    /**
     * **③ وجهُ المال — واحدٌ لا اثنان.**
     *
     * `credit_note` يُنقص الدينَ بقدر ما يمكن، ولا يجعله سالباً. أمّا
     * `cash_refund` **فلا يمسّ الدينَ إطلاقاً**: المالُ عاد نقداً، وخصمُه
     * من الدين فوقَ ذلك احتسابٌ مرّتين.
     *
     * **وفي الحالتين يُكتب سطرُ دفتر** — فمرتجعٌ بلا أثرٍ في كشف المورد
     * يجعل الكشفَ يناقض المخزون.
     */
    private function settle(PurchaseReturn $return): void
    {
        $supplier = Supplier::where('id', $return->supplier_id)
            ->lockForUpdate()->first();
        if (! $supplier) {
            throw new DomainException('المورد غير موجود');
        }

        $amount = (string) $return->total_amount;

        if ($return->settlement_type === PurchaseReturn::SETTLE_CASH_REFUND) {
            SupplierLedgerEntry::create([
                'supplier_id' => $supplier->id,
                'merchant_user_id' => $return->merchant_user_id,
                'entry_type' => 'po_return',
                'amount' => $amount,
                'cash_amount' => $amount,
                'debt_after' => (string) $supplier->current_debt,
                'reference' => $return->return_ulid,
                'note' => 'مرتجع شراء — استُرِدّ نقداً، ولا يمسّ الدين',
            ]);

            return;
        }

        $debt = (string) $supplier->current_debt;
        $applied = bccomp($amount, $debt, 4) > 0 ? $debt : $amount;
        $unapplied = bcsub($amount, $applied, 4);

        $supplier->current_debt = bcsub($debt, $applied, 4);
        $supplier->save();

        SupplierLedgerEntry::create([
            'supplier_id' => $supplier->id,
            'merchant_user_id' => $return->merchant_user_id,
            'entry_type' => 'po_return',
            'amount' => $amount,
            'cash_amount' => '0',
            'debt_after' => (string) $supplier->current_debt,
            'reference' => $return->return_ulid,
            // **والفائضُ يُقال ولا يُخفى** — دينٌ سالبٌ يُقرأ عكسَ معناه.
            'note' => bccomp($unapplied, '0', 4) > 0
                ? sprintf('مرتجع شراء — خُصم %s من الدين، وبقي %s بلا خصم (الدين لا يصير سالباً)',
                    $applied, $unapplied)
                : 'مرتجع شراء — خُصم من دين المورد',
        ]);
    }
}
