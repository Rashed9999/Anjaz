<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-TXN-NO-001 — رقم العملية الرقمي (15 خانة) مميّز بنوع العملية:
 *   عميل↔عميل → 120 | عميل→تاجر → 20 | وكيل↔عميل → 50.
 * ومشترك بين صفوف العملية الواحدة (المرسِل والمستقبِل يريان نفس الرقم).
 */
class TransactionNumberTest extends TestCase
{
    use RefreshDatabase;
    use TransactionTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function wallet(int $userId, string $balance): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    /** @test عميل→عميل: رقم العملية 15 خانة يبدأ بـ 120 ومشترك بين الطرفين. */
    public function customer_to_customer_number_starts_with_120_and_is_shared(): void
    {
        $a = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        $b = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        $this->wallet($a->id, '5000.0000');
        $this->wallet($b->id, '0.0000');

        $txId = $this->customer_send_money_transaction(
            from_user_id: $a->id, to_user_id: $b->id,
            amount: '1000.0000', charge: '10.0000',
        );

        $primary = Transaction::where('transaction_id', $txId)->first();
        $this->assertNotNull($primary->transaction_no);
        $this->assertSame(15, strlen($primary->transaction_no));
        $this->assertStringStartsWith('120', $primary->transaction_no);
        $this->assertMatchesRegularExpression('/^\d{15}$/', $primary->transaction_no);

        // المستقبِل (RECEIVED_MONEY، ref = المرجع الأساسي) يرى نفس الرقم
        $receiverRow = Transaction::where('ref_trans_id', $txId)
            ->where('user_id', $b->id)->first();
        $this->assertSame($primary->transaction_no, $receiverRow->transaction_no);
    }

    /** @test عميل→تاجر: رقم العملية 15 خانة يبدأ بـ 20. */
    public function customer_to_merchant_number_starts_with_20(): void
    {
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        $merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $merchant->id, 'verification_status' => 'verified']);
        $mr = new Merchant();
        $mr->user_id = $merchant->id;
        $mr->store_name = 'متجر';
        $mr->merchant_number = 'M-90001';
        $mr->address = '—';
        $mr->save();

        $this->wallet($customer->id, '10000.0000');
        $this->wallet($merchant->id, '0.0000');
        $this->wallet(\App\CentralLogics\Helpers::get_admin_id() ?: $this->makeAdmin(), '0.0000');

        $txId = $this->merchant_payment_transaction(
            $customer->id, $merchant->id, '2000', 'qr', null, 'دفع',
        );

        $primary = Transaction::where('transaction_id', $txId)->first();
        $this->assertNotNull($primary->transaction_no);
        $this->assertSame(15, strlen($primary->transaction_no));
        $this->assertStringStartsWith('20', $primary->transaction_no);
    }

    private function makeAdmin(): int
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        return $admin->id;
    }
}
