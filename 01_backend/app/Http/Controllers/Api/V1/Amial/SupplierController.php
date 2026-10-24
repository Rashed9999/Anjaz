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
use Illuminate\Support\Str;

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

        $totalDebt = $totalCredit = '0';
        foreach ($suppliers as $supplier) {
            $totalDebt = \App\Services\MoneyService::add(
                $totalDebt, (string) $supplier->current_debt
            );
            $totalCredit = \App\Services\MoneyService::add(
                $totalCredit, (string) ($supplier->current_credit ?? '0')
            );
        }

        return $this->ok([
            'totals' => [
                'total_debt' => $totalDebt,
                'total_credit' => $totalCredit,
                'net_payable' => \App\Services\MoneyService::sub($totalDebt, $totalCredit),
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
                'current_credit' => '0',
            ]);
            if (bccomp($opening, '0', 4) > 0) {
                SupplierLedgerEntry::create([
                    'entry_ulid' => (string) Str::ulid(),
                    'supplier_id' => $s->id,
                    'merchant_user_id' => $mid,
                    'entry_type' => 'opening',
                    'amount' => $opening,
                    'debt_after' => $opening,
                    'credit_after' => '0',
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
            'cashier_shift_id' => 'sometimes|nullable|integer|min:1',
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

                // **مصدر النقد صريح.** غياب cashier_shift_id يعني سداداً
                // خارج درج نقاط البيع. وإذا اختير درجٌ فلا نسمح أن يصبح
                // المتوقع سالباً؛ سجّل إيداعاً أولاً إن دخل نقد من الخارج.
                $cashierShift = $this->lockCashierShiftForOutflow(
                    $request, $mid, $amount
                );

                $s->current_debt = bcsub((string) $s->current_debt, $amount, 4);
                $s->save();

                SupplierLedgerEntry::create([
                    'entry_ulid' => (string) Str::ulid(),
                    'supplier_id' => $s->id,
                    'merchant_user_id' => $mid,
                    'entry_type' => 'payment',
                    'amount' => $amount,
                    'cash_amount' => $amount,
                    'payment_method' => $cashierShift ? 'cash_shift' : 'cash_external',
                    'debt_after' => (string) $s->current_debt,
                    'credit_after' => (string) ($s->current_credit ?? '0'),
                    'reference' => $cashierShift ? 'SHIFT-'.$cashierShift->id : null,
                    'cashier_shift_id' => $cashierShift?->id,
                    'note' => trim((string) $request->input('note')) ?: (
                        $cashierShift
                            ? 'سداد للمورد من درج الوردية #'.$cashierShift->id
                            : 'سداد نقدي خارج درج نقاط البيع'
                    ),
                ]);

                if ($cashierShift) {
                    app(\App\Services\Retail\MerchantShiftCashService::class)->record(
                        \App\Models\Retail\ShiftCashMovement::CASHIER,
                        $cashierShift->id,
                        $request->user(),
                        'out',
                        'supplier_payment',
                        $amount,
                        'سداد للمورد '.$s->name,
                        'SUPPLIER-'.$s->id,
                        $mid,
                    );
                }

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

    /**
     * تحصيل رصيد لنا عند المورد (نشأ مثلاً من مرتجع يفوق الدين).
     * النقد الخارجي لا يغيّر POS، أما تحديد وردية فيسجل دخولاً في درجها.
     */
    public function creditRefund(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string|max:500',
            'cashier_shift_id' => 'sometimes|nullable|integer|min:1',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $mid = $request->user()->id;

        try {
            $supplier = DB::transaction(function () use ($request, $id, $mid) {
                $s = Supplier::whereKey($id)
                    ->where('merchant_user_id', $mid)
                    ->lockForUpdate()->first();
                if (! $s) throw new \RuntimeException('المورد غير موجود');

                $amount = \App\Services\MoneyService::normalize(
                    (string) $request->input('amount')
                );
                $credit = (string) ($s->current_credit ?? '0');
                if (bccomp($amount, $credit, 4) > 0) {
                    throw new \RuntimeException('المبلغ أكبر من الرصيد المستحق لنا عند المورد');
                }

                $shift = null;
                if ($request->filled('cashier_shift_id')) {
                    $shift = \App\Models\CashierShift::whereKey(
                            (int) $request->input('cashier_shift_id'))
                        ->where('merchant_user_id', $mid)
                        ->where('status', 'open')
                        ->lockForUpdate()->first();
                    if (! $shift) {
                        throw new \RuntimeException('وردية التحصيل غير موجودة أو مغلقة');
                    }
                }

                $s->current_credit = bcsub($credit, $amount, 4);
                $s->save();

                $entry = SupplierLedgerEntry::create([
                    'entry_ulid' => (string) Str::ulid(),
                    'supplier_id' => $s->id,
                    'merchant_user_id' => $mid,
                    'entry_type' => 'supplier_refund',
                    'amount' => $amount,
                    'cash_amount' => $amount,
                    'payment_method' => $shift ? 'cash_shift' : 'cash_external',
                    'debt_after' => (string) $s->current_debt,
                    'credit_after' => (string) $s->current_credit,
                    'reference' => $shift ? 'SHIFT-'.$shift->id : null,
                    'cashier_shift_id' => $shift?->id,
                    'note' => trim((string) $request->input('note'))
                        ?: ($shift
                            ? 'تحصيل رصيد من المورد إلى درج الوردية #'.$shift->id
                            : 'تحصيل رصيد من المورد نقداً خارج نقاط البيع'),
                ]);

                if ($shift) {
                    app(\App\Services\Retail\MerchantShiftCashService::class)->record(
                        \App\Models\Retail\ShiftCashMovement::CASHIER,
                        $shift->id,
                        $request->user(),
                        'in',
                        'supplier_refund',
                        $amount,
                        'تحصيل رصيد من المورد '.$s->name,
                        $entry->entry_ulid,
                        $mid,
                    );
                }

                return [
                    'supplier' => $s->fresh(),
                    'receipt' => $entry,
                ];
            }, 3);
        } catch (\RuntimeException|\DomainException $e) {
            return $this->error('SUPPLIER_CREDIT_REFUND_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(
            $supplier,
            'SUPPLIER_CREDIT_REFUND_RECORDED',
            'تم تحصيل الرصيد من المورد'
        );
    }

    /**
     * سداد دين المورد من محفظة المنشأة إلى حساب أميال المرتبط بالمورد.
     * المال يتحرك عبر محرك المحافظ والدفتر نفسه؛ سجل المورد لا يحرّك المال
     * بل يقرأ نتيجة التحويل ثم يخفض الدين بالمبلغ الأصلي فقط.
     */
    public function walletPayment(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $merchant = $request->user();
        $mid = $merchant->id;
        if ($err = $this->assertMerchant($mid)) return $err;

        $profile = MerchantProfile::where('user_id', $mid)->first();
        if (($profile?->verification_status ?? null) !== 'verified') {
            return $this->error('MERCHANT_NOT_VERIFIED',
                'يجب اعتماد المنشأة قبل السداد من المحفظة', 403);
        }

        $supplier = Supplier::where('id', $id)
            ->where('merchant_user_id', $mid)->first();
        if (! $supplier) return $this->error('NOT_FOUND', 'المورد غير موجود', 404);
        if (! $supplier->phone && ! $supplier->amial_user_id) {
            return $this->error('SUPPLIER_AMIAL_ACCOUNT_REQUIRED',
                'أضف رقم هاتف المورد المرتبط بحساب أميال قبل السداد من المحفظة', 422);
        }

        $recipient = $supplier->amial_user_id
            ? \App\Models\User::find($supplier->amial_user_id)
            : \App\Models\User::whereIn(
                'phone',
                \App\Support\Phone::variants((string) $supplier->phone)
            )->first();

        if (! $recipient) {
            return $this->error('SUPPLIER_AMIAL_ACCOUNT_NOT_FOUND',
                'لا يوجد حساب أميال مرتبط برقم هذا المورد', 422);
        }
        if ((int) $recipient->id === (int) $merchant->id) {
            return $this->error('SUPPLIER_SELF_PAYMENT_FORBIDDEN',
                'لا يمكن سداد المورد إلى محفظة المنشأة نفسها', 422);
        }
        if ((int) ($recipient->is_active ?? 0) !== 1
            || (int) ($recipient->is_temp_blocked ?? 0) === 1
            || (string) ($recipient->sanction_status ?? 'clear') === 'blocked') {
            return $this->error('SUPPLIER_AMIAL_ACCOUNT_UNAVAILABLE',
                'حساب أميال المرتبط بالمورد غير متاح لاستقبال السداد', 422);
        }

        try {
            $kyc = app(\App\Services\KycTierService::class);
            if ($kyc->isIndividualCustomer($recipient)) {
                $kyc->assertIndividualCanReceive($recipient, (string) $request->input('amount'));
            } elseif ((int) ($recipient->is_kyc_verified ?? 0) !== 1
                && ! MerchantProfile::where('user_id', $recipient->id)
                    ->where('verification_status', 'verified')->exists()) {
                throw new \RuntimeException('حساب المورد لم يُعتمد بعد لاستقبال الأموال');
            }

            foreach ([$merchant, $recipient] as $party) {
                $policy = app(\App\Services\ZonePolicyService::class)
                    ->authorize($party, 'send_money');
                if (! ($policy['allowed'] ?? false)) {
                    throw new \RuntimeException('سياسة المنطقة لا تسمح بالسداد لهذا الحساب حالياً');
                }
            }
        } catch (\RuntimeException $e) {
            return $this->error('SUPPLIER_PAYMENT_POLICY_DENIED', $e->getMessage(), 403);
        }

        $pricing = app(\App\Services\FeeService::class)->calculate(
            'SUPPLIER_PAYMENT',
            (string) $request->input('amount'),
            [
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
                'applies_to' => 'merchant',
                'plan' => $profile?->subscription_plan,
            ],
        );
        if (($pricing['pricing_state'] ?? null) === 'missing_config') {
            return $this->error('SUPPLIER_PAYMENT_PRICING_MISSING',
                'تسعير سداد المورد غير مهيأ؛ لم تُنفّذ العملية', 503);
        }

        $requestKey = trim((string) $request->header('Idempotency-Key'));
        $subledgerKey = $requestKey !== ''
            ? 'supplier-pay:'.hash('sha256', $requestKey)
            : null;

        try {
            $result = DB::transaction(function () use (
                $request, $merchant, $recipient, $supplier, $pricing,
                $requestKey, $subledgerKey, $mid
            ) {
                if ($subledgerKey) {
                    $existing = SupplierLedgerEntry::where('idempotency_key', $subledgerKey)
                        ->where('merchant_user_id', $mid)
                        ->lockForUpdate()->first();
                    if ($existing) {
                        return [
                            'supplier' => Supplier::findOrFail($existing->supplier_id),
                            'payment' => $existing,
                            'transaction_id' => $existing->transaction_id,
                            'ledger_entry_ulid' => null,
                            'duplicate' => true,
                        ];
                    }
                }

                $locked = Supplier::where('id', $supplier->id)
                    ->where('merchant_user_id', $mid)
                    ->lockForUpdate()->firstOrFail();

                $amount = \App\Services\MoneyService::normalize(
                    (string) $request->input('amount')
                );
                if (bccomp($amount, (string) $locked->current_debt, 4) > 0) {
                    throw new \RuntimeException('مبلغ السداد أكبر من المديونية الحالية');
                }

                $transfer = app(\App\Services\AdminWalletTransferService::class)->transfer(
                    sender: $merchant,
                    recipient: $recipient,
                    amount: $amount,
                    reason: trim((string) $request->input('note'))
                        ?: 'سداد مورد: '.$locked->name,
                    requestIdempotencyKey: $requestKey !== '' ? $requestKey : null,
                    actor: $merchant,
                    sourceType: 'supplier_payment',
                    debitTransactionType: SEND_MONEY,
                    creditTransactionType: RECEIVED_MONEY,
                    description: 'سداد مورد من محفظة المنشأة إلى محفظة أميال',
                    walletReason: 'supplier_payment',
                    fee: (string) ($pricing['fee'] ?? '0'),
                    metadata: [
                        'supplier_id' => $locked->id,
                        'merchant_user_id' => $mid,
                        'fee_scheme_id' => $pricing['scheme_id'] ?? null,
                        'fee_scheme_version' => $pricing['scheme_version'] ?? null,
                    ],
                );

                $locked->current_debt = bcsub((string) $locked->current_debt, $amount, 4);
                $locked->amial_user_id = $recipient->id;
                $locked->save();

                $payment = SupplierLedgerEntry::create([
                    'entry_ulid' => (string) Str::ulid(),
                    'supplier_id' => $locked->id,
                    'merchant_user_id' => $mid,
                    'entry_type' => 'payment',
                    'amount' => $amount,
                    'cash_amount' => '0',
                    'payment_method' => 'amial_pay',
                    'debt_after' => (string) $locked->current_debt,
                    'credit_after' => (string) ($locked->current_credit ?? '0'),
                    'reference' => 'AMIAL-'.$transfer['transaction_id'],
                    'transaction_id' => $transfer['transaction_id'],
                    'idempotency_key' => $subledgerKey,
                    'note' => trim((string) $request->input('note'))
                        ?: 'سداد من محفظة أميال',
                ]);

                app(\App\Services\AuditService::class)->record([
                    'actor_type' => 'merchant',
                    'actor_user_id' => $mid,
                    'subject_type' => 'supplier_payment',
                    'subject_id' => $payment->entry_ulid,
                    'action' => 'SUPPLIER_WALLET_PAYMENT_COMPLETED',
                    'decision_code' => 'POSTED',
                    'severity' => 'info',
                    'transaction_id' => $transfer['transaction_id'],
                    'idempotency_key' => $subledgerKey,
                    'context' => [
                        'supplier_id' => $locked->id,
                        'recipient_user_id' => $recipient->id,
                        'amount' => $amount,
                        'fee' => (string) ($pricing['fee'] ?? '0'),
                        'debt_after' => (string) $locked->current_debt,
                    ],
                ]);

                return [
                    'supplier' => $locked->fresh(),
                    'payment' => $payment,
                    'transaction_id' => $transfer['transaction_id'],
                    'ledger_entry_ulid' => $transfer['ledger_entry_ulid'],
                    'fee' => (string) ($pricing['fee'] ?? '0'),
                    'total_debited' => (string) ($transfer['total_debited']
                        ?? \App\Services\MoneyService::add($amount, (string) ($pricing['fee'] ?? '0'))),
                    'duplicate' => (bool) ($transfer['duplicate'] ?? false),
                ];
            }, 3);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return $this->error('INSUFFICIENT_BALANCE', 'رصيد محفظة المنشأة غير كافٍ', 422);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return $this->error('SUPPLIER_WALLET_PAYMENT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok($result, 'SUPPLIER_WALLET_PAYMENT_COMPLETED',
            'تم سداد المورد من محفظة المنشأة');
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
            'items.*.item_type' => 'sometimes|string|in:inventory,fixed_asset,other',
            'items.*.asset_category' => 'sometimes|nullable|string|in:furniture,equipment,computer,vehicle,machinery,fixtures,building_improvement,other',
            'items.*.useful_life_months' => 'sometimes|nullable|integer|min:1|max:600',
            'items.*.salvage_value' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $mid = $request->user()->id;
        $supplier = Supplier::where('id', $request->input('supplier_id'))
            ->where('merchant_user_id', $mid)->first();
        if (!$supplier) return $this->error('NOT_FOUND', 'المورد غير موجود', 404);

        foreach ($request->input('items') as $item) {
            $type = isset($item['item_type'])
                ? (string) $item['item_type']
                : (!empty($item['product_id']) ? 'inventory' : 'other');
            if ($type === 'fixed_asset' && !empty($item['product_id'])) {
                return $this->error('ASSET_PRODUCT_CONFLICT',
                    'بند الأصل الثابت لا يُربط بصنف مخزون بيع؛ اختر أحد التصنيفين', 422);
            }
            if ($type === 'inventory' && empty($item['product_id'])) {
                return $this->error('INVENTORY_PRODUCT_REQUIRED',
                    'بند المخزون يجب ربطه بمنتج فعلي؛ استخدم «شراء آخر» للبند غير المخزني', 422);
            }
            if ($type === 'fixed_asset') {
                if (empty($item['useful_life_months'])) {
                    return $this->error('ASSET_LIFE_REQUIRED',
                        'العمر الإنتاجي مطلوب لبند الأصل الثابت', 422);
                }
                $unitCost = (string) ($item['unit_cost'] ?? '0');
                $salvage = (string) ($item['salvage_value'] ?? '0');
                if (bccomp($salvage, $unitCost, 4) >= 0) {
                    return $this->error('ASSET_SALVAGE_INVALID',
                        'القيمة المتبقية للوحدة يجب أن تكون أقل من تكلفة الوحدة', 422);
                }
            }
        }

        $po = DB::transaction(function () use ($request, $mid, $supplier) {
            $total = '0';
            foreach ($request->input('items') as $it) {
                $total = bcadd($total,
                    bcmul((string) $it['quantity'], (string) $it['unit_cost'], 4), 4);
            }

            $po = PurchaseOrder::create([
                'document_ulid' => (string) Str::ulid(),
                'po_number' => $this->nextPoNumber($mid),
                'merchant_user_id' => $mid,
                'supplier_id' => $supplier->id,
                'status' => 'draft',
                'total_amount' => $total,
                'notes' => $request->input('notes'),
            ]);

            foreach ($request->input('items') as $it) {
                $itemType = isset($it['item_type'])
                    ? (string) $it['item_type']
                    : (!empty($it['product_id']) ? 'inventory' : 'other');

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $itemType === 'inventory' ? ($it['product_id'] ?? null) : null,
                    'item_type' => $itemType,
                    'asset_category' => $it['asset_category'] ?? null,
                    'useful_life_months' => $it['useful_life_months'] ?? null,
                    'salvage_value' => (string) ($it['salvage_value'] ?? '0'),
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
     * استلام بضاعة: {items: [{item_id, received_quantity}], paid_now?, location_id?}
     *
     * ══════════════════════════════════════════════════════════════════
     * **AMIAL-DAILY-MOVEMENT-001 — عطلان قِيسا هنا، والثاني يُتلف بيانات.**
     *
     * **① الاستلامُ كان يتجاوز دفترَ المخزون كلَّه.** كان يكتب
     * `$product->quantity` مباشرةً، **ولا يمرّ من `StockService`** — وهو
     * صاحبُ الحقيقة (`product_stocks.on_hand` وسجلُّ الحركات). وقِيس
     * بالتشغيل، لا بالقراءة:
     *
     *     استُلمت ١٠٠ حبّة
     *     products.quantity      = 110.000   ← العمودُ القديم يرتفع
     *     product_stocks.on_hand =  10.000   ← والمخزونُ الحقيقيّ ساكن
     *     حركاتُ المخزون: opening_balance وحدها — **لا حركةَ استلامٍ قطّ**
     *     ثمّ بيعُ ٦٠ يسقط: «الكمية غير كافية… المتاح 10»
     *
     * **فبضاعةٌ استُلمت ودُفع ثمنُها لا تُباع.** وأسوأُ منه ما بعده:
     *
     *     ثمّ بيعُ ٥ فقط ينجح ⇒ products.quantity = 5.000
     *
     * أي أنّ `syncLegacyQuantity` تُعيد بناء العمود القديم من مجموع
     * المواقع، **فتمحو المئةَ المستلمة بلا خطأ ولا أثر**. (وهذا عينُ
     * القاعدة السادسة: الرقمُ يُحسب من مصدره، ومن كتب فوق المرآة ضاع.)
     *
     * **و`purchase_receive` سببُ حركةٍ معرَّفٌ في `StockMovement::INBOUND`
     * منذ بُني المخزون ولا مُصدِرَ له** — كان الرفُّ ينتظر هذا النداء.
     *
     * **② والشراءُ النقديُّ لم يكن ممكناً.** كلُّ استلامٍ يرفع دينَ المورد،
     * فمن اشترى نقداً لا يجد إلّا خمسَ خطواتٍ أو لا يسجّل. فصار
     * `paid_now` جزءاً من الاستلام: يُرفَع الدينُ ثمّ يُخفَض بما دُفع،
     * **والحركتان كلتاهما في الدفتر** فالكشفُ يبقى مقروءاً.
     * ══════════════════════════════════════════════════════════════════
     */
    public function poReceive(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.received_quantity' => 'required|numeric|min:0.001',
            'paid_now' => 'sometimes|nullable|numeric|min:0',
            'location_id' => 'sometimes|nullable|integer',
            'cashier_shift_id' => 'sometimes|nullable|integer|min:1',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $mid = $request->user()->id;
        $stock = app(\App\Services\Retail\StockService::class);

        $location = $request->filled('location_id')
            ? \App\Models\Retail\MerchantLocation::where('id', (int) $request->input('location_id'))
                ->where('merchant_user_id', $mid)->first()
            : null;
        $location ??= $stock->defaultLocation($mid);

        try {
            $po = DB::transaction(function () use ($request, $id, $mid, $stock, $location) {
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

                    // **المخزونُ يمرّ من صاحبه** — انظر ① في رأس الدالّة.
                    if ((string) ($item->item_type ?? 'inventory') === 'inventory' && $item->product_id) {
                        $product = MerchantProduct::where('id', $item->product_id)
                            ->where('merchant_user_id', $mid)
                            ->first();
                        if ($product) {
                            $stock->move(
                                product: $product,
                                location: $location,
                                delta: $qty,
                                reason: 'purchase_receive',
                                actor: $request->user(),
                                unitCost: (string) $item->unit_cost,
                                sourceType: 'purchase_order',
                                sourceId: $po->id,
                                note: 'استلام ' . $po->po_number,
                            );
                        }
                    }

                    // الأصل الثابت لا يدخل مخزون البيع. يُنشأ من الكمية
                    // المستلمة فعلاً ويرتبط بأمر الشراء والبند والمورد.
                    app(\App\Services\MerchantFixedAssetService::class)
                        ->registerPurchaseReceipt($request->user(), $po, $item, $qty);
                }

                if (bccomp($receivedValue, '0', 4) <= 0) {
                    throw new \RuntimeException('لا كميات مستلمة');
                }

                // رصيد دائن سابق عند المورد يُستهلك أولاً. لا يجوز أن
                // نظهر «ديناً علينا» و«رصيداً لنا» للمورد نفسه في الوقت نفسه.
                $supplier = Supplier::where('id', $po->supplier_id)
                    ->lockForUpdate()->first();
                $creditBefore = (string) ($supplier->current_credit ?? '0');
                $creditApplied = bccomp($creditBefore, $receivedValue, 4) > 0
                    ? $receivedValue : $creditBefore;
                $netNewPayable = bcsub($receivedValue, $creditApplied, 4);

                $supplier->current_credit = bcsub($creditBefore, $creditApplied, 4);
                $supplier->current_debt = bcadd(
                    (string) $supplier->current_debt, $netNewPayable, 4
                );
                $supplier->save();

                // ما يُدفع «عند هذا الاستلام» لا يتجاوز صافي ما نشأ بعد
                // استهلاك رصيدنا السابق عند المورد.
                $paidNow = trim((string) $request->input('paid_now', ''));
                $paidNow = $paidNow === '' ? '0' : $paidNow;
                if (bccomp($paidNow, $netNewPayable, 4) > 0) {
                    throw new \RuntimeException(
                        'المدفوع فوراً أكبر من صافي المستحق بعد رصيد المورد — استخدم سداداً مستقلاً لدين سابق');
                }

                SupplierLedgerEntry::create([
                    'entry_ulid' => (string) Str::ulid(),
                    'supplier_id' => $supplier->id,
                    'merchant_user_id' => $mid,
                    'entry_type' => 'po_receive',
                    'amount' => $receivedValue,
                    'cash_amount' => $paidNow,
                    'payment_method' => bccomp($paidNow, '0', 4) > 0
                        ? ($request->filled('cashier_shift_id') ? 'cash_shift' : 'cash_external')
                        : 'credit',
                    'debt_after' => (string) $supplier->current_debt,
                    'credit_after' => (string) ($supplier->current_credit ?? '0'),
                    'supplier_credit_applied' => $creditApplied,
                    'reference' => $po->po_number,
                    'note' => bccomp($creditApplied, '0', 4) > 0
                        ? 'استلام شراء — استُخدم '.$creditApplied.' من رصيد سابق لنا عند المورد'
                        : 'استلام بضاعة من أمر الشراء',
                ]);

                if (bccomp($paidNow, '0', 4) > 0) {
                    $cashierShift = $this->lockCashierShiftForOutflow(
                        $request, $mid, $paidNow
                    );

                    $supplier->current_debt =
                        bcsub((string) $supplier->current_debt, $paidNow, 4);
                    $supplier->save();

                    SupplierLedgerEntry::create([
                        'entry_ulid' => (string) Str::ulid(),
                        'supplier_id' => $supplier->id,
                        'merchant_user_id' => $mid,
                        'entry_type' => 'payment',
                        'amount' => $paidNow,
                        'cash_amount' => $paidNow,
                        'payment_method' => $cashierShift ? 'cash_shift' : 'cash_external',
                        'debt_after' => (string) $supplier->current_debt,
                        'credit_after' => (string) ($supplier->current_credit ?? '0'),
                        'reference' => $po->po_number,
                        'cashier_shift_id' => $cashierShift?->id,
                        'note' => $cashierShift
                            ? 'دفع نقدي عند الاستلام من درج الوردية #'.$cashierShift->id
                            : 'دفع نقدي عند الاستلام خارج درج نقاط البيع',
                    ]);

                    if ($cashierShift) {
                        app(\App\Services\Retail\MerchantShiftCashService::class)->record(
                            \App\Models\Retail\ShiftCashMovement::CASHIER,
                            $cashierShift->id,
                            $request->user(),
                            'out',
                            'supplier_payment',
                            $paidNow,
                            'دفع شراء للمورد '.$supplier->name,
                            $po->po_number,
                            $mid,
                        );
                    }
                }

                // تحديث حالة الأمر
                $po->load('items');
                $fully = $po->items->every(fn ($i) =>
                    bccomp((string) $i->received_quantity, (string) $i->quantity, 3) >= 0);
                $po->update($fully
                    ? ['status' => 'completed', 'completed_at' => now()]
                    : ['status' => 'partially_received']);

                return $po->fresh('items', 'supplier:id,name,current_debt,current_credit');
            });
        // **و`DomainException` تُلتقَط معها.** `StockService` يرميها
        // (`LogicException` لا `RuntimeException`)، ورسائلُها عربيّةٌ
        // للمشغّل — «الكمية غير كافية في الفرع الرئيسي». وتركُها تُفلت
        // يجعل رفضاً سليماً يخرج ٥٠٠ بالإنجليزيّة في وجه أمين المخزن،
        // وهو عينُ ما أمسكته الطبقةُ التاسعة من قبل.
        } catch (\RuntimeException|\DomainException $e) {
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

    // ============ مرتجعات الشراء (AMIAL-DAILY-MOVEMENT-001) ============

    /** GET /merchant/purchase-returns */
    public function prIndex(Request $request): JsonResponse
    {
        $mid = $request->user()->id;
        if ($err = $this->assertMerchant($mid)) return $err;

        $status = (string) $request->query('status', 'all');

        $q = \App\Models\PurchaseReturn::with(['items', 'supplier:id,name'])
            ->where('merchant_user_id', $mid)->orderByDesc('id');
        if ($status !== 'all') $q->where('status', $status);

        return $this->ok(['returns' => $q->limit(100)->get()]);
    }

    /** GET /merchant/purchase-returns/{id} */
    public function prShow(Request $request, int $id): JsonResponse
    {
        $r = \App\Models\PurchaseReturn::with(['items', 'supplier:id,name,current_debt'])
            ->where('id', $id)->where('merchant_user_id', $request->user()->id)->first();
        if (! $r) return $this->error('NOT_FOUND', 'المرتجع غير موجود', 404);

        return $this->ok(['return' => $r]);
    }

    /** POST /merchant/purchase-returns */
    public function prStore(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'supplier_id' => 'required|integer',
            'purchase_order_id' => 'sometimes|nullable|integer',
            'location_id' => 'sometimes|nullable|integer',
            'settlement_type' => 'sometimes|string|in:credit_note,cash_refund',
            'cashier_shift_id' => 'sometimes|nullable|integer|min:1',
            'reason' => 'sometimes|nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.purchase_order_item_id' => 'sometimes|nullable|integer',
            'items.*.product_id' => 'sometimes|nullable|integer',
            'items.*.name' => 'sometimes|nullable|string|max:200',
            'items.*.unit_cost' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $return = app(\App\Services\PurchaseReturnService::class)->create(
                $request->user(), (int) $request->input('supplier_id'),
                $request->input('items'), [
                    'purchase_order_id' => $request->input('purchase_order_id'),
                    'location_id' => $request->input('location_id'),
                    'settlement_type' => $request->input('settlement_type', 'credit_note'),
                    'cashier_shift_id' => $request->input('cashier_shift_id'),
                    'reason' => $request->input('reason'),
                    'actor_id' => $request->user()->id,
                ]);
        } catch (\DomainException|\RuntimeException $e) {
            return $this->error('PURCHASE_RETURN_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['return' => $return->load('items')], 'PURCHASE_RETURN_CREATED',
            'سُجّل طلب مرتجع الشراء — يُعتمد لتخرج البضاعة ويتحرّك حساب المورد', 201);
    }

    /** POST /merchant/purchase-returns/{id}/approve */
    public function prApprove(Request $request, int $id): JsonResponse
    {
        $r = \App\Models\PurchaseReturn::where('id', $id)
            ->where('merchant_user_id', $request->user()->id)->first();
        if (! $r) return $this->error('NOT_FOUND', 'المرتجع غير موجود', 404);

        try {
            $r = app(\App\Services\PurchaseReturnService::class)->approve($request->user(), $r);
        } catch (\DomainException|\RuntimeException $e) {
            return $this->error('APPROVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['return' => $r->load('items')], 'PURCHASE_RETURN_APPROVED',
            'اعتُمد المرتجع: خرجت البضاعة من المخزون وتحرّك حساب المورد');
    }

    /** POST /merchant/purchase-returns/{id}/reject */
    public function prReject(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500']);
        if ($v->fails()) return $this->validationError($v);

        $r = \App\Models\PurchaseReturn::where('id', $id)
            ->where('merchant_user_id', $request->user()->id)->first();
        if (! $r) return $this->error('NOT_FOUND', 'المرتجع غير موجود', 404);

        try {
            $r = app(\App\Services\PurchaseReturnService::class)
                ->reject($request->user(), $r, (string) $request->input('reason'));
        } catch (\DomainException|\RuntimeException $e) {
            return $this->error('REJECT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['return' => $r], 'PURCHASE_RETURN_REJECTED', 'رُفض المرتجع');
    }

    // ---- helpers ----

    /**
     * يربط خروج النقد بدرج وردية محددة فقط عندما يصرّح المستخدم بذلك.
     * لا نخمّن وردية من «آخر وردية مفتوحة»، لأن وجود أكثر من POS يجعل
     * التخمين خصماً من درج موظف آخر.
     */
    private function lockCashierShiftForOutflow(
        Request $request, int $merchantUserId, string $amount
    ): ?\App\Models\CashierShift {
        if (! $request->filled('cashier_shift_id')) {
            return null;
        }

        $shift = \App\Models\CashierShift::where('id', (int) $request->input('cashier_shift_id'))
            ->where('merchant_user_id', $merchantUserId)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if (! $shift) {
            throw new \RuntimeException('الوردية النقدية غير موجودة أو مغلقة');
        }

        $snapshot = app(\App\Services\CashierShiftService::class)->snapshot($shift);
        $expected = (string) ($snapshot['expected_cash'] ?? '0');
        if (bccomp($amount, $expected, 4) > 0) {
            throw new \RuntimeException(
                'رصيد الدرج المتوقع لا يكفي لهذا السداد — سجّل إيداعاً نقدياً أو اختر سداداً خارج الدرج'
            );
        }

        return $shift;
    }

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
