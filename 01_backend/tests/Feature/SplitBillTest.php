<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use App\Models\User;
use App\Services\MoneyService;
use App\Services\SplitBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-SPLIT-BILL-001 — تقسيم الفاتورة.
 */
class SplitBillTest extends TestCase
{
    use RefreshDatabase;

    private SplitBillService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SplitBillService::class);
        // أدمن للرسوم/الدفتر
        $admin = User::factory()->create(['type' => 0]);
        EMoney::create(['user_id' => $admin->id, 'current_balance' => '0', 'charge_earned' => '0']);
    }

    private function wallet(int $userId, string $balance = '0.0000'): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance, 'charge_earned' => '0.0000',
            'pending_balance' => '0.0000', 'held_balance' => '0.0000',
            'zone_code' => 'SOUTH', 'version' => 0,
        ]);
    }

    /** @test */
    public function create_splits_equally(): void
    {
        $merchant = User::factory()->create(['type' => 1, 'phone' => '+967700009000']);
        $c1 = User::factory()->create(['type' => 2, 'phone' => '+967700009001']);
        $c2 = User::factory()->create(['type' => 2, 'phone' => '+967700009002']);

        $bill = $this->service->create($merchant, '100.0000', [$c1->phone, $c2->phone]);

        $this->assertSame('completed', 'completed'); // sanity
        $this->assertSame(2, $bill->participant_count);
        $this->assertSame('open', $bill->status);
        $shares = SplitBillParticipant::where('split_bill_id', $bill->id)->pluck('share_amount')->all();
        $this->assertCount(2, $shares);
        // 100 / 2 = 50 لكل واحد
        $this->assertSame('50.0000', (string)$shares[0]);
        $this->assertSame('50.0000', (string)$shares[1]);
    }

    /** @test */
    public function rounding_difference_is_absorbed_so_sum_equals_total(): void
    {
        $merchant = User::factory()->create(['type' => 1, 'phone' => '+967700009100']);
        $c1 = User::factory()->create(['type' => 2, 'phone' => '+967700009101']);
        $c2 = User::factory()->create(['type' => 2, 'phone' => '+967700009102']);
        $c3 = User::factory()->create(['type' => 2, 'phone' => '+967700009103']);

        $bill = $this->service->create($merchant, '100.0000', [$c1->phone, $c2->phone, $c3->phone]);

        $shares = SplitBillParticipant::where('split_bill_id', $bill->id)->pluck('share_amount')->all();
        $sum = '0';
        foreach ($shares as $s) $sum = MoneyService::add($sum, (string)$s);
        // المجموع = 100 بالضبط رغم 100/3
        $this->assertSame(MoneyService::normalize('100'), $sum);
    }

    /** @test */
    public function duplicate_phone_is_rejected(): void
    {
        $merchant = User::factory()->create(['type' => 1, 'phone' => '+967700009200']);
        $c1 = User::factory()->create(['type' => 2, 'phone' => '+967700009201']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($merchant, '100.0000', [$c1->phone, $c1->phone]);
    }

    /** @test */
    public function pay_share_moves_money_to_merchant_and_completes_bill(): void
    {
        $merchant = User::factory()->create(['type' => 1, 'phone' => '+967700009300']);
        $c1 = User::factory()->create(['type' => 2, 'phone' => '+967700009301']);
        $c2 = User::factory()->create(['type' => 2, 'phone' => '+967700009302']);
        // AMIAL-MERCHANT-VERIFY-RECEIVE-001: القبضُ الماليّ يتطلّب تاجراً
        // موثّقاً في الخادم — فالمستلمُ في التقسيم تاجرٌ حقيقيّ لا حسابٌ عارٍ.
        \App\Models\MerchantProfile::create([
            'user_id' => $merchant->id, 'tier' => 'small',
            'verification_status' => 'verified',
            'single_receive_limit' => '500000', 'daily_receive_limit' => '5000000',
        ]);
        $this->wallet($merchant->id);
        $this->wallet($c1->id, '1000.0000');
        $this->wallet($c2->id, '1000.0000');

        // 1% رسم على دفع التاجر
        FeeScheme::create([
            'code' => 'MERCHANT_QR', 'zone_code' => 'SOUTH', 'applies_to' => 'merchant',
            'fee_type' => 'percent', 'percent_rate' => '1.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'merchant', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $bill = $this->service->create($merchant, '100.0000', [$c1->phone, $c2->phone]);
        $parts = SplitBillParticipant::where('split_bill_id', $bill->id)->orderBy('id')->get();

        // المشارك 1 يدفع حصته (50)
        $r1 = $this->service->payShare($parts[0]->id, $c1);
        $this->assertNotNull($r1['transaction_id']);
        $this->assertSame('paid', $parts[0]->fresh()->status);
        $this->assertSame('partially_paid', $bill->fresh()->status);
        // التاجر استلم 50 - 1% = 49.5
        $this->assertSame('49.5000', (string)EMoney::where('user_id', $merchant->id)->first()->current_balance);

        // المشارك 2 يدفع → الفاتورة مكتملة
        $this->service->payShare($parts[1]->id, $c2);
        $this->assertSame('completed', $bill->fresh()->status);
        // التاجر 49.5 + 49.5 = 99
        $this->assertSame('99.0000', (string)EMoney::where('user_id', $merchant->id)->first()->current_balance);
    }

    /** @test */
    public function cannot_pay_someone_elses_share(): void
    {
        $merchant = User::factory()->create(['type' => 1, 'phone' => '+967700009400']);
        $c1 = User::factory()->create(['type' => 2, 'phone' => '+967700009401']);
        $c2 = User::factory()->create(['type' => 2, 'phone' => '+967700009402']);
        $other = User::factory()->create(['type' => 2, 'phone' => '+967700009403']);
        $this->wallet($merchant->id);
        $this->wallet($c1->id, '1000.0000');
        $this->wallet($c2->id, '1000.0000');
        $this->wallet($other->id, '1000.0000');

        $bill = $this->service->create($merchant, '100.0000', [$c1->phone, $c2->phone]);
        $part = SplitBillParticipant::where('split_bill_id', $bill->id)->first();

        $this->expectException(\RuntimeException::class);
        $this->service->payShare($part->id, $other); // ليست حصته
    }
}
