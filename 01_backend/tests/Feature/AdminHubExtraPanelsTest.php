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

    /**
     * @test AMIAL-CSP-FIX: كل سكربتات لوحات الـhub يجب أن تحمل nonce الـCSP —
     * وإلّا حجبها المتصفّح فتتوقّف كل الأزرار والقوائم («جار التحميل» للأبد).
     * هذا الاختبار يمنع تكرار العطل: يتحقّق أن السكربت المضمّن يحمل نفس الـnonce
     * الموجود في ترويسة Content-Security-Policy لكل صفحة.
     */
    public function every_hub_page_inline_script_carries_the_csp_nonce(): void
    {
        $pages = ['customers', 'agents', 'merchants', 'finance',
            'settlements', 'staff', 'settings', 'subscriptions', 'disputes', 'verification'];

        foreach ($pages as $page) {
            $resp = $this->actingAs($this->admin, 'user')->get("/admin/amial/hub/{$page}");
            $resp->assertOk();

            $html = $resp->getContent();
            $csp = $resp->headers->get('Content-Security-Policy');
            $this->assertNotNull($csp, "لا ترويسة CSP في صفحة {$page}");

            // استخرج الـnonce من الترويسة
            preg_match("/'nonce-([^']+)'/", (string) $csp, $m);
            $nonce = $m[1] ?? null;
            $this->assertNotNull($nonce, "لا nonce في CSP لصفحة {$page}");

            // كل صفحة hub فيها سكربت مضمّن — يجب أن يحمل هذا الـnonce
            $this->assertStringContainsString(
                'script nonce="' . $nonce . '"',
                $html,
                "سكربت صفحة {$page} لا يحمل nonce الـCSP — سيحجبه المتصفّح"
            );
            // ولا يوجد سكربت مضمّن مجرّد بلا nonce (باستثناء سكربتات src=)
            $this->assertStringNotContainsString('<script>', $html,
                "صفحة {$page} فيها سكربت بلا nonce سيُحجب");
        }
    }
}
