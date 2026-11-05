<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SupportPlaybookCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-SUPPORT-PLAYBOOKS-001
 *
 * يحرس أن دعم أميال ليس «اعرف الشاشة واحفظ الخطوات».
 * الـ49 حالة كتالوج حي داخل مركز الدعم، وصلاحيات التشخيص منفصلة عن
 * الأفعال الحساسة.
 */
class SupportPlaybookCatalogGuardTest extends TestCase
{
    use RefreshDatabase;

    private function supportOperator(): User
    {
        $user = User::factory()->create([
            'type' => ADMIN_TYPE,
            'zone_code' => 'SOUTH',
        ]);

        $roleId = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', 'platform_support')
            ->value('id');

        $this->assertNotNull($roleId);

        DB::table('admin_user_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    public function test_catalog_contains_exactly_the_49_measured_support_cases(): void
    {
        $rows = app(SupportPlaybookCatalogService::class)->all();

        $this->assertCount(49, $rows);
        $this->assertSame(range(1, 49), array_column($rows, 'id'));

        foreach ($rows as $row) {
            foreach ([
                'question', 'category', 'readiness', 'diagnosis',
                'steps', 'escalation', 'customer_reply',
            ] as $key) {
                $this->assertArrayHasKey($key, $row, "الحالة #{$row['id']} ينقصها {$key}");
            }

            $this->assertNotEmpty($row['steps'], "الحالة #{$row['id']} بلا خطوات تشخيص");
        }
    }

    public function test_natural_customer_phrases_find_the_expected_playbooks(): void
    {
        $catalog = app(SupportPlaybookCatalogService::class);

        $cases = [
            'الحوالة ما وصلت ومعي رقم العملية' => 2,
            'كل ما ادفع للتاجر يقول الانترنت' => 38,
            'عندي جهاز جديد كيف استعيد حسابي' => 15,
            'حولت مبلغ للشخص الخطأ وابغى استرجعه' => 7,
        ];

        foreach ($cases as $question => $expectedId) {
            $ids = array_column($catalog->search($question), 'id');
            $this->assertContains(
                $expectedId,
                $ids,
                "سؤال العميل لم يصل إلى Playbook المتوقع: {$question}",
            );
        }
    }

    public function test_support_can_diagnose_without_getting_sensitive_decision_permissions(): void
    {
        $support = $this->supportOperator();

        $this->assertTrue($support->hasPlatformPermission('platform.customers.devices.view'));
        $this->assertTrue($support->hasPlatformPermission('platform.wrong_transfer.claim.open'));
        $this->assertTrue($support->hasPlatformPermission('platform.recovery.view'));

        $this->assertFalse($support->hasPlatformPermission('platform.customers.sessions'));
        $this->assertFalse($support->hasPlatformPermission('platform.disputes.decide'));
        $this->assertFalse($support->hasPlatformPermission('platform.recovery.approve'));
        $this->assertFalse($support->hasPlatformPermission('platform.recovery.reject'));
    }

    public function test_sensitive_support_routes_keep_read_and_decide_separate(): void
    {
        $expected = [
            'admin.support-center.customers.devices' => 'platform:platform.customers.devices.view',
            'admin.support-center.wrong-transfer.open' => 'platform:platform.wrong_transfer.claim.open',
            'admin.support-center.wrong-transfer.resolve' => 'platform:platform.disputes.decide',
            'admin.amial.recovery.index' => 'platform:platform.recovery.view',
            'admin.amial.recovery.approve' => 'platform:platform.recovery.approve',
            'admin.amial.recovery.reject' => 'platform:platform.recovery.reject',
        ];

        foreach ($expected as $routeName => $middleware) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "المسار {$routeName} اختفى");
            $this->assertContains(
                $middleware,
                $route->gatherMiddleware(),
                "{$routeName} لا يحمل الصلاحية الصحيحة",
            );
        }
    }

    public function test_support_console_exposes_the_catalog_as_a_reachable_screen(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/support/console.blade.php')
        );

        $this->assertStringContainsString('دليل الدعم التشغيلي', $view);
        $this->assertStringContainsString('49 حالة تشغيلية', $view);
        $this->assertStringContainsString('playbook-search-input', $view);
        $this->assertStringContainsString("get('/playbooks?'", $view);

        $this->assertTrue(Route::has('admin.support-center.playbooks'));
    }
}
