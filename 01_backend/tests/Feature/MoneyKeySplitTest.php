<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MONEY-KEY-SPLIT-001 — **مفتاحٌ واحدٌ كان يفتح تسعةً وأربعين باباً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *   platform.money.move  →  ٤٩ مساراً  →  `platform_admin` وحدَه
 *   platform_finance     →  ٦ صلاحيّاتٍ ليس فيها واحدةٌ ماليّة
 *
 * **ففريقُ المالية لا يقرأ تقريرَ ربحٍ ولا يعتمد تسويةً ولا يشحن وكيلاً**،
 * ومن يفعل ذلك كلَّه مديرُ المنصّة — أي أنّ من يدير النظامَ تقنيّاً هو
 * صاحبُ مفتاح المال.
 *
 * **وقد ضوعف هذا التركيزُ في هذه الجلسة نفسِها**: حُرست `hub/transfer`
 * و`agents/credit` و`agents/daily/*` بـ`money.move` — فأُغلقت أبوابٌ
 * وزاد ما يفتحه المفتاحُ الواحد. **إغلاقُ ثغرةٍ بمفتاحٍ عامٍّ يُنشئ
 * مشكلةً أخرى.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والوجهُ الأخفى:** تسعَ عشرةَ **قراءةً** كانت خلفه — لوحةُ المركز
 * الماليّ والفواتيرُ وتصديرُها ومصاريفُ المنصّة. فمن أراد **قراءةَ**
 * تقريرٍ وجب منحُه مفتاحَ التحريك.
 *
 * **فالمفتاحُ العامُّ لا يمنع غيرَ المخوَّل فحسب — بل يُجبر على توسيع من
 * يحمله**، ويتسرّب بابُ التحريك مع كلّ منحِ قراءة.
 */
class MoneyKeySplitTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, $role);

        return $u->refresh();
    }

    /** كلُّ صلاحيّات المسار — لا أوّلُها (انظر `AdminDoorsAreGuardedTest`). */
    private function perms(string $name): array
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
    //  ① القراءةُ لا تحتاج مفتاحَ التحريك
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function no_read_route_demands_the_key_that_moves_money(): void
    {
        $offenders = [];
        $seen = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                continue;
            }

            $seen++;

            foreach ($route->gatherMiddleware() as $mw) {
                if ($mw === 'platform:platform.money.move') {
                    $offenders[] = (string) $route->getName();
                    break;
                }
            }
        }

        $this->assertGreaterThan(200, $seen,
            "لم تُقرأ إلّا {$seen} مساراً — المرشِّحُ لا يرى المسارات، فالفحصُ يمرّ على لا شيء.");

        sort($offenders);

        $this->assertSame([], $offenders,
            "قراءاتٌ تطلب مفتاحَ تحريك المال:\n  " . implode("\n  ", $offenders) . "\n\n"
            . 'فمن أراد أن يقرأ وجب منحُه أن يُحرّك — وهو ما يُوسّع حاملي '
            . 'المفتاح بدل أن يُضيّقهم. تُنقَل إلى `platform.money.view`.');
    }

    /** @test */
    public function no_money_write_is_left_behind_a_read_permission_alone(): void
    {
        // **العطلُ الذي وقع في أثناء هذه القسمة نفسِها.** نقلُ صلاحيّةِ
        // **المجموعة** إلى `money.view` ترك `settlements.approve` و
        // `handovers.confirm` و`dispute` خلف **قراءةٍ فقط** — أي أنّ من
        // يقرأ صار يعتمد تسوية. أمسكه القياسُ قبل الالتزام.
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                continue;
            }

            $perms = $this->perms((string) $route->getName());

            if ($perms === ['platform.money.view']) {
                $offenders[] = (string) $route->getName();
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders,
            "مساراتُ كتابةٍ خلف صلاحيّةِ **قراءةٍ** وحدَها:\n  "
            . implode("\n  ", $offenders) . "\n\n"
            . 'فمن يقرأ صار يقرّر. تُزاد صلاحيّةُ القرار على القراءة.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الحبّاتُ مفصولة — ومنحُ إحداها ليس منحاً للأخرى
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function reading_the_money_screens_does_not_grant_deciding_or_issuing(): void
    {
        // المخاطرُ والامتثالُ يقرآن ولا يقرّران.
        foreach ([PlatformRoleService::RISK, PlatformRoleService::COMPLIANCE] as $role) {
            $u = $this->operator($role);

            $this->assertTrue($u->hasPlatformPermission('platform.money.view'),
                "دورُ {$role} لا يقرأ الشاشاتِ الماليّة — وهي أداةُ عمله");
            $this->assertFalse($u->hasPlatformPermission('platform.settlements.decide'),
                "دورُ {$role} يعتمد التسويات — والقارئُ ليس المقرِّر");
            $this->assertFalse($u->hasPlatformPermission('platform.treasury.issue'),
                "دورُ {$role} يُصدر رصيداً — وهو خلقُ مال");
        }
    }

    /** @test */
    public function the_supervisor_reads_but_never_moves(): void
    {
        $sup = $this->operator(PlatformRoleService::SUPERVISOR);

        $this->assertTrue($sup->hasPlatformPermission('platform.money.view'));
        $this->assertTrue($sup->hasPlatformPermission('platform.fees.view'));
        $this->assertFalse($sup->hasPlatformPermission('platform.money.move'));
        $this->assertFalse($sup->hasPlatformPermission('platform.fees.update'));
        $this->assertFalse($sup->hasPlatformPermission('platform.treasury.issue'));
    }

    /** @test */
    public function support_gains_nothing_from_the_split(): void
    {
        // **شرطُ صحّة القسمة كلِّها.** توسيعُ الأدوار الماليّة يجب ألّا
        // يتسرّب إلى الدعم — وإلّا كانت القسمةُ توسعةً بثوب تنظيم.
        $support = $this->operator(PlatformRoleService::SUPPORT);

        foreach (['platform.money.view', 'platform.money.move',
            'platform.settlements.decide', 'platform.treasury.issue',
            'platform.fees.view', 'platform.fees.update'] as $code) {
            $this->assertFalse($support->hasPlatformPermission($code),
                "الدعمُ نال {$code} من القسمة — والقسمةُ تنظيمٌ لا توسعة");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ وفريقُ المالية يعمل عملَ المالية
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function finance_can_finally_do_finance(): void
    {
        // قِيس قبل القسمة: `platform_finance` يملك ستّاً ليس فيها واحدةٌ
        // ماليّة. ففريقُ المالية لا يقرأ تقريرَ ربحٍ ولا يعتمد تسوية.
        $fin = $this->operator(PlatformRoleService::FINANCE);

        foreach (['platform.money.view', 'platform.fees.view',
            'platform.settlements.decide', 'platform.treasury.issue'] as $code) {
            $this->assertTrue($fin->hasPlatformPermission($code),
                "فريقُ المالية لا يملك {$code}");
        }

        // **ولا يُعدّل الرسومَ بنفسه** — تغييرُ نسبةِ ربحٍ فعلٌ نادرٌ
        // أثرُه دائم، ويبقى قرارَ إدارةٍ حتّى يُبنى المُعِدُّ والمعتمِد.
        $this->assertFalse($fin->hasPlatformPermission('platform.fees.update'),
            'فريقُ المالية يُعدّل نسبَ الأرباح بنفسه بلا معتمِد');
    }

    /** @test */
    public function finance_may_open_the_financial_centre_and_the_admin_still_may(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرةٍ تُكتشَف بتدقيق.**
        foreach ([PlatformRoleService::FINANCE, PlatformRoleService::ADMIN] as $role) {
            $this->actingAs($this->operator($role), 'user')
                ->get(route('admin.amial.hub.finance'))->assertOk();
        }
    }

    /** @test */
    public function issuing_platform_balance_is_refused_to_everyone_but_treasury(): void
    {
        foreach ([PlatformRoleService::SUPPORT, PlatformRoleService::RISK,
            PlatformRoleService::COMPLIANCE, PlatformRoleService::SUPERVISOR] as $role) {
            $this->actingAs($this->operator($role), 'user')
                ->post(route('admin.amial.hub.agents.credit', ['id' => 1]))
                ->assertForbidden();
        }

        $this->assertTrue($this->operator(PlatformRoleService::FINANCE)
            ->hasPlatformPermission('platform.treasury.issue'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ وما بقي على المفتاح العامّ قليلٌ ومعدود
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_general_money_key_now_guards_only_a_handful(): void
    {
        $n = 0;

        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $mw) {
                if ($mw === 'platform:platform.money.move') {
                    $n++;
                    break;
                }
            }
        }

        // كان ٤٩. والحدُّ يمنع التسرّبَ العكسيّ: مسارٌ جديدٌ يُلحَق
        // بالمفتاح العامّ بدل أن يُصنَّف.
        $this->assertLessThanOrEqual(12, $n,
            "المفتاحُ العامُّ يحرس {$n} مساراً — وكان ٤٩ فقُسّم إلى ستّة. "
            . 'مسارٌ جديدٌ أُلحق به بدل أن يُصنَّف في حبّته.');

        $this->assertGreaterThan(0, $n,
            'اختفى المفتاحُ العامُّ كلِّيّاً — فإمّا قُسّم آخرُه ويُحذف '
            . 'هذا الفحصُ بوعي، وإمّا سقطت حراسةٌ بالخطأ.');
    }
}
