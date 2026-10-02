<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\Branch;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** فصل جلسة التاجر عن المنصة وPOS، ثم حماية الوحدات والدفتر. */
class MerchantWebPortalTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $u = User::factory()->create([
            'role' => A::ROLE_MERCHANT, 'type' => MERCHANT_TYPE,
            'is_active' => 1, 'phone' => '967771098765',
            'email' => 'merchant-web@example.test',
            'password' => Hash::make('Merchant!2026'),
        ]);
        MerchantProfile::create([
            'user_id' => $u->id, 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE, 'verification_status' => 'verified',
        ]);
        $store = new Merchant();
        $store->user_id = $u->id;
        $store->merchant_number = 'WEB-101';
        $store->store_name = 'متجر التجربة';
        $store->save();

        return $u->fresh();
    }

    public function test_merchant_can_sign_in_with_merchant_number_and_open_owner_dashboard(): void
    {
        $this->owner();
        $this->post('/merchant/login', [
            'identifier' => 'WEB-101', 'password' => 'Merchant!2026',
        ])->assertRedirect(route('merchant.web.dashboard'));

        $this->get('/merchant')->assertOk()->assertSee('متجر التجربة');
        $this->getJson('/merchant/data/overview')->assertOk()
            ->assertJsonPath('meta.merchant.business_name', 'متجر التجربة');
    }

    public function test_merchant_dashboard_mobile_navigation_is_accessible_and_tables_scroll(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'merchant_web')
            ->get('/merchant')
            ->assertOk()
            ->assertSee('id="menu-toggle"', false)
            ->assertSee('aria-controls="merchant-side"', false)
            ->assertSee('id="nav-backdrop"', false)
            ->assertSee('@media(max-width:1199px)', false)
            ->assertSee("e.key==='Escape'", false)
            ->assertSee('جدول قابل للتمرير أفقياً', false);
    }

    /** نقطة البيع معالجٌ فوق الفرع والموظف والجهاز في النسخة المنشورة فعلياً. */
    public function test_owner_portal_exposes_guided_pos_setup_in_deployed_view(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'merchant_web')
            ->get('/merchant')
            ->assertOk()
            ->assertSee('إعداد نقطة بيع')
            ->assertSee('async function posSetup()', false)
            ->assertSee("json.data&&typeof json.data==='object'", false)
            ->assertSee('branch_id', false)
            ->assertSee("const selectedBranch=normaliseBranch(branchSelect.value)", false)
            ->assertSee("staffSelect.disabled=!rows.length", false);
    }

    /** إنشاء موظف الويب يجب أن يكتب حساباً فعلياً لا أن يكتفي بنموذج واجهة. */
    public function test_owner_can_create_pos_employee_from_web_portal(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'merchant_web')
            ->postJson('/merchant/data/staff', [
                'employee_code' => 'WEB-POS-01',
                'display_name' => 'موظف الويب',
                'password' => 'TempPass2026',
            ])
            ->assertCreated()
            ->assertJsonPath('code', 'STAFF_CREATED')
            ->assertJsonPath('meta.employee_code', 'WEB-POS-01');

        $this->assertDatabaseHas('pos_users', [
            'merchant_user_id' => $owner->id,
            'pos_number' => 'WEB-POS-01',
            'display_name' => 'موظف الويب',
            'is_active' => 1,
        ]);
    }

    /** الفرع الموقوف لا يقبل رمز جهاز جديد. */
    public function test_pos_activation_code_rejects_inactive_branch(): void
    {
        $owner = $this->owner();
        $branch = Branch::create([
            'merchant_user_id' => $owner->id,
            'name' => 'فرع موقوف',
            'code' => 'STOP-POS',
            'is_active' => false,
            'is_default' => false,
        ]);

        $this->actingAs($owner, 'merchant_web')
            ->postJson('/merchant/data/devices/activation-codes', [
                'display_name' => 'جهاز موقوف',
                'branch_id' => $branch->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'BRANCH_NOT_FOUND');
    }

    public function test_owner_without_a_vertical_can_choose_it_from_the_web_portal(): void
    {
        $owner = $this->owner();
        MerchantProfile::where('user_id', $owner->id)->update(['business_type' => null]);

        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/sector/types')
            ->assertOk()
            ->assertJsonFragment(['code' => A::BIZ_PHARMACY]);

        $this->putJson('/merchant/data/sector/type', ['business_type' => A::BIZ_PHARMACY])
            ->assertOk()
            ->assertJsonPath('code', 'BUSINESS_TYPE_UPDATED')
            ->assertJsonPath('meta.business_type', A::BIZ_PHARMACY);

        $this->assertSame(A::BIZ_PHARMACY,
            MerchantProfile::where('user_id', $owner->id)->value('business_type'));
    }

    public function test_wallet_and_credit_pages_use_owner_only_shared_data_routes(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'merchant_web')
            ->get('/merchant')
            ->assertOk()
            ->assertSee('id="portal-nav"', false)
            ->assertSee('walletVerification')
            ->assertSee('walletOrigins')
            ->assertSee('debtInvoices')
            ->assertSee('debtStatementPdf');

        $this->getJson('/merchant/data/wallet-verification')
            ->assertOk()->assertJsonStructure(['meta' => ['verification' => [
                'state', 'operational_balance', 'ledger_balance', 'gap',
            ]]]);

        $this->getJson('/merchant/data/wallet-origins')->assertOk()
            ->assertJsonStructure(['meta' => ['available', 'verification', 'sources', 'summary']]);

        $this->getJson('/merchant/data/debts')->assertOk()
            ->assertJsonStructure(['meta' => ['total_due', 'debtors_count']]);

        $this->getJson('/merchant/data/debts/customers')->assertOk()
            ->assertJsonStructure(['meta' => ['customers', 'pagination']]);
    }


    /** Group real posted journal lines; other merchants never enter this owner endpoint. */
    public function test_owner_can_trace_wallet_funds_to_posted_sources_without_guessing_sales(): void
    {
        $owner = $this->owner();
        $sender = User::factory()->create(['type' => 2, 'role' => 'user']);
        $ledger = app(\App\Services\LedgerService::class);
        $ledger->getOrCreateUserWallet($owner->id);
        $ledger->getOrCreateUserWallet($sender->id);
        foreach (['send_money' => '500', 'payment_request' => '200'] as $source => $amount) {
            $ledger->post(
                sourceType: $source,
                sourceId: 'WALLET-ORIGINS-' . $source,
                description: 'قيد اختبار مصدر الرصيد',
                lines: [
                    ['account' => 'USER_WALLET_' . $sender->id, 'direction' => 'debit', 'amount' => $amount],
                    ['account' => 'USER_WALLET_' . $owner->id, 'direction' => 'credit', 'amount' => $amount],
                ],
                allowNegative: true,
            );
        }
        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/wallet-origins')
            ->assertOk()
            ->assertJsonPath('meta.available', true)
            ->assertJsonPath('meta.summary.posted_in', '700.0000')
            ->assertJsonPath('meta.summary.posted_out', '0.0000')
            ->assertJsonCount(2, 'meta.sources');
        $this->getJson('/merchant/data/ledger?source_type=send_money')
            ->assertOk()->assertJsonCount(1, 'meta.entries')
            ->assertJsonPath('meta.entries.0.source_type', 'send_money');
        $this->getJson('/merchant/data/ledger?source_type=payment_request')
            ->assertOk()->assertJsonCount(1, 'meta.entries')
            ->assertJsonPath('meta.entries.0.source_type', 'payment_request');
    }

    public function test_web_and_pos_read_same_credit_account_and_guard_other_merchants(): void
    {
        $owner = $this->owner();
        $account = app(\App\Services\CustomerCreditService::class)->findOrCreateAccount(
            $owner->id, '967771889900', 'عميل التجربة', '5000'
        );
        app(\App\Services\CustomerCreditService::class)->recordSale(
            $account, '1500', referenceNumber: 'WEB-INV-1'
        );

        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/debts')->assertOk()
            ->assertJsonPath('meta.total_due', '1500.0000');
        $this->getJson('/merchant/data/debts/customers')->assertOk()
            ->assertJsonPath('meta.customers.0.customer_name', 'عميل التجربة');
        $this->getJson('/merchant/data/debts/customers/'.$account->id.'/invoices')->assertOk()
            ->assertJsonPath('meta.invoices_total', '1500.0000')
            ->assertJsonPath('meta.invoices.0.reference_number', 'WEB-INV-1');
        $this->getJson('/merchant/data/debts/customers/'.$account->id.'/statement')->assertOk()
            ->assertJsonPath('meta.movements.0.amount', '1500.0000');

        $other = User::factory()->create([
            'role' => A::ROLE_MERCHANT, 'type' => MERCHANT_TYPE, 'is_active' => 1,
        ]);
        MerchantProfile::create([
            'user_id' => $other->id, 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
        ]);
        $this->actingAs($other, 'merchant_web')
            ->getJson('/merchant/data/debts/customers/'.$account->id.'/invoices')
            ->assertNotFound();
    }

    public function test_customer_and_pos_cannot_read_merchant_credit_book_or_wallet_truth(): void
    {
        $this->owner();
        foreach ([
            ['type' => 2, 'role' => 'user'],
            ['type' => 4, 'role' => 'pos'],
        ] as $identity) {
            $user = User::factory()->create($identity);

            // بعض حرّاس البوابة ينهون جلسة الهوية غير المسموح بها بعد
            // أول رفض. نعيد المصادقة لكل باب حتى نقيس تفويض كل endpoint
            // نفسه (403)، لا أثر رفض الباب السابق (401).
            foreach ([
                '/merchant/data/wallet-verification',
                '/merchant/data/debts',
                '/merchant/data/wallet-origins',
            ] as $endpoint) {
                $this->actingAs($user, 'merchant_web')
                    ->getJson($endpoint)
                    ->assertForbidden();
            }
        }
    }

    public function test_platform_session_does_not_grant_merchant_session(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'user')
            ->getJson('/merchant/data/wallet')->assertUnauthorized();
    }

    public function test_customer_and_pos_cannot_access_merchant_wallet_even_on_merchant_guard(): void
    {
        $this->owner();
        foreach ([
            ['type' => 2, 'role' => 'user'],
            ['type' => 4, 'role' => 'pos'],
            ['type' => 0, 'role' => 'admin'],
        ] as $identity) {
            $u = User::factory()->create($identity);
            $this->actingAs($u, 'merchant_web')
                ->getJson('/merchant/data/wallet')->assertForbidden();
        }
    }

    public function test_owner_portal_exposes_customer_directory_separate_from_debts(): void
    {
        $owner = $this->owner();
        app(\App\Services\CustomerCreditService::class)->findOrCreateAccount(
            $owner->id, '967777123456', 'عميل نقدي وآجل', '10000'
        );

        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/sector/customers')
            ->assertOk()
            ->assertJsonPath('meta.sector', A::BIZ_RETAIL)
            ->assertJsonPath('meta.result.customers.0.customer_name', 'عميل نقدي وآجل');

        $this->get('/merchant')
            ->assertOk()
            ->assertSee('async function customers()', false)
            ->assertSee('قاعدة بيانات العملاء')
            ->assertSee('staffPerformance')
            ->assertSee('إنشاء دور مخصص');
    }

    public function test_owner_wallet_and_staff_data_remain_scoped_to_own_account(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/stats')->assertOk()
            ->assertJsonStructure(['meta' => ['current_balance', 'today_sales']]);
        $this->getJson('/merchant/data/ledger')->assertOk()
            ->assertJsonStructure(['meta' => ['account', 'entries']]);
    }

    public function test_all_merchant_data_routes_require_owner_and_writes_keep_plan_gates(): void
    {
        foreach (['overview', 'dashboard-v2', 'sector.customers', 'sector.sales', 'sector.sales.show', 'sector.sales.invoice', 'stats', 'profit-report', 'wallet', 'ledger', 'wallet.origins', 'products',
                  'branches', 'roles', 'staff', 'staff.performance', 'devices', 'receipts'] as $endpoint) {
            $route = Route::getRoutes()->getByName('merchant.web.data.' . $endpoint);
            $this->assertNotNull($route, $endpoint . ' not registered');
            $this->assertContains('merchant.web', $route->gatherMiddleware());
        }
        foreach (['profit-report' => 'capability:profit_reports',
                  'products.create' => 'capability:products',
                  'suppliers.store' => 'capability:suppliers',
                  'purchase-orders.store' => 'capability:purchases',
                  'purchase-returns.store' => 'capability:purchases',
                  'expenses.store' => 'capability:expenses',
                  'branches.create' => 'capability:branches',
                  'staff.create' => 'capability:employees',
                  'devices.activate' => 'capability:multi_pos'] as $endpoint => $cap) {
            $this->assertContains($cap,
                Route::getRoutes()->getByName('merchant.web.data.' . $endpoint)->gatherMiddleware());
        }
    }

    public function test_portal_navigation_and_sector_data_follow_the_actual_vertical_and_entitlement(): void
    {
        $owner = $this->owner();
        MerchantProfile::where('user_id', $owner->id)->update([
            'business_type' => A::BIZ_FUEL,
            'subscription_plan' => A::PLAN_FREE,
        ]);

        $this->actingAs($owner, 'merchant_web')
            ->get('/merchant')
            ->assertOk()
            ->assertSee('id="portal-nav"', false);

        // منتجات الوقود مدفوعة؛ بوابة الويب لا يجوز أن تتجاوز الحارس
        // بمجرد استدعائها المباشر لمحرك القطاع.
        $this->getJson('/merchant/data/sector/products')
            ->assertStatus(402)
            ->assertJsonPath('code', 'SECTOR_CAPABILITY_DENIED');
    }

    /**
     * المالك Web-only يجب أن ينفذ رحلة المورد والمشتريات من البوابة نفسها.
     * المثال المحاسبي: شراء بـ 80,000 ودفع 30,000 فوراً = دين مورد 50,000.
     */
    public function test_owner_web_procurement_records_cash_and_supplier_debt_without_flutter(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'merchant_web')
            ->get('/merchant')
            ->assertOk()
            ->assertSee('الموردون والمشتريات')
            ->assertSee('المصروفات')
            ->assertSee('async function suppliers()', false)
            ->assertSee('async function expenses()', false);

        $supplier = $this->withHeader('Idempotency-Key', 'guard-mw-supplier-1-2026')
            ->postJson('/merchant/data/suppliers', [
                'name' => 'مورد الأثاث',
                'phone' => '967777000001',
            ])
            ->assertCreated()
            ->json('meta.supplier');

        $order = $this->withHeader('Idempotency-Key', 'guard-mw-po-1-2026')
            ->postJson('/merchant/data/purchase-orders', [
                'supplier_id' => $supplier['id'],
                'items' => [[
                    'name' => 'أثاث مكتبي',
                    'quantity' => '1',
                    'unit_cost' => '80000',
                ]],
            ])
            ->assertCreated()
            ->json('meta.order');

        $this->withHeader('Idempotency-Key', 'guard-mw-po-approve-1-2026')
            ->postJson('/merchant/data/purchase-orders/'.$order['id'].'/approve')
            ->assertOk();

        $item = $this->getJson('/merchant/data/purchase-orders/'.$order['id'])
            ->assertOk()
            ->json('meta.order.items.0');

        $this->withHeader('Idempotency-Key', 'guard-mw-po-receive-1-2026')
            ->postJson('/merchant/data/purchase-orders/'.$order['id'].'/receive', [
                'items' => [[
                    'item_id' => $item['id'],
                    'received_quantity' => '1',
                ]],
                'paid_now' => '30000',
            ])
            ->assertOk()
            ->assertJsonPath('meta.order.supplier.current_debt', '50000.0000');

        $this->getJson('/merchant/data/suppliers/'.$supplier['id'])
            ->assertOk()
            ->assertJsonPath('meta.supplier.current_debt', '50000.0000')
            ->assertJsonCount(2, 'meta.ledger');

        $this->withHeader('Idempotency-Key', 'guard-mw-expense-1-2026')
            ->postJson('/merchant/data/expenses', [
                'title' => 'كهرباء الفرع',
                'amount' => '12000',
                'category' => 'utilities',
                'spent_on' => now()->toDateString(),
            ])
            ->assertCreated();

        $this->getJson('/merchant/data/expenses')
            ->assertOk()
            ->assertJsonPath('meta.total', '12000.0000');
    }

    public function test_bearer_token_cannot_change_the_merchant_web_entitlement_identity(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'merchant_web')
            ->withHeader('Authorization', 'Bearer unrelated-pos-token')
            ->getJson('/merchant/data/wallet')->assertForbidden()
            ->assertJsonPath('code', 'WEB_SESSION_ONLY');
    }

    public function test_optional_merchant_host_redirects_get_and_rejects_post_without_losing_payload(): void
    {
        config(['amial.hosts.merchant' => 'merchant.amialpay.com']);
        $this->assertSame('merchant.amialpay.com', PortalHost::expectedFor('merchant/login'));
        $this->get('http://amialpay.com/merchant/login')
            ->assertRedirect('http://merchant.amialpay.com/merchant/login');
        $this->post('http://amialpay.com/merchant/login', [
            'identifier' => 'WEB-101', 'password' => 'x',
        ])->assertStatus(421);
    }
}
