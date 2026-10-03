<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Otp\EmailOtpService;
use App\Services\PlatformRoleService;
use App\Support\PlatformAccessTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationCenterGuardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role): User
    {
        $user = User::factory()->admin()->create();
        app(PlatformRoleService::class)->assign($user, $role);
        return $user;
    }

    private function challenge(array $attributes = []): string
    {
        $id = (string) Str::ulid();
        DB::table('otp_challenges')->insert(array_replace([
            'challenge_id' => $id,
            'identifier' => 'private-recipient@example.test',
            'channel' => 'email',
            'purpose' => 'password_reset',
            'token_hash' => Hash::make('739582'),
            'expires_at' => now()->addMinutes(5),
            'delivery_status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
        return $id;
    }

    public function test_historical_provider_errors_are_redacted_even_for_pii_readers(): void
    {
        $this->challenge(['last_error' => 'RESEND_HTTP_400: echoed OTP 739582 private-provider-payload']);
        $this->actingAs($this->operator(PlatformRoleService::COMPLIANCE), 'user')
            ->get(route('admin.amial.email-center.index'))
            ->assertOk()
            ->assertSee('pr***@example.test')
            ->assertDontSee('private-recipient@example.test')
            ->assertDontSee('739582')
            ->assertDontSee('private-provider-payload');

        $this->actingAs($this->operator(PlatformRoleService::ADMIN), 'user')
            ->get(route('admin.amial.email-center.index'))
            ->assertOk()
            ->assertSee('private-recipient@example.test')
            ->assertSee('RESEND_HTTP_400')
            ->assertDontSee('739582')
            ->assertDontSee('private-provider-payload');
    }

    public function test_csv_export_masks_pii_and_quotes_formula_prefixes(): void
    {
        $this->challenge(['identifier' => '=danger@example.test']);
        $response = $this->actingAs($this->operator(PlatformRoleService::ADMIN), 'user')
            ->get(route('admin.amial.email-center.export'))->assertOk();
        $lines = explode("\n", trim($response->streamedContent()));
        $cells = str_getcsv($lines[1]);
        $this->assertSame("'=d***@example.test", $cells[2]);
        $this->assertStringNotContainsString('=danger@example.test', implode("\n", $lines));
        $this->assertStringNotContainsString('739582', implode("\n", $lines));
    }

    public function test_live_proof_remains_revocable_after_the_original_code_expires(): void
    {
        $proof = str_repeat('a', 64);
        $id = $this->challenge([
            'expires_at' => now()->subMinute(),
            'verified_at' => now()->subMinutes(2),
            'verification_token_hash' => Hash::make($proof),
            'verification_expires_at' => now()->addMinutes(5),
        ]);
        $url = route('admin.amial.email-center.challenges.revoke', $id);
        $this->actingAs($this->operator(PlatformRoleService::COMPLIANCE), 'user')
            ->post($url)->assertForbidden();
        $this->assertNull(DB::table('otp_challenges')->where('challenge_id', $id)->value('consumed_at'));

        $this->actingAs($this->operator(PlatformRoleService::ADMIN), 'user')
            ->get(route('admin.amial.email-center.index'))
            ->assertOk()->assertSee($url, false);
        $this->post($url)->assertRedirect();
        $row = DB::table('otp_challenges')->where('challenge_id', $id)->first();
        $this->assertNotNull($row->consumed_at);
        $this->assertNull($row->verification_token_hash);
        $this->assertDatabaseHas('audit_decisions', [
            'subject_id' => $id, 'action' => 'EMAIL_OTP_CHALLENGE_REVOKED', 'decision_code' => 'REVOKED',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OTP_NOT_VERIFIED');
        app(EmailOtpService::class)->consumeVerification($id, 'private-recipient@example.test', 'password_reset', $proof);
    }

    public function test_email_center_routes_have_granular_permissions(): void
    {
        $index = Route::getRoutes()->getByName('admin.amial.email-center.index');
        $export = Route::getRoutes()->getByName('admin.amial.email-center.export');
        $revoke = Route::getRoutes()->getByName('admin.amial.email-center.challenges.revoke');

        $this->assertNotNull($index);
        $this->assertNotNull($export);
        $this->assertNotNull($revoke);

        $this->assertContains('platform:platform.email.view', $index->gatherMiddleware());
        $this->assertContains('platform:platform.email.view', $export->gatherMiddleware());
        $this->assertContains('platform:platform.email.manage', $revoke->gatherMiddleware());
    }

    public function test_email_permissions_are_grantable_in_operator_ui(): void
    {
        $this->assertTrue(PlatformAccessTabs::isGrantable('platform.email.view'));
        $this->assertTrue(PlatformAccessTabs::isGrantable('platform.email.manage'));
    }

    public function test_admin_view_never_renders_otp_or_hash_fields(): void
    {
        $view = file_get_contents(resource_path('views/admin-views/amial/email-center/index.blade.php'));

        $this->assertStringNotContainsString('$row->token_hash', $view);
        $this->assertStringNotContainsString('$row->verification_token_hash', $view);
        $this->assertStringNotContainsString('$row->otp', $view);
        $this->assertStringContainsString('عناوين البريد مقنّعة', $view);
    }

    public function test_sensitive_email_center_actions_are_audited(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/EmailVerificationCenterController.php'));

        $this->assertStringContainsString('EMAIL_CENTER_VIEWED', $controller);
        $this->assertStringContainsString('EMAIL_CENTER_PII_VIEWED', $controller);
        $this->assertStringContainsString('EMAIL_CENTER_EXPORTED', $controller);
        $this->assertStringContainsString('EMAIL_OTP_CHALLENGE_REVOKED', $controller);
    }

    public function test_sidebar_only_shows_email_center_to_authorized_staff(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/admin/app.blade.php'));

        $this->assertStringContainsString("hasPlatformPermission('platform.email.view')", $layout);
        $this->assertStringContainsString("route('admin.amial.email-center.index')", $layout);
    }
}
