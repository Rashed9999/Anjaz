<?php

namespace Tests\Feature;

use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditMovement;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-CREDIT-001 — نظام الديون.
 */
class CustomerCreditTest extends TestCase
{
    use RefreshDatabase;

    private CustomerCreditService $svc;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CustomerCreditService::class);
        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
    }

    /** @test */
    public function creates_account_and_links_to_existing_user(): void
    {
        // عميل مسجّل في أميال باي بنفس الرقم
        $existing = User::factory()->create(['type' => 2, 'phone' => '+967700123456']);

        $account = $this->svc->findOrCreateAccount(
            $this->merchant->id, '+967700123456', 'محمد علي', '500000'
        );

        $this->assertSame('محمد علي', $account->customer_name);
        $this->assertSame($existing->id, $account->customer_user_id);
        $this->assertSame(MoneyService::normalize('500000'), (string)$account->credit_limit);
        $this->assertSame('bronze', $account->classification);
    }

    /** @test */
    public function sale_increases_balance_and_payment_decreases_it(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967700111', 'عميل أ');

        $this->svc->recordSale($account, '10000', dueDate: '2026-07-01', note: 'بيع');
        $this->svc->recordSale($account, '5000');
        $account->refresh();
        $this->assertSame(MoneyService::normalize('15000'), (string)$account->current_balance);

        $this->svc->recordPayment($account, '7000', note: 'سداد جزئي');
        $account->refresh();
        $this->assertSame(MoneyService::normalize('8000'), (string)$account->current_balance);
        $this->assertNotNull($account->last_payment_at);
    }

    /** @test */
    public function return_decreases_balance(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967700222', 'عميل ب');
        $this->svc->recordSale($account, '20000');
        $this->svc->recordReturn($account, '5000', note: 'مرتجع منتج تالف');

        $account->refresh();
        $this->assertSame(MoneyService::normalize('15000'), (string)$account->current_balance);
    }

    /** @test */
    public function payment_cannot_exceed_debt(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967700333', 'عميل ج');
        $this->svc->recordSale($account, '10000');

        $this->expectException(\RuntimeException::class);
        $this->svc->recordPayment($account, '15000'); // أكبر من الدين
    }

    /** @test */
    public function statement_returns_movements_with_totals(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967700444', 'عميل د');
        $this->svc->recordSale($account, '30000');
        $this->svc->recordPayment($account, '10000');
        $this->svc->recordReturn($account, '5000');

        $stmt = $this->svc->getStatement($account);

        $this->assertCount(3, $stmt['movements']);
        $this->assertSame(MoneyService::normalize('30000'), $stmt['totals']['debit']);
        $this->assertSame(MoneyService::normalize('15000'), $stmt['totals']['credit']);
        $this->assertSame(MoneyService::normalize('15000'), (string)$stmt['closing_balance']);
    }

    /** @test */
    public function balance_snapshot_is_recorded_per_movement(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967700555', 'عميل هـ');
        $m1 = $this->svc->recordSale($account, '10000');
        $m2 = $this->svc->recordPayment($account, '3000');

        $this->assertSame(MoneyService::normalize('10000'), (string)$m1->balance_after);
        $this->assertSame(MoneyService::normalize('7000'), (string)$m2->balance_after);
    }

    /** @test */
    public function classification_promotes_when_paying_regularly_with_low_utilization(): void
    {
        $account = $this->svc->findOrCreateAccount(
            $this->merchant->id, '+967700666', 'عميل و', '100000'
        );
        $this->svc->recordSale($account, '30000');     // استهلاك 30%
        $this->svc->recordPayment($account, '10000');  // آخر سداد الآن

        $account->refresh();
        $this->assertSame('gold', $account->classification); // <60% + سدّد توّاً
    }

    /** @test */
    public function dashboard_summarizes_correctly(): void
    {
        $a = $this->svc->findOrCreateAccount($this->merchant->id, '+967700777', 'أ', '50000');
        $b = $this->svc->findOrCreateAccount($this->merchant->id, '+967700888', 'ب', '30000');
        $this->svc->findOrCreateAccount($this->merchant->id, '+967700999', 'ج');

        $this->svc->recordSale($a, '20000');
        $this->svc->recordSale($b, '40000'); // تجاوز الحد 30000

        $sum = $this->svc->dashboardSummary($this->merchant->id);

        $this->assertSame(MoneyService::normalize('60000'), $sum['total_due']);
        $this->assertSame(2, $sum['debtors_count']);
        $this->assertSame(1, $sum['over_limit_count']);
    }

    /** @test */
    public function list_customers_filters_correctly(): void
    {
        $a = $this->svc->findOrCreateAccount($this->merchant->id, '+967700111', 'أحمد');
        $b = $this->svc->findOrCreateAccount($this->merchant->id, '+967700222', 'سارة', '10000');
        $this->svc->findOrCreateAccount($this->merchant->id, '+967700333', 'محمد');

        $this->svc->recordSale($a, '5000');
        $this->svc->recordSale($b, '15000'); // فوق الحد

        $debtors = $this->svc->listCustomers($this->merchant->id, filter: 'debtors');
        $this->assertSame(2, $debtors->total());

        $overLimit = $this->svc->listCustomers($this->merchant->id, filter: 'over_limit');
        $this->assertSame(1, $overLimit->total());

        $search = $this->svc->listCustomers($this->merchant->id, search: 'سارة');
        $this->assertSame(1, $search->total());
    }
}
