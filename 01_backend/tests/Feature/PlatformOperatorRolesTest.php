<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-OPERATOR-RBAC-002 — فصل صلاحيات فريق المنصّة.
 *
 * **الحال قبله:** حارس اللوحة كلّه `type === ADMIN_TYPE`، يفتح 125 مساراً.
 * فموظّف الدعم الذي يحتاج قراءة سجلّ عميل يستطيع تصفير رمزه السرّي وتجميد
 * حسابه وتغيير إعدادات المنصّة.
 *
 * وسجلّ التدقيق غير القابل للحذف — المبنيّ لقرارات النزاعات — يقول «من فعل»
 * ولا يقول «من كان يحقّ له». وفصل الصلاحيات هو ما يجعله ذا معنى: الفعل خارج
 * الدور يصير مستحيلاً لا مكتشَفاً بعد وقوعه.
 */
class PlatformOperatorRolesTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $roleCode): User
    {
        $user = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', $roleCode)->value('id');

        $this->assertNotNull($roleId, "الدور $roleCode غير مزروع — الهجرة لم تكتمل");

        DB::table('admin_user_roles')->insert([
            'user_id' => $user->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    // ── ما يستطيعه كل فريق ─────────────────────────────────────────────

    public function test_support_can_read_a_customer_file_but_not_touch_the_account(): void
    {
        $support = $this->operator('platform_support');

        // عمله: أن يجيب العميل عن «أين حوالتي؟»
        $this->assertTrue($support->hasPlatformPermission('platform.customers.view'));
        $this->assertTrue($support->hasPlatformPermission('platform.transactions.view'));
        $this->assertTrue($support->hasPlatformPermission('platform.receipts.view'));

        // وليس عمله: أن يمسّ الحساب نفسه.
        $this->assertFalse($support->hasPlatformPermission('platform.customers.reset_pin'),
            'موظّف الدعم يستطيع تصفير رمز عميل سرّي — وهذا أخطر ما في اللوحة');
        $this->assertFalse($support->hasPlatformPermission('platform.customers.freeze'));
        $this->assertFalse($support->hasPlatformPermission('platform.settings.update'));
        $this->assertFalse($support->hasPlatformPermission('platform.approvals.decide'));
    }

    public function test_maintenance_sees_operations_but_no_customer_data(): void
    {
        $ops = $this->operator('platform_maintenance');

        $this->assertTrue($ops->hasPlatformPermission('platform.ops.view'));
        $this->assertTrue($ops->hasPlatformPermission('platform.ops.retry'));

        // الصيانة تُصلح الأنابيب ولا تقرأ ما يجري فيها.
        $this->assertFalse($ops->hasPlatformPermission('platform.customers.view'),
            'الصيانة تقرأ ملفّات العملاء بلا حاجة — أقلّ امتياز يعني أقلّ ما يكفي');
        $this->assertFalse($ops->hasPlatformPermission('platform.disputes.decide'));
    }

    public function test_a_supervisor_decides_and_reads_the_audit_trail(): void
    {
        $sup = $this->operator('platform_supervisor');

        $this->assertTrue($sup->hasPlatformPermission('platform.audit.view'));
        $this->assertTrue($sup->hasPlatformPermission('platform.approvals.decide'));
        $this->assertTrue($sup->hasPlatformPermission('platform.disputes.decide'));

        // ولا يغيّر إعدادات المنصّة: الإشراف رقابةٌ على التنفيذ لا تنفيذ.
        $this->assertFalse($sup->hasPlatformPermission('platform.settings.update'));
        $this->assertFalse($sup->hasPlatformPermission('platform.customers.reset_pin'));
    }

    /** إعدادات المنصّة أخطر ما فيها — لا يملكها إلا مدير المنصّة. */
    public function test_only_the_platform_admin_can_change_platform_settings(): void
    {
        $this->assertTrue(
            $this->operator('platform_admin')->hasPlatformPermission('platform.settings.update'));

        foreach (['platform_support', 'platform_maintenance', 'platform_supervisor'] as $role) {
            $this->assertFalse(
                $this->operator($role)->hasPlatformPermission('platform.settings.update'),
                "الدور $role يغيّر إعدادات المنصّة");
        }
    }

    // ── ألّا يُقفل الباب على أصحابه ─────────────────────────────────────

    /**
     * أخطر ما في هذه الهجرة: أن تُقفل اللوحة على مالكها لحظة نشرها.
     *
     * فالمسارات تبدأ بطلب صلاحيات ولا أحد يملك أيّاً منها بعد. ولهذا تُسند
     * الهجرةُ دورَ مدير المنصّة لكل حساب إدارة قائم.
     */
    public function test_existing_admins_keep_full_access_after_the_migration(): void
    {
        // حساب إدارة قائم قبل الهجرة — بلا أي دور مُسنَد.
        $legacy = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        $this->assertFalse($legacy->hasPlatformPermission('platform.settings.update'),
            'الحالة الابتدائية خاطئة — الاختبار سيمرّ بلا أن يفحص شيئاً');

        // تُشغَّل الهجرة عليه. وهي مكتوبة بـ updateOrInsert فإعادتُها آمنة،
        // وهذا نفسه ما يجعل هذا الفحص ممكناً: لا محاكاة، بل الشيفرة التي
        // ستُنفَّذ على خادمك حرفياً.
        $migration = require database_path(
            'migrations/2026_07_27_120000_amial_platform_operator_roles.php');
        $migration->up();

        $this->assertTrue(User::find($legacy->id)->hasPlatformPermission('platform.settings.update'),
            'الهجرة أقفلت اللوحة على مالكها — كل مسار يطلب صلاحية ولا أحد يملكها');
    }

    /** الهجرة تُسند الدور فعلاً — لا تكتفي بإنشائه. */
    public function test_the_migration_grants_the_admin_role_to_admin_accounts(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        $adminRoleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
        DB::table('admin_user_roles')->insert([
            'user_id' => $admin->id, 'role_id' => $adminRoleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $fresh = User::find($admin->id);
        foreach (['platform.settings.update', 'platform.customers.reset_pin',
                  'platform.disputes.decide', 'platform.ops.retry'] as $perm) {
            $this->assertTrue($fresh->hasPlatformPermission($perm), "ينقص مدير المنصّة: $perm");
        }
    }

    // ── الحالات الحدّية ────────────────────────────────────────────────

    /** حساب بلا دور لا يملك شيئاً — الافتراض منعٌ لا سماح. */
    public function test_an_operator_with_no_role_has_nothing(): void
    {
        $orphan = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        foreach (array_keys($this->allPermissionCodes()) as $perm) {
            $this->assertFalse($orphan->hasPlatformPermission($perm),
                "حساب بلا دور يملك $perm — الافتراض يجب أن يكون المنع");
        }
    }

    /** دوران معاً يجمعان صلاحياتهما — مشرفٌ يعمل في الدعم أيضاً. */
    public function test_two_roles_combine_their_permissions(): void
    {
        $user = $this->operator('platform_support');

        $supRoleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_maintenance')->value('id');
        DB::table('admin_user_roles')->insert([
            'user_id' => $user->id, 'role_id' => $supRoleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $fresh = User::find($user->id);
        $this->assertTrue($fresh->hasPlatformPermission('platform.customers.view'));
        $this->assertTrue($fresh->hasPlatformPermission('platform.ops.retry'));
        // ولا يُكتسب ما لا يملكه أيٌّ منهما.
        $this->assertFalse($fresh->hasPlatformPermission('platform.settings.update'));
    }

    /** كل دور يشير إلى صلاحيات موجودة — خطأ إملائي يُنتج دوراً بلا أثر. */
    public function test_no_role_points_at_a_permission_that_does_not_exist(): void
    {
        $orphans = DB::table('role_permissions')
            ->leftJoin('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereNull('permissions.id')
            ->count();

        $this->assertSame(0, $orphans, 'ربطُ صلاحية غير موجودة يُنتج دوراً يبدو كاملاً وهو ناقص');
    }

    private function allPermissionCodes(): array
    {
        return DB::table('permissions')->where('code', 'like', 'platform.%')
            ->pluck('code', 'code')->all();
    }

    // ── المسارات نفسها، لا الصلاحيات وحدها ─────────────────────────────

    /**
     * الصلاحية في قاعدة البيانات بلا حارس على المسار لا تمنع شيئاً.
     *
     * وهذا بالضبط الفرق بين «بنينا نظام صلاحيات» و«النظام يعمل»: الأوّل
     * جداولٌ صحيحة، والثاني أن يُردّ الطلب فعلاً. وقد مرّ عليّ في هذه الجولة
     * حقلٌ صحيح لا يُنادى، فلا أكتفي بالجداول.
     */
    public function test_support_is_actually_blocked_from_resetting_a_pin(): void
    {
        $support = $this->operator('platform_support');
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        $this->actingAs($support, 'user')
            ->post("/admin/support-center/customers/{$customer->id}/reset-pin")
            ->assertStatus(403);
    }

    public function test_support_is_blocked_from_freezing_an_account(): void
    {
        $support = $this->operator('platform_support');
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        $this->actingAs($support, 'user')
            ->post("/admin/support-center/customers/{$customer->id}/freeze")
            ->assertStatus(403);
    }

    /** ومن يملك الصلاحية لا يُردّ — الحارس يمنع الممنوع لا كل أحد. */
    public function test_the_platform_admin_is_not_blocked(): void
    {
        $admin = $this->operator('platform_admin');
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        $response = $this->actingAs($admin, 'user')
            ->post("/admin/support-center/customers/{$customer->id}/freeze");

        $this->assertNotSame(403, $response->getStatusCode(),
            'الحارس يمنع من يملك الصلاحية — وهذا يُعطّل اللوحة لا يؤمّنها');
    }

    /** الصيانة لا تفتح ملفّات العملاء ولو عرفت الرابط. */
    public function test_maintenance_cannot_reach_customer_actions(): void
    {
        $ops = $this->operator('platform_maintenance');
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        $this->actingAs($ops, 'user')
            ->post("/admin/support-center/customers/{$customer->id}/reset-pin")
            ->assertStatus(403);
    }

    /**
     * السطح الثاني: نفس الأفعال متاحة عبر واجهة API أيضاً.
     *
     * حُصّنت مسارات لوحة الويب أوّلاً، ثم أظهر `route:list` أن لكلّ منها
     * نظيراً تحت `api/v1/amial/admin/support/`. وتحصين أحد السطحين وحده
     * أسوأ من عدمه: يُظنّ الباب مقفلاً وله باب آخر مفتوح.
     */
    public function test_the_api_surface_is_guarded_too(): void
    {
        $support = $this->operator('platform_support');
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        $this->actingAs($support, 'api')
            ->postJson("/api/v1/amial/admin/support/customers/{$customer->id}/reset-pin")
            ->assertStatus(403);
    }

    /** ولا يُترك مسارٌ حسّاس بلا حارس في أيّ من الملفّين. */
    public function test_every_account_touching_route_declares_a_permission(): void
    {
        $sensitive = ['reset-pin', 'freeze', 'revoke-sessions', 'require-kyc'];

        foreach (['routes/admin.php', 'routes/api/amial.php'] as $file) {
            $lines = file(base_path($file));
            foreach ($lines as $i => $line) {
                foreach ($sensitive as $action) {
                    if (!str_contains($line, "customers/{id}/$action")) {
                        continue;
                    }
                    $this->assertStringContainsString('platform:', $line,
                        "$file:" . ($i + 1) . " — مسار «{$action}» بلا صلاحية مطلوبة. "
                        . 'كل ما يمسّ حساب عميل يحتاج حارساً، ومسارٌ واحد منسيّ '
                        . 'يُبطل الفصل كلّه.');
                }
            }
        }
    }
}
