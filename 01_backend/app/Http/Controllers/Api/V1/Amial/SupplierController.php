<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-SUPPLIERS-001 — الموردون وأوامر الشراء (تصاميم 53/57/67/68).
 *
 *   GET  /merchant/suppliers                    قائمة + إجماليات
 *   POST /merchant/suppliers                    إضافة مورد (برصيد افتتاحي اختياري)
 *   GET  /merchant/suppliers/{id}               تفاصيل + كشف الحركات
 *   POST /merchant/suppliers/{id}/payment       سداد دفعة للمورد (−دين)
 *   GET  /merchant/purchase-orders              أوامر الشراء
 *   POST /merchant/purchase-orders              إنشاء أمر (بنود بكمية وتكلفة)
 *   GET  /merchant/purchase-orders/{id}         تفاصيل ببنوده
 *   POST /merchant/purchase-orders/{id}/approve اعتماد
 *   POST /merchant/purchase-orders/{id}/receive استلام بضاعة (يزيد المخزون والدين)
 *   POST /merchant/purchase-orders/{id}/cancel  إلغاء (مسودة/معتمد غير مستلم)
 */
class SupplierController extends Controller
{
    // ============ الموردون ============

    public function index(Request $request): JsonResponse
    {
        $mid = $request->user()->id;
        if ($err = $this->assertMerchant($mid)) return $err;

        $suppliers = Supplier::where('merchant_user_id', $mid)
            ->where('is_active', true)
            ->orderByDesc('current_debt')
            ->get();

        $activePoCount = PurchaseOrder::where('merchant_user_id', $mid)
            ->whereIn('status', ['draft', 'approved', 'partially_received'])
            ->count();

        return $this->ok([
            'totals' => [
                'total_debt' => (string) $suppliers->sum(fn ($s) => (float) $s->current_debt),
                'suppliers_count' => $suppliers->count(),
                'active_po_count' => $activePoCount,
            ],
            'suppliers' => $suppliers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:200',
            'contact_person' => 'sometimes|nullable|string|max:200',
            'phone' => 'sometimes|nullable|string|max:32',
            'email' => 'sometimes|nullable|email|max:190',
            'address' => 'sometimes|nullable|string|max:500',
            'category' => 'sometimes|nullable|string|max:100',
            'opening_balance' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $mid = $request->user()->id;
        if ($err = $this->assertMerchant($mid)) return $err;

        $opening = (string) $request->input('opening_balance', '0');

        $supplier = DB::transaction(function () use ($request, $mid, $opening) {
            $s = Supplier::create([
                'merchant_user_id' => $mid,
                'name' => $request->input('name'),
                'contact_person' => $request->input('contact_person'),
                'phone' => $request->input('phone'),
                'email' => $request->input('email'),
                'address' => $request->input('address'),
                'category' => $request->input('category'),
                'current_debt' => $opening,
            ]);
            if (bccomp($opening, '0', 4) > 0) {
                SupplierLedgerEntry::create([
                    'supplier_id' => $s->id,
                    'merchant_user_id' => $mid,
                    'entry_type' => 'opening',
                    'amount' => $opening,
                    'debt_after' => $opening,
                    'note' => 'رصيد افتتاحي (دين سابق للمورد)',
                ]);
            }
            return $s;
        });

        return $this->ok(['supplier' => $supplier], 'SUPPLIER_CREATED', 'تم حفظ المورد', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $mid = $request->user()->id;
        $supplier = Supplier::where('id', $id)->where('merchant_user_id', $mid)->first();
        if (!$supplier) return $this->error('NOT_FOUND', 'المورد غير موجود', 404);

        return $this->ok([
            'supplier' => $supplier,
            'ledger' => $supplier->ledger()->limit(100)->get(),
            'purchase_orders' => $supplier->purchaseOrders()->limit(20)->get(),
        ]);
    }

    /** سداد دفعة للمورد — تُنقص المديونية (لا تمس محفظة أميال: سداد خارجي). */
    public function payment(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $mid = $request->user()->id;

        try {
            $supplier = DB::transaction(function () use ($request, $id, $mid) {
                $s = Supplier::where('id', $id)
                    ->where('merchant_user_id', $mid)
                    ->lockForUpdate()
                    ->first();
                if (!$s) {
                    throw new \RuntimeException('المورد غير موجود');
                }
                $amount = (string) $request->input('amount');
                if (bccomp($amount, (string) $s->current_debt, 4) > 0) {
                    throw new \RuntimeException('مبلغ السداد أكبر من المديونية الحالية');
                }
                $s->current_debt = bcsub((string) $s->current_debt, $amount, 4);
                $s->save();

                SupplierLedgerEntry::create([
                    'supplier_id' => $s->id,
                    'merchant_user_id' => $mid,
                    'entry_type' => 'payment',
                    'amount' => $amount,
                    'debt_after' => (string) $s->current_debt,
                    'note' => $request->input('note'),
                ]);
                return $s;
            });
        } catch (\RuntimeException $e) {
            return $this->error('PAYMENT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(
            ['supplier' => $supplier],
            'PAYMENT_RECORDED',
            'تم تسجيل السداد وتخفيض المديونية'
        );
    }

    // ============ أوامر الشراء ============

    public function poIndex(Request $request): JsonResponse
    {
        $mid = $request->user()->id;
        $status = (string) $request->query('status', 'all');

        $q = PurchaseOrder::with('supplier:id,name')
            ->where('merchant_user_id', $mid)
            ->orderByDesc('id');
        if ($status !== 'all') {
            $q->where('status', $status);
        }

        return $this->ok(['orders' => $q->limit(100)->get()]);
    }

    public function poStore(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'supplier_id' => 'required|integer',
            'notes' => 'sometimes|nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.product_id' => 'sometimes|nullable|integer',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $mid = $request->user()->id;
        $supplier = Supplier::where('id', $request->input('supplier_id'))
            ->where('merchant_user_id', $mid)->first();
        if (!$supplier) return $this->error('NOT_FOUND', 'المورد غير موجود', 404);

        $po = DB::transaction(function () use ($request, $mid, $supplier) {
            $total = '0';
            foreach ($request->input('items') as $it) {
                $total = bcadd($total,
                    bcmul((string) $it['quantity'], (string) $it['unit_cost'], 4), 4);
            }

            $po = PurchaseOrder::create([
                'po_number' => $this->nextPoNumber($mid),
                'merchant_user_id' => $mid,
                'supplier_id' => $supplier->id,
                'status' => 'draft',
                'total_amount' => $total,
                'notes' => $request->input('notes'),
            ]);

            foreach ($request->input('items') as $it) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $it['product_id'] ?? null,
                    'name' => $it['name'],
                    'quantity' => (string) $it['quantity'],
                    'unit_cost' => (string) $it['unit_cost'],
                ]);
            }
            return $po->load('items', 'supplier:id,name');
        });

        return $this->ok(['order' => $po], 'PO_CREATED', 'تم إنشاء أمر الشراء', 201);
    }

    public function poShow(Request $request, int $id): JsonResponse
    {
        $po = PurchaseOrder::with('items', 'supplier:id,name,current_debt')
            ->where('id', $id)
            ->where('merchant_user_id', $request->user()->id)
            ->first();
        if (!$po) return $this->error('NOT_FOUND', 'أمر الشراء غير موجود', 404);

        return $this->ok(['order' => $po]);
    }

    public function poApprove(Request $request, int $id): JsonResponse
    {
        $po = PurchaseOrder::where('id', $id)
            ->where('merchant_user_id', $request->user()->id)
            ->first();
        if (!$po) return $this->error('NOT_FOUND', 'أمر الشراء غير موجود', 404);
        if ($po->status !== 'draft') {
            return $this->error('INVALID_STATUS', 'يُعتمد أمر بحالة مسودة فقط', 422);
        }
        $po->update(['status' => 'approved', 'approved_at' => now()]);

        return $this->ok(['order' => $po->fresh('items')], 'PO_APPROVED', 'تم اعتماد أمر الشراء');
    }

    /**
     * استلام بضاعة: {items: [{item_id, received_quantity}]}
     * يزيد مخزون المنتجات المربوطة، ويزيد مديونية المورد بقيمة المُستلَم،
     * ويحدّث حالة الأمر (مستلم جزئياً / مكتمل).
     */
    public function poReceive(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.received_quantity' => 'required|numeric|min:0.001',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $mid = $request->user()->id;

        try {
            $po = DB::transaction(function () use ($request, $id, $mid) {
                $po = PurchaseOrder::with('items')
                    ->where('id', $id)
                    ->where('merchant_user_id', $mid)
                    ->lockForUpdate()
                    ->first();
                if (!$po) throw new \RuntimeException('أمر الشراء غير موجود');
                if (!in_array($po->status, ['approved', 'partially_received'], true)) {
                    throw new \RuntimeException('اعتمد الأمر أولاً قبل الاستلام');
                }

                $receivedValue = '0';
                foreach ($request->input('items') as $r) {
                    $item = $po->items->firstWhere('id', (int) $r['item_id']);
                    if (!$item) continue;
                    $qty = (string) $r['received_quantity'];
                    $remaining = bcsub((string) $item->quantity,
                        (string) $item->received_quantity, 3);
                    if (bccomp($qty, $remaining, 3) > 0) {
                        throw new \RuntimeException(
                            "الكمية المستلمة لبند «{$item->name}» تتجاوز المتبقي ($remaining)");
                    }

                    $item->received_quantity =
                        bcadd((string) $item->received_quantity, $qty, 3);
                    $item->save();

                    $receivedValue = bcadd($receivedValue,
                        bcmul($qty, (string) $item->unit_cost, 4), 4);

                    // زيادة مخزون المنتج المربوط
                    if ($item->product_id) {
                        $product = MerchantProduct::where('id', $item->product_id)
                            ->where('merchant_user_id', $mid)
                            ->lockForUpdate()->first();
                        if ($product) {
                            $product->quantity =
                                bcadd((string) $product->quantity, $qty, 3);
                            $product->save();
                        }
                    }
                }

                if (bccomp($receivedValue, '0', 4) <= 0) {
                    throw new \RuntimeException('لا كميات مستلمة');
                }

                // زيادة مديونية المورد بقيمة المُستلَم
                $supplier = Supplier::where('id', $po->supplier_id)
                    ->lockForUpdate()->first();
                $supplier->current_debt =
                    bcadd((string) $supplier->current_debt, $receivedValue, 4);
                $supplier->save();
                SupplierLedgerEntry::create([
                    'supplier_id' => $supplier->id,
                    'merchant_user_id' => $mid,
                    'entry_type' => 'po_receive',
                    'amount' => $receivedValue,
                    'debt_after' => (string) $supplier->current_debt,
                    'reference' => $po->po_number,
                    'note' => 'استلام بضاعة من أمر الشراء',
                ]);

                // تحديث حالة الأمر
                $po->load('items');
                $fully = $po->items->every(fn ($i) =>
                    bccomp((string) $i->received_quantity, (string) $i->quantity, 3) >= 0);
                $po->update($fully
                    ? ['status' => 'completed', 'completed_at' => now()]
                    : ['status' => 'partially_received']);

                return $po->fresh('items', 'supplier:id,name,current_debt');
            });
        } catch (\RuntimeException $e) {
            return $this->error('RECEIVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['order' => $po], 'GOODS_RECEIVED',
            'تم الاستلام وتحديث المخزون ومديونية المورد');
    }

    public function poCancel(Request $request, int $id): JsonResponse
    {
        $po = PurchaseOrder::where('id', $id)
            ->where('merchant_user_id', $request->user()->id)
            ->first();
        if (!$po) return $this->error('NOT_FOUND', 'أمر الشراء غير موجود', 404);
        if (!in_array($po->status, ['draft', 'approved'], true)) {
            return $this->error('INVALID_STATUS',
                'لا يُلغى أمر بدأ استلامه — أكمل الاستلام أو سوِّ مع المورد', 422);
        }
        $po->update(['status' => 'cancelled']);

        return $this->ok(['order' => $po], 'PO_CANCELLED', 'أُلغي أمر الشراء');
    }

    // ---- helpers ----

    private function nextPoNumber(int $mid): string
    {
        $seq = PurchaseOrder::where('merchant_user_id', $mid)->count() + 1;
        return sprintf('PO-%s-%04d', now()->format('Y'), $seq);
    }

    private function assertMerchant(int $userId): ?JsonResponse
    {
        if (!MerchantProfile::where('user_id', $userId)->exists()) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجار فقط', 403);
        }
        return null;
    }

    private function ok(array $meta, string $code = 'OK', string $message = '', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(),
            'meta' => (object) [],
        ], 422);
    }
}
