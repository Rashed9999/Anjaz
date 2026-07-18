<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-HUB-001 — اللوحات المركزية الأربع للوحة الويب.
 */
class AdminHubTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009001']);
        EMoney::create([
            'user_id' => $this->admin->id, 'current_balance' => '1000000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    private function wallet(int $userId, string $balance = '0.0000'): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance, 'held_balance' => '0.0000',
            'pending_balance' => '0.0000', 'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function hub_pages_render_for_admin(): void
    {
        foreach (['customers', 'agents', 'merchants', 'finance'] as $page) {
            $this->actingAs($this->admin, 'user')
                ->get("/admin/amial/hub/{$page}")
                ->assertOk();
        }
    }

    /** @test */
    public function hub_pages_require_login(): void
    {
        $this->get('/admin/amial/hub/customers')->assertRedirect(route('admin.auth.login'));
    }

    /** @test */
    public function users_json_lists_only_requested_type(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009001']);
        User::factory()->create(['type' => AGENT_TYPE, 'phone' => '967771009002']);
        $this->wallet($customer->id, '5000.0000');

        $resp = $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/customers/users.json')
            ->assertOk()
            ->json();

        $ids = array_column($resp['data'], 'id');
        $this->assertContains($customer->id, $ids);
        $this->assertCount(1, $resp['data']);
        $row = collect($resp['data'])->firstWhere('id', $customer->id);
        $this->assertSame('5000.0000', $row['balance']);
    }

    /** @test */
    public function admin_can_add_customer_with_wallet(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/customers/users', [
                'f_name' => 'اختبار', 'l_name' => 'عميل',
                'phone' => '967771009010', 'password' => 'Secret@123',
            ])
            ->assertCreated();

        $user = User::where('phone', '967771009010')->first();
        $this->assertNotNull($user);
        $this->assertSame(CUSTOMER_TYPE, (int) $user->type);
        $this->assertTrue(EMoney::where('user_id', $user->id)->exists());
    }

    /** @test */
    public function duplicate_phone_is_rejected(): void
    {
        User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009011']);

        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/customers/users', [
                'f_name' => 'مكرر', 'phone' => '967771009011', 'password' => 'Secret@123',
            ])
            ->assertStatus(422);
    }

    /** @test */
    public function toggle_active_freezes_and_unfreezes(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009020', 'is_active' => 1]);

        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/toggle-active", ['reason' => 'اختبار'])
            ->assertOk()
            ->assertJson(['is_active' => false]);
        $this->assertSame(0, (int) $customer->fresh()->is_active);

        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/toggle-active", ['reason' => 'اختبار'])
            ->assertOk()
            ->assertJson(['is_active' => true]);
        $this->assertSame(1, (int) $customer->fresh()->is_active);
    }

    /** @test */
    public function kyc_approve_and_reject(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009030', 'is_kyc_verified' => 0]);

        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/kyc", ['status' => 1])
            ->assertOk();
        $this->assertSame(1, (int) $customer->fresh()->is_kyc_verified);

        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/hub/users/{$customer->id}/kyc", ['status' => 2])
            ->assertOk();
        $this->assertSame(2, (int) $customer->fresh()->is_kyc_verified);
    }

    /** @test */
    public function admin_transfer_moves_money_from_admin_wallet(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009040']);
        $this->wallet($customer->id);

        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/transfer', [
                'to_user_id' => $customer->id, 'amount' => '2500', 'reason' => 'إعادة مبلغ',
            ])
            ->assertOk();

        $this->assertSame('2500.0000',
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'));
        $this->assertSame('997500.0000',
            (string) EMoney::where('user_id', $this->admin->id)->value('current_balance'));
    }

    /** @test */
    public function transfer_fails_when_admin_balance_insufficient(): void
    {
        EMoney::where('user_id', $this->admin->id)->update(['current_balance' => '100.0000']);
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967771009041']);
        $this->wallet($customer->id);

        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/transfer', ['to_user_id' => $customer->id, 'amount' => '2500'])
            ->assertStatus(422);

        $this->assertSame('0.0000',
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'));
    }

    /** @test */
    public function admin_topup_credits_admin_wallet(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/finance/topup', ['amount' => '50000'])
            ->assertOk();

        $this->assertSame('1050000.0000',
            (string) EMoney::where('user_id', $this->admin->id)->value('current_balance'));
    }

    /** @test */
    public function finance_stats_and_feed_respond(): void
    {
        $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/finance/stats.json')
            ->assertOk()
            ->assertJsonStructure(['admin_balance', 'customers_balance', 'agents_balance',
                'merchants_balance', 'held_total', 'today_entries', 'today_volume']);

        $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/finance/feed.json')
            ->assertOk()
            ->assertJsonStructure(['max_id', 'data']);
    }
}
