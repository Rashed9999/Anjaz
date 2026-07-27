<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-OPERATOR-RBAC-004 — إسناد الأدوار: الصفحة التي بدونها لا يعمل الفصل.
 *
 * **الفجوة التي كشفها بناء الحراسة نفسها:** الهجرة تُسند دور مدير المنصّة
 * لحسابات الإدارة القائمة فلا تُقفل اللوحة على مالكها. أمّا الحساب الذي
 * يُنشأ **بعدها** فيولد بلا دور — والافتراض منعٌ لا سماح.
 *
 * وذلك صحيحٌ أمنياً وقاتلٌ عملياً بلا هذه الصفحة: يُضاف موظّف دعم فلا يفتح
 * شيئاً، ولا سبيل إلى منحه إلا بكتابة صفٍّ في قاعدة البيانات يدوياً. (ظهرت
 * الفجوة حين سقطت اختبارات الدعم القائمة بعد التحصين — وكان يمكن أن تظهر
 * على خادم الإنتاج بدلاً منها.)
 *
 * **وإسناد الصلاحية فعلٌ أخطر من استعمالها:** من يمنح دور مدير المنصّة يمنح
 * كل شيء دفعةً واحدة. فالضوابط هنا أشدّ ممّا في الصفحات الأخرى.
 */
class OperatorRolesAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', $code)->value('id');
    }

    private function operator(?string $roleCode = null): User
    {
        $user = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        if ($roleCode) {
            DB::table('admin_user_roles')->insert([
                'user_id' => $user->id, 'role_id' => $this->roleId($roleCode),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user->fresh();
    }

    // ── العمل الأساسي ──────────────────────────────────────────────────

    public function test_a_new_operator_starts_with_nothing_and_can_be_granted_a_role(): void
    {
        $admin = $this->operator('platform_admin');
        $newbie = $this->operator();

        $this->assertFalse($newbie->hasPlatformPermission('platform.customers.view'),
            'الحساب الجديد يبدأ بصلاحيات — الافتراض يجب أن يكون المنع');

        $this->actingAs($admin, 'user')
            ->post("/admin/amial/ops/roles/{$newbie->id}",
                ['role_ids' => [$this->roleId('platform_support')]])
            ->assertRedirect();

        $this->assertTrue(User::find($newbie->id)->hasPlatformPermission('platform.customers.view'),
            'مُنح الدور ولم تصل الصلاحية — الصفحة بلا أثر');
    }

    public function test_removing_a_role_takes_the_permission_away(): void
    {
        $admin = $this->operator('platform_admin');
        $support = $this->operator('platform_support');

        $this->actingAs($admin, 'user')
            ->post("/admin/amial/ops/roles/{$support->id}", ['role_ids' => []])
            ->assertRedirect();

        $this->assertFalse(User::find($support->id)->hasPlatformPermission('platform.customers.view'));
    }

    public function test_two_roles_can_be_granted_at_once(): void
    {
        $admin = $this->operator('platform_admin');
        $user = $this->operator();

        $this->actingAs($admin, 'user')->post("/admin/amial/ops/roles/{$user->id}", [
            'role_ids' => [$this->roleId('platform_support'), $this->roleId('platform_maintenance')],
        ])->assertRedirect();

        $fresh = User::find($user->id);
        $this->assertTrue($fresh->hasPlatformPermission('platform.customers.view'));
        $this->assertTrue($fresh->hasPlatformPermission('platform.ops.retry'));
    }

    // ── الضوابط ────────────────────────────────────────────────────────

    /** الدعم لا يمنح أدواراً — وإلّا منح نفسه ما يشاء. */
    public function test_a_support_agent_cannot_open_or_change_roles(): void
    {
        $support = $this->operator('platform_support');

        $this->actingAs($support, 'user')->get('/admin/amial/ops/roles')->assertStatus(403);

        $this->actingAs($support, 'user')
            ->post("/admin/amial/ops/roles/{$support->id}",
                ['role_ids' => [$this->roleId('platform_admin')]])
            ->assertStatus(403);

        $this->assertFalse(User::find($support->id)->hasPlatformPermission('platform.settings.update'),
            'موظّف دعم رقّى نفسه إلى مدير منصّة');
    }

    /** ولا الإشراف — الرقابة لا تمنح صلاحيات. */
    public function test_a_supervisor_cannot_change_roles(): void
    {
        $this->actingAs($this->operator('platform_supervisor'), 'user')
            ->get('/admin/amial/ops/roles')->assertStatus(403);
    }

    /**
     * من يسحب دوره عن نفسه يُقفل الباب وهو داخله.
     *
     * ولا أحد يفتحه له إن كان المدير الوحيد — فالمنع هنا يحمي من خطأ لا
     * تراجع عنه إلا بكتابة صفٍّ في قاعدة البيانات يدوياً.
     */
    public function test_an_admin_cannot_strip_their_own_admin_role(): void
    {
        $admin = $this->operator('platform_admin');

        // مديرٌ ثانٍ عمداً: بدونه تُمسك الحالةَ قاعدةُ «لا تُترك بلا مدير»،
        // فيمرّ الاختبار لسبب آخر ويبدو حارساً وهو لا يفحص شيئاً. (اكتُشف
        // بنزع الحارس المقصود — فمرّ الاختبار كما هو.)
        $this->operator('platform_admin');

        $this->actingAs($admin, 'user')
            ->post("/admin/amial/ops/roles/{$admin->id}",
                ['role_ids' => [$this->roleId('platform_support')]])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue(User::find($admin->id)->hasPlatformPermission('platform.settings.update'),
            'أقفل المدير الباب على نفسه');
    }

    /** ولا تبقى المنصّة بلا مدير واحد على الأقلّ. */
    public function test_the_last_admin_cannot_be_demoted_by_another_admin(): void
    {
        $onlyAdmin = $this->operator('platform_admin');
        $second = $this->operator('platform_admin');

        // بمديرَين: خفضُ أحدهما مسموح
        $this->actingAs($onlyAdmin, 'user')
            ->post("/admin/amial/ops/roles/{$second->id}", ['role_ids' => []])
            ->assertRedirect();
        $this->assertFalse(User::find($second->id)->hasPlatformPermission('platform.settings.update'));

        // وبمديرٍ واحد: يُمنع
        $newbie = $this->operator();
        DB::table('admin_user_roles')->insert([
            'user_id' => $newbie->id, 'role_id' => $this->roleId('platform_support'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($onlyAdmin, 'user')
            ->post("/admin/amial/ops/roles/{$onlyAdmin->id}", ['role_ids' => []])
            ->assertSessionHas('error');

        $this->assertTrue(User::find($onlyAdmin->id)->hasPlatformPermission('platform.settings.update'));
    }

    /** حساب عميل لا يُمنح أدوار منصّة ولو أُرسل معرّفه. */
    public function test_a_customer_account_cannot_be_given_a_platform_role(): void
    {
        $admin = $this->operator('platform_admin');
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        $this->actingAs($admin, 'user')
            ->post("/admin/amial/ops/roles/{$customer->id}",
                ['role_ids' => [$this->roleId('platform_admin')]])
            ->assertSessionHas('error');

        $this->assertFalse(User::find($customer->id)->hasPlatformPermission('platform.settings.update'),
            'حساب عميل صار مدير منصّة');
    }

    // ── الأثر ──────────────────────────────────────────────────────────

    /** كل منح وسحب يُكتب باسم فاعله — إسناد الصلاحية أخطر من استعمالها. */
    public function test_every_change_is_written_to_the_audit_trail(): void
    {
        $admin = $this->operator('platform_admin');
        $user = $this->operator();

        $this->actingAs($admin, 'user')->post("/admin/amial/ops/roles/{$user->id}",
            ['role_ids' => [$this->roleId('platform_support')]])->assertRedirect();

        $this->assertDatabaseHas('audit_decisions', [
            'actor_user_id' => $admin->id,
            'subject_type' => 'operator',
            'subject_id' => (string) $user->id,
            'action' => 'roles_changed',
            'severity' => 'critical',
        ]);
    }

    /** ويُسجَّل من منح الدور في الصفّ نفسه — للسؤال السريع بلا بحث في السجلّ. */
    public function test_the_granter_is_recorded_on_the_assignment(): void
    {
        $admin = $this->operator('platform_admin');
        $user = $this->operator();

        $this->actingAs($admin, 'user')->post("/admin/amial/ops/roles/{$user->id}",
            ['role_ids' => [$this->roleId('platform_support')]])->assertRedirect();

        $this->assertSame($admin->id, (int) DB::table('admin_user_roles')
            ->where('user_id', $user->id)->value('granted_by_user_id'));
    }
}
