<?php

namespace Tests\Feature;

use App\Models\FeeScheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FEE-ENGINE-001 — لوحة تحكم الرسوم (HTTP).
 *
 * نتجاوز middleware 'admin' (يُختبر في مكان آخر) ونركّز على
 * route → controller → FeeService → DB والمحاكي JSON.
 */
class FeeSchemeAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function store_creates_a_new_fee_version()
    {
        $resp = $this->withoutMiddleware()->post('/admin/amial/fees', [
            'code' => 'SEND_MONEY',
            'label' => 'تحويل',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent',
            'percent_rate' => '1.5',
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ]);

        $resp->assertRedirect();
        $this->assertDatabaseHas('fee_schemes', [
            'code' => 'SEND_MONEY', 'version' => 1, 'is_active' => 1,
        ]);
    }

    /** @test */
    public function store_again_supersedes_and_makes_version_2()
    {
        $payload = [
            'code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '20',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0', 'bearer' => 'sender',
        ];
        $this->withoutMiddleware()->post('/admin/amial/fees', $payload);
        $this->withoutMiddleware()->post('/admin/amial/fees', array_merge($payload, ['fixed_amount' => '30']));

        $this->assertDatabaseHas('fee_schemes', ['code' => 'CASH_OUT', 'version' => 2, 'is_active' => 1]);
        $this->assertDatabaseHas('fee_schemes', ['code' => 'CASH_OUT', 'version' => 1, 'is_active' => 0]);
    }

    /** @test */
    public function store_rejects_invalid_percent()
    {
        $resp = $this->withoutMiddleware()->post('/admin/amial/fees', [
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '150', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0', 'bearer' => 'sender',
        ]);
        $resp->assertSessionHasErrors();
        $this->assertDatabaseCount('fee_schemes', 0);
    }

    /** @test */
    public function simulate_returns_breakdown_json()
    {
        $resp = $this->withoutMiddleware()->postJson('/admin/amial/fees/simulate', [
            'amount' => '1000',
            'code' => 'SEND_MONEY',
            'fee_type' => 'percent',
            'percent_rate' => '1',
            'fixed_amount' => '0',
            'agent_commission_percent' => '40',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ]);

        $resp->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.fee', '10.0000')
            ->assertJsonPath('result.agent_commission', '4.0000')
            ->assertJsonPath('result.platform_profit', '6.0000');
    }

    /** @test */
    public function deactivate_marks_scheme_inactive()
    {
        $scheme = FeeScheme::create([
            'code' => 'BILL_PAY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '10',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $resp = $this->withoutMiddleware()->post("/admin/amial/fees/{$scheme->id}/deactivate");
        $resp->assertRedirect();
        $this->assertFalse($scheme->fresh()->is_active);
    }
}
