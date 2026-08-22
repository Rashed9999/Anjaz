<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-SECURITY-ANALYST-001 — **الصيانةُ ليست الأمن.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قِيس دورُ الصيانة فإذا هو: `ops.retry` · `ops.status.view` · `ops.view`
 * · **`transactions.view`**.
 *
 * والأخيرةُ تفتح خمسَ عشرةَ صفحةَ قراءة، فيها **أدلّةُ الدفع الآمن**
 * وكشفُ المعاملات كلِّه وتصديرُه. **ومهندسُ تشغيلٍ يقرأ أدلّةَ نزاعٍ بين
 * عميلين وصولٌ لا يحتاجه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الملفُّ يحرس تصحيحَ خطأٍ ارتكبتُه قبل ساعة.**
 *
 * منحتُ `aml.decide` و`security.act` **لكلّ من يملك `audit.view`** بحجّة
 * «لا يفقد أحدٌ ما يفعله اليوم». والحجّةُ صحيحةٌ في ظاهرها وخاطئةٌ في
 * أثرها: **ما كان يفعله هؤلاء هو العطلُ نفسُه** الذي جاءت الهجرةُ
 * لإصلاحه. فصار المُشرِفُ العامُّ يُعدّل قواعد غسل الأموال «حفاظاً على
 * القائم».
 *
 * **وحفظُ عطلٍ ليس حفظَ عمل.**
 */
class SecurityAnalystRoleTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, $role);

        return $u->refresh();
    }

    /** @return list<string> */
    private function holdersOf(string $code): array
    {
        $permId = DB::table('permissions')->where('code', $code)->value('id');

        if ($permId === null) {
            return [];
        }

        $out = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permission_id', $permId)
            ->whereNull('roles.merchant_user_id')
            ->pluck('roles.code')->all();

        sort($out);

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الصيانةُ لا تقرأ معاملاتِ العملاء
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function maintenance_no_longer_reads_every_customers_transactions(): void
    {
        $maint = $this->operator(PlatformRoleService::MAINTENANCE);

        $this->assertFalse($maint->hasPlatformPermission('platform.transactions.view'),
            'الصيانةُ تقرأ كشفَ المعاملات كلَّه وأدلّةَ الدفع الآمن — '
            . 'ومهندسُ تشغيلٍ لا يحتاج ذلك');

        $this->actingAs($maint, 'user')
            ->get(route('admin.transaction.index'))->assertForbidden();

        $this->actingAs($maint, 'user')
            ->get(route('admin.amial.safe-payments.index'))->assertForbidden();
    }

    /** @test */
    public function maintenance_keeps_the_console_it_actually_works_in(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرةٍ تُكتشَف بتدقيق.**
        $maint = $this->operator(PlatformRoleService::MAINTENANCE);

        foreach (['platform.ops.view', 'platform.ops.retry',
            'platform.ops.status.view'] as $code) {
            $this->assertTrue($maint->hasPlatformPermission($code),
                "الصيانةُ فقدت {$code} — وهي وحدةُ عملها");
        }

        $this->actingAs($maint, 'user')->get('/admin/amial/ops')->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② محلّلُ الأمن — يحقّق ويقطع، ولا يقرأ مالاً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_security_analyst_can_investigate_and_cut_access(): void
    {
        $sec = $this->operator('platform_security');

        foreach (['platform.audit.view', 'platform.security.act',
            'platform.customers.security.view', 'platform.customers.sessions'] as $code) {
            $this->assertTrue($sec->hasPlatformPermission($code),
                "محلّلُ الأمن لا يملك {$code} — فبمَ يحقّق؟");
        }

        // **والقطعُ عملُه بعينه**: من يحقّق في اختراقٍ يقطع الوصولَ فوراً،
        // وانتظارُ دورٍ آخرَ يترك المهاجمَ داخلاً.
        $this->actingAs($sec, 'user')
            ->get(route('admin.amial.sentinel.index'))->assertOk();
    }

    /** @test */
    public function the_security_analyst_reads_no_money_and_no_transactions(): void
    {
        $sec = $this->operator('platform_security');

        foreach (['platform.transactions.view', 'platform.money.view',
            'platform.analytics.view', 'platform.money.move',
            'platform.customers.pii.reveal'] as $code) {
            $this->assertFalse($sec->hasPlatformPermission($code),
                "محلّلُ الأمن يملك {$code} — والتحقيقُ في اختراقٍ لا يحتاج كشفَ حساب");
        }

        $this->actingAs($sec, 'user')
            ->get(route('admin.transaction.index'))->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ وحفظُ عطلٍ ليس حفظَ عمل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function deciding_on_money_laundering_belongs_to_compliance_alone(): void
    {
        $this->assertSame(['platform_admin', 'platform_compliance'],
            $this->holdersOf('platform.aml.decide'),
            'قرارُ الإبلاغ عن غسل الأموال خرج عن الامتثال — '
            . 'وقد مُنح للمُشرِف «حفاظاً على القائم»، وحفظُ عطلٍ ليس حفظَ عمل');
    }

    /** @test */
    public function investigating_is_wider_than_deciding_but_still_bounded(): void
    {
        $this->assertSame(
            ['platform_admin', 'platform_compliance', 'platform_risk'],
            $this->holdersOf('platform.aml.investigate'));

        // **والمخاطرُ تحقّق ولا تُبلّغ** — وهو فصلُ المحقّق عن صاحب القرار.
        $risk = $this->operator(PlatformRoleService::RISK);
        $this->assertTrue($risk->hasPlatformPermission('platform.aml.investigate'));
        $this->assertFalse($risk->hasPlatformPermission('platform.aml.decide'));
    }

    /** @test */
    public function acting_on_security_is_not_a_side_effect_of_reading_the_audit_log(): void
    {
        $this->assertSame(
            ['platform_admin', 'platform_risk', 'platform_security'],
            $this->holdersOf('platform.security.act'),
            'حجبُ عنوانِ شبكةٍ وفكُّه خرج عن الأمن والمخاطر');

        // والمُشرِفُ يقرأ السجلَّ ولا يحجب.
        $sup = $this->operator(PlatformRoleService::SUPERVISOR);
        $this->assertTrue($sup->hasPlatformPermission('platform.audit.view'));
        $this->assertFalse($sup->hasPlatformPermission('platform.security.act'));
        $this->assertFalse($sup->hasPlatformPermission('platform.aml.decide'));

        $this->actingAs($sup, 'user')
            ->post(route('admin.amial.sentinel.block'))->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ وقائمةٌ مكتوبةٌ تشيخ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_role_registry_knows_every_platform_role_in_the_database(): void
    {
        // **العطلُ وقع فعلاً**: `platform_auditor` أُنشئ في هجرةٍ أمس ولم
        // يُضَف إلى `PlatformRoleService::ALL`، فبقي خارج فحص «كلُّ دورٍ
        // له صلاحيّةٌ واحدةٌ على الأقلّ». **ودورٌ لا يفحصه شيءٌ يولد
        // فارغاً ولا يُعلَم** — يُسنَد لموظّفٍ فيرى ٤٠٣ في كلّ باب، بلا
        // رسالةٍ تقول لماذا.
        $inDb = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'like', 'platform\_%')->pluck('code')->all();

        sort($inDb);

        $registry = PlatformRoleService::ALL;
        sort($registry);

        $this->assertSame($registry, $inDb,
            "سِجلُّ الأدوار في الشيفرة لا يطابق ما في القاعدة.\n"
            . '  في القاعدة ولا في `ALL` : ' . implode(' · ', array_diff($inDb, $registry)) . "\n"
            . '  في `ALL` ولا في القاعدة : ' . implode(' · ', array_diff($registry, $inDb)));
    }

    /** @test */
    public function no_platform_role_is_born_empty(): void
    {
        // **دورٌ بلا صلاحيّةٍ ليس دوراً** — وهو نمطُ «مبنيٌّ ولا يُوصَل
        // إليه» في أخصّ صوره: يُعرَض في شاشة الإسناد، ويُسنَد، ولا يفتح
        // باباً واحداً.
        foreach (PlatformRoleService::ALL as $code) {
            $id = app(PlatformRoleService::class)->roleId($code);

            $this->assertNotNull($id, "دورٌ مفقود من القاعدة: {$code}");

            $this->assertGreaterThan(0,
                DB::table('role_permissions')->where('role_id', $id)->count(),
                "دورٌ بلا صلاحيّةٍ واحدة: {$code}");
        }
    }

    /** @test */
    public function the_two_new_roles_are_offered_where_roles_are_actually_assigned(): void
    {
        // **والقاعدة الثانية عشرة:** دورٌ يُنشأ في هجرةٍ ولا يظهر في
        // الشاشة التي تُسنَد منها الأدوار هو دورٌ لا وجودَ له عمليّاً.
        $admin = $this->operator(PlatformRoleService::ADMIN);

        $html = $this->actingAs($admin, 'user')
            ->get(route('admin.amial.ops.roles.index'))->assertOk()->getContent();

        foreach (['التدقيق الداخليّ', 'عمليّات الأمن'] as $label) {
            $this->assertStringContainsString($label, $html,
                "الدورُ «{$label}» غيرُ معروضٍ في شاشة إسناد الأدوار — "
                . 'فهو مبنيٌّ ولا يُوصَل إليه');
        }
    }

    /** @test */
    public function the_auditor_still_writes_nothing_after_the_new_role(): void
    {
        // **الثابتُ لا يُخترق بدورٍ جديد.** إضافةُ دورٍ أو نقلُ منحةٍ قد
        // تُعيد فتحَ ما أُغلق — فيُعاد سؤالُ الثابت بعد كلّ تغييرٍ في
        // الأدوار، لا مرّةً واحدةً يومَ كُتب.
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_auditor')->value('id');

        $held = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_id', $roleId)->pluck('permissions.code')->all();

        $offences = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                continue;
            }

            if ((string) $route->getName() === 'admin.amial.customer.action') {
                continue; // مفحوصٌ في متن الخدمة — انظر `ReadOnlyAuditorTest`
            }

            $perms = [];

            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'platform:')) {
                    $perms[] = substr($mw, strlen('platform:'));
                }
            }

            if ($perms !== [] && ! array_diff($perms, $held)) {
                $offences[] = (string) $route->getName();
            }
        }

        sort($offences);

        $this->assertSame([], $offences,
            "المدقّقُ صار يكتب بعد إضافة دورٍ جديد:\n  " . implode("\n  ", $offences));
    }
}
