<?php

namespace Tests\Feature;

use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The safe and a teller drawer are separate physical-cash custody dimensions.
 */
class ReconciliationCashCustodyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_teller_drawer_movement_does_not_create_a_branch_safe_variance(): void
    {
        $actor = User::factory()->create(['phone' => '967770091001']);

        DB::table('agent_cash_tills')->insert([
            'branch_id' => 9901, 'cash_on_hand' => '100.0000',
            'max_cash_on_hand' => '0', 'min_cash_alert' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('agent_cash_movements')->insert([
            [
                'branch_id' => 9901, 'is_drawer' => false,
                'direction' => 'in', 'reason' => 'opening', 'amount' => '100.0000',
                'balance_before' => '0', 'balance_after' => '100.0000',
                'actor_user_id' => $actor->id, 'created_at' => now(),
            ],
            [
                // This cash belongs to a drawer, not the branch safe.
                'branch_id' => 9901, 'is_drawer' => true,
                'direction' => 'in', 'reason' => 'customer_deposit', 'amount' => '900.0000',
                'balance_before' => '0', 'balance_after' => '900.0000',
                'actor_user_id' => $actor->id, 'created_at' => now(),
            ],
        ]);

        $result = app(ReconciliationService::class)->tills();

        $this->assertSame(0, $result['diverged'],
            'حركة درج صراف دُمجت مع خزنة الفرع وأنتجت فرقاً وهمياً');
        $this->assertSame(0, bccomp($result['gap'], '0', 4));
    }

    /** @test */
    public function recurring_safe_variance_creates_one_escalating_case(): void
    {
        $actor = User::factory()->create(['phone' => '967770091002']);

        DB::table('agent_cash_tills')->insert([
            'branch_id' => 9902, 'cash_on_hand' => '50.0000',
            'max_cash_on_hand' => '0', 'min_cash_alert' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agent_cash_movements')->insert([
            'branch_id' => 9902, 'is_drawer' => false,
            'direction' => 'in', 'reason' => 'opening', 'amount' => '100.0000',
            'balance_before' => '0', 'balance_after' => '100.0000',
            'actor_user_id' => $actor->id, 'created_at' => now(),
        ]);

        $this->artisan('amial:reconcile-nightly --quiet-alerts')->assertSuccessful();
        $this->artisan('amial:reconcile-nightly --quiet-alerts')->assertSuccessful();

        $case = ReconciliationCase::where('case_type', 'cash_till')
            ->where('branch_id', 9902)->firstOrFail();

        $this->assertSame(1, ReconciliationCase::where('case_type', 'cash_till')
            ->where('branch_id', 9902)->count());
        $this->assertSame(2, (int) $case->detection_count);
        $this->assertSame('high', $case->severity);
        $this->assertSame(0, bccomp((string) $case->difference, '-50.0000', 4));
    }
}
