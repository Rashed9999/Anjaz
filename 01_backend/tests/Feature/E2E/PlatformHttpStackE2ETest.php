<?php

namespace Tests\Feature\E2E;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * E2E — طبقة HTTP الكاملة (Routing → Middleware → Controller → JSON).
 *
 * يستهدف مسارات موجودة فعلاً في هذه الحزمة (Health + Unified Auth) لإثبات أن
 * الـ kernel و middleware و معالجة الأخطاء الموحّدة تعمل من طرف إلى طرف.
 */
class PlatformHttpStackE2ETest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function health_liveness_endpoint_responds_ok(): void
    {
        $response = $this->getJson(route('health.liveness'));

        $response->assertStatus(200);
    }

    /** @test */
    public function unified_login_rejects_missing_role_with_structured_error(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ROLE_REQUIRED');
    }

    /** @test */
    public function unified_login_rejects_invalid_role(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['role' => 'superuser']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_ROLE');
    }

    /** @test */
    public function unified_login_fails_with_wrong_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'role' => 'customer',
            'national_id' => '12345678',
            'phone' => '+967700000099',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_FAILED');
    }

    /** @test */
    public function protected_endpoint_requires_authentication(): void
    {
        // بدون توكن → يجب أن يُرفض الوصول لمسار محمي
        $response = $this->getJson('/api/v1/amial/me');

        $this->assertContains($response->getStatusCode(), [401, 403, 404]);
    }

    /** @test */
    public function authenticated_user_passes_auth_guard(): void
    {
        // إثبات أن طبقة Passport actingAs تمرّ عبر الـ guard كاملاً
        $user = User::factory()->create(['zone_code' => 'SOUTH']);
        Passport::actingAs($user, [], 'api');

        $response = $this->getJson('/api/v1/amial/me');

        // المستخدم مُصادَق → لا يجب أن يُرجَع 401
        $this->assertNotSame(401, $response->getStatusCode());
    }
}
