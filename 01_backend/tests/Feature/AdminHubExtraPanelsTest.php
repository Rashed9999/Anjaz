<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-HUB-001 — اللوحات الإضافية: التسويات، الموظفين، الإعدادات.
 */
class AdminHubExtraPanelsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009300']);
    }

    /** @test */
    public function extra_hub_pages_render(): void
    {
        foreach (['settlements', 'staff', 'settings'] as $page) {
            $this->actingAs($this->admin, 'user')
                ->get("/admin/amial/hub/{$page}")
                ->assertOk();
        }
    }

    /** @test */
    public function settlements_json_lists_and_summarizes(): void
    {
        $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/settlements/list.json?status=pending')
            ->assertOk()
            ->assertJsonStructure(['summary' => ['pending', 'completed'], 'data']);
    }

    /** @test */
    public function staff_json_responds(): void
    {
        $this->actingAs($this->admin, 'user')
            ->getJson('/admin/amial/hub/staff/list.json')
            ->assertOk()
            ->assertJsonStructure(['summary' => ['total', 'active'], 'data']);
    }

    /** @test */
    public function settings_flag_toggle_persists_to_business_settings(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/settings/flag', ['key' => 'phone_verification', 'value' => '1'])
            ->assertOk();

        $this->assertDatabaseHas('business_settings', [
            'key' => 'phone_verification', 'value' => '1',
        ]);

        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/settings/flag', ['key' => 'phone_verification', 'value' => '0'])
            ->assertOk();
        $this->assertDatabaseHas('business_settings', [
            'key' => 'phone_verification', 'value' => '0',
        ]);
    }

    /** @test */
    public function settings_flag_rejects_unknown_key(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/hub/settings/flag', ['key' => 'drop_all_tables', 'value' => '1'])
            ->assertStatus(422);
    }

    /** @test */
    public function maintenance_panel_is_reachable(): void
    {
        $this->actingAs($this->admin, 'user')
            ->get('/admin/maintenance')
            ->assertOk();
    }
}
