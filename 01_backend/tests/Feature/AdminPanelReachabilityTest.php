<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-ORPHAN-001 — كل ما يُنقر في لوحة أميال يفتح فعلاً.
 *
 * الفحص الشامل كشف 63 صفحة في اللوحة القديمة (6cash) ترمي 500 لأن قوالبها
 * حُذفت وبقيت مساراتها مُسجَّلة. لم يشتكِ أحد لأن قائمة أميال الجانبية لا
 * تشير إلى أيّ منها — تُبلغ بكتابة العنوان أو ببصمة قديمة وحدها.
 *
 * لكن ما يحمي اللوحة اليوم مصادفةٌ لا ضمانة: رابط واحد يُضاف غداً إلى
 * القائمة نحو صفحة محذوفة القالب يعيد المشكلة صامتاً. هذا الاختبار يجعلها
 * تفشل في وجه من أضافها، لا في وجه الموظّف.
 */
class AdminPanelReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private const SIDEBAR = 'admin-views.amial.partials._sidebar';

    /** أسماء المسارات التي تظهر كروابط في القائمة الجانبية. */
    private function sidebarRouteNames(): array
    {
        $path = resource_path('views/' . str_replace('.', '/', self::SIDEBAR) . '.blade.php');
        $this->assertFileExists($path, 'ملفّ القائمة الجانبية غير موجود');

        preg_match_all("/route\(\s*'([a-zA-Z0-9._\-]+)'/", file_get_contents($path), $m);

        return array_values(array_unique($m[1]));
    }

    public function test_every_sidebar_link_points_at_a_registered_route(): void
    {
        $names = $this->sidebarRouteNames();
        $this->assertNotEmpty($names, 'لم يُعثر على روابط — تغيّرت صيغة القائمة؟');

        foreach ($names as $name) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                "رابط في القائمة الجانبية لا مسار له: {$name}"
            );
        }
    }

    /**
     * كل صفحة في القائمة تُفتح فعلاً.
     *
     * التحقّق من وجود المسار لا يكفي: المسار المسجَّل الذي يُصيّر قالباً
     * محذوفاً موجودٌ ومعطوب معاً — وهذه بالضبط حال الصفحات الـ63.
     */
    public function test_every_sidebar_page_actually_opens(): void
    {
        $admin = User::factory()->create(['type' => ADMIN_TYPE]);
        $broken = [];

        foreach ($this->sidebarRouteNames() as $name) {
            $route = Route::getRoutes()->getByName($name);
            if (!$route || !in_array('GET', $route->methods(), true)) {
                continue;
            }
            // المسارات ذات المعاملات تحتاج بيانات حقيقية — خارج نطاق الحارس.
            if (str_contains($route->uri(), '{')) {
                continue;
            }

            try {
                $status = $this->actingAs($admin, 'user')->get('/' . $route->uri())->status();
            } catch (\Throwable $e) {
                $status = 599;
            }

            if ($status >= 500) {
                $broken[] = "{$name} (/{$route->uri()}) → {$status}";
            }
        }

        $this->assertSame([], $broken, "صفحات معطّلة في القائمة:\n  " . implode("\n  ", $broken));
    }
}
