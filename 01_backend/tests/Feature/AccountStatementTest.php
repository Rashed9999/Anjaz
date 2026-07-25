<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-STATEMENT-001 — اختبارات كشف حساب المحفظة.
 */
class AccountStatementTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $u = User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
        ]);
        EMoney::create([
            'user_id' => $u->id,
            'current_balance' => '10000.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        return $u;
    }

    private function txn(User $u, array $attrs): Transaction
    {
        return Transaction::create(array_merge([
            'transaction_id' => (string) \Illuminate\Support\Str::ulid(),
            'ref_trans_id' => (string) \Illuminate\Support\Str::ulid(),
            'user_id' => $u->id,
            'transaction_type' => 'send_money',
            'debit' => '0',
            'credit' => '0',
            'charge' => '0',
            'balance' => '0',
            'zone_code' => 'SOUTH',
        ], $attrs));
    }

    public function test_statement_returns_movements_with_totals(): void
    {
        $u = $this->actor();

        $this->txn($u, ['debit' => '1000', 'balance' => '9000', 'transaction_type' => 'send_money']);
        $this->txn($u, ['credit' => '500', 'balance' => '9500', 'transaction_type' => 'received_money']);
        $this->txn($u, ['debit' => '200', 'balance' => '9300', 'transaction_type' => 'cash_out']);

        $r = $this->actingAs($u, 'api')->getJson('/api/v1/amial/statement');

        $r->assertOk()->assertJsonPath('success', true);

        $meta = $r->json('meta');
        $this->assertCount(3, $meta['items']);
        $this->assertEquals('1200.0000', $meta['total_debit']);
        $this->assertEquals('500.0000', $meta['total_credit']);
        $this->assertEquals('9300.0000', $meta['closing_balance']);
        $this->assertFalse($meta['truncated']);
    }

    public function test_each_row_carries_a_human_statement_line(): void
    {
        $u = $this->actor();
        $this->txn($u, ['debit' => '750', 'balance' => '9250', 'transaction_type' => 'cash_out']);

        $items = $this->actingAs($u, 'api')
            ->getJson('/api/v1/amial/statement')
            ->json('meta.items');

        $this->assertNotEmpty($items[0]['statement']);
        $this->assertNotEmpty($items[0]['type_label']);
        // البيان يجب أن يكون عربياً مفهوماً لا رمز نوع خام.
        $this->assertStringNotContainsString('cash_out', $items[0]['statement']);
    }

    public function test_range_filter_excludes_movements_outside_period(): void
    {
        $u = $this->actor();

        $old = $this->txn($u, ['debit' => '100', 'balance' => '9900']);
        $old->created_at = now()->subDays(90);
        $old->save();

        $this->txn($u, ['debit' => '50', 'balance' => '9850']);

        $meta = $this->actingAs($u, 'api')
            ->getJson('/api/v1/amial/statement?from=' . now()->subDays(5)->toDateString()
                . '&to=' . now()->toDateString())
            ->json('meta');

        $this->assertCount(1, $meta['items']);
        $this->assertEquals('50.0000', $meta['total_debit']);
    }

    public function test_opening_balance_reflects_state_before_period(): void
    {
        $u = $this->actor();

        $old = $this->txn($u, ['debit' => '1000', 'balance' => '7777']);
        $old->created_at = now()->subDays(60);
        $old->save();

        $meta = $this->actingAs($u, 'api')
            ->getJson('/api/v1/amial/statement?from=' . now()->subDays(5)->toDateString()
                . '&to=' . now()->toDateString())
            ->json('meta');

        $this->assertEquals('7777.0000', $meta['opening_balance']);
    }

    public function test_statement_never_leaks_another_users_movements(): void
    {
        $mine = $this->actor();
        $other = $this->actor();

        $this->txn($other, ['debit' => '9999', 'balance' => '1']);

        $meta = $this->actingAs($mine, 'api')
            ->getJson('/api/v1/amial/statement')
            ->json('meta');

        $this->assertCount(0, $meta['items']);
        $this->assertEquals('0', $meta['total_debit']);
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $u = $this->actor();
        $this->txn($u, ['debit' => '300', 'balance' => '9700']);

        $r = $this->actingAs($u, 'api')->get('/api/v1/amial/statement/pdf');

        $r->assertOk();
        $this->assertStringContainsString('application/pdf', $r->headers->get('content-type'));
        $this->assertStringStartsWith("%PDF", $r->getContent());
    }

    public function test_guest_cannot_read_a_statement(): void
    {
        $this->getJson('/api/v1/amial/statement')->assertUnauthorized();
    }
}
