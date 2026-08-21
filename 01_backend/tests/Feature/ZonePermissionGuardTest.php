<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-ZONE-RBAC-001 — **قطاعُ النطاقات كان بلا صلاحيّةٍ واحدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس قبل هذا الحارس:** تسعةُ مساراتٍ في ثلاث مجموعات، **ولا واحدٌ
 * عليه `platform:`**. فكلُّ من يدخل اللوحة — موظّفُ دعمٍ أو صيانة — كان
 * ينقل حساباً بين نطاقين.
 *
 * **ونقلُ الحساب ليس تصنيفاً إداريّاً**: `EnforceZonePolicy` تقرأ النطاقَ
 * فتسمح بالحركة أو تمنعها. فالنقلُ يفتح أو يُغلق **حركةَ مالِ صاحبِه**،
 * ولا شيءَ في الشاشة ولا في الدور يقول إنّ الفاعلَ تجاوز حدَّه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحارسُ يجرّب المنعَ لا السماحَ وحدَه.**
 *
 * فاختبارٌ يثبت أنّ المديرَ يستطيع لا يُثبت أنّ غيرَه لا يستطيع — وهو
 * السؤالُ كلُّه. ولذلك لكلّ مسارٍ **دوران**: من يملك، ومن لا يملك.
 *
 * و«UI hiding ليس حماية» (نصُّ الوثيقة): تُطلَب المساراتُ مباشرةً بلا
 * مرورٍ بشاشة، لأنّ ذلك ما يفعله من يتجاوز.
 */
class ZonePermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, $role);

        return $u->refresh();
    }

    private function citizen(): User
    {
        return User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
            'residence_governorate' => 'عدن',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الصلاحيّاتُ موجودةٌ فعلاً — لا مذكورةً في مسارٍ وحدَه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_five_zone_permissions_exist_in_the_catalogue(): void
    {
        // **حارسٌ يذكر صلاحيّةً غيرَ موجودةٍ يمنع الجميع.** فالوسيطُ يسأل
        // القاعدةَ، ورمزٌ لا صفَّ له لا يملكه أحد — فيُقفَل المسارُ على
        // مديرِ المنصّة نفسِه، ويُقرأ ذلك «الحمايةُ تعمل».
        foreach ([
            'platform.zones.view', 'platform.zones.assign',
            'platform.zones.override', 'platform.zones.policy.update',
            'platform.zones.audit.view',
        ] as $code) {
            $this->assertDatabaseHas('permissions', ['code' => $code]);
        }
    }

    /** @test */
    public function the_platform_admin_holds_all_five(): void
    {
        $admin = $this->operator(PlatformRoleService::ADMIN);

        foreach ([
            'platform.zones.view', 'platform.zones.assign',
            'platform.zones.override', 'platform.zones.policy.update',
            'platform.zones.audit.view',
        ] as $code) {
            $this->assertTrue($admin->hasPlatformPermission($code),
                "مديرُ المنصّة لا يملك {$code} — فالمسارُ مقفلٌ على الجميع");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الدعمُ لا ينقل حساباً — وهو نصُّ الوثيقة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function ordinary_support_cannot_move_an_account_between_zones(): void
    {
        // «لا تجعل موظف دعم عادي يستطيع تغيير منطقة حساب» — نصّاً.
        $support = $this->operator(PlatformRoleService::SUPPORT);
        $victim = $this->citizen();

        $this->actingAs($support, 'user')
            ->post(route('admin.amial.zone.assign'), [
                'user_id' => $victim->id,
                'zone' => 'NORTH',
                'reason' => 'سببٌ مكتوبٌ طويلٌ بما يكفي',
            ])->assertForbidden();

        $this->actingAs($support, 'user')
            ->post(route('admin.amial.hub.zones.reassign', $victim->id))
            ->assertForbidden();

        $this->assertSame('SOUTH', $victim->fresh()->zone_code,
            'انتقل الحسابُ رغم الرفض — والرفضُ الذي لا يمنع ليس رفضاً');
    }

    /** @test */
    public function an_admin_account_with_no_role_at_all_is_refused(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا هو العقدُ الذي تغيّر، فيُثبَّت.**
        //
        // كان نوعُ الحساب وحدَه يفتح اللوحةَ التسعةَ مساراتٍ كلَّها —
        // «هل هذا موظّفُ منصّة؟» بدل «هل يحقّ له هذا الفعل؟». وقد كشفه
        // سقوطُ أربعةَ عشرَ اختباراً قائماً كانت تُصادِق بـ`ADMIN_TYPE`
        // بلا دور.
        //
        // ولا يُترَك التغييرُ في تعديل تلك الاختبارات وحدَه: **تعديلُ
        // اختبارٍ يُسكت العقدَ القديمَ ولا يُثبّت الجديد.**
        // ══════════════════════════════════════════════════════════════
        $roleless = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);

        $this->actingAs($roleless, 'user')
            ->get(route('admin.amial.hub.zones.index'))->assertForbidden();

        $this->actingAs($roleless, 'user')
            ->post(route('admin.amial.zone.assign'), [
                'user_id' => $this->citizen()->id,
                'zone' => 'NORTH',
                'reason' => 'سببٌ مكتوبٌ طويلٌ بما يكفي',
            ])->assertForbidden();
    }

    /** @test */
    public function support_cannot_even_read_the_zones_board(): void
    {
        $this->actingAs($this->operator(PlatformRoleService::SUPPORT), 'user')
            ->get(route('admin.amial.hub.zones.index'))->assertForbidden();
    }

    /** @test */
    public function the_json_doors_are_guarded_too_not_just_the_page(): void
    {
        // **حراسةُ الصفحة وحدَها تترك البيانات مفتوحةً لمن يعرف عنوانَها**
        // — وهو أوّلُ ما يُجرَّب.
        $support = $this->operator(PlatformRoleService::SUPPORT);

        foreach (['summary', 'events'] as $door) {
            $this->actingAs($support, 'user')
                ->get(route('admin.amial.hub.zones.' . $door))
                ->assertForbidden();
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ التجاوزُ مفصولٌ عن الإسناد
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function compliance_may_assign_from_kyc_but_never_override_it(): void
    {
        // **وهذا الفصلُ هو بيتُ القصيد.** الامتثالُ صاحبُ الوثائق، فالإسنادُ
        // منها عملُه. أمّا نطاقٌ **يخالف** الوثيقة فقرارُ إدارةٍ لا قرارُ
        // مدقّق — وجمعُهما في صلاحيّةٍ واحدةٍ يجعل منحَ المقيَّدة منحاً
        // للمطلقة.
        $compliance = $this->operator(PlatformRoleService::COMPLIANCE);

        $this->assertTrue($compliance->hasPlatformPermission('platform.zones.assign'));
        $this->assertFalse($compliance->hasPlatformPermission('platform.zones.override'),
            'الامتثالُ يملك التجاوز — فمخالفةُ الوثيقة صارت عملاً يوميّاً');

        $victim = $this->citizen();

        $this->actingAs($compliance, 'user')
            ->post(route('admin.amial.zone.assign'), [
                'user_id' => $victim->id,
                'zone' => 'NORTH',
                'reason' => 'سببٌ مكتوبٌ طويلٌ بما يكفي',
            ])->assertForbidden();
    }

    /** @test */
    public function only_the_admin_may_change_the_operating_governorates(): void
    {
        // فعلٌ نادرٌ أثرُه على **كلّ** الحسابات لا على حساب — فيُفرَد.
        foreach ([PlatformRoleService::COMPLIANCE, PlatformRoleService::RISK,
            PlatformRoleService::SUPERVISOR, PlatformRoleService::SUPPORT] as $role) {
            $this->actingAs($this->operator($role), 'user')
                ->post(route('admin.amial.zones.update'), [])
                ->assertForbidden();
        }

        $this->assertTrue($this->operator(PlatformRoleService::ADMIN)
            ->hasPlatformPermission('platform.zones.policy.update'));
    }

    /** @test */
    public function reading_a_persons_zone_history_is_not_the_same_as_running_the_board(): void
    {
        // المُشرِفُ يقرأ اللوحةَ ولا يقرأ تاريخَ فردٍ بعينه.
        $supervisor = $this->operator(PlatformRoleService::SUPERVISOR);

        $this->assertTrue($supervisor->hasPlatformPermission('platform.zones.view'));
        $this->assertFalse($supervisor->hasPlatformPermission('platform.zones.audit.view'),
            'من يُشغّل اللوحةَ صار يقرأ الفحصَ الجغرافيَّ لكلّ شخص');

        $this->actingAs($supervisor, 'user')
            ->get(route('admin.amial.hub.zones.geo-check', $this->citizen()->id))
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ ومن يملك يمرّ — وإلّا شُلَّ العملُ باسم الحماية
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_admin_still_gets_through_every_door(): void
    {
        // **حاجزٌ يحجب الجميع يجتاز نصفَ الفحص ثمّ يشلّ كلَّ عملٍ سليم.**
        $admin = $this->operator(PlatformRoleService::ADMIN);

        $this->actingAs($admin, 'user')
            ->get(route('admin.amial.hub.zones.index'))->assertOk();
        $this->actingAs($admin, 'user')
            ->get(route('admin.amial.hub.zones.summary'))->assertOk();
        // والشاشةُ القديمةُ تُعيد التوجيهَ إلى اللوحة الموحّدة (وهو ما
        // تطلبه `t02`) — فالمقصودُ أنّها **لا تُردّ ٤٠٣**: البابُ مفتوحٌ
        // لمن يملك، والوجهةُ شأنٌ آخر.
        $this->actingAs($admin, 'user')
            ->get(route('admin.amial.zones.index'))
            ->assertRedirect(route('admin.amial.hub.zones.index'));
    }

    /** @test */
    public function no_zone_route_is_left_without_a_permission(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والحارسُ الأهمّ: مسارٌ عاشرٌ يُضاف غداً بلا صلاحيّة.**
        //
        // فالثمانيةُ أعلاه تحرس ما أعرفه اليوم. وهذا يحرس ما لا أعرفه —
        // ويسقط لحظةَ يُسجَّل مسارُ نطاقاتٍ جديدٌ مكشوف.
        // ══════════════════════════════════════════════════════════════
        $naked = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.amial.zone')
                && ! str_contains($name, '.zones.')) {
                continue;
            }

            $hasZonePerm = false;

            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'platform:platform.zones.')) {
                    $hasZonePerm = true;
                    break;
                }
            }

            if (! $hasZonePerm) {
                $naked[] = $name . '  [' . implode('|', $route->methods()) . ' ' . $route->uri() . ']';
            }
        }

        sort($naked);

        $this->assertSame([], $naked,
            "مساراتُ نطاقاتٍ بلا صلاحيّةِ نطاق:\n  " . implode("\n  ", $naked) . "\n\n"
            . "ونقلُ حسابٍ بين نطاقين يفتح أو يُغلق حركةَ ماله — "
            . '`EnforceZonePolicy` تقرأ النطاقَ فتسمح أو تمنع.');
    }

    /** @test */
    public function the_guard_above_is_actually_looking_at_something(): void
    {
        // **وحارسٌ لا يجد ما يفحص ليس حارساً.** لو تغيّرت تسميةُ المسارات
        // لخرج الفحصُ أعلاه أخضرَ على صفر — وهو الصمتُ بثوب نجاح.
        $seen = 0;

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($name, 'admin.amial.zone') || str_contains($name, '.zones.')) {
                $seen++;
            }
        }

        $this->assertGreaterThanOrEqual(9, $seen,
            "لم يُعثر إلّا على {$seen} مسارَ نطاقات — والمقيسُ تسعة. "
            . 'تغيّرت التسميةُ ولم يعد المرشِّحُ يراها، فالفحصُ يمرّ على لا شيء.');
    }

    /** @test */
    public function moving_a_zone_still_writes_an_audit_row(): void
    {
        // الصلاحيّةُ تمنع من لا يملك — **ولا تُغني عن الأثر**. فمن يملك
        // يفعل، ويبقى السؤالُ: من فعل ومتى ولماذا.
        $admin = $this->operator(PlatformRoleService::ADMIN);
        $victim = $this->citizen();

        $before = DB::table('audit_decisions')->count();

        $this->actingAs($admin, 'user')
            ->post(route('admin.amial.zone.assign'), [
                'user_id' => $victim->id,
                'zone' => 'NORTH',
                'reason' => 'قرارُ إدارةٍ بعد مراجعةٍ يدويّة',
            ])->assertSuccessful();

        $this->assertGreaterThan($before, DB::table('audit_decisions')->count(),
            'نُقل الحسابُ بلا سطرٍ في سجلّ التدقيق');
    }
}
