<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-NO-REMEMBER-001 / AMIAL-ADMIN-IDLE-001 — جلسة لوحة الإدارة.
 *
 * **ما وصل من التجربة الحيّة:** صاحب المشروع دخل «لوحة الإدارة» من هاتفه بلا كلمة مرور. لم يكن في الشيفرة باب بلا كلمة
 * مرور (الأبواب الثلاثة تشترطها)، فالسببُ جلسةٌ محفوظةٌ بـ«تذكرني» تبقى سنواتٍ. وهاتفٌ يضيع بجلسةٍ كهذه يفتح لوحةً فيها
 * أموالٌ وهويّات. فلا «تذكرني» للإدارة، والجلسةُ تنتهي بالخمول.
 */
class AdminNoRememberAndIdleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009301']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    /** @test */
    public function no_admin_door_passes_the_remember_flag_to_the_guard(): void
    {
        foreach ([
            'app/Http/Controllers/Admin/Auth/LoginController.php' => "auth('user')->login(\$admin, false)",
            'app/Http/Controllers/Admin/Auth/TwoFactorChallengeController.php' => "auth('user')->login(\$admin, false)",
            'app/Http/Controllers/Web/UnifiedLoginController.php' => "Auth::guard('user')->login(\$user, false)",
        ] as $file => $expected) {
            $src = file_get_contents(base_path($file));
            $this->assertStringContainsString($expected, $src, "{$file}: دخول الإدارة لا يجوز أن يتذكّر");
            $this->assertStringNotContainsString("login(\$admin, \$request->boolean('remember'))", $src);
            $this->assertStringNotContainsString("Auth::guard('user')->login(\$user, \$request->boolean('remember'))", $src);
        }

        $adminView = file_get_contents(resource_path('views/admin-views/auth/login.blade.php'));
        $this->assertStringNotContainsString('name="remember"', $adminView, 'خانةُ «تذكرني» ما زالت في دخول الإدارة');
    }

    /** @test */
    public function an_active_admin_session_works_and_stamps_its_activity(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'user')->get('/admin/auth/change-pin')->assertSuccessful();

        $this->assertGreaterThan(time() - 5, (int) session('admin_last_activity'), 'لا يُسجَّل آخر نشاط');
    }

    /** @test */
    public function an_admin_session_idle_past_the_limit_is_ended(): void
    {
        config(['amial.admin_idle_minutes' => 30]);
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'user')
            ->withSession(['admin_last_activity' => time() - 31 * 60])
            ->get('/admin/auth/change-pin');

        $res->assertRedirect(route('admin.auth.login'));
        $this->assertGuest('user');
    }

    /** @test */
    public function activity_inside_the_limit_keeps_the_session(): void
    {
        config(['amial.admin_idle_minutes' => 30]);
        $admin = $this->admin();

        $this->actingAs($admin, 'user')
            ->withSession(['admin_last_activity' => time() - 20 * 60])
            ->get('/admin/auth/change-pin')->assertSuccessful();
        $this->assertAuthenticated('user');
    }

    /** @test */
    public function the_idle_limit_has_a_floor_so_it_cannot_be_set_to_zero_by_mistake(): void
    {
        config(['amial.admin_idle_minutes' => 0]);
        $admin = $this->admin();

        $this->actingAs($admin, 'user')
            ->withSession(['admin_last_activity' => time() - 3 * 60])
            ->get('/admin/auth/change-pin')->assertSuccessful();
    }
}
