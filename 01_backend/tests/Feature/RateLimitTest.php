<?php

namespace Tests\Feature;

use Laravel\Passport\Passport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-RATELIMIT-001 (v1.0-D) — اختبارات.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('amial_rl:test_action:ip:127.0.0.1');

        // route للاختبار فقط
        Route::middleware(['amial.rate-limit:test_action,3,1,true'])
            ->get('/_test/rate-limit', function () {
                return response()->json(['ok' => true]);
            });
    }

    /** @test */
    public function it_allows_requests_under_the_limit()
    {
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->get('/_test/rate-limit');
            $this->assertEquals(200, $response->status(), "Request {$i} should succeed");
        }
    }

    /** @test */
    public function it_blocks_request_above_the_limit_with_429()
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->get('/_test/rate-limit')->assertOk();
        }

        $response = $this->get('/_test/rate-limit');
        $response->assertStatus(429);
        $response->assertJsonPath('code', 'RATE_LIMITED');
        $response->assertJsonStructure(['meta' => ['retry_after_seconds']]);
    }

    /** @test */
    public function rate_limit_response_includes_retry_after_header()
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->get('/_test/rate-limit');
        }

        $response = $this->get('/_test/rate-limit');
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('3', $response->headers->get('X-RateLimit-Limit'));
    }

    /** @test */
    public function rate_limit_is_per_ip_for_unauthenticated_routes()
    {
        // simulate IP 1
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1']);
        for ($i = 1; $i <= 3; $i++) {
            $this->get('/_test/rate-limit')->assertOk();
        }
        $this->get('/_test/rate-limit')->assertStatus(429);

        // simulate IP 2 — fresh quota
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2']);
        $this->get('/_test/rate-limit')->assertOk();
    }

    /** @test */
    public function rate_limit_is_per_user_when_authenticated()
    {
        // مسار اختبار يحمل rate-limit per-user فقط؛ نُصادق عبر actingAs (الحارس يقرأ $request->user())
        Route::middleware(['amial.rate-limit:user_test,2,1,false'])
            ->get('/_test/user-rl', fn() => response()->json(['ok' => true]));

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // user1: 2 success, 3rd is 429
        $this->actingAs($user1);
        $this->get('/_test/user-rl')->assertOk();
        $this->get('/_test/user-rl')->assertOk();
        $this->get('/_test/user-rl')->assertStatus(429);

        // user2 (مستخدم مختلف): نظيف — يثبت التمييز per-user
        $this->actingAs($user2);
        $this->get('/_test/user-rl')->assertOk();
    }
}
