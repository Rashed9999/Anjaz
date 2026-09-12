<?php

namespace Tests\Feature;

use App\Support\PlatformAccessTabs;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailVerificationCenterGuardTest extends TestCase
{
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
