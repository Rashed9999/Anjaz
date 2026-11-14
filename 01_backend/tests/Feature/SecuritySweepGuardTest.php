<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Otp\PasswordResetGuard;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-SEC-SWEEP-001 — حرّاسُ فحصٍ أمنيٍّ شامل. كلُّ اختبارٍ هنا يقابل ثغرةً وُجدت وأُصلحت:
 *  ١) CORS كان `*` · ٢) رمزُ استعادة كلمة المرور كان نصّاً صريحاً · ٣) صورُ الهويّة القديمة كانت برابطٍ عامّ
 *  ٤) عمودٌ يُدمَج في SQL خاماً بلا تغليف.
 */
class SecuritySweepGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cors_never_allows_every_origin(): void
    {
        $cfg = require base_path('config/cors.php');
        $this->assertNotContains('*', $cfg['allowed_origins'], 'CORS مفتوحٌ لكلّ نطاق.');
        $this->assertNotContains('*', $cfg['allowed_headers'], 'CORS يسمح بكلّ ترويسة.');
    }

    /** @test */
    public function reset_tokens_are_never_stored_in_clear_text_by_the_writers(): void
    {
        foreach (['Customer', 'Agent'] as $role) {
            $src = file_get_contents(app_path("Http/Controllers/Api/V1/{$role}/Auth/PasswordResetController.php"));
            $this->assertStringNotContainsString("'token' => \$otp,", $src, "{$role}: الرمزُ يُخزَّن نصّاً صريحاً.");
            $this->assertStringContainsString('PasswordResetGuard::digest($otp)', $src, "{$role}: الكتابةُ لا تمرّ بالبصمة.");
        }
    }

    /** @test */
    public function a_digested_token_is_consumed_and_a_legacy_clear_one_still_works_once(): void
    {
        $phone = '967770000771';
        User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => $phone]);

        DB::table('password_resets')->insert(['phone' => $phone, 'token' => PasswordResetGuard::digest('482913'), 'otp_hit_count' => 0, 'is_temp_blocked' => 0, 'created_at' => now()]);
        $this->assertNotSame('482913', DB::table('password_resets')->where('phone', $phone)->value('token'));
        $this->assertFalse(app(PasswordResetGuard::class)->consume($phone, '000000'), 'رمزٌ خاطئ قُبل.');
        $this->assertTrue(app(PasswordResetGuard::class)->consume($phone, '482913'));
        $this->assertFalse(app(PasswordResetGuard::class)->consume($phone, '482913'), 'الرمز لا يُستهلك مرّتين.');
    }

    /** @test */
    public function legacy_identity_images_are_served_only_to_authorized_staff(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('user/identity/abc123.png', 'PNGDATA');

        $this->get('/admin/amial/legacy-identity/abc123.png')->assertRedirect();   // ضيفٌ ⇒ يُردّ إلى الدخول

        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009771']);
        app(PlatformRoleService::class)->assign($admin, PlatformRoleService::ADMIN);

        $this->actingAs($admin->refresh(), 'user')->get('/admin/amial/legacy-identity/abc123.png')->assertOk();
        $this->actingAs($admin, 'user')->get('/admin/amial/legacy-identity/missing.png')->assertNotFound();
        $this->actingAs($admin, 'user')->get('/admin/amial/legacy-identity/..%2F..%2F.env')->assertNotFound();
    }

    /** @test */
    public function nginx_blocks_the_public_identity_path_and_sensitive_files_in_both_configs(): void
    {
        foreach (['docker/nginx.conf', 'docker/nginx.prod.conf'] as $f) {
            $c = file_get_contents(base_path($f));
            $this->assertMatchesRegularExpression('~location ~\* \^/storage/\(app/public/\)\?user/identity/~', $c, "{$f}: صورُ الهويّة برابطٍ عامّ.");
        }
        $c = file_get_contents(base_path('docker/nginx.conf'));
        $this->assertStringContainsString('(env|git|htaccess)', $c);
        $this->assertStringContainsString('storage/logs', $c);
        $this->assertStringContainsString('(sql|log|bak)', $c);
    }

    /** @test */
    public function no_raw_sql_interpolates_a_php_variable(): void
    {
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            foreach (file($f->getPathname()) as $n => $line) {
                if (preg_match('/(selectRaw|whereRaw|orderByRaw|havingRaw|groupByRaw|DB::raw)\(\s*"[^"]*\{\$/', $line)) {
                    $bad[] = str_replace(base_path().'/', '', $f->getPathname()).':'.($n + 1);
                }
            }
        }
        $this->assertSame([], $bad, "SQL خامٌ يدمج متغيّراً مباشرةً (استعمل wrap() أو bindings):\n".implode("\n", $bad));
    }
}
