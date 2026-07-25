<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CustomerCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-CREDIT-VIEW-001 — العميل يرى فواتيره الآجلة لحظياً:
 * حين يسجّل التاجر بيعاً آجلاً على عميل مسجّل (طابق هاتفه)، يظهر في حساب العميل.
 */
class CustomerCreditViewTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private User $customer;
    private CustomerCreditService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CustomerCreditService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $mr = new Merchant();
        $mr->user_id = $this->merchant->id;
        $mr->store_name = 'بقالة النور';
        $mr->merchant_number = 'M-77001';
        $mr->address = '—';
        $mr->save();

        $this->customer = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'phone' => '+967771700055',
        ]);
    }

    /** @test بيع آجل على عميل مسجّل → يظهر في «حساباتي الآجلة» ثم في كشف الحساب. */
    public function credit_sale_appears_in_customer_account_and_statement(): void
    {
        // التاجر ينشئ حساب العميل بهاتفه → يُربط تلقائياً بالمستخدم المسجّل
        $account = $this->svc->findOrCreateAccount(
            $this->merchant->id, '+967771700055', 'علي نونو',
        );
        $this->assertSame($this->customer->id, $account->customer_user_id);

        // التاجر يسجّل بيعاً آجلاً 1200
        $this->svc->recordSale($account, '1200', createdBy: $this->merchant->id, referenceNumber: 'INV-1');

        // العميل يرى حساباته الآجلة
        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/customer/credits')
            ->assertOk()
            ->assertJsonPath('meta.accounts_count', 1)
            ->assertJsonPath('meta.total_owed', '1200.0000')
            ->assertJsonPath('meta.accounts.0.merchant_name', 'بقالة النور')
            ->assertJsonPath('meta.accounts.0.current_balance', '1200.0000');

        // وكشف الحساب يعرض الفاتورة
        $this->getJson("/api/v1/amial/customer/credits/{$account->id}/statement")
            ->assertOk()
            ->assertJsonPath('meta.movements.0.type', 'sale')
            ->assertJsonPath('meta.movements.0.amount', '1200.0000')
            ->assertJsonPath('meta.movements.0.reference_number', 'INV-1');
    }

    /** @test لا يرى العميل حساب عميل آخر (عزل). */
    public function customer_cannot_see_another_customers_statement(): void
    {
        $other = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH', 'phone' => '+967771700099']);
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967771700099', 'شخص آخر');
        $this->svc->recordSale($account, '500', createdBy: $this->merchant->id);

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->getJson("/api/v1/amial/customer/credits/{$account->id}/statement")
            ->assertStatus(404);
    }

    /**
     * AMIAL-CREDIT-LINK-001 — انحدار: التاجر يكتب الرقم الوطني «777100001»
     * بينما المستخدم مخزَّن «+967777100001». المطابقة الحرفية كانت تفشل فيبقى
     * الحساب غير مربوط، فلا يرى العميل ما عليه ولا يستطيع سداده.
     */
    public function test_credit_account_links_to_user_by_any_phone_form(): void
    {
        $national = ltrim(str_replace('+967', '', $this->customer->phone), '+');

        $account = app(\App\Services\CustomerCreditService::class)->findOrCreateAccount(
            merchantId: $this->merchant->id,
            customerPhone: $national,
            customerName: 'عميل اختبار',
        );

        $this->assertSame(
            $this->customer->id,
            $account->customer_user_id,
            'يجب أن يُربط الحساب بالمستخدم مهما اختلفت صيغة الرقم'
        );
    }

    /**
     * حساب أُنشئ قبل تسجيل العميل يبقى بلا ربط — تُطالب به الشاشة عند أول فتح.
     */
    public function test_unlinked_account_is_claimed_on_first_open(): void
    {
        $orphan = \App\Models\CustomerCreditAccount::create([
            'merchant_user_id' => $this->merchant->id,
            'customer_phone' => $this->customer->phone,
            'customer_user_id' => null,
            'customer_name' => 'عميل قديم',
            'credit_limit' => '0.0000',
            'current_balance' => '2500.0000',
            'classification' => 'bronze',
            'is_active' => true,
        ]);

        $this->actingAs($this->customer, 'api')
            ->getJson('/api/v1/amial/customer/credits')
            ->assertOk();

        $this->assertSame($this->customer->id, $orphan->fresh()->customer_user_id);
    }
}
