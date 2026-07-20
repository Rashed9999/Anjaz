<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\InstallmentContract;
use App\Models\InstallmentSchedule;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\InstallmentService;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-INSTALLMENTS-001 — البيع بالتقسيط (شروط وضمانات عالمية). */
class InstallmentTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
        $this->customer = User::factory()->create(['type' => 2, 'phone' => '+967700111222',
            'zone_code' => 'SOUTH', 'is_kyc_verified' => 1]);
        $this->wallet($this->merchant->id, '0');
        $this->wallet($this->customer->id, '100000');
    }

    private function wallet(int $id, string $bal): void
    {
        EMoney::create(['user_id' => $id, 'current_balance' => $bal, 'charge_earned' => '0',
            'pending_balance' => '0', 'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 0]);
    }

    private function upgrade(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_MERCHANT_PRO, $admin);
    }

    /** @test غير التاجر برو ممنوع → 402. */
    public function non_pro_cannot_use_installments(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/installments/plan')->assertStatus(402);
    }

    /** @test حساب القسط: سعر 10000، دفعة 25%، هامش 0، 3 أشهر → دفعة 2500، قسط 2500. */
    public function quote_math_is_correct(): void
    {
        $this->upgrade();
        $svc = app(InstallmentService::class);
        $plan = $svc->savePlan($this->merchant->fresh(),
            ['is_active' => true, 'down_payment_percent' => '25', 'markup_percent' => '0', 'durations' => [3, 6, 12]]);

        $q = $svc->quote($plan, 10000, 3);
        $this->assertSame(2500.0, $q['down_payment']);
        $this->assertSame(7500.0, $q['financed_amount']);
        $this->assertSame(7500.0, $q['total_payable']);
        $this->assertSame(2500.0, $q['monthly_amount']);
    }

    /** @test إنشاء عقد يحصّل الدفعة الأولى فوراً ويولّد جدول الأقساط. */
    public function creating_contract_collects_down_payment(): void
    {
        $this->upgrade();
        $svc = app(InstallmentService::class);
        $svc->savePlan($this->merchant->fresh(),
            ['is_active' => true, 'down_payment_percent' => '25', 'markup_percent' => '10', 'durations' => [3]]);

        $contract = $svc->createContract($this->merchant->fresh(), $this->customer->fresh(), 10000, 3, null, 'ثلاجة');

        // الدفعة الأولى 2500 خرجت من العميل ودخلت التاجر
        $this->assertSame('97500.0000', (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'));
        $this->assertSame('2500.0000', (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'));

        // المموّل 7500 + هامش 10% = 8250 إجمالي واجب على 3 أشهر
        $this->assertSame('8250.00', (string) $contract->total_payable);
        $this->assertSame(3, InstallmentSchedule::where('contract_id', $contract->id)->count());
    }

    /** @test سداد قسط يخصم من المحفظة ويقلّص المتبقّي، والسداد الكامل يُكمل العقد. */
    public function paying_installments_completes_contract(): void
    {
        $this->upgrade();
        $svc = app(InstallmentService::class);
        $svc->savePlan($this->merchant->fresh(),
            ['is_active' => true, 'down_payment_percent' => '0', 'markup_percent' => '0', 'durations' => [2]]);

        $contract = $svc->createContract($this->merchant->fresh(), $this->customer->fresh(), 10000, 2);
        // total_payable = 10000، قسطان 5000

        $r1 = $svc->payInstallment($this->customer->fresh(), $contract, '5000');
        $this->assertSame('5000.0000', $r1['remaining']);
        $this->assertSame('active', $r1['status']);
        $this->assertSame(1, InstallmentSchedule::where('contract_id', $contract->id)->where('status', 'paid')->count());

        $r2 = $svc->payInstallment($this->customer->fresh(), $contract, '5000');
        $this->assertSame('completed', $r2['status']);
        $this->assertSame('completed', InstallmentContract::find($contract->id)->status);
    }

    /** @test ضمان الهوية: عميل غير موثّق يُرفض إن اشترط التاجر KYC. */
    public function unverified_customer_rejected_when_kyc_required(): void
    {
        $this->upgrade();
        $svc = app(InstallmentService::class);
        $svc->savePlan($this->merchant->fresh(),
            ['is_active' => true, 'require_kyc' => true, 'durations' => [3]]);
        $unverified = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH', 'is_kyc_verified' => 0]);
        $this->wallet($unverified->id, '100000');

        $this->expectException(\RuntimeException::class);
        $svc->createContract($this->merchant->fresh(), $unverified, 10000, 3);
    }
}
