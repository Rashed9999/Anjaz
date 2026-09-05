<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CustomerCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-CREDIT-SETTLE-001 — العميل يسدّد دَينه الآجل من محفظته:
 * ينتقل المال للتاجر ويُخفَّض رصيد الآجل. سداد حقيقي بقيود مزدوجة.
 */
class CustomerCreditSettleTest extends TestCase
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
        $mr->merchant_number = 'M-88001';
        $mr->address = '—';
        $mr->save();
        EMoney::create([
            'user_id' => $this->merchant->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        $this->customer = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'phone' => '+967771700066',
            'transaction_pin' => Hash::make('1234'),
        ]);
        EMoney::create([
            'user_id' => $this->customer->id, 'current_balance' => '10000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    /** @test سداد جزئي: ينتقل المال للتاجر ويقلّ الدَّين. */
    public function partial_settle_moves_money_and_reduces_debt(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967771700066', 'علي');
        $this->svc->recordSale($account, '3000', createdBy: $this->merchant->id);
        $this->assertSame('3000.0000', (string) $account->fresh()->current_balance);

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/customer/credits/{$account->id}/settle", [
            'amount' => '2000', 'pin' => '1234',
        ])->assertOk()
          ->assertJsonPath('meta.paid', '2000.0000')
          ->assertJsonPath('meta.new_balance', '1000.0000');

        // المال تحرّك
        $this->assertSame('8000.0000',
            (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'));
        $this->assertSame('2000.0000',
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'));
    }

    /** @test رمز خاطئ يرفض السداد. */
    public function wrong_pin_is_rejected(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967771700066', 'علي');
        $this->svc->recordSale($account, '3000', createdBy: $this->merchant->id);

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/customer/credits/{$account->id}/settle", [
            'amount' => '1000', 'pin' => '0000',
        ])->assertStatus(403);
    }

    /** @test لا يمكن سداد أكثر من الدَّين. */
    public function cannot_settle_more_than_debt(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967771700066', 'علي');
        $this->svc->recordSale($account, '1000', createdBy: $this->merchant->id);

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/customer/credits/{$account->id}/settle", [
            'amount' => '5000', 'pin' => '1234',
        ])->assertStatus(422);
    }

    /** @test يحق للعميل سداد فاتورة مؤجلة محددة جزئياً، لا أقدم دين بالضرورة. */
    public function customer_can_partially_settle_the_selected_deferred_invoice(): void
    {
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967771700066', 'علي');
        $older = $this->svc->recordSale($account, '1000', referenceNumber: 'OLD-1');
        $selected = $this->svc->recordSale($account, '900', referenceNumber: 'NEW-1');

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/customer/credits/{$account->id}/settle", [
            'amount' => '400',
            'pin' => '1234',
            'sale_movement_ulid' => $selected->movement_ulid,
        ])->assertOk()
          ->assertJsonPath('meta.new_balance', '1500.0000')
          ->assertJsonPath('meta.allocations.0.sale_movement_ulid', $selected->movement_ulid)
          ->assertJsonPath('meta.allocations.0.amount', '400.0000');

        $open = app(\App\Services\CreditSourceSettlementService::class)
            ->openInvoices($account->fresh());
        $byReference = collect($open)->keyBy('reference_number');
        $this->assertSame('1000.0000', $byReference['OLD-1']['remaining']);
        $this->assertSame('500.0000', $byReference['NEW-1']['remaining']);
        $this->assertNotSame($older->movement_ulid, $selected->movement_ulid);
    }
}
