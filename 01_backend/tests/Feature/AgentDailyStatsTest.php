<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-FIX(AGENT-STATS) — إحصاءات الوكيل تقرأ من مصدر السجلّ الفعلي. */
class AgentDailyStatsTest extends TestCase
{
    use RefreshDatabase;

    /** @test سحب مكتمل اليوم يظهر في إحصاءات الوكيل (كان يقرأ الجدول الخطأ). */
    public function completed_withdrawal_shows_in_daily_stats(): void
    {
        $agent = User::factory()->create(['type' => 1, 'zone_code' => 'SOUTH']);
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $agent->id, 'current_balance' => '192000', 'charge_earned' => '0',
            'pending_balance' => '0', 'held_balance' => '0', 'zone_code' => 'SOUTH', 'version' => 0]);

        // طلب سحب مكتمل اليوم بيد هذا الوكيل: مبلغ 5000، عمولة 200
        WithdrawalRequest::create([
            'op_code' => '123456789', 'customer_user_id' => $customer->id, 'agent_user_id' => $agent->id,
            'amount' => '5000', 'fee' => '500', 'agent_commission' => '200', 'platform_profit' => '300',
            'total_debit' => '5500', 'status' => 'completed', 'completed_at' => now(),
            'expires_at' => now()->addMinutes(45), 'zone_code' => 'SOUTH',
        ]);

        // عملية إيداع (cash_in) للوكيل اليوم في جدول transactions
        DB::table('transactions')->insert([
            'transaction_id' => (string) \Illuminate\Support\Str::ulid(),
            'user_id' => $agent->id, 'transaction_type' => 'cash_in',
            'debit' => '3000', 'credit' => '0', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Passport::actingAs($agent->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/agent/daily-stats')
            ->assertOk()
            ->assertJsonPath('meta.today_cash_out', '5000.0000')
            ->assertJsonPath('meta.today_commission', '200.0000')
            ->assertJsonPath('meta.today_cash_in', '3000.0000')
            ->assertJsonPath('meta.today_count', 2)
            ->assertJsonPath('meta.current_balance', '192000.0000');
    }
}
