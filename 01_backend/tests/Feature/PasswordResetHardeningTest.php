<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Otp\PasswordResetGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SEC-2026-11 — reset-password كان يقبل تخمين الرمز بلا عدّ ولا انتهاء صلاحية:
 * من يعرف رقم هاتف يجرّب الرموز مباشرةً ويستولي على الحساب.
 */
class PasswordResetHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function seedReset(string $phone, string $otp = '654321', int $ageSeconds = 0): User
    {
        $user = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => $phone, 'password' => bcrypt('1111')]);
        DB::table('password_resets')->insert(['phone' => $phone, 'token' => $otp, 'otp_hit_count' => 0, 'is_temp_blocked' => 0, 'created_at' => now()->subSeconds($ageSeconds)]);

        return $user;
    }

    private function submit(string $phone, string $otp, string $pw = '2222', ?string $confirm = null, string $role = 'customer')
    {
        return $this->withHeaders(['device-id' => 'dev-'.bin2hex(random_bytes(4))])
            ->putJson("/api/v1/{$role}/auth/reset-password", ['phone' => $phone, 'otp' => $otp, 'password' => $pw, 'confirm_password' => $confirm ?? $pw]);
    }

    public function test_guessing_is_counted_and_blocks_even_the_right_code(): void
    {
        $user = $this->seedReset('+967770000001');
        $guard = app(PasswordResetGuard::class);
        for ($i = 0; $i < PasswordResetGuard::MAX_FAILS; $i++) {
            $this->assertFalse($guard->consume('+967770000001', '00000'.$i));
        }
        $this->submit('+967770000001', '654321')->assertStatus(400);
        $this->assertTrue(Hash::check('1111', $user->fresh()->password), 'الرمز الصحيح بعد الحظر لا يغيّر كلمة المرور');
    }

    public function test_wrong_code_over_http_never_changes_the_password(): void
    {
        $user = $this->seedReset('+967770000002');
        $this->submit('+967770000002', '111111')->assertStatus(400);
        $this->assertSame(1, (int) DB::table('password_resets')->where('phone', '+967770000002')->value('otp_hit_count'));
        $this->assertTrue(Hash::check('1111', $user->fresh()->password));
    }

    public function test_expired_code_is_refused(): void
    {
        $user = $this->seedReset('+967770000003', '654321', PasswordResetGuard::TTL_SECONDS + 5);
        $this->submit('+967770000003', '654321')->assertStatus(400);
        $this->assertTrue(Hash::check('1111', $user->fresh()->password));
    }

    public function test_valid_code_works_once_for_customer_and_agent_routes(): void
    {
        foreach (['customer' => '+967770000004', 'agent' => '+967770000005'] as $role => $phone) {
            $user = $this->seedReset($phone);
            $this->submit($phone, '654321', '2222', '3333', $role)->assertStatus(401);
            $this->assertSame(1, DB::table('password_resets')->where('phone', $phone)->count(), 'عدم التطابق لا يستهلك الرمز');
            $this->submit($phone, '654321', '2222', null, $role)->assertOk();
            $this->assertTrue(Hash::check('2222', $user->fresh()->password));
            $this->submit($phone, '654321', '4444', null, $role)->assertStatus(400);
            $this->assertTrue(Hash::check('2222', $user->fresh()->password), 'الرمز لا يُستعمل مرتين');
        }
    }

    public function test_auth_routes_are_throttled_per_target_phone_across_devices(): void
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = $this->submit('+967770000009', '000000')->getStatusCode();
        }
        $this->assertContains(429, $codes, 'تخمين رقم بعينه يُحدّ ولو تغيّر الجهاز');
        $this->assertNotSame(429, $this->submit('+967770000010', '000000')->getStatusCode(), 'رقم آخر لا يتأثر');
    }
}
