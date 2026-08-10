<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-OPERATOR-RBAC-003 — **كلُّ صلاحيّةٍ تُطلَب لا بدّ أن تكون موجودة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع لهذا الحارس:**
 *
 * `platform.settings.manage` كانت مكتوبةً في أحدَ عشرَ مساراً — منها
 * **مركزُ الباقات والقدرات بأكمله، عشرةُ مسارات** — ولا وجودَ لها في
 * جدول `permissions`.
 *
 * و`hasPlatformPermission($code)` تبني قائمةَ رموزٍ من الجدول ثمّ تسأل
 * `in_array`. فرمزٌ لا صفَّ له في الجدول **لا يملكه أحد بحكم البناء**:
 * لا موظّف، ولا مدير المنصّة (دورُه `*` وُسِّع لحظةَ هجرته إلى أسماء
 * الصلاحيّات المعرَّفة يومئذٍ، وجُمِّد).
 *
 * **والنتيجة صنفُ عطلٍ لا يُنتج خطأً:** المسارُ مسجَّل، والمتحكّمُ
 * سليم، والقالبُ موجود، والاختباراتُ تمرّ — **وكلُّ من يفتح الشاشة يرى
 * ٤٠٣**. ولا سطرَ في أيّ سجلٍّ يقول إنّ السبب رمزٌ لا وجود له، لأنّ
 * الوسيطَ يسجّل «صلاحيّة مرفوضة» — وهي جملةٌ صحيحةٌ تُخفي السبب.
 *
 * ولا يمسكه اختبارُ مسارٍ واحد: من يكتب اختباراً لشاشةٍ يسند لحسابه
 * الصلاحيّة **بالرمز نفسه الخطأ**، فيمرّ الاختبارُ ويسقط الإنسان.
 * فلا يمسكه إلّا مسحُ المسارات كلِّها مقابل الجدول.
 */
class PlatformPermissionExistenceGuardTest extends TestCase
{
    use RefreshDatabase;

    /** كلّ رمزٍ يطلبه وسيطُ `platform:` في أيّ مسار مسجَّل. */
    private function requestedCodes(): array
    {
        $codes = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $mw) {
                if (! is_string($mw) || ! str_starts_with($mw, 'platform:')) {
                    continue;
                }

                $codes[substr($mw, strlen('platform:'))][] = $route->getName() ?: $route->uri();
            }
        }

        return $codes;
    }

    public function test_the_scan_actually_finds_platform_guarded_routes(): void
    {
        // حارسٌ يمسح فلا يجد شيئاً يمرّ دائماً — وهو أسوأ من غيابه.
        $this->assertGreaterThan(
            20,
            count($this->requestedCodes(), COUNT_RECURSIVE),
            'المسحُ لم يجد مساراتٍ محميّةً بـ`platform:` — الحارسُ نفسُه معطَّل');
    }

    public function test_every_permission_a_route_asks_for_exists_in_the_table(): void
    {
        $known = DB::table('permissions')->pluck('code')->all();
        $missing = [];

        foreach ($this->requestedCodes() as $code => $routes) {
            if (! in_array($code, $known, true)) {
                $missing[$code] = array_slice($routes, 0, 4);
            }
        }

        $this->assertSame([], $missing, "\n" . 'صلاحيّاتٌ تُطلَب ولا وجود لها في جدول `permissions`:' . "\n"
            . collect($missing)->map(fn ($r, $c) => "  {$c}  ←  " . implode('، ', $r))->implode("\n") . "\n\n"
            . 'ولا يملكها أحدٌ — ولا مديرُ المنصّة. والشاشاتُ خلفها تردّ ٤٠٣ '
            . 'على الجميع بلا سطرٍ في أيّ سجلّ يقول لماذا. '
            . 'إمّا أن تُضاف الصلاحيّةُ في هجرة، وإمّا أن يُصحَّح الرمزُ في المسار.');
    }

    public function test_the_platform_admin_holds_every_platform_permission(): void
    {
        // مديرُ المنصّة يملك الكلّ **بحكم تعريفه**. ولمّا كان توسيعُ `*`
        // يقع لحظةَ الهجرة، فكلُّ صلاحيّةٍ تُضاف بعدها تسقط منه صامتةً.
        $adminRoleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        $this->assertNotNull($adminRoleId, 'دور مدير المنصّة غير مزروع');

        $held = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $adminRoleId)
            ->pluck('permissions.code')->all();

        $all = DB::table('permissions')->where('code', 'like', 'platform.%')->pluck('code')->all();

        $this->assertSame([], array_values(array_diff($all, $held)),
            'صلاحيّاتٌ لا يملكها مديرُ المنصّة — أُضيفت بعد توسيع `*` ولم تُربط به');
    }

    public function test_the_five_operator_roles_are_seeded_with_their_permissions(): void
    {
        // «لا يجوز أن يستطيع كلّ موظّف في أميال رؤية كلّ شيء.»
        $expected = [
            'platform_support' => 'platform.tickets.manage',
            'platform_finance' => 'platform.merchants.money',
            'platform_risk' => 'platform.merchants.risk',
            'platform_compliance' => 'platform.merchants.compliance',
            'platform_admin' => 'platform.settings.manage',
        ];

        foreach ($expected as $role => $mustHold) {
            $roleId = DB::table('roles')->whereNull('merchant_user_id')
                ->where('code', $role)->value('id');

            $this->assertNotNull($roleId, "الدور {$role} غير مزروع");

            $held = DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role_id', $roleId)
                ->pluck('permissions.code')->all();

            $this->assertContains($mustHold, $held, "الدور {$role} لا يملك {$mustHold}");
        }
    }

    public function test_finance_cannot_reach_compliance_and_support_cannot_reach_money(): void
    {
        // الحدُّ يُقاس بما **لا** يملكه الدور، لا بما يملكه: دورٌ يملك كلَّ
        // شيءٍ يمرّ بكلّ اختبارٍ إيجابيّ.
        $of = function (string $role): array {
            $roleId = DB::table('roles')->whereNull('merchant_user_id')
                ->where('code', $role)->value('id');

            return DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role_id', $roleId)
                ->pluck('permissions.code')->all();
        };

        $this->assertNotContains('platform.merchants.compliance', $of('platform_finance'),
            'فريق المالية يعدّل التوثيق — «Finance لا يستطيع تعديل KYC»');

        $this->assertNotContains('platform.merchants.money', $of('platform_support'),
            'فريق الدعم يقرأ مال التاجر — «Customer Support لا يستطيع تعديل التسويات ولا الأرصدة»');

        $this->assertNotContains('platform.customers.freeze', $of('platform_support'),
            'فريق الدعم يجمّد الحسابات — التجميدُ الأمنيّ لفريق المخاطر');

        $this->assertNotContains('platform.settings.manage', $of('platform_risk'),
            'فريق المخاطر يغيّر الباقات — التسعيرُ لمدير النظام وحده');

        $this->assertNotContains('platform.merchants.money', $of('platform_compliance'),
            'فريق الامتثال يقرأ أرصدة التاجر — عملُه الوثائق لا المال');
    }
}
