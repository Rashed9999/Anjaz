<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EstablishesKycEvidence;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-HUB-001 — اللوحات المركزية الأربع للوحة الويب.
 */
class AdminHubTest extends TestCase
{
    use RefreshDatabase;
    use EstablishesKycEvidence;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009001']);
        // AMIAL-OPERATOR-RBAC-003: أدمنٌ بلا دورٍ لا يصل مسارات المال —
        // وهو ما يقع في الإنتاج أيضاً. فالمُثبِّت يعكس الواقع لا يتجاوزه.
        app(\App\Services\PlatformRoleService::class)->ensureHasSomeRole($this->admin);
        EMoney::create([
            'user_id' => $this->admin->id, 'current_balance' => '1000000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    private function wallet(int $userId, string $balance = '0.0000'): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance, 'held_balance' => '0.0000',
            'pending_balance' => '0.0000', 'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function hub_pages_render_for_admin(): void
    {
        foreach (['customers', 'agents', 'merchants', 'finance'] as $page) {
            $this->actingAs($this->admin, 'user')
                ->get("/admin/amial/hub/{$page}")
                ->assertOk();
        }
    }

    /** @test */
    public function hub_pages_require_login(): void
    {
        $this->get('/admin/amial/hub/customers')->assertRedirect(route('admin.auth.login'));
    }

    /** @test */
    public function users_json_lists_only_requested_type(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009001']);
        User::factory()->create(['type' => AGENT_TYPE, 'phone' => '967771009002']);
        $this->wallet($customer->id, '5000.0000');

        $resp = $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/customers/users.json')
            ->assertOk()
            ->json();

        $ids = array_column($resp['data'], 'id');
        $this->assertContains($customer->id, $ids);
        $this->assertCount(1, $resp['data']);
        $row = collect($resp['data'])->firstWhere('id', $customer->id);
        $this->assertSame('5000.0000', $row['balance']);
    }

    /** @test */
    public function admin_can_add_customer_with_wallet(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/customers/users', [
                'f_name' => 'اختبار', 'l_name' => 'عميل',
                'phone' => '967771009010', 'password' => 'Secret@123',
            ])
            ->assertCreated();

        $user = User::where('phone', '967771009010')->first();
        $this->assertNotNull($user);
        $this->assertSame(CUSTOMER_TYPE, (int) $user->type);
        $this->assertTrue(EMoney::where('user_id', $user->id)->exists());
    }

    /** @test */
    public function duplicate_phone_is_rejected(): void
    {
        User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009011']);

        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/customers/users', [
                'f_name' => 'مكرر', 'phone' => '967771009011', 'password' => 'Secret@123',
            ])
            ->assertStatus(422);
    }

    /** @test */
    public function toggle_active_freezes_and_unfreezes(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009020', 'is_active' => 1]);

        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/toggle-active", ['reason' => 'اختبار'])
            ->assertOk()
            ->assertJson(['is_active' => false]);
        $this->assertSame(0, (int) $customer->fresh()->is_active);

        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/toggle-active", ['reason' => 'اختبار'])
            ->assertOk()
            ->assertJson(['is_active' => true]);
        $this->assertSame(1, (int) $customer->fresh()->is_active);
    }

    /** @test */
    public function kyc_approve_and_reject(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009030', 'is_kyc_verified' => 0]);

        // اعتمادٌ بلا وثيقة مرفوض بحقّ — يُبنى الدليلُ أوّلاً.
        $this->establishKycEvidence($customer);
        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/kyc", ['status' => 1])
            ->assertOk();
        $this->assertSame(1, (int) $customer->fresh()->is_kyc_verified);

        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/kyc", ['status' => 2, 'reason' => 'الوثائق غير واضحة ولا تُقرأ'])
            ->assertOk();
        $this->assertSame(2, (int) $customer->fresh()->is_kyc_verified);
    }

    /** @test */
    public function admin_transfer_moves_money_from_admin_wallet(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009040']);
        $this->wallet($customer->id);

        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/transfer', [
                'to_user_id' => $customer->id, 'amount' => '2500', 'reason' => 'إعادة مبلغ',
            ])
            ->assertOk();

        $this->assertSame('2500.0000',
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'));
        $this->assertSame('997500.0000',
            (string) EMoney::where('user_id', $this->admin->id)->value('current_balance'));
    }

    /** @test */
    public function transfer_fails_when_admin_balance_insufficient(): void
    {
        EMoney::where('user_id', $this->admin->id)->update(['current_balance' => '100.0000']);
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009041']);
        $this->wallet($customer->id);

        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/transfer', ['to_user_id' => $customer->id, 'amount' => '2500'])
            ->assertStatus(422);

        $this->assertSame('0.0000',
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'));
    }

    /** @test */
    public function admin_topup_credits_admin_wallet(): void
    {
        // إصدارُ خزينةٍ مالٌ يُخلَق من لا شيء — فلا يُقبل بلا سندٍ وسبب.
        // (‏`PlatformTreasuryIssuanceTest` يحرس الشرطَ نفسَه من جهته.)
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/finance/topup', [
                'amount' => '50000',
                'reference' => 'BANK-DEP-2026-0001',
                'reason' => 'إيداعٌ بنكيٌّ مقابلَ إصدار رصيدٍ إلكترونيّ',
            ])
            ->assertOk();

        $this->assertSame('1050000.0000',
            (string) EMoney::where('user_id', $this->admin->id)->value('current_balance'));
    }

    // ==================== «حقيقي وليس واجهة» ====================
    // الحساب المُنشأ من اللوحة يجب أن يدخل من مسار دخول التطبيق نفسه فوراً.

    /** @test */
    public function hub_created_customer_can_login_from_app(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/customers/users', [
                'f_name' => 'عميل', 'l_name' => 'حقيقي',
                'phone' => '967771009050', 'password' => 'Secret@123', 'pin' => '5678',
            ])->assertCreated();

        \Illuminate\Support\Facades\Artisan::call('passport:install', ['--no-interaction' => true]);
        $this->postJson('/api/v1/auth/login', [
            'role' => 'customer', 'phone' => '967771009050', 'password' => 'Secret@123',
        ])->assertOk();
    }

    /** @test */
    public function hub_created_merchant_can_login_and_use_cashier(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/merchants/users', [
                'f_name' => 'تاجر', 'l_name' => 'حقيقي',
                'phone' => '967771009051', 'password' => 'Secret@123',
                'store_name' => 'بقالة الاختبار', 'business_type' => 'retail', 'plan' => 'business',
            ])->assertCreated();

        // دخول التطبيق بدور تاجر — التطبيق يطلب رقم التاجر الذي تعيده اللوحة
        \Illuminate\Support\Facades\Artisan::call('passport:install', ['--no-interaction' => true]);
        $merchantUser = User::where('phone', '967771009051')->first();
        $merchantNumber = \App\Models\Merchant::where('user_id', $merchantUser->id)->value('merchant_number');
        $this->assertNotNull($merchantNumber);
        $this->postJson('/api/v1/auth/login', [
            'role' => 'merchant', 'merchant_number' => $merchantNumber,
            'phone' => '967771009051', 'password' => 'Secret@123',
        ])->assertOk();

        // الملف والباقة حقيقيان
        $merchant = User::where('phone', '967771009051')->first();
        $profile = \App\Models\MerchantProfile::where('user_id', $merchant->id)->first();
        // AMIAL-ADMIN-KYC-001: كان هنا assertSame('verified', ...) — أي أن هذا
        // الاختبار كان يُثبّت العطل نفسه: حساب يخرج موثّقاً بلا مراجعة وثائق.
        // الصحيح أن يخرج بانتظار المراجعة، ويُوثَّق بقرار من لوحة التحقّق.
        $this->assertSame('pending_review', $profile->verification_status);
        $this->assertSame('business', $profile->subscription_plan);
        $this->assertSame('retail', $profile->business_type);

        // بعد اعتماد الإدارة يصير موثّقاً
        // اعتمادٌ بلا وثيقة مرفوض بحقّ — يُبنى الدليلُ أوّلاً.
        $this->establishKycEvidence($merchant);
        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$merchant->id}/kyc", ['status' => 1])
            ->assertSuccessful();
        $this->assertSame('verified', $profile->fresh()->verification_status);

        // واجهة الكاشير في التطبيق تعمل لهذا التاجر (تتطلّب MerchantProfile فعلياً)
        \Laravel\Passport\Passport::actingAs($merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/cashier/products')->assertOk();
    }

    /** @test */
    public function hub_created_agent_passes_app_login_step_one(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/agents/users', [
                'f_name' => 'وكيل', 'l_name' => 'حقيقي',
                'phone' => '967771009052', 'password' => 'Secret@123',
            ])->assertCreated();

        $agent = User::where('phone', '967771009052')->first();
        $this->assertNotNull($agent->agent_number, 'رقم الوكيل يُولَّد تلقائياً');

        // خطوة الدخول الأولى (رقم الوكيل + كلمة السر) تنجح وتصدر رمز OTP
        $this->postJson('/api/v1/auth/login', [
            'role' => 'agent', 'agent_number' => $agent->agent_number,
            'phone' => '967771009052', 'password' => 'Secret@123',
        ])->assertOk();
    }

    // ==================== لوحة الاشتراكات ====================

    /** @test */
    public function subscriptions_page_and_plan_change_are_real(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/merchants/users', [
                'f_name' => 'تاجر', 'l_name' => 'باقات',
                'phone' => '967771009053', 'password' => 'Secret@123',
                'store_name' => 'متجر الباقات', 'plan' => 'free',
            ])->assertCreated();
        $merchant = User::where('phone', '967771009053')->first();

        $this->actingAs($this->admin, 'user')
            ->get('/admin/amial/hub/subscriptions')->assertOk();

        $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/subscriptions/list.json')
            ->assertOk()->assertJsonStructure(['summary', 'data']);

        // تغيير الباقة يمرّ عبر SubscriptionService الحقيقي ويغيّر الملف فعلاً
        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/subscriptions/{$merchant->id}/plan", ['plan' => 'merchant_pro'])
            ->assertOk();
        $this->assertSame('merchant_pro',
            \App\Models\MerchantProfile::where('user_id', $merchant->id)->value('subscription_plan'));

        // والتمديد يحرّك تاريخ الانتهاء
        $before = \App\Models\MerchantProfile::where('user_id', $merchant->id)->value('subscription_expires_at');
        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/subscriptions/{$merchant->id}/extend", ['days' => 30])
            ->assertOk();
        $after = \App\Models\MerchantProfile::where('user_id', $merchant->id)->value('subscription_expires_at');
        $this->assertTrue(\Carbon\Carbon::parse($after)->gt(\Carbon\Carbon::parse($before)));
    }

    // ==================== لوحة النزاعات ====================

    /** @test */
    public function disputes_page_renders_and_lists_safe_payments(): void
    {
        $this->actingAs($this->admin, 'user')
            ->get('/admin/amial/hub/disputes')->assertOk();

        $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/safe-payments?status=disputed_open')
            ->assertOk();
    }

    // ==================== صفحة تفاصيل الحساب + المخاطر ====================

    /** @test */
    public function account_detail_page_and_json_render_for_customer(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009060']);
        $this->wallet($customer->id, '7500.0000');

        $this->actingAs($this->admin, 'user')
            ->get("/admin/amial/hub/account/{$customer->id}")->assertOk();

        $this->actingAs($this->admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/detail.json")
            ->assertOk()
            ->assertJsonPath('id', $customer->id)
            ->assertJsonPath('wallet.current', '7500.0000')
            ->assertJsonPath('risk.label', 'سليم') // لا ملف مخاطر = منخفض = سليم
            ->assertJsonStructure(['name', 'phone', 'documents', 'risk' => ['level', 'label', 'score', 'is_dangerous'], 'transactions']);
    }

    /** @test AMIAL — حالة المخاطر تعكس ملف AML (خطر جداً = critical). */
    public function account_detail_reflects_aml_risk_level(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009061']);
        \App\Models\Aml\AmlUserRiskProfile::create([
            'user_id' => $customer->id,
            'current_risk_score' => 85,
            'risk_level' => 'critical',
        ]);

        $this->actingAs($this->admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/detail.json")
            ->assertOk()
            ->assertJsonPath('risk.label', 'خطر جداً')
            ->assertJsonPath('risk.is_dangerous', true);
    }

    /** @test AMIAL — تفاصيل التاجر تشمل موظفيه وفروعه ومبيعاته. */
    public function merchant_account_detail_includes_staff_and_sales(): void
    {
        $merchant = User::factory()->create(['type' => MERCHANT_TYPE, 'phone' => '967771009062']);
        \App\Models\MerchantProfile::create(['user_id' => $merchant->id, 'verification_status' => 'verified', 'business_type' => 'retail']);

        $this->actingAs($this->admin, 'user')
            ->getJson("/admin/amial/hub/users/{$merchant->id}/detail.json")
            ->assertOk()
            ->assertJsonStructure(['merchant' => ['business_type', 'staff', 'branches', 'sales_total', 'sales_count']]);
    }

    /** @test AMIAL — تفاصيل الوكيل تشمل التسويات وبياناته. */
    public function agent_account_detail_includes_settlements(): void
    {
        $agent = User::factory()->create(['type' => AGENT_TYPE, 'phone' => '967771009063']);

        $this->actingAs($this->admin, 'user')
            ->getJson("/admin/amial/hub/users/{$agent->id}/detail.json")
            ->assertOk()
            ->assertJsonStructure(['agent' => ['status', 'commission_rate', 'settlements']]);
    }

    /** @test */
    public function finance_stats_and_feed_respond(): void
    {
        $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/finance/stats.json')
            ->assertOk()
            ->assertJsonStructure(['admin_balance', 'customers_balance', 'agents_balance',
                'merchants_balance', 'held_total', 'today_entries', 'today_volume']);

        $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/finance/feed.json')
            ->assertOk()
            ->assertJsonStructure(['max_id', 'data']);
    }
}
