<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-OPS-001 — قسم التشغيل: وضع الصيانة + تنظيف الكاش + إصدار التطبيق.
 *
 * يثبت: التفعيل من اللوحة يحجب غير الأدمن بـ 503 بينما يمرّ الأدمن ومسارات
 * الدخول، الإيقاف يعيد كل شيء، وضبط الإصدار يظهر في الـ endpoint العام.
 */
class AdminOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Schema::hasTable('addon_settings')) {
            Schema::create('addon_settings', function (Blueprint $t) {
                $t->string('id', 36)->primary();
                $t->string('key_name', 191)->nullable();
                $t->longText('live_values')->nullable();
                $t->longText('test_values')->nullable();
                $t->string('settings_type', 255)->nullable();
                $t->string('mode', 20)->default('live');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->longText('additional_data')->nullable();
            });
        }
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['type' => 0, 'role' => 'super_admin'])->save();
        return $u;
    }

    public function test_non_admin_cannot_manage_ops(): void
    {
        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/v1/amial/admin/ops/status')->assertStatus(403);
        $this->postJson('/api/v1/amial/admin/ops/maintenance', ['enabled' => true])->assertStatus(403);
    }

    public function test_maintenance_blocks_public_but_not_admin_or_login(): void
    {
        $admin = $this->admin();
        Passport::actingAs($admin);

        // التفعيل من اللوحة
        $this->postJson('/api/v1/amial/admin/ops/maintenance', [
            'enabled' => true, 'message' => 'صيانة مجدولة',
        ])->assertOk()->assertJsonPath('meta.maintenance.enabled', true);

        // مستخدم عادي يُحجب بـ 503 + الرسالة (الأدمن المُفعِّل يمرّ بطبيعته)
        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/v1/amial/support-contact')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MAINTENANCE')
            ->assertJsonPath('message', 'صيانة مجدولة');

        // ping يمرّ (للمراقبة)
        $this->getJson('/api/v1/amial/ping')->assertOk();

        // الأدمن يصل لمسارات الأدمن (لإيقاف الصيانة)
        Passport::actingAs($admin);
        $this->getJson('/api/v1/amial/admin/ops/status')->assertOk()
            ->assertJsonPath('meta.maintenance.enabled', true);
        $this->getJson('/api/v1/amial/support-contact')->assertOk(); // الأدمن غير محجوب

        // الإيقاف يعيد كل شيء للمستخدم العادي
        $this->postJson('/api/v1/amial/admin/ops/maintenance', ['enabled' => false])->assertOk();
        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/v1/amial/support-contact')->assertOk();
    }

    public function test_clear_cache_works(): void
    {
        Passport::actingAs($this->admin());
        cache()->put('probe_key', 'x', 60);
        $this->assertSame('x', cache()->get('probe_key'));

        $this->postJson('/api/v1/amial/admin/ops/clear-cache')
            ->assertOk()->assertJsonPath('code', 'CLEARED');

        $this->assertNull(cache()->get('probe_key'));
    }

    public function test_app_version_saved_and_public(): void
    {
        Passport::actingAs($this->admin());

        $this->postJson('/api/v1/amial/admin/ops/app-version', [
            'min_version' => '1.2.0', 'latest_version' => '1.3.0', 'message' => 'حدّث تطبيقك',
        ])->assertOk()->assertJsonPath('meta.app_version.min_version', '1.2.0');

        // الـ endpoint العام (بدون auth)
        auth()->forgetGuards();
        $this->getJson('/api/v1/amial/app-version')->assertOk()
            ->assertJsonPath('meta.app_version.min_version', '1.2.0')
            ->assertJsonPath('meta.app_version.latest_version', '1.3.0');

        // صيغة غير صالحة تُرفض
        Passport::actingAs($this->admin());
        $this->postJson('/api/v1/amial/admin/ops/app-version', ['min_version' => 'abc'])
            ->assertStatus(422);
    }
}
