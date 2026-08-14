<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-OPS-CONSOLE-001 — واجهة الويب لمنصة العمليات (جلسة الأدمن).
 */
class SupportConsoleWebTest extends TestCase
{
    use RefreshDatabase;

    /** حسابُ إدارةٍ بدوره — لا بنوعه وحده. */
    private function operator(string $phone): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'admin', 'phone' => $phone,
        ]);

        app(\App\Services\PlatformRoleService::class)
            ->assign($u, \App\Services\PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    public function test_admin_sees_console_page(): void
    {
        // **نوعُ الحساب لا يفتح الشاشة** — نقاطُ الدعم صارت خلف صلاحيّةٍ
        // منصّيّة، فحسابُ إدارةٍ بلا دورٍ يُردّ `PERMISSION_DENIED`.
        $admin = $this->operator('967770000010');

        $this->actingAs($admin, 'user')
            ->get('/admin/support-center')
            ->assertOk()
            ->assertSee('ops-console', false)
            ->assertSee('خدمة العملاء');
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/admin/support-center')->assertRedirect(route('admin.auth.login'));
    }

    public function test_web_json_endpoints_work_with_session(): void
    {
        $admin = $this->operator('967770000011');
        $customer = User::factory()->create(['type' => 2, 'phone' => '967771555099']);

        $this->actingAs($admin, 'user')
            ->getJson('/admin/support-center/search?q=967771555099')
            ->assertOk()
            ->assertJsonPath('meta.users.0.id', $customer->id);

        $this->actingAs($admin, 'user')
            ->getJson('/admin/support-center/ops-dashboard')
            ->assertOk()
            ->assertJsonPath('meta.health.database', 'up');
    }

    public function test_non_admin_user_cannot_access(): void
    {
        $customer = User::factory()->create(['type' => 2, 'phone' => '967771555098']);

        $this->actingAs($customer, 'user')
            ->get('/admin/support-center')
            ->assertRedirect(route('admin.auth.login'));
    }
}
