<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-EDIT-ROUTES-GUARD-001
 *
 * نافذة «تعديل الحساب» لا تملك قيمة إذا بقي HTML موجوداً واختفت مسارات
 * readiness / documents / profile. هذا ما حدث فعلياً: فتحت النافذة،
 * سقط أول fetch بـ404، فبقي edit-body مخفياً وبدت القائمة كأنها حُذفت.
 */
class AdminAccountEditRoutesGuardTest extends TestCase
{
    public function test_account_edit_routes_are_registered_with_expected_names_and_actions(): void
    {
        $expected = [
            'admin.amial.hub.users.readiness' => [
                'uri' => 'admin/amial/hub/users/{id}/readiness.json',
                'action' => 'AdminHubController@accountReadinessJson',
            ],
            'admin.amial.hub.users.documents.upload' => [
                'uri' => 'admin/amial/hub/users/{id}/documents',
                'action' => 'AdminHubController@uploadDocument',
            ],
            'admin.amial.hub.users.profile.update' => [
                'uri' => 'admin/amial/hub/users/{id}/profile',
                'action' => 'AdminHubController@updateProfile',
            ],
        ];

        foreach ($expected as $name => $expect) {
            $this->assertTrue(Route::has($name), "المسار {$name} اختفى من Route collection.");

            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertSame($expect['uri'], $route->uri());
            $this->assertStringContainsString(
                $expect['action'],
                $route->getActionName(),
                "المسار {$name} لم يعد يشير إلى دالة نافذة التعديل الصحيحة.",
            );
        }
    }

    public function test_edit_view_still_contains_all_three_sections_and_calls_readiness_endpoint(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/amial/hub/users.blade.php')
        );

        $this->assertIsString($view);
        $this->assertStringContainsString('ما ينقص هذا الحساب ليعمل', $view);
        $this->assertStringContainsString('وثائق الهويّة', $view);
        $this->assertStringContainsString('بيانات الحساب', $view);
        $this->assertStringContainsString('/readiness.json', $view);
        $this->assertStringContainsString('/documents', $view);
        $this->assertStringContainsString('/profile', $view);
    }
}
