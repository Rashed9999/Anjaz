<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Merchant;
use App\Models\MerchantAssetDepreciation;
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

        $created = $this->withHeader('Idempotency-Key', 'asset-po-create')
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

        $this->withHeader('Idempotency-Key', 'asset-po-approve')
            ->postJson("/merchant/data/purchase-orders/{$poId}/approve")
            ->assertOk();
        $this->assertDatabaseCount('merchant_fixed_assets', 0,
            'اعتماد أمر الشراء وحده أنشأ أصلاً قبل الاستلام');

        $itemId = (int) PurchaseOrder::with('items')->findOrFail($poId)->items->first()->id;

        $this->withHeader('Idempotency-Key', 'asset-po-receive')
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

        $svc = app(MerchantFixedAssetService::class);
        $first = $svc->postDepreciationThrough($owner, now()->endOfMonth());
        $second = $svc->postDepreciationThrough($owner, now()->endOfMonth());

        $this->assertSame(1, $first['entries_posted']);
        $this->assertSame(0, $second['entries_posted'],
            'إعادة إثبات الشهر نفسه أنشأت إهلاكاً مكرراً');
        $this->assertDatabaseCount('merchant_asset_depreciations', 1);

        $dep = MerchantAssetDepreciation::where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $dep->amount, '1000', 4));
        $this->assertSame(0, bccomp((string) $dep->book_value_after, '79000', 4));
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

        // نجعل الدفتر يقرأ نفس الرصيد التشغيلي قبل الحركة؛ لا اختبار فوق drift.
        app(LedgerService::class)->openWalletBalance($owner->id, 'رصيد اختبار سداد المورد');
        app(LedgerService::class)->openWalletBalance($recipient->id, 'رصيد اختبار مستلم المورد');

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
