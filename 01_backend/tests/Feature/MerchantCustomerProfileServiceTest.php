<?php

namespace Tests\Feature;

use App\Models\CustomerCreditMovement;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Services\Merchant\MerchantCustomerProfileService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantCustomerProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function customer_360_reads_sales_and_credit_without_creating_money_movements(): void
    {
        $merchant = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $credit = app(CustomerCreditService::class);
        $account = $credit->findOrCreateAccount(
            $merchant->id,
            '+967700123456',
            'عميل ملف 360',
            '5000',
        );
        $credit->recordSale($account, '700', note: 'بيع آجل موثق');

        foreach ([
            ['1000', 'cash', 'completed'],
            ['500', 'amial_pay', 'completed'],
            ['9000', 'cash', 'pending_payment'],
        ] as [$amount, $method, $status]) {
            MerchantSale::create([
                'sale_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'customer_phone' => '+967700123456',
                'customer_name' => 'عميل ملف 360',
                'total_amount' => $amount,
                'payment_method' => $method,
                'status' => $status,
                'items' => [],
                'zone_code' => 'SOUTH',
            ]);
        }

        $salesBefore = MerchantSale::count();
        $movementsBefore = CustomerCreditMovement::count();
        $balanceBefore = (string) $account->fresh()->current_balance;

        $profile = app(MerchantCustomerProfileService::class)
            ->profile($merchant, $account->id);

        $this->assertSame('generic', $profile['kind']);
        $this->assertSame('2', (string) $profile['commerce']['sales_count']);
        $this->assertSame('1500.0000', $profile['commerce']['sales_total']);
        $this->assertSame('750.0000', $profile['commerce']['average_ticket']);
        $this->assertNotNull($profile['commerce']['last_visit_at']);
        $this->assertSame('700.0000', $profile['credit']['current_balance']);
        $this->assertSame('700.0000', $profile['credit']['closing_balance']);
        $this->assertCount(2, $profile['commerce']['recent_sales']);

        $this->assertSame($salesBefore, MerchantSale::count());
        $this->assertSame($movementsBefore, CustomerCreditMovement::count());
        $this->assertSame($balanceBefore, (string) $account->fresh()->current_balance);
    }
}
