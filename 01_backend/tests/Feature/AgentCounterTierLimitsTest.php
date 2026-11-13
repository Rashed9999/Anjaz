<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentStaffService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-COUNTER-TIER-LIMITS-001 — حدودُ مستوى العميل عند الشبّاك.
 *
 * **عطلٌ وصل إلى التجربة الحيّة:** عميلةٌ بالمستوى الثاني (حدّه ٢٥٠ ألفاً) أُودع في محفظتها وسُحب منها
 * فوق ٣٠٠ ألف ريال. السببُ أنّ `AgentCounterService` كان يفحص حالة الحساب والرصيد والنقد ولا يسأل المستوى،
 * بينما التحويلُ وطلبُ السحب والفواتير تفرضه. فكان الشبّاكُ باباً خلفيّاً حول سياسةٍ مكتوبة.
 *
 * ويُحرس هنا من الجهات الأربع: حدُّ العملية الواحدة، وحدُّ الرصيد، والحدُّ اليوميّ المتراكم عبر الإيداع
 * والسحب معاً (العدّاد لم يكن يرى عمليات الشبّاك أصلاً)، وسلامةُ المستوى الأعلى.
 */
class AgentCounterTierLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967772000011',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);
        app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967772000199', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '9000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع المكلا', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->branch = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع المكلا', 'code' => 'MKL', 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
        ]);
        $this->branch->till()->create([
            'cash_on_hand' => '5000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);
        $this->branch = $this->branch->fresh('till');
    }

    private function customer(int $tier, string $balance = '0'): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '96777310' . random_int(1000, 9999),
            'is_active' => 1, 'is_kyc_verified' => 1, 'kyc_tier' => $tier,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => $balance]);

        return $c;
    }

    private function counter(): AgentCounterService
    {
        return app(AgentCounterService::class);
    }

    private function balanceOf(User $c): string
    {
        return bcadd((string) EMoney::where('user_id', $c->id)->value('current_balance'), '0', 4);
    }

    /** @test */
    public function a_tier_two_customer_cannot_be_credited_above_her_single_transaction_limit(): void
    {
        $customer = $this->customer(2);

        try {
            $this->counter()->deposit($this->branch, $customer, '300000', $this->branch->account);
            $this->fail('أُودع ٣٠٠ ألف في محفظة مستوى حدّه ٢٥٠ ألفاً');
        } catch (DomainException $e) {
            $this->assertStringContainsString('حد', $e->getMessage());
        }

        $this->assertSame('0.0000', $this->balanceOf($customer), 'المحفظة تغيّرت رغم الرفض');
    }

    /** @test */
    public function a_deposit_that_would_push_the_balance_over_the_tier_limit_is_refused(): void
    {
        $customer = $this->customer(2, '200000');

        $this->expectException(DomainException::class);
        $this->counter()->deposit($this->branch, $customer, '100000', $this->branch->account);
    }

    /** @test */
    public function a_tier_two_customer_cannot_withdraw_above_the_single_transaction_limit(): void
    {
        $customer = $this->customer(2, '250000');
        // رصيدٌ سبق أن تجاوز الحدّ (قبل الإصلاح) لا يجوز أن يُسحب بعملية واحدة فوق حدّها.
        EMoney::where('user_id', $customer->id)->update(['current_balance' => '400000']);

        try {
            $this->counter()->withdraw($this->branch, $customer, '300000', $this->branch->account);
            $this->fail('سُحب ٣٠٠ ألف بعمليةٍ واحدة من مستوى حدّه ٢٥٠ ألفاً');
        } catch (DomainException $e) {
            $this->assertStringContainsString('حد', $e->getMessage());
        }

        $this->assertSame('400000.0000', $this->balanceOf($customer));
    }

    /** @test */
    public function counter_operations_count_toward_the_daily_total_across_deposit_and_withdraw(): void
    {
        $customer = $this->customer(2);

        // ٢٠٠ ألف وارد ثم ٢٠٠ ألف صادر = ٤٠٠ ألف حركةً في اليوم، والحدّ ٢٥٠ ألفاً.
        $this->counter()->deposit($this->branch, $customer, '200000', $this->branch->account);

        try {
            $this->counter()->withdraw($this->branch, $customer, '200000', $this->branch->account);
            $this->fail('الحدّ اليومي لم يحسب إيداعَ الشبّاك — العدّاد لا يرى عملياته');
        } catch (DomainException $e) {
            $this->assertStringContainsString('اليومي', $e->getMessage());
        }

        $this->assertDatabaseHas('customer_turnover_usage', [
            'user_id' => $customer->id, 'direction' => 'in', 'transaction_type' => 'agent_deposit',
        ]);
        $this->assertDatabaseMissing('customer_turnover_usage', [
            'user_id' => $customer->id, 'direction' => 'out', 'transaction_type' => 'agent_withdraw',
        ]);
    }

    /** @test */
    public function operations_within_the_limit_still_work_and_release_nothing_extra(): void
    {
        $customer = $this->customer(2);

        $this->counter()->deposit($this->branch, $customer, '100000', $this->branch->account);
        $this->counter()->withdraw($this->branch, $customer, '50000', $this->branch->account);

        // الرصيد بعد إيداع ١٠٠ ألف وسحب ٥٠ ألفاً (الرسوم إن وُجدت صغيرة) — لا يُفقد شيء بلا سبب.
        $this->assertGreaterThan(40000, (float) $this->balanceOf($customer));
        $this->assertLessThanOrEqual(50000, (float) $this->balanceOf($customer));
        // وكلتا العمليتين تُسجَّلان في عدّاد الحركة (وإلا لا يحسبهما الحدّ اليومي).
        $this->assertDatabaseHas('customer_turnover_usage', ['user_id' => $customer->id, 'transaction_type' => 'agent_deposit', 'direction' => 'in']);
        $this->assertDatabaseHas('customer_turnover_usage', ['user_id' => $customer->id, 'transaction_type' => 'agent_withdraw', 'direction' => 'out']);
    }

    /**
     * حدودُ حساب الذكر (المستوى ٣): العملية ١ مليون، واليوم ٢ مليون. وهي ما يمنحه المسار القياسي عند الاعتماد،
     * فلا يكون الشبّاك باباً خلفياً حولها كذلك.
     *
     * @test
     */
    public function a_male_tier_three_account_is_bound_by_its_own_limits_at_the_counter(): void
    {
        $customer = $this->customer(3);

        try {
            $this->counter()->deposit($this->branch, $customer, '1100000', $this->branch->account);
            $this->fail('أُودع فوق حدّ العملية الواحدة للمستوى ٣ (مليون)');
        } catch (DomainException $e) {
            $this->assertStringContainsString('حد', $e->getMessage());
        }

        $this->counter()->deposit($this->branch, $customer, '900000', $this->branch->account);
        $this->counter()->deposit($this->branch, $customer, '900000', $this->branch->account);

        try {
            $this->counter()->deposit($this->branch, $customer, '300000', $this->branch->account);
            $this->fail('تجاوز الحدّ اليومي للمستوى ٣ (مليونان) عبر الشبّاك');
        } catch (DomainException $e) {
            $this->assertStringContainsString('اليومي', $e->getMessage());
        }

        $this->assertSame('1800000.0000', $this->balanceOf($customer));
    }

    /**
     * المستوى ١ (موثّق جزئياً — هاتف وسكن): ١٠٠ ألف للعملية والرصيد واليوم والشهر. وهو ما تفتحه الأنثى
     * في المسار التدريجي قبل أي هوية، فأوسعُ الأبواب (الشبّاك) يجب ألا يتجاوزه.
     *
     * @test
     */
    public function a_tier_one_account_cannot_exceed_one_hundred_thousand_at_the_counter(): void
    {
        $customer = $this->customer(1);

        try {
            $this->counter()->deposit($this->branch, $customer, '120000', $this->branch->account);
            $this->fail('أُودع ١٢٠ ألفاً في محفظة المستوى ١ (حدّها ١٠٠ ألف)');
        } catch (DomainException $e) {
            $this->assertStringContainsString('حد', $e->getMessage());
        }

        $this->counter()->deposit($this->branch, $customer, '60000', $this->branch->account);

        try {
            $this->counter()->deposit($this->branch, $customer, '60000', $this->branch->account);
            $this->fail('الرصيد تجاوز ١٠٠ ألف في المستوى ١');
        } catch (DomainException $e) {
            $this->assertStringContainsString('حد', $e->getMessage());
        }

        $this->assertSame('60000.0000', $this->balanceOf($customer));
    }

    /** @test */
    public function a_tier_one_account_cannot_withdraw_above_its_single_transaction_limit(): void
    {
        $customer = $this->customer(1);
        // رصيدٌ سابقٌ فوق الحدّ (قبل الإصلاح): السحبُ بعمليةٍ واحدة لا يتجاوز حدّ العملية.
        EMoney::where('user_id', $customer->id)->update(['current_balance' => '300000']);

        $this->expectException(DomainException::class);
        $this->counter()->withdraw($this->branch, $customer, '150000', $this->branch->account);
    }

    /** @test */
    public function a_higher_tier_keeps_its_higher_limits(): void
    {
        $customer = $this->customer(3);

        $out = $this->counter()->deposit($this->branch, $customer, '500000', $this->branch->account);

        $this->assertSame('500000', $out['amount']);
        $this->assertGreaterThan(250000, (float) $this->balanceOf($customer));
    }
}
