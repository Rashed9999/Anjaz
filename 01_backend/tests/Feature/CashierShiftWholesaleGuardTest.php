<?php

namespace Tests\Feature;

use App\Models\PosUser;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression guard for wholesale cash inside POS shifts.
 *
 * A wholesale collection belongs to the authenticated employee who received it.
 * The X/Z report for one POS employee must never count a colleague's cash.
 */
class CashierShiftWholesaleGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function wholesale_cash_collection_is_scoped_to_the_shift_pos_user(): void
    {
        $merchant = User::factory()->create([
            'type' => 3,
            'role' => 'merchant',
            'zone_code' => 'SOUTH',
        ]);

        $cashierUser = User::factory()->create(['zone_code' => 'SOUTH']);
        $otherUser = User::factory()->create(['zone_code' => 'SOUTH']);

        $pos = PosUser::create([
            'user_id' => $cashierUser->id,
            'merchant_user_id' => $merchant->id,
            'pos_number' => 'POS-WHOLESALE-001',
            'display_name' => 'كاشير الجملة',
            'is_active' => true,
        ]);

        $business = WholesaleBusiness::create([
            'merchant_user_id' => $merchant->id,
            'business_name' => 'منشأة جملة اختبارية',
            'zone_code' => 'SOUTH',
        ]);

        $service = app(CashierShiftService::class);
        $shift = $service->open($merchant, $pos->id, '100');

        DB::table('wholesale_collections')->insert([
            [
                'collection_ulid' => (string) Str::ulid(),
                'invoice_id' => 1,
                'customer_id' => 1,
                'business_id' => $business->id,
                'received_by_user_id' => $cashierUser->id,
                'collection_date' => now()->toDateString(),
                'amount' => '750.0000',
                'payment_method' => 'cash',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'collection_ulid' => (string) Str::ulid(),
                'invoice_id' => 2,
                'customer_id' => 2,
                'business_id' => $business->id,
                'received_by_user_id' => $otherUser->id,
                'collection_date' => now()->toDateString(),
                'amount' => '400.0000',
                'payment_method' => 'cash',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $snapshot = $service->snapshot($shift);

        $this->assertSame('750.0000', $snapshot['cash_sales']);
        $this->assertSame('850.0000', $snapshot['expected_cash']);
        $this->assertSame(1, $snapshot['sales_count']);
    }
}
