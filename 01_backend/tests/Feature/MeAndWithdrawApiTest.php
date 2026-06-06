<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Notification;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Models\AmialNotification;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-MERGED — اختبارات تكامل API:
 *   - شاشة "رقم حسابي" (Me).
 *   - السحب من العميل (مع إشعار تلقائي).
 */
class MeAndWithdrawApiTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create([
            'type' => 2,
            'phone' => '+967700111222',
            'account_number' => '12345678',
            'zone_code' => 'SOUTH',
        ]);
        EMoney::create([
            'user_id' => $this->customer->id,
            'current_balance' => '100000.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function me_endpoint_returns_user_info_and_balance(): void
    {
        Passport::actingAs($this->customer);

        $r = $this->getJson('/api/v1/amial/me');

        $r->assertOk()
          ->assertJsonPath('success', true)
          ->assertJsonPath('meta.account_number', '12345678')
          ->assertJsonPath('meta.phone', '+967700111222')
          ->assertJsonPath('meta.zone_code', 'SOUTH')
          ->assertJsonPath('meta.roles.is_customer', true);
    }

    /** @test */
    public function account_number_endpoint_returns_only_account_number(): void
    {
        Passport::actingAs($this->customer);

        $r = $this->getJson('/api/v1/amial/me/account-number');
        $r->assertOk()->assertJsonPath('meta.account_number', '12345678');
    }

    /** @test */
    public function withdraw_request_creates_pending_and_dispatches_notification(): void
    {
        Passport::actingAs($this->customer);

        $r = $this->postJson('/api/v1/amial/withdraw/request', ['amount' => '5000'], ['Idempotency-Key' => (string) Str::ulid()]);

        $r->assertOk()->assertJsonPath('success', true);
        $opCode = $r->json('meta.op_code');
        $this->assertNotEmpty($opCode);

        // الطلب موجود
        $this->assertDatabaseHas('withdrawal_requests', [
            'customer_user_id' => $this->customer->id,
            'op_code' => $opCode,
            'status' => 'pending',
        ]);

        // الإشعار أُنشئ تلقائياً
        $n = AmialNotification::where('user_id', $this->customer->id)
            ->where('type', 'withdraw_pending')
            ->first();
        $this->assertNotNull($n, 'إشعار طلب السحب يجب أن يُنشأ');
        $this->assertSame($opCode, $n->data['op_code']);
    }

    /** @test */
    public function withdraw_mine_lists_pending_requests(): void
    {
        Passport::actingAs($this->customer);

        $this->postJson('/api/v1/amial/withdraw/request', ['amount' => '3000'], ['Idempotency-Key' => (string) Str::ulid()]);

        $r = $this->getJson('/api/v1/amial/withdraw/mine');
        $r->assertOk();
        $this->assertCount(1, $r->json('meta.requests'));
    }

    /** @test */
    public function withdraw_cancel_releases_hold(): void
    {
        Passport::actingAs($this->customer);

        $r1 = $this->postJson('/api/v1/amial/withdraw/request', ['amount' => '2000'], ['Idempotency-Key' => (string) Str::ulid()]);
        $reqId = $r1->json('meta.request_id');

        $r2 = $this->postJson("/api/v1/amial/withdraw/{$reqId}/cancel");
        $r2->assertOk()->assertJsonPath('meta.status', 'cancelled');

        // الحجز فُكّ
        $this->customer->refresh();
        $balance = EMoney::where('user_id', $this->customer->id)->first();
        $this->assertSame('0.0000', (string)$balance->held_balance);
    }
}
