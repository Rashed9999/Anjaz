<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MAINT-001 — واجهة ويب لوحة الصيانة الأولية (جلسة الأدمن).
 */
class MaintenanceWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_maintenance_page(): void
    {
        $admin = User::factory()->create(['type' => 0, 'phone' => '967770008001']);
        // AMIAL-ADMIN-DOORS-002 — وضعُ الصيانة يُوقف المنصّةَ على الجميع،
        // فصار خلف `settings.update`.
        app(\App\Services\PlatformRoleService::class)
            ->assign($admin, \App\Services\PlatformRoleService::ADMIN);
        $admin->refresh();
        $this->seed(\Database\Seeders\FeatureFlagsSeeder::class);

        $this->actingAs($admin, 'user')
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSee('maint-panel', false)
            ->assertSee('الصيانة الأولية');
    }

    public function test_web_list_endpoint_returns_features(): void
    {
        $admin = User::factory()->create(['type' => 0, 'phone' => '967770008002']);
        // AMIAL-ADMIN-DOORS-002 — وضعُ الصيانة يُوقف المنصّةَ على الجميع،
        // فصار خلف `settings.update`.
        app(\App\Services\PlatformRoleService::class)
            ->assign($admin, \App\Services\PlatformRoleService::ADMIN);
        $admin->refresh();
        $this->seed(\Database\Seeders\FeatureFlagsSeeder::class);

        $this->actingAs($admin, 'user')
            ->getJson('/admin/maintenance/list')
            ->assertOk()
            ->assertJsonPath('meta.features.0.key', fn ($k) => is_string($k));
    }

    public function test_guest_redirected(): void
    {
        $this->get('/admin/maintenance')->assertRedirect(route('admin.auth.login'));
    }
}
