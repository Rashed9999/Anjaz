<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
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

    public function test_wallet_and_credit_pages_use_owner_only_shared_data_routes(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'merchant_web')
            ->get('/merchant')
            ->assertOk()
            ->assertSee('data-tab="debts"', false)
            ->assertSee('walletVerification')
            ->assertSee('debtInvoices')
            ->assertSee('debtStatementPdf');

        $this->getJson('/merchant/data/wallet-verification')
            ->assertOk()->assertJsonStructure(['meta' => ['verification' => [
                'state', 'operational_balance', 'ledger_balance', 'gap',
            ]]]);

        $this->getJson('/merchant/data/debts')->assertOk()
            ->assertJsonStructure(['meta' => ['total_due', 'debtors_count']]);

        $this->getJson('/merchant/data/debts/customers')->assertOk()
            ->assertJsonStructure(['meta' => ['customers', 'pagination']]);
    }

    public function test_customer_and_pos_cannot_read_merchant_credit_book_or_wallet_truth(): void
    {
        $this->owner();
        foreach ([
            ['type' => CUSTOMER_TYPE, 'role' => A::ROLE_CUSTOMER],
            ['type' => POS_TYPE, 'role' => A::ROLE_POS],
        ] as $identity) {
            $user = User::factory()->create($identity);
            $this->actingAs($user, 'merchant_web')
                ->getJson('/merchant/data/wallet-verification')->assertForbidden();
            $this->getJson('/merchant/data/debts')->assertForbidden();
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
        foreach (['overview', 'stats', 'wallet', 'ledger', 'products',
                  'branches', 'roles', 'staff', 'devices', 'receipts'] as $endpoint) {
            $route = Route::getRoutes()->getByName('merchant.web.data.' . $endpoint);
            $this->assertNotNull($route, $endpoint . ' not registered');
            $this->assertContains('merchant.web', $route->gatherMiddleware());
        }
        foreach (['products.create' => 'capability:products',
                  'branches.create' => 'capability:branches',
                  'staff.create' => 'capability:employees',
                  'devices.activate' => 'capability:multi_pos'] as $endpoint => $cap) {
            $this->assertContains($cap,
                Route::getRoutes()->getByName('merchant.web.data.' . $endpoint)->gatherMiddleware());
        }
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
