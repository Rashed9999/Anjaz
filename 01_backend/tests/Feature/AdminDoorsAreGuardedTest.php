<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-DOORS-001 — **موظّفُ الدعم كان يفتح سبعاً وعشرين صفحة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** أنشأ صاحبُ المشروع حسابَ موظّفِ دعمٍ ودخل به،
 * وأرسل صورةَ الشاشة وقال: «انظر القوائم والشاشة ماذا يظهر؟».
 *
 * وقِيس فكان أسوأَ ممّا تُظهره الصورة. **ثلاثةٌ وثلاثون رابطاً من ستّين
 * في القائمة لا تُصرّح بصلاحيّة**، والمرشِّحُ لا يعمل إلّا على ما يُصرّح.
 * ومن تلك الروابط **سبعةٌ وعشرون تقود إلى مساراتٍ بلا حارسٍ إطلاقاً** —
 * أي أنّها لا تُرى فحسب، بل **تُفتح وتُستعمَل**:
 *
 *   · إعداداتُ الأعمال (الرسوم والحدود) · إعدادُ Firebase · اللغات
 *   · مصفوفةُ الصلاحيّات · المفاتيحُ السريعة (تشغيل/إيقاف الميزات)
 *   · مصاريفُ المنصّة · مكافحةُ غسل الأموال · أحداثُ الأمان · حارسُ الأمان
 *   · استعادةُ الحسابات · لوحةُ التحقّق · كشفُ المعاملات
 *
 * **وموظّفُ دعمٍ يفتح «إعدادات الأعمال» يعدّل نسبةَ رسمٍ أو حدَّ معاملة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والثاني في الصورة نفسِها: مجاميعُ المنصّة.**
 *
 * حجمُ السنة، والرسمُ البيانيّ، و«أعلى العملاء تعاملاً» بالأسماء
 * والمبالغ. واللوحةُ كانت تعمل **كما صُمّمت** — تحجب الأرصدةَ بـ
 * `money.move` وتبني الباقيَ على `transactions.view` **ودورُ الدعم
 * يملكها**. فالعطلُ في التصميم: خلطُ «أرِني حركةَ هذا العميل» بـ«أرِني
 * المنصّة».
 */
class AdminDoorsAreGuardedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **بابان مفتوحان عمداً — ويُسمَّيان لئلّا يُنسى لماذا.**
     *
     *   · `agent.login` — بوّابةُ الوكيل، خارج لوحة الإدارة أصلاً.
     *   · `admin.amial.2fa.page` — المصادقةُ الثنائيّة **لحساب الموظّف
     *     نفسِه**. وحجبُها بصلاحيّةٍ يمنع من لا يملكها من تأمين حسابه،
     *     وهو عكسُ المقصود.
     */
    private const OPEN_BY_DESIGN = ['agent.login', 'admin.amial.2fa.page'];

    private function operator(string $role): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, $role);

        return $u->refresh();
    }

    /** @return list<array{0:string,1:string,2:?string}> روابطُ القائمة */
    private function sidebarLinks(): array
    {
        $src = file_get_contents(resource_path(
            'views/admin-views/amial/partials/_sidebar.blade.php'));

        preg_match_all(
            "~\[\s*'([^']{2,80})'\s*,\s*route\('([^']+)'\)[^,]*,\s*(null|'[^']+')~",
            $src, $m, PREG_SET_ORDER);

        return array_map(
            fn ($x) => [$x[1], $x[2], $x[3] === 'null' ? null : trim($x[3], "'")],
            $m);
    }

    /**
     * **كلُّ صلاحيّات المسار — لا أوّلُها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **عطلٌ في هذا الحارس نفسِه، كُشف قبل أن يمرّ:** كانت الدالّةُ تُعيد
     * أوّلَ `platform:` تجده. و`gatherMiddleware()` تُعيد **صلاحيّاتِ
     * المجموعة والمسار معاً**، والمجموعةُ أوّلاً — فثلاثةٌ وثلاثون مساراً
     * تحمل صلاحيّتين كانت تُقرأ بصلاحيّة مجموعتها وحدَها.
     *
     * ومثالُه: `charity.settlements.payout` يطلب `transactions.view` من
     * مجموعته و`money.move` من نفسه. فالقراءةُ الأولى تقول «يكفيه
     * `transactions.view`» — **وهي الصلاحيّةُ التي يملكها الدعم**، فيمرّ
     * صرفُ تسويةِ جمعيّةٍ في نظر الحارس وكأنّه مفتوحٌ للدعم.
     *
     * **وحارسٌ يقرأ نصفَ الشرط يُطمئن على النصف الآخر.**
     *
     * @return list<string>
     */
    private function routePermissions(string $name): array
    {
        $route = app('router')->getRoutes()->getByName($name);

        if (! $route) {
            return [];
        }

        $out = [];

        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'platform:')) {
                $out[] = substr($mw, strlen('platform:'));
            }
        }

        return array_values(array_unique($out));
    }


    // ══════════════════════════════════════════════════════════════════
    //  ⓪ كلُّ بابٍ في اللوحة — لا أبوابُ القائمة وحدَها
    // ══════════════════════════════════════════════════════════════════

    /**
     * **أبوابٌ مفتوحةٌ عمداً، ولكلٍّ سببُه مكتوباً.**
     *
     * وكلُّها من صنفٍ واحد: **الموظّفُ يفعلها بحسابِ نفسِه**، أو تقع قبل
     * المصادقة أصلاً. وحجبُها بصلاحيّةٍ يمنع من لا يملكها من تسجيل
     * الدخول أو تأمين حسابه — وهو عكسُ المقصود.
     *
     * @var array<string,string>
     */
    private const WRITE_OPEN_BY_DESIGN = [
        'admin.auth.' => 'تسجيلُ الدخول — قبل المصادقة',
        'admin.auth.two-factor.verify' => 'تحقّقُ الخطوة الثانية — قبل اكتمال الدخول',
        'admin.amial.2fa.setup' => 'مصادقةُ الموظّف الثنائيّة لحسابه',
        'admin.amial.2fa.confirm' => 'مصادقةُ الموظّف الثنائيّة لحسابه',
        'admin.amial.2fa.disable' => 'مصادقةُ الموظّف الثنائيّة لحسابه',
        'admin.amial.2fa.regenerate' => 'رموزُ تعافي الموظّف لحسابه',
        'admin.amial.locale' => 'لغةُ جلسة الموظّف نفسِه',
        'admin.settings-password' => 'كلمةُ مرور الموظّف نفسِه',
    ];

    /**
     * @test
     *
     * **لا مسارَ كتابةٍ إداريٌّ بلا صلاحيّة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا ما لم يره الحارسُ الأوّل.** ذاك يفحص وجهاتِ القائمة، وهذه
     * أبوابٌ لا تظهر في قائمةٍ إطلاقاً — تُطلَب من شاشةٍ بجافاسكربت.
     *
     * وقِيس عند أوّل تشغيلٍ فكانت **واحداً وثلاثين مسارَ كتابةٍ بلا
     * صلاحيّة**، وفيها:
     *
     *   · `hub/transfer` — تحويلُ محفظةٍ من الإدارة.
     *   · `hub/agents/{id}/credit` — **شحنُ محفظة وكيل: خلقُ مال.**
     *   · `agents/daily/{ulid}/accept` — إقفالُ يوم شبكةٍ بمالِه.
     *   · `variances/{id}/resolve` — حسمُ فرقٍ ماليّ.
     *   · `maintenance/enable` — إيقافُ المنصّة على الجميع.
     *
     * **وأبلغُها أنّ `finance/topup` أسفلَ اثنين منها بأسطر محروسةٌ بـ
     * `money.move` منذ شهور.** فحُرس جارٌ ونُسي جاران يفعلان الشيءَ
     * نفسَه — وهو نمطُ الثغرة الذي لا يظهر في مسحٍ يسأل «أفي القطاع
     * حراسة؟»، بل في مسحٍ يسأل **«أكلُّ بابٍ فيه محروس؟»**.
     */
    public function no_admin_write_route_is_left_without_a_permission(): void
    {
        $naked = [];
        $seen = 0;

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.')) {
                continue;
            }

            if (! array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                continue;
            }

            $seen++;

            if (array_key_exists($name, self::WRITE_OPEN_BY_DESIGN)) {
                continue;
            }

            $guarded = false;

            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'platform:')) {
                    $guarded = true;
                    break;
                }
            }

            if (! $guarded) {
                $naked[] = $name . '  [' . implode('|', $route->methods()) . ' ' . $route->uri() . ']';
            }
        }

        // **وحارسٌ لا يجد ما يفحص ليس حارساً.**
        $this->assertGreaterThan(100, $seen,
            "لم يُلتقط إلّا {$seen} مسارَ كتابةٍ إداريّاً — والمشروعُ فيه أضعافُها.");

        sort($naked);

        $this->assertSame([], $naked,
            "مساراتُ كتابةٍ إداريّةٌ بلا صلاحيّة — **يستدعيها كلُّ من يدخل "
            . "اللوحة**:\n  " . implode("\n  ", $naked) . "\n\n"
            . "وإخفاءُ الزرّ ليس حماية: من يعرف العنوان يستدعيه.\n"
            . 'وإن كان البابُ مفتوحاً عمداً فيُضاف إلى WRITE_OPEN_BY_DESIGN بسببه.');
    }

    /** @test */
    public function money_writes_are_never_open_to_support(): void
    {
        // **جرّبها بالطلب لا بقراءة الوسائط.** فالوسيطُ قد يكون مسجَّلاً
        // ولا يعمل، والقياسُ الصادقُ هو الردّ نفسُه.
        $support = $this->operator(PlatformRoleService::SUPPORT);

        $this->actingAs($support, 'user')
            ->post(route('admin.amial.hub.transfer'))->assertForbidden();

        $this->actingAs($support, 'user')
            ->post(route('admin.amial.hub.agents.credit', ['id' => 1]))->assertForbidden();

        $this->actingAs($support, 'user')
            ->post(route('admin.amial.hub.agents.daily.accept',
                ['ulid' => strtoupper((string) \Illuminate\Support\Str::ulid())]))
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① لا بابَ بلا حارس
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_sidebar_destination_is_guarded(): void
    {
        $naked = [];

        foreach ($this->sidebarLinks() as [$label, $routeName, $_]) {
            if (in_array($routeName, self::OPEN_BY_DESIGN, true)) {
                continue;
            }

            if ($this->routePermissions($routeName) === []) {
                $naked[] = "{$label}  →  {$routeName}";
            }
        }

        sort($naked);

        $this->assertSame([], $naked,
            "صفحاتٌ في قائمة الإدارة بلا صلاحيّةٍ على مسارها — **يفتحها كلُّ "
            . "من يدخل اللوحة**:\n  " . implode("\n  ", $naked) . "\n\n"
            . "و«هل هذا موظّفُ منصّة؟» ليس جواباً عن «هل يحقّ له هذا الفعل؟».\n"
            . 'وإن كان البابُ مفتوحاً عمداً فيُضاف إلى OPEN_BY_DESIGN بسببه.');
    }

    /** @test */
    public function the_link_asks_for_exactly_what_its_page_demands(): void
    {
        // **رابطٌ يُعرَض ثمّ يُردّ ٤٠٣ يُربك أكثر ممّا يفيد** — وهي قاعدةٌ
        // مكتوبةٌ في رأس ملفّ القائمة نفسِه، وكانت مخروقةً في ٣١ رابطاً.
        $mismatched = [];

        foreach ($this->sidebarLinks() as [$label, $routeName, $linkPerm]) {
            if (in_array($routeName, self::OPEN_BY_DESIGN, true)) {
                continue;
            }

            $routePerms = $this->routePermissions($routeName);

            // **والرابطُ يطلب ما تطلبه صفحتُه بالضبط.**
            //
            // فإن كان للصفحة شرطان فالرابطُ يذكر أحدَهما على الأقلّ —
            // وذكرُ غيرِهما يجعله يظهر لمن يُردّ، أو يختفي عمّن يُقبَل.
            if ($linkPerm === null || ! in_array($linkPerm, $routePerms, true)) {
                $mismatched[] = sprintf('%s — الرابط %s · المسار %s',
                    $label, $linkPerm ?? 'null',
                    $routePerms === [] ? 'null' : implode(' + ', $routePerms));
            }
        }

        sort($mismatched);

        $this->assertSame([], $mismatched,
            "صلاحيّةُ الرابط تخالف صلاحيّةَ صفحته:\n  "
            . implode("\n  ", $mismatched) . "\n\n"
            . 'فإمّا يُعرَض ولا يُفتح (٤٠٣ محيّر)، وإمّا يُخفى وهو متاح '
            . '(مبنيٌّ ولا يُوصَل إليه).');
    }

    /** @test */
    public function the_probe_is_actually_reading_the_sidebar(): void
    {
        // **وحارسٌ لا يجد ما يفحص ليس حارساً.** لو تغيّرت صيغةُ القائمة
        // لخرج الفحصان أخضرين على صفر.
        $this->assertGreaterThanOrEqual(50, count($this->sidebarLinks()),
            'لم تُقرأ روابطُ القائمة — تغيّرت صيغتُها ولم يعد المرشِّحُ يراها، '
            . 'فالفحصُ يمرّ على لا شيء.');
    }

    /** @test */
    public function a_two_permission_route_is_not_read_by_its_weaker_half(): void
    {
        // **العطلُ الذي كان في هذا الملفّ نفسِه.** `charity.settlements.payout`
        // يطلب `transactions.view` من مجموعته و`money.move` من نفسه —
        // وقراءةُ الأولى وحدَها تقول إنّه مفتوحٌ للدعم.
        $perms = $this->routePermissions('admin.amial.charity.settlements.payout');

        $this->assertContains('platform.money.move', $perms,
            'صرفُ تسويةِ جمعيّةٍ يُقرأ بصلاحيّةِ مجموعته وحدَها — '
            . 'وحارسٌ يقرأ نصفَ الشرط يُطمئن على النصف الآخر');

        // ويُجرَّب بالطلب لا بالقراءة: الدعمُ يُردّ فعلاً.
        //
        // **والمعرّفُ يطابق قيدَ المسار.** أوّلُ صياغةٍ مرّرت `'X'` فخرج
        // ٤٠٤ لا ٤٠٣ — لأنّ المسارَ لم يُطابَق أصلاً، فلم يبلغ الحارسَ.
        // **واختبارٌ لا يصل إلى ما يفحصه يمرّ على غيابه.**
        $this->actingAs($this->operator(PlatformRoleService::SUPPORT), 'user')
            ->post(route('admin.amial.charity.settlements.payout',
                ['ulid' => strtoupper((string) \Illuminate\Support\Str::ulid())]))
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② والدعمُ يُردّ فعلاً — لا نظريّاً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function support_cannot_open_the_platform_settings(): void
    {
        $support = $this->operator(PlatformRoleService::SUPPORT);

        foreach ([
            'admin.business-settings.business-setup',
            'admin.business-settings.fcm-index',
            'admin.amial.hub.settings',
            'admin.amial.surface.rbac',
        ] as $name) {
            $this->actingAs($support, 'user')->get(route($name))
                ->assertForbidden();
        }
    }

    /** @test */
    public function support_cannot_open_security_or_aml_or_recovery(): void
    {
        $support = $this->operator(PlatformRoleService::SUPPORT);

        foreach ([
            'admin.amial.aml.page',
            'admin.amial.security-events.index',
            'admin.amial.sentinel.index',
            'admin.amial.recovery.index',
            'admin.expense.index',
        ] as $name) {
            $this->actingAs($support, 'user')->get(route($name))
                ->assertForbidden();
        }
    }

    /** @test */
    public function support_keeps_the_doors_its_work_needs(): void
    {
        // **وحاجزٌ يحجب كلَّ شيء يجتاز نصفَ الفحص ثمّ يشلّ كلَّ عملٍ سليم.**
        // فالدعمُ يبقى يفتح ملفَّ العميل ومركزَ الدعم.
        $support = $this->operator(PlatformRoleService::SUPPORT);

        $this->actingAs($support, 'user')
            ->get(route('admin.support-center.index'))->assertOk();
        $this->actingAs($support, 'user')
            ->get(route('admin.amial.hub.customers'))->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ مجاميعُ المنصّة ليست بيانات دعم
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function support_does_not_see_the_platform_totals_or_the_leaderboard(): void
    {
        $support = $this->operator(PlatformRoleService::SUPPORT);

        $this->assertTrue($support->hasPlatformPermission('platform.transactions.view'),
            'الدعمُ فقد قراءةَ الحركة — وهي عملُه اليوميّ');
        $this->assertFalse($support->hasPlatformPermission('platform.analytics.view'),
            'الدعمُ يملك مجاميعَ المنصّة — ومن يعرف من أكبرُ عملائنا وكم '
            . 'يُحرّكون يملك ما يُباع');

        $html = $this->actingAs($support, 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        // **ولا يُكتفى بغياب الرقم** — يُسأل عن اسمٍ لا يظهر إلّا في
        // قائمة الترتيب. فرقمٌ قد يغيب لخلوّ البيانات، والاسمُ لا يغيب
        // إلّا بالحجب.
        $this->assertStringNotContainsString('أعلى العملاء تعاملاً', $html);
        $this->assertStringNotContainsString('أعلى الوكلاء تعاملاً', $html);
    }

    /** @test */
    public function the_admin_still_sees_the_platform_totals(): void
    {
        $admin = $this->operator(PlatformRoleService::ADMIN);

        $this->assertTrue($admin->hasPlatformPermission('platform.analytics.view'));

        $this->actingAs($admin, 'user')->get(route('admin.dashboard'))->assertOk();
    }

    /** @test */
    public function risk_and_compliance_keep_the_leaderboard_they_investigate_with(): void
    {
        // منعُها عنهما يشلّ التحقيق: «من أكبرُ المتعاملين» أوّلُ سؤالٍ في
        // كشف الغسل.
        foreach ([PlatformRoleService::RISK, PlatformRoleService::COMPLIANCE] as $role) {
            $this->assertTrue(
                $this->operator($role)->hasPlatformPermission('platform.analytics.view'),
                "دورُ {$role} فقد مجاميعَ المنصّة — وهي أداةُ تحقيقه الأولى");
        }
    }
}
