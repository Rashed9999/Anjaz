<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-OPERATOR-RBAC-002/003 — الأدوار تُنفَّذ فعلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **عطلان، وكلاهما صامت.**
 *
 * ١) **حسابُ إدارةٍ جديدٌ يولد بلا دور.** نظامُ الأدوار كان مبنيّاً كاملاً —
 *    أربعةُ أدوار، وثلاثَ عشرةَ صلاحية، ووسيطٌ يفحص، وواحدٌ وأربعون مساراً
 *    يستعمله. والإسنادُ الوحيد داخلَ الهجرة: تُسند الدور لمن كان قائماً
 *    لحظةَ تشغيلها، وتُشغَّل مرّة. فمن أُنشئ بعدها يرى ٤٠٣ على واحدٍ
 *    وأربعين مساراً بلا رسالةٍ تقول لماذا.
 *
 * ٢) **المالُ لم يكن يسأل عن الدور أصلاً.** التسويةُ والرسومُ والتمويل —
 *    أخطرُ ثلاثة في المنصّة — كانت تكتفي بـ«هل هذا موظّف منصّة؟». فموظّف
 *    الدعم يعتمد تسوية وكيلٍ ويغيّر نسب الأرباح، ولا شيء في شاشةٍ ولا سجلّ
 *    يقول إنّه تجاوز دوره: الدورُ لم يُسأل.
 *
 * **ولا يُكتفى هنا بـ«الأدمن يستطيع».** ذاك يمرّ ولو كان الحارس معطَّلاً
 * تماماً. فلكلّ مسارٍ اختبارٌ من طرفيه: من يملك يمرّ، ومن لا يملك يُردّ.
 */
class PlatformRoleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $phone, string $roleCode = null): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'موظّف', 'l_name' => 'منصّة', 'phone' => $phone,
            'email' => $phone . '@amialpay.test',
            'type' => ADMIN_TYPE, 'password' => Hash::make('admin12345'),
            'is_active' => 1,
        ])->save();

        if ($roleCode !== null) {
            app(PlatformRoleService::class)->assign($u, $roleCode);
        }

        return $u->fresh();
    }

    // ══════════════════════════════════════════════════════════════
    // ١) الغياب — العطل الصامت
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الأدوار الأربعة مزروعةٌ وصلاحيّاتُها مربوطة.**
     *
     * فبلا هذا كلُّ ما بعده يفحص فراغاً.
     */
    public function the_four_platform_roles_exist_with_their_permissions(): void
    {
        foreach (PlatformRoleService::ALL as $code) {
            $id = app(PlatformRoleService::class)->roleId($code);

            $this->assertNotNull($id, "دورٌ مفقود: {$code}");

            $count = DB::table('role_permissions')->where('role_id', $id)->count();

            $this->assertGreaterThan(0, $count, "دورٌ بلا صلاحيّة واحدة: {$code}");
        }
    }

    /**
     * @test
     *
     * **حسابُ إدارةٍ لا يولد بلا دور.**
     *
     * ويُفحص من المسار الذي يُنشئه فعلاً (`amial:ensure-demo-staff`) لا
     * باستدعاءٍ مباشرٍ للخدمة — فالخدمةُ صحيحةٌ ولا تُنادى هو العطل نفسه.
     */
    public function a_freshly_created_admin_is_never_left_without_a_role(): void
    {
        $this->artisan('amial:ensure-demo-staff')->assertExitCode(0);

        $admins = User::where('type', ADMIN_TYPE)->get();

        $this->assertNotEmpty($admins, 'لم يُنشأ حساب إدارة — الاختبار يفحص فراغاً');

        foreach ($admins as $a) {
            $this->assertNotEmpty(app(PlatformRoleService::class)->codesOf($a),
                "حساب إدارةٍ بلا دور: {$a->phone} — يرى ٤٠٣ على ٤١ مساراً بلا تفسير");
        }
    }

    /**
     * @test
     *
     * **والشفاءُ يعمل على من سبق.**
     */
    public function orphaned_admins_are_healed(): void
    {
        $orphan = $this->admin('967770000101');  // بلا دور عمداً

        $this->assertSame([], app(PlatformRoleService::class)->codesOf($orphan));

        $healed = app(PlatformRoleService::class)->healAdminsWithoutRoles();

        $this->assertSame(1, $healed);
        $this->assertSame([PlatformRoleService::ADMIN],
            app(PlatformRoleService::class)->codesOf($orphan->fresh()));
    }

    /**
     * @test
     *
     * **وغيابُ الدور لا يُقرأ رخصةً.**
     *
     * ولو قُرئ كذلك لانقلب الحارس باباً مفتوحاً: من أراد تجاوزه نزع أدواره
     * كلَّها فملك كلّ شيء.
     */
    public function no_roles_means_no_permissions_not_all_of_them(): void
    {
        $orphan = $this->admin('967770000102');

        $this->assertFalse($orphan->hasPlatformPermission('platform.money.move'));
        $this->assertFalse($orphan->hasPlatformPermission('platform.customers.view'));
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) المال — من طرفيه
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **موظّف الدعم لا يعتمد تسوية، ولا يغيّر رسماً، ولا يشحن وكيلاً.**
     */
    public function support_staff_cannot_touch_money_or_fees(): void
    {
        $support = $this->admin('967770000103', PlatformRoleService::SUPPORT);

        $this->actingAs($support, 'user');

        foreach ([
            ['get',  route('admin.amial.fees.index'),        'الرسوم'],
            ['get',  route('admin.amial.hub.settlements'),   'تسويات الوكلاء'],
            ['get',  route('admin.amial.hub.finance'),       'المركز المالي'],
            ['post', route('admin.amial.hub.finance.topup'), 'شحن محفظة وكيل'],
        ] as [$method, $url, $label]) {
            $status = $this->{$method}($url)->getStatusCode();

            $this->assertSame(403, $status,
                "موظّف الدعم وصل «{$label}» بردّ {$status} — والدور لا يمنحه ذلك");
        }
    }

    /**
     * @test
     *
     * **ومدير المنصّة يصل — وإلّا كان الحارسُ قفلاً على الجميع.**
     *
     * (القاعدة الثانية بوجهها الآخر: حارسٌ يمنع من يحقّ له ليس حارساً بل
     * عطل. وقد وقع ذلك فعلاً: ٤١ مساراً كانت مقفلةً على أدمنٍ بلا دور.)
     */
    public function the_platform_admin_does_reach_money_and_fees(): void
    {
        $admin = $this->admin('967770000104', PlatformRoleService::ADMIN);

        $this->actingAs($admin, 'user');

        foreach ([
            route('admin.amial.fees.index'),
            route('admin.amial.hub.settlements'),
            route('admin.amial.hub.finance'),
        ] as $url) {
            $this->assertNotSame(403, $this->get($url)->getStatusCode(),
                "مدير المنصّة مُنع من {$url} — الحارس صار قفلاً");
        }
    }

    /**
     * @test
     *
     * **وفريق الإشراف: يرى ويوافق، ولا يمسّ المال ولا الإعدادات.**
     *
     * وهو الفصلُ المطلوب: قرارُ توثيقٍ فعلٌ يوميّ، وتغييرُ نسبة ربحٍ فعلٌ
     * أثرُه على كلّ عمليّةٍ بعده.
     */
    public function the_supervisor_decides_but_does_not_move_money(): void
    {
        $sup = $this->admin('967770000105', PlatformRoleService::SUPERVISOR);

        $this->assertTrue($sup->hasPlatformPermission('platform.approvals.decide'),
            'الإشراف لا يستطيع الاعتماد — فما فائدته');
        $this->assertTrue($sup->hasPlatformPermission('platform.audit.view'));

        $this->assertFalse($sup->hasPlatformPermission('platform.money.move'));
        $this->assertFalse($sup->hasPlatformPermission('platform.fees.update'));
        $this->assertFalse($sup->hasPlatformPermission('platform.settings.update'));
    }

    /**
     * @test
     *
     * **وفريق الصيانة لا يرى بيانات العملاء.**
     */
    public function the_maintenance_team_sees_operations_not_customers(): void
    {
        $m = $this->admin('967770000106', PlatformRoleService::MAINTENANCE);

        $this->assertTrue($m->hasPlatformPermission('platform.ops.view'));
        $this->assertTrue($m->hasPlatformPermission('platform.ops.retry'));

        $this->assertFalse($m->hasPlatformPermission('platform.customers.view'));
        $this->assertFalse($m->hasPlatformPermission('platform.customers.freeze'));
        $this->assertFalse($m->hasPlatformPermission('platform.money.move'));
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) القائمة لا تعرض ما لا يُفتح
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **رابطٌ يُعرَض ثمّ يردّ ٤٠٣ أسوأ من غيابه.**
     *
     * يجعل المستعمل يظنّ النظام معطّلاً بدل أن يعرف أنّ الفعل ليس له.
     */
    public function the_sidebar_hides_what_the_role_cannot_open(): void
    {
        $support = $this->admin('967770000107', PlatformRoleService::SUPPORT);
        $admin   = $this->admin('967770000108', PlatformRoleService::ADMIN);

        $seenBySupport = $this->actingAs($support, 'user')
            ->get(route('admin.amial.workspace.index'))->assertOk()->getContent();

        // ══════════════════════════════════════════════════════════════
        // **وتُقاس الوجهةُ لا التسمية.**
        //
        // كانت هذه تبحث عن «مركز الرسوم والأرباح» نصّاً، فلمّا نُقلت
        // الروابطُ إلى مساحة العمل صارت التسميةُ «الرسوم والأرباح» —
        // **فسقط الحارسُ على إعادة تنظيمٍ سليمة**، والوجهةُ لم تتغيّر.
        //
        // والمحروسُ أن **لا يبلغ الدعمُ شاشةَ المال**، لا أن تُكتب
        // بصياغةٍ بعينها. وحارسٌ يمنع تسميةً أفضلَ يُعطَّل ثمّ لا يحرس.
        // ══════════════════════════════════════════════════════════════
        $moneyDoors = [
            route('admin.amial.fees.index'),
            route('admin.amial.hub.settlements'),
            route('admin.amial.hub.finance'),
        ];

        foreach ($moneyDoors as $url) {
            $this->assertStringNotContainsString($url, $seenBySupport,
                "«{$url}» ظهر لموظّف الدعم — ويردّ ٤٠٣ حين يضغطه");
        }

        // والضبط المقابل: تظهر لمن يملكها، وإلّا كان الاختبار يفحص قالباً
        // فارغاً لا فلترةً تعمل.
        $seenByAdmin = $this->actingAs($admin, 'user')
            ->get(route('admin.amial.workspace.index'))->assertOk()->getContent();

        foreach ([$moneyDoors[0], $moneyDoors[1]] as $url) {
            $this->assertStringContainsString($url, $seenByAdmin,
                "«{$url}» اختفى عن مدير المنصّة — الفلترة تُخفي عن الجميع");
        }
    }
}
