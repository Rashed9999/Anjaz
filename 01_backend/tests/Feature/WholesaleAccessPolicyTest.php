<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Models\WholesaleCollection;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\Vertical\VerticalBootstrapService;
use App\Services\WholesaleInvoiceService;
use App\Services\WholesaleService;
use App\Services\Wholesale\WholesaleAccessPolicyService as Policy;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AMIAL-WHOLESALE-ACCESS-001 — الباقة + الدور + تغطية كل endpoint. */
class WholesaleAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $plan): User
    {
        $m = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
            'verification_level' => A::VERIFICATION_PREMIUM,
            'is_kyc_verified' => 1,
        ]);

        MerchantProfile::create([
            'user_id' => $m->id,
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => $plan,
            'verification_status' => 'verified',
        ]);

        app(VerticalBootstrapService::class)->ensureFor($m);

        return $m->refresh();
    }

    private function staff(User $merchant, string $roleCode): User
    {
        // role=pos صريح حتى يرث Business Type/Plan من صاحب المنشأة ولا
        // يُفسَّر كتاجر مستقل بلا MerchantProfile.
        $u = User::factory()->create(['type' => 3, 'role' => 'pos']);

        PosUser::create([
            'merchant_user_id' => $merchant->id,
            'user_id' => $u->id,
            'pos_number' => 'W-' . $u->id,
            'is_active' => true,
            'permissions' => [],
        ]);

        $role = DB::table('merchant_roles')
            ->where('merchant_user_id', $merchant->id)
            ->where('code', $roleCode)
            ->first();
        $this->assertNotNull($role, "دور الجملة {$roleCode} غير موجود");

        DB::table('merchant_user_roles')->insert([
            'merchant_user_id' => $merchant->id,
            'user_id' => $u->id,
            'merchant_role_id' => $role->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $u->refresh();
    }

    /** موظف دورٍ مستقل، لا حساب POS: مهم لفصل الدخول عن الجهاز. */
    private function roleStaff(User $merchant, string $roleCode): User
    {
        $u = User::factory()->create(['type' => 3, 'role' => 'merchant_staff']);
        $role = DB::table('merchant_roles')
            ->where('merchant_user_id', $merchant->id)
            ->where('code', $roleCode)
            ->first();
        $this->assertNotNull($role, "دور الجملة {$roleCode} غير موجود");
        DB::table('merchant_user_roles')->insert([
            'merchant_user_id' => $merchant->id,
            'user_id' => $u->id,
            'merchant_role_id' => $role->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $u->refresh();
    }

    private function state(User $user, string $action): string
    {
        return (string) app(Policy::class)->state($user, $action)['state'];
    }

    /** @test */
    public function free_wholesale_keeps_history_but_cannot_create_catalog_dependent_invoice(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $this->assertSame(Policy::AVAILABLE, $this->state($m, 'product.view'));
        $this->assertSame(Policy::AVAILABLE, $this->state($m, 'customer.view'));
        $this->assertSame(Policy::AVAILABLE, $this->state($m, 'invoice.view'));
        $this->assertSame(Policy::AVAILABLE, $this->state($m, 'collection.record'));

        $this->assertSame(Policy::LOCKED_BY_PLAN, $this->state($m, 'product.create'));
        $this->assertSame(Policy::LOCKED_BY_PLAN, $this->state($m, 'customer.manage'));
        $this->assertSame(Policy::LOCKED_BY_PLAN, $this->state($m, 'invoice.create'));
        $this->assertSame(Policy::LOCKED_BY_PLAN, $this->state($m, 'report.view'));
        $this->assertSame(Policy::LOCKED_BY_PLAN, $this->state($m, 'stock_alert.view'));
    }

    /** @test */
    public function business_opens_the_complete_wholesale_sales_flow(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        foreach ([
            'product.create', 'product.update', 'stock.adjust', 'unit.manage',
            'lot.receive', 'stock_alert.view', 'customer.manage', 'invoice.create',
            'report.view', 'rep.manage', 'return.request', 'price.view', 'price.set',
            'tier.manage',
        ] as $action) {
            $this->assertSame(Policy::AVAILABLE, $this->state($m, $action), $action);
        }
    }

    /** @test */
    public function business_is_the_first_plan_that_can_complete_current_invoice_flow(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        foreach (['product.create', 'customer.manage', 'invoice.create', 'report.view', 'export', 'rep.manage', 'price.view', 'price.set', 'tier.manage'] as $action) {
            $this->assertSame(Policy::AVAILABLE, $this->state($m, $action), $action);
        }
    }

    /** @test */
    public function enterprise_keeps_the_same_wholesale_depth_without_artificially_hiding_business_features(): void
    {
        $m = $this->merchant(A::PLAN_ENTERPRISE);

        foreach (['price.view', 'price.set', 'tier.manage', 'invoice.create', 'report.view'] as $action) {
            $this->assertSame(Policy::AVAILABLE, $this->state($m, $action), $action);
        }
    }

    /** @test */
    public function sales_rep_can_sell_but_cannot_void_an_invoice(): void
    {
        $owner = $this->merchant(A::PLAN_BUSINESS);
        $rep = $this->staff($owner, 'sales_rep');

        $this->assertSame(Policy::AVAILABLE, $this->state($rep, 'invoice.create'));
        $this->assertSame(Policy::AVAILABLE, $this->state($rep, 'collection.record'));
        $this->assertSame(Policy::LOCKED_BY_ROLE, $this->state($rep, 'invoice.void'));
        $this->assertSame(Policy::LOCKED_BY_ROLE, $this->state($rep, 'customer.manage'));
    }

    /** @test */
    public function collector_collects_and_reads_aging_but_cannot_create_or_void_invoices(): void
    {
        $owner = $this->merchant(A::PLAN_BUSINESS);
        $collector = $this->staff($owner, 'collector');

        $this->assertSame(Policy::AVAILABLE, $this->state($collector, 'invoice.view'));
        $this->assertSame(Policy::AVAILABLE, $this->state($collector, 'collection.record'));
        $this->assertSame(Policy::AVAILABLE, $this->state($collector, 'report.view'));
        $this->assertSame(Policy::LOCKED_BY_ROLE, $this->state($collector, 'invoice.create'));
        $this->assertSame(Policy::LOCKED_BY_ROLE, $this->state($collector, 'invoice.void'));
        $this->assertSame(Policy::LOCKED_BY_ROLE, $this->state($collector, 'dashboard.metrics'));
    }

    /** @test */
    public function a_non_pos_collector_can_record_for_the_business_and_is_the_audited_receiver(): void
    {
        $owner = $this->merchant(A::PLAN_BUSINESS);
        $collector = $this->roleStaff($owner, 'collector');
        $service = app(WholesaleService::class);
        $business = $service->getOrCreateBusiness($owner);
        $product = $service->addProduct($business, [
            'name' => 'صنف تحصيل الموظف', 'base_price' => '1000', 'initial_stock' => 5,
        ]);
        $customer = $service->addCustomer($business, [
            'full_name' => 'عميل التحصيل', 'phone' => '771700001', 'credit_limit' => '10000',
        ]);
        $invoice = app(WholesaleInvoiceService::class)->createInvoice($owner, $business, [[
            'product_id' => $product->id, 'quantity' => '2',
        ]], ['customer_id' => $customer->id, 'payment_type' => 'credit']);

        // لا يملك الموظف MerchantProfile ولا PosUser؛ نجاحه هنا يثبت أن
        // دور الموظف لم يعد يُعامل كتاجر مفقود. ولا يمرر received_by من
        // التطبيق: المتحكم يحقن المستخدم المصدّق بعد الحارس.
        $this->actingAs($collector, 'api')
            ->postJson("/api/v1/amial/merchant/wholesale/invoices/{$invoice->id}/collect", [
                'amount' => '500', 'payment_method' => 'cash',
                'received_by_user_id' => $owner->id,
            ])
            ->assertCreated()
            ->assertJsonPath('code', 'RECORDED');

        $collection = WholesaleCollection::query()->sole();
        $this->assertSame($collector->id, (int) $collection->received_by_user_id,
            'سجل التحصيل نسب المال للمالك بدل الموظف الذي نفذه');
    }

    /** @test */
    public function accountant_can_void_but_cannot_sell_or_claim_a_collection(): void
    {
        $owner = $this->merchant(A::PLAN_BUSINESS);
        $accountant = $this->staff($owner, 'accountant');

        $this->assertSame(Policy::AVAILABLE, $this->state($accountant, 'invoice.void'));
        $this->assertSame(Policy::AVAILABLE, $this->state($accountant, 'invoice.view'));
        $this->assertSame(Policy::AVAILABLE, $this->state($accountant, 'report.view'));
        $this->assertSame(Policy::LOCKED_BY_ROLE, $this->state($accountant, 'invoice.create'));
        $this->assertSame(Policy::LOCKED_BY_ROLE, $this->state($accountant, 'collection.record'));
    }

    /** @test */
    public function every_current_wholesale_endpoint_is_mapped_to_an_action(): void
    {
        $p = app(Policy::class);
        $base = 'api/v1/amial/merchant/wholesale';
        $cases = [
            ['GET', "$base/access"],
            ['GET', "$base/dashboard"],
            ['GET', $base], ['POST', $base],
            ['POST', "$base/price-tiers"],
            ['GET', "$base/products"], ['POST', "$base/products"],
            ['PUT', "$base/products/12"],
            ['POST', "$base/products/12/adjust-stock"],
            ['GET', "$base/products/12/units"], ['POST', "$base/products/12/units"],
            ['GET', "$base/products/12/lots"], ['POST', "$base/products/12/lots"],
            ['GET', "$base/products/12/quote"],
            ['GET', "$base/products/12/prices"], ['POST', "$base/products/12/prices"],
            ['GET', "$base/customers"], ['POST', "$base/customers"],
            ['PUT', "$base/customers/7"],
            ['GET', "$base/invoices"], ['POST', "$base/invoices"],
            ['GET', "$base/invoices/3"], ['GET', "$base/invoices/3/pdf"],
            ['POST', "$base/invoices/3/void"], ['POST', "$base/invoices/3/collect"],
            ['POST', "$base/invoices/amial-payment-request"],
            ['POST', "$base/invoices/3/amial-payment-request"],
            ['POST', "$base/payment-requests/17/cancel"],
            ['GET', "$base/returns"], ['POST', "$base/invoices/3/returns"],
            ['POST', "$base/returns/7/resolve"],
            ['GET', "$base/collections"],
            ['GET', "$base/sales-reps"], ['POST', "$base/sales-reps"],
            ['GET', "$base/reports/aging"],
            ['GET', "$base/reports/customer/9/statement"],
            ['GET', "$base/reports/sales-reps"],
        ];

        foreach ($cases as [$method, $path]) {
            $this->assertNotNull($p->actionFor($method, $path), "$method $path غير محروس بالسياسة");
        }

        $this->assertNull($p->actionFor('POST', "$base/new-unmapped-action"));
    }

    /** @test */
    public function wholesale_role_permissions_are_not_replaced_by_generic_retail_patterns(): void
    {
        $owner = $this->merchant(A::PLAN_BUSINESS);
        $warehouse = $this->staff($owner, 'warehouse_staff');
        $perm = app(MerchantPermissionService::class);

        $this->assertTrue($perm->can($warehouse, \App\Support\Merchant\MerchantPermissions::WHOLESALE_PRODUCT_MANAGE));
        $this->assertSame(Policy::AVAILABLE, $this->state($warehouse, 'product.update'));
        $this->assertSame(Policy::LOCKED_BY_ROLE, $this->state($warehouse, 'stock.adjust'));
    }
}
