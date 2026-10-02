<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Merchant;
use App\Models\MerchantAssetDepreciation;
use App\Models\MerchantAssetAdjustment;
use App\Models\MerchantExpenseReversal;
use App\Models\MerchantExpense;
use App\Models\MerchantFixedAsset;
use App\Models\MerchantProfile;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\MerchantFixedAssetService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-ACCOUNTING-DEPTH-001
 *
 * يحرس الفرق بين أربعة أشياء لا يجوز دمجها:
 * مخزون، أصل ثابت، مصروف تشغيلي، ومال حقيقي في محفظة أميال.
 */
class MerchantAccountingDepthGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $phone = '967771240001'): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 1,
            'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH',
            'phone' => $phone,
            'sanction_status' => 'clear',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $m = new Merchant();
        $m->user_id = $u->id;
        $m->merchant_number = 'ACC-'.Str::upper(Str::random(8));
        $m->store_name = 'منشأة المحاسبة';
        $m->save();

        return $u->fresh();
    }

    public function test_furniture_purchase_creates_asset_not_expense_and_keeps_only_unpaid_supplier_debt(): void
    {
        $owner = $this->merchant();
        $supplier = Supplier::create([
            'merchant_user_id' => $owner->id,
            'name' => 'مورد الأثاث',
            'current_debt' => '0',
        ]);

        $this->actingAs($owner, 'merchant_web');

        $created = $this->withHeader('Idempotency-Key', 'guard-asset-po-create-2026')
            ->postJson('/merchant/data/purchase-orders', [
                'supplier_id' => $supplier->id,
                'items' => [[
                    'item_type' => 'fixed_asset',
                    'asset_category' => 'furniture',
                    'name' => 'أثاث مكتب',
                    'quantity' => '1',
                    'unit_cost' => '80000',
                    'salvage_value' => '20000',
                    'useful_life_months' => 60,
                ]],
            ])->assertCreated();

        $poId = (int) $created->json('meta.order.id');
        $this->assertDatabaseCount('merchant_fixed_assets', 0,
            'إنشاء أمر شراء وحده أنشأ أصلاً قبل أن يصل شيء إلى المنشأة');

        $this->withHeader('Idempotency-Key', 'guard-asset-po-approve-2026')
            ->postJson("/merchant/data/purchase-orders/{$poId}/approve")
            ->assertOk();
        $this->assertDatabaseCount('merchant_fixed_assets', 0,
            'اعتماد أمر الشراء وحده أنشأ أصلاً قبل الاستلام');

        $itemId = (int) PurchaseOrder::with('items')->findOrFail($poId)->items->first()->id;

        $this->withHeader('Idempotency-Key', 'guard-asset-po-receive-2026')
            ->postJson("/merchant/data/purchase-orders/{$poId}/receive", [
                'items' => [['item_id' => $itemId, 'received_quantity' => '1']],
                'paid_now' => '30000',
            ])
            ->assertOk()
            ->assertJsonPath('meta.order.supplier.current_debt', '50000.0000');

        $asset = MerchantFixedAsset::where('merchant_user_id', $owner->id)->firstOrFail();
        $this->assertSame('furniture', $asset->category);
        $this->assertSame(0, bccomp((string) $asset->acquisition_cost, '80000', 4));
        $this->assertSame(60, (int) $asset->useful_life_months);
        $this->assertSame($poId, (int) $asset->purchase_order_id);
        $this->assertDatabaseCount('merchant_expenses', 0,
            'الأثاث سُجّل مصروفاً تشغيلياً كاملاً بدل أصل ثابت');

        $supplier->refresh();
        $this->assertSame(0, bccomp((string) $supplier->current_debt, '50000', 4));
        $this->assertSame(
            ['po_receive', 'payment'],
            SupplierLedgerEntry::where('supplier_id', $supplier->id)
                ->orderBy('id')->pluck('entry_type')->all()
        );

        $asset->update([
            'acquired_on' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'depreciation_starts_on' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        ]);

        $svc = app(MerchantFixedAssetService::class);
        $closedMonth = now()->subMonthNoOverflow()->endOfMonth();
        $first = $svc->postDepreciationThrough($owner, $closedMonth);
        $second = $svc->postDepreciationThrough($owner, $closedMonth);

        $this->assertSame(1, $first['entries_posted']);
        $this->assertSame(0, $second['entries_posted'],
            'إعادة إثبات الشهر نفسه أنشأت إهلاكاً مكرراً');
        $this->assertDatabaseCount('merchant_asset_depreciations', 1);

        $dep = MerchantAssetDepreciation::where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $dep->amount, '1000', 4));
        $this->assertSame(0, bccomp((string) $dep->book_value_after, '79000', 4));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن إثبات إهلاك شهر لم يُغلق بعد');
        $svc->postDepreciationThrough($owner, now()->endOfMonth());
    }

    public function test_fixed_asset_return_preserves_history_and_reverses_only_returned_share(): void
    {
        $owner = $this->merchant('967771240040');
        $supplier = Supplier::create([
            'merchant_user_id' => $owner->id,
            'name' => 'مورد أصول',
            'current_debt' => '0',
            'current_credit' => '0',
        ]);

        $this->actingAs($owner, 'merchant_web');
        $created = $this->withHeader('Idempotency-Key', 'guard-asset-return-po-2026')
            ->postJson('/merchant/data/purchase-orders', [
                'supplier_id' => $supplier->id,
                'items' => [[
                    'item_type' => 'fixed_asset',
                    'asset_category' => 'furniture',
                    'name' => 'كرسيان مكتبيان',
                    'quantity' => '2',
                    'unit_cost' => '40000',
                    'salvage_value' => '10000',
                    'useful_life_months' => 60,
                ]],
            ])->assertCreated();

        $poId = (int) $created->json('meta.order.id');
        $this->withHeader('Idempotency-Key', 'guard-asset-return-approve-2026')
            ->postJson("/merchant/data/purchase-orders/{$poId}/approve")->assertOk();
        $item = PurchaseOrder::with('items')->findOrFail($poId)->items->first();

        $this->withHeader('Idempotency-Key', 'guard-asset-return-receive-2026')
            ->postJson("/merchant/data/purchase-orders/{$poId}/receive", [
                'items' => [['item_id' => $item->id, 'received_quantity' => '2']],
            ])->assertOk();

        $asset = MerchantFixedAsset::where('purchase_order_item_id', $item->id)->firstOrFail();
        $asset->update([
            'acquired_on' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'depreciation_starts_on' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        ]);

        app(MerchantFixedAssetService::class)->postDepreciationThrough(
            $owner, now()->subMonthNoOverflow()->endOfMonth()
        );

        $this->assertSame('1000.0000',
            (string) MerchantAssetDepreciation::where('asset_id', $asset->id)->value('amount'));

        $ret = $this->withHeader('Idempotency-Key', 'guard-asset-return-create-2026')
            ->postJson('/merchant/data/purchase-returns', [
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $poId,
                'settlement_type' => 'credit_note',
                'reason' => 'إرجاع كرسي واحد للمورد',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'quantity' => '1',
                ]],
            ])->assertCreated();

        $returnId = (int) $ret->json('meta.return.id');
        $this->withHeader('Idempotency-Key', 'guard-asset-return-finalize-2026')
            ->postJson("/merchant/data/purchase-returns/{$returnId}/approve")
            ->assertOk();

        $asset->refresh();
        $this->assertSame('80000.0000', (string) $asset->acquisition_cost,
            'تكلفة الاقتناء التاريخية كُتبت فوقها بعد المرتجع');

        $adj = MerchantAssetAdjustment::where('asset_id', $asset->id)
            ->where('purchase_return_id', $returnId)->firstOrFail();
        $this->assertSame(0, bccomp((string) $adj->quantity, '1', 3));
        $this->assertSame(0, bccomp((string) $adj->cost_amount, '40000', 4));
        $this->assertSame(0, bccomp((string) $adj->depreciation_reversed, '500', 4));

        $view = app(MerchantFixedAssetService::class)->show($owner, $asset->id)['asset'];
        $this->assertSame('1.000', $view['active_quantity']);
        $this->assertSame('40000.0000', $view['carrying_cost_basis']);
        $this->assertSame('500.0000', $view['accumulated_depreciation']);
        $this->assertSame('39500.0000', $view['book_value']);
    }

    public function test_supplier_credit_survives_excess_return_then_nets_future_purchase_and_collects_into_exact_drawer(): void
    {
        $owner = $this->merchant('967771240041');
        $supplier = Supplier::create([
            'merchant_user_id' => $owner->id,
            'name' => 'مورد رصيد',
            'current_debt' => '0',
            'current_credit' => '0',
        ]);

        $this->actingAs($owner, 'merchant_web');

        $makeOrder = function (string $key, string $amount) use ($supplier) {
            $created = $this->withHeader('Idempotency-Key', $key.'-create')
                ->postJson('/merchant/data/purchase-orders', [
                    'supplier_id' => $supplier->id,
                    'items' => [[
                        'item_type' => 'other',
                        'name' => 'بند غير مخزني',
                        'quantity' => '1',
                        'unit_cost' => $amount,
                    ]],
                ])->assertCreated();
            $poId = (int) $created->json('meta.order.id');
            $this->withHeader('Idempotency-Key', $key.'-approve')
                ->postJson("/merchant/data/purchase-orders/{$poId}/approve")->assertOk();
            $item = PurchaseOrder::with('items')->findOrFail($poId)->items->first();
            return [$poId, $item];
        };

        [$po1, $item1] = $makeOrder('credit-po1', '80000');
        $this->withHeader('Idempotency-Key', 'guard-credit-receive1-2026')
            ->postJson("/merchant/data/purchase-orders/{$po1}/receive", [
                'items' => [['item_id' => $item1->id, 'received_quantity' => '1']],
                'paid_now' => '30000',
            ])->assertOk();

        $ret = $this->withHeader('Idempotency-Key', 'guard-credit-return-2026')
            ->postJson('/merchant/data/purchase-returns', [
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $po1,
                'settlement_type' => 'credit_note',
                'reason' => 'إعادة كامل البند',
                'items' => [[
                    'purchase_order_item_id' => $item1->id,
                    'quantity' => '1',
                ]],
            ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'guard-credit-return-approve-2026')
            ->postJson('/merchant/data/purchase-returns/'.$ret->json('meta.return.id').'/approve')
            ->assertOk();

        $supplier->refresh();
        $this->assertSame('0.0000', (string) $supplier->current_debt);
        $this->assertSame('30000.0000', (string) $supplier->current_credit,
            'فائض المرتجع ضاع بدل أن يصبح حقاً للتاجر عند المورد');

        [$po2, $item2] = $makeOrder('credit-po2', '20000');
        $this->withHeader('Idempotency-Key', 'guard-credit-receive2-2026')
            ->postJson("/merchant/data/purchase-orders/{$po2}/receive", [
                'items' => [['item_id' => $item2->id, 'received_quantity' => '1']],
            ])->assertOk();

        $supplier->refresh();
        $this->assertSame('0.0000', (string) $supplier->current_debt);
        $this->assertSame('10000.0000', (string) $supplier->current_credit);

        $receive = SupplierLedgerEntry::where('reference', PurchaseOrder::findOrFail($po2)->po_number)
            ->where('entry_type', 'po_receive')->firstOrFail();
        $this->assertSame('20000.0000', (string) $receive->supplier_credit_applied);

        $report = app(\App\Services\MerchantFinancialTruthReportService::class)
            ->report($owner);
        $purchase = collect($report['movement']['rows'])->firstWhere('code', 'purchase');
        $this->assertNotNull($purchase);
        $this->assertTrue(bccomp((string) $purchase['supplier_credit'], '20000', 4) >= 0);
        $this->assertSame('daily-movement/v2', $report['movement']['contract']);

        $shift = app(\App\Services\CashierShiftService::class)
            ->open($owner, null, '1000');

        $this->withHeader('Idempotency-Key', 'guard-collect-credit-2026')
            ->postJson("/merchant/data/suppliers/{$supplier->id}/credit-refund", [
                'amount' => '10000',
                'cashier_shift_id' => $shift->id,
            ])->assertOk();

        $supplier->refresh();
        $this->assertSame('0.0000', (string) $supplier->current_credit);
        $this->assertDatabaseHas('merchant_shift_cash_movements', [
            'shift_type' => 'cashier',
            'shift_id' => $shift->id,
            'reason' => 'supplier_refund',
            'direction' => 'in',
        ]);

        $snapshot = app(\App\Services\CashierShiftService::class)->snapshot($shift->fresh());
        $this->assertSame(0, bccomp((string) $snapshot['expected_cash'], '11000', 4));
    }

    public function test_expense_void_is_an_append_only_reversal_not_history_deletion(): void
    {
        $owner = $this->merchant('967771240042');
        $this->actingAs($owner, 'merchant_web');

        $created = $this->withHeader('Idempotency-Key', 'guard-expense-create-2026')
            ->postJson('/merchant/data/expenses', [
                'title' => 'كهرباء المكتب',
                'amount' => '12000',
                'category' => 'utilities',
                'spent_on' => now()->subDay()->toDateString(),
            ])->assertCreated();

        $expenseId = (int) $created->json('meta.expense.id');

        $this->withHeader('Idempotency-Key', 'guard-expense-void-2026')
            ->deleteJson("/merchant/data/expenses/{$expenseId}", [
                'reason' => 'قيد مكرر بالخطأ',
            ])->assertOk();

        $expense = MerchantExpense::findOrFail($expenseId);
        $this->assertSame('voided', $expense->status);
        $this->assertDatabaseHas('merchant_expenses', ['id' => $expenseId],
            'إلغاء المصروف حذف الأصل التاريخي');

        $reversal = MerchantExpenseReversal::where('expense_id', $expenseId)->firstOrFail();
        $this->assertSame('12000.0000', (string) $reversal->amount);

        // إعادة الإلغاء لا تخلق عكساً ثانياً.
        $this->withHeader('Idempotency-Key', 'guard-expense-void-again-2026')
            ->deleteJson("/merchant/data/expenses/{$expenseId}", [
                'reason' => 'إعادة إرسال نفس قرار الإلغاء',
            ])->assertOk();

        $this->assertSame(1, MerchantExpenseReversal::where('expense_id', $expenseId)->count());

        $report = app(\App\Services\CashierService::class)->profitReport($owner, 7);
        $this->assertSame('12000.0000', $report['totals']['gross_cash_operating_expenses']);
        $this->assertSame('12000.0000', $report['totals']['expense_reversals']);
        $this->assertSame('0.0000', $report['totals']['cash_operating_expenses']);
    }

    public function test_asset_disposal_freezes_book_value_and_puts_cash_in_only_the_selected_drawer(): void
    {
        $owner = $this->merchant('967771240043');
        $asset = app(MerchantFixedAssetService::class)->createOpening($owner, [
            'name' => 'حاسوب مكتبي',
            'category' => 'computer',
            'quantity' => '1',
            'acquisition_cost' => '12000',
            'salvage_value' => '0',
            'useful_life_months' => 12,
            'acquired_on' => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
            'depreciation_starts_on' => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
        ]);

        app(MerchantFixedAssetService::class)->postDepreciationThrough(
            $owner, now()->subMonthNoOverflow()->endOfMonth()
        );

        $shift = app(\App\Services\CashierShiftService::class)
            ->open($owner, null, '500');

        $this->actingAs($owner, 'merchant_web')
            ->withHeader('Idempotency-Key', 'guard-asset-dispose-2026')
            ->postJson("/merchant/data/assets/{$asset->id}/dispose", [
                'disposed_on' => now()->toDateString(),
                'disposal_proceeds' => '9000',
                'cashier_shift_id' => $shift->id,
                'reason' => 'بيع الجهاز بعد استبداله',
            ])->assertOk();

        $asset->refresh();
        $this->assertSame('disposed', $asset->status);
        $this->assertSame('10000.0000', (string) $asset->disposal_book_value);
        $this->assertSame('-1000.0000', (string) $asset->disposal_gain_loss);
        $this->assertSame('cash_shift', $asset->disposal_payment_source);

        $view = app(MerchantFixedAssetService::class)->show($owner, $asset->id)['asset'];
        $this->assertSame('0.0000', $view['book_value'],
            'أصل مستبعد ما زال داخل القيمة الدفترية الحالية');
        $this->assertSame('10000.0000', $view['book_value_before_removal']);

        $this->assertDatabaseHas('merchant_shift_cash_movements', [
            'shift_type' => 'cashier',
            'shift_id' => $shift->id,
            'reason' => 'asset_disposal_proceeds',
            'direction' => 'in',
        ]);
        $snapshot = app(\App\Services\CashierShiftService::class)->snapshot($shift->fresh());
        $this->assertSame(0, bccomp((string) $snapshot['expected_cash'], '9500', 4));
    }

    public function test_supplier_wallet_payment_moves_real_wallet_money_once_and_reduces_debt_once(): void
    {
        $owner = $this->merchant('967771240010');
        $recipient = $this->merchant('967771240011');

        EMoney::whereIn('user_id', [$owner->id, $recipient->id])->delete();
        EMoney::create([
            'user_id' => $owner->id, 'current_balance' => '1000.0000',
            'pending_balance' => '0', 'held_balance' => '0', 'charge_earned' => '0',
            'zone_code' => 'SOUTH', 'version' => 0,
        ]);
        EMoney::create([
            'user_id' => $recipient->id, 'current_balance' => '0.0000',
            'pending_balance' => '0', 'held_balance' => '0', 'charge_earned' => '0',
            'zone_code' => 'SOUTH', 'version' => 0,
        ]);

        // نمول الدفتر نفسه أولاً ثم نجعل المحفظة التشغيلية تطابقه؛
        // لا Opening فوق حساب له تاريخ ولا تعديل مباشر لدفتر قائم.
        $ledger = app(LedgerService::class);
        $ownerWallet = $ledger->getOrCreateUserWallet($owner->id);
        $recipientWallet = $ledger->getOrCreateUserWallet($recipient->id);
        $funding = $ledger->getOrCreateSystemAccount(
            'TEST_SUPPLIER_FUNDING', 'asset', 'تمويل اختبار سداد المورد', 'debit'
        );
        $ledger->post(
            sourceType: 'test_supplier_funding',
            sourceId: 'SUPPLIER-WALLET-FUND-001',
            description: 'تمويل اختبار سداد المورد',
            lines: [
                ['account' => $funding->account_code, 'direction' => 'debit', 'amount' => '1000'],
                ['account' => $ownerWallet->account_code, 'direction' => 'credit', 'amount' => '1000'],
            ],
            allowNegative: true,
        );

        FeeScheme::create([
            'code' => 'SUPPLIER_PAYMENT',
            'label' => 'سداد مورد من المحفظة',
            'zone_code' => 'SOUTH',
            'applies_to' => 'merchant',
            'fee_type' => 'fixed',
            'percent_rate' => '0',
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'merchant',
            'version' => 1,
            'is_active' => true,
            'effective_from' => now()->subMinute(),
        ]);

        $supplier = Supplier::create([
            'merchant_user_id' => $owner->id,
            'amial_user_id' => $recipient->id,
            'name' => 'مورد أميال',
            'phone' => $recipient->phone,
            'current_debt' => '500.0000',
        ]);
        SupplierLedgerEntry::create([
            'entry_ulid' => (string) Str::ulid(),
            'supplier_id' => $supplier->id,
            'merchant_user_id' => $owner->id,
            'entry_type' => 'opening',
            'amount' => '500.0000',
            'debt_after' => '500.0000',
        ]);

        $this->actingAs($owner, 'merchant_web');

        $first = $this->withHeader('Idempotency-Key', 'supplier-wallet-pay-1')
            ->postJson("/merchant/data/suppliers/{$supplier->id}/wallet-payment", [
                'amount' => '300',
                'note' => 'دفعة للمورد',
            ])->assertOk()
            ->assertJsonPath('code', 'SUPPLIER_WALLET_PAYMENT_COMPLETED')
            ->assertJsonPath('meta.fee', '0.0000');

        $tx = (string) $first->json('meta.transaction_id');
        $this->assertNotSame('', $tx);

        $this->assertSame('700.0000',
            (string) EMoney::where('user_id', $owner->id)->value('current_balance'));
        $this->assertSame('300.0000',
            (string) EMoney::where('user_id', $recipient->id)->value('current_balance'));
        $this->assertSame('200.0000',
            (string) Supplier::whereKey($supplier->id)->value('current_debt'));

        $payment = SupplierLedgerEntry::where('supplier_id', $supplier->id)
            ->where('entry_type', 'payment')->firstOrFail();
        $this->assertSame('amial_pay', $payment->payment_method);
        $this->assertSame($tx, $payment->transaction_id);

        $this->assertDatabaseHas('ledger_journal_entries', [
            'source_type' => 'supplier_payment',
        ]);

        // نفس المفتاح: لا مال ثانٍ ولا خفض ثانٍ للدين.
        $this->withHeader('Idempotency-Key', 'supplier-wallet-pay-1')
            ->postJson("/merchant/data/suppliers/{$supplier->id}/wallet-payment", [
                'amount' => '300',
                'note' => 'دفعة للمورد',
            ])->assertOk();

        $this->assertSame('700.0000',
            (string) EMoney::where('user_id', $owner->id)->value('current_balance'));
        $this->assertSame('300.0000',
            (string) EMoney::where('user_id', $recipient->id)->value('current_balance'));
        $this->assertSame('200.0000',
            (string) Supplier::whereKey($supplier->id)->value('current_debt'));
        $this->assertSame(1, SupplierLedgerEntry::where('supplier_id', $supplier->id)
            ->where('entry_type', 'payment')->count());
    }

    public function test_purchase_and_supplier_payment_documents_are_publicly_verifiable_without_party_pii(): void
    {
        $owner = $this->merchant('967771240020');
        $supplier = Supplier::create([
            'merchant_user_id' => $owner->id,
            'name' => 'اسم مورد خاص',
            'phone' => '967771240099',
            'current_debt' => '100',
        ]);
        $po = PurchaseOrder::create([
            'document_ulid' => (string) Str::ulid(),
            'po_number' => 'PO-VERIFY-001',
            'merchant_user_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'status' => 'approved',
            'total_amount' => '80000',
        ]);
        $payment = SupplierLedgerEntry::create([
            'entry_ulid' => (string) Str::ulid(),
            'supplier_id' => $supplier->id,
            'merchant_user_id' => $owner->id,
            'entry_type' => 'payment',
            'amount' => '100',
            'payment_method' => 'cash_external',
            'debt_after' => '0',
        ]);

        $poPage = $this->get('/v/'.$po->document_ulid)->assertOk()->getContent();
        $this->assertStringContainsString('أمر شراء', $poPage);
        $this->assertStringNotContainsString('اسم مورد خاص', $poPage);
        $this->assertStringNotContainsString('967771240099', $poPage);

        $payPage = $this->get('/v/'.$payment->entry_ulid)->assertOk()->getContent();
        $this->assertStringContainsString('سند سداد مورد', $payPage);
        $this->assertStringNotContainsString('اسم مورد خاص', $payPage);
        $this->assertStringNotContainsString('967771240099', $payPage);
    }

    public function test_procurement_pdf_endpoints_use_cached_server_documents_and_never_money_writes(): void
    {
        $owner = $this->merchant('967771240030');
        $supplier = Supplier::create([
            'merchant_user_id' => $owner->id, 'name' => 'مورد PDF', 'current_debt' => '50',
        ]);
        $po = PurchaseOrder::create([
            'document_ulid' => (string) Str::ulid(),
            'po_number' => 'PO-PDF-001',
            'merchant_user_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'status' => 'approved',
            'total_amount' => '50',
        ]);
        $payment = SupplierLedgerEntry::create([
            'entry_ulid' => (string) Str::ulid(),
            'supplier_id' => $supplier->id,
            'merchant_user_id' => $owner->id,
            'entry_type' => 'payment',
            'amount' => '50',
            'payment_method' => 'cash_external',
            'debt_after' => '0',
        ]);

        $this->actingAs($owner, 'merchant_web');

        foreach ([
            "/merchant/data/purchase-orders/{$po->id}/pdf",
            "/merchant/data/suppliers/{$supplier->id}/statement/pdf",
            "/merchant/data/supplier-payments/{$payment->entry_ulid}/pdf",
        ] as $url) {
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }

        $this->assertSame(0, MerchantExpense::where('merchant_user_id', $owner->id)->count());
        $this->assertSame('50.0000', (string) Supplier::whereKey($supplier->id)->value('current_debt'),
            'تنزيل PDF حرّك مديونية المورد');
    }
}
