<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-PAY-001 — دفع العميل للتاجر (QR/POS).
 */
class MerchantPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function wallet(int $userId, string $balance = '0.0000'): EMoney
    {
        return EMoney::create([
            'user_id' => $userId,
            'current_balance' => $balance,
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);
    }

    private function trait()
    {
        return new class {
            use \App\Traits\TransactionTrait;
        };
    }

    private function scheme(string $code, string $percent): FeeScheme
    {
        return FeeScheme::create([
            'code' => $code, 'zone_code' => 'SOUTH', 'applies_to' => 'merchant',
            'fee_type' => 'percent', 'percent_rate' => $percent, 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'merchant', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);
    }

    /** @test */
    public function qr_payment_merchant_bears_fee(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $customer = User::factory()->create(['type' => 2]);
        $merchant = User::factory()->create(['type' => 1]);
        $this->wallet($admin->id);
        $this->wallet($customer->id, '2000.0000');
        $this->wallet($merchant->id);

        $scheme = $this->scheme('MERCHANT_QR', '1.0000'); // 1%

        $txId = $this->trait()->merchant_payment_transaction(
            customer_user_id: $customer->id,
            merchant_user_id: $merchant->id,
            amount: '1000.0000',
            channel: 'qr',
        );

        $this->assertNotNull($txId);
        // العميل يدفع المبلغ كاملاً: 2000 - 1000 = 1000
        $this->assertSame('1000.0000', (string)EMoney::where('user_id', $customer->id)->first()->current_balance);
        // التاجر يستلم المبلغ ناقص الرسم: 1000 - 10 = 990
        $this->assertSame('990.0000', (string)EMoney::where('user_id', $merchant->id)->first()->current_balance);
        // المنصّة تكسب 10
        $this->assertSame(MoneyService::normalize('10'), MoneyService::normalize((string)\App\Models\PlatformFeeEntry::sum('amount')));

        // snapshot + النوع
        $primary = Transaction::where('transaction_id', $txId)->first();
        $this->assertSame('merchant_payment', $primary->transaction_type);
        $this->assertSame($scheme->id, (int)$primary->fee_scheme_id);
        $this->assertSame('10.0000', (string)$primary->charge);
    }

    /** @test */
    public function pos_payment_attributes_pos_user(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $customer = User::factory()->create(['type' => 2]);
        $merchant = User::factory()->create(['type' => 1]);
        $this->wallet($admin->id);
        $this->wallet($customer->id, '2000.0000');
        $this->wallet($merchant->id);

        $this->scheme('MERCHANT_POS', '2.0000');

        $txId = $this->trait()->merchant_payment_transaction(
            customer_user_id: $customer->id,
            merchant_user_id: $merchant->id,
            amount: '500.0000',
            channel: 'pos',
            pos_user_id: 55,
        );

        $this->assertNotNull($txId);
        // الرسم 2% من 500 = 10 → التاجر 490
        $this->assertSame('490.0000', (string)EMoney::where('user_id', $merchant->id)->first()->current_balance);

        // pos_user_id مخزّن على صفّي العميل والتاجر
        $rows = Transaction::where('pos_user_id', 55)->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
    }

    /** @test */
    public function no_scheme_means_merchant_gets_full_amount(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $customer = User::factory()->create(['type' => 2]);
        $merchant = User::factory()->create(['type' => 1]);
        $this->wallet($admin->id);
        $this->wallet($customer->id, '2000.0000');
        $this->wallet($merchant->id);

        // لا توجد نسخة MERCHANT_QR → رسم صفر
        $txId = $this->trait()->merchant_payment_transaction(
            customer_user_id: $customer->id,
            merchant_user_id: $merchant->id,
            amount: '1000.0000',
            channel: 'qr',
        );

        $this->assertNotNull($txId);
        $this->assertSame('1000.0000', (string)EMoney::where('user_id', $merchant->id)->first()->current_balance);
        $this->assertSame('0.0000', (string)EMoney::where('user_id', $admin->id)->first()->charge_earned);
    }
}
