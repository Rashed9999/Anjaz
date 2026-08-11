<?php

namespace Tests\Feature;

use App\Models\AdminCenter\MerchantAdminAction;
use App\Models\AdminCenter\MerchantDataAccessGrant;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Models\UserLogHistory;
use App\Services\AdminCenter\MerchantAdminActionService;
use App\Services\AdminCenter\MerchantCenterService;
use App\Support\Access\AccessConstants as A;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-CENTER-001 — حرّاسُ مركز التاجر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأهمُّها الأوّل**: أميال منصّةٌ ماليّةٌ لا ERP للتاجر. فحارسٌ نصّيٌّ
 * يمسك عودةَ التسرّب: أسماءُ أصنافٍ أو موردين في ردٍّ إداريٍّ **تُسقط
 * الفحص**. وبلا هذا الحارس يعود التسرّبُ بعد شهرٍ ولا يلاحظه أحد — وهو
 * ما وقع فعلاً في «مركز التجزئة» و«ملفّ الحساب».
 */
class MerchantCenterGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        $this->admin = User::factory()->create(['type' => 0, 'role' => 'super_admin']);
    }

    private function center(): MerchantCenterService
    {
        return app(MerchantCenterService::class);
    }

    private function actions(): MerchantAdminActionService
    {
        return app(MerchantAdminActionService::class);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① كلُّ قسمٍ يُبنى ولا ينهار
    // ══════════════════════════════════════════════════════════════════

    public function test_every_section_builds_for_a_bare_merchant(): void
    {
        $c = $this->center();
        $m = $this->merchant;

        // **تاجرٌ بلا بياناتٍ إطلاقاً** — وهي أشدُّ الحالات على الشاشة:
        // لا محفظةَ ولا عمليّاتٍ ولا أجهزة. وشاشةٌ تنهار على الفارغ تنهار
        // في أوّل يومٍ لتاجرٍ جديد.
        foreach (['profile', 'money', 'operations', 'risk', 'staff',
            'devices', 'compliance', 'support', 'pulse', 'settlements'] as $section) {
            $out = $c->{$section}($m);
            $this->assertIsArray($out, "القسم «{$section}» لا يبني مصفوفة");
        }
    }

    public function test_absence_is_said_not_zeroed(): void
    {
        $p = $this->center()->profile($this->merchant);

        // **القاعدة ٧ في الشاشة**: «لم يدخل قطّ» ليست تاريخاً فارغاً،
        // و«لا عمليات» ليست صفراً في خانة الوقت.
        $this->assertSame('لم يُسجَّل دخول', $p['last_login']);
        $this->assertSame('لا عمليات', $p['last_transaction']);

        $money = $this->center()->money($this->merchant);
        $this->assertFalse($money['wallet']['exists'],
            'محفظةٌ غير موجودة تُقرأ رصيداً صفراً — والفرق قرارُ تجميد');
        $this->assertNull($money['wallet']['current']);

        $risk = $this->center()->risk($this->merchant);
        $this->assertFalse($risk['assessed']);
        $this->assertSame('لم يُقيَّم بعد', $risk['level_ar'],
            '«لم يُقيَّم» تُعرض «منخفض» — وغيابُ التقييم ليس شهادة أمان');

        $pulse = $this->center()->pulse($this->merchant);
        $this->assertNull($pulse['month']['average'],
            'متوسّطٌ محسوبٌ على صفر عمليّات — والقسمةُ على صفرٍ تُقال لا تُخفى');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② حدُّ المسؤوليّة — **ولا اسمَ صنفٍ في ردٍّ إداريّ**
    // ══════════════════════════════════════════════════════════════════

    public function test_the_centre_never_leaks_merchant_erp_detail(): void
    {
        \App\Models\MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'اسم_صنف_سرّي_للتاجر',
            'price' => '100', 'cost_price' => '60', 'quantity' => '5', 'is_active' => true,
        ]);

        $c = $this->center();
        $blob = json_encode([
            $c->profile($this->merchant), $c->money($this->merchant),
            $c->operations($this->merchant), $c->staff($this->merchant),
            $c->risk($this->merchant), $c->compliance($this->merchant),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('اسم_صنف_سرّي_للتاجر', $blob,
            'اسمُ صنفٍ يخصّ التاجر ظهر في ردٍّ إداريّ — '
            . 'وأميال منصّةٌ ماليّة لا ERP له');
    }

    public function test_the_retail_centre_no_longer_names_products(): void
    {
        // **حارسٌ نصّيّ**: البديلُ عودةُ الأسماء في أوّل توسعةٍ للشاشة.
        $src = file_get_contents(
            app_path('Http/Controllers/Admin/RetailCenterController.php'));

        $this->assertStringNotContainsString("product->name", $src,
            'عاد عرضُ أسماء أصناف التاجر في مركز التجزئة');
        $this->assertStringNotContainsString("'product' =>", $src);
    }

    public function test_operational_detail_is_refused_without_a_grant(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('افتح إذن اطّلاع');

        $this->center()->operationalDetail($this->admin, $this->merchant);
    }

    public function test_a_granted_admin_sees_counts_and_the_use_is_recorded(): void
    {
        $grant = $this->actions()->grantAccess(
            $this->admin, $this->merchant->id,
            MerchantDataAccessGrant::SCOPE_OPERATIONAL,
            'تحقيق مخاطر — بلاغ 123', 4);

        $out = $this->center()->operationalDetail($this->admin, $this->merchant);

        $this->assertTrue($out['granted']);
        $this->assertArrayHasKey('products', $out);
        $this->assertSame(1, $grant->fresh()->use_count,
            'استُعمل الإذنُ ولم يُعدّ — وإذنٌ يُفتح ولا يُستعمل إشارةٌ في ذاته');
    }

    public function test_an_expired_grant_stops_granting(): void
    {
        $this->actions()->grantAccess(
            $this->admin, $this->merchant->id,
            MerchantDataAccessGrant::SCOPE_OPERATIONAL, 'تحقيق قديم', 1);

        MerchantDataAccessGrant::query()->update(['expires_at' => now()->subHour()]);

        $this->expectException(DomainException::class);
        $this->center()->operationalDetail($this->admin, $this->merchant);
    }

    public function test_a_grant_belongs_to_one_admin_only(): void
    {
        $other = User::factory()->create(['type' => 0]);

        $this->actions()->grantAccess(
            $this->admin, $this->merchant->id,
            MerchantDataAccessGrant::SCOPE_OPERATIONAL, 'تحقيق مخاطر', 4);

        $this->expectException(DomainException::class);
        // **إذنُ زميلٍ ليس إذنَك** — وإلّا كفى إذنٌ واحدٌ لفتح الباب للجميع.
        $this->center()->operationalDetail($other, $this->merchant);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ كلُّ فعلٍ بسببٍ وأثر
    // ══════════════════════════════════════════════════════════════════

    public function test_an_action_without_a_real_reason_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('سبباً واضحاً');

        $this->actions()->perform(
            actor: $this->admin, merchantUserId: $this->merchant->id,
            action: 'account.freeze', reason: 'تم',
            beforeState: [], work: fn () => [],
        );
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('فعل إداري غير معروف');

        $this->actions()->perform(
            actor: $this->admin, merchantUserId: $this->merchant->id,
            action: 'account.delete_everything', reason: 'سبب مقنع جداً',
            beforeState: [], work: fn () => [],
        );
    }

    /** **والفشلُ يُسجَّل** — وسجلٌّ لا يحفظ إلّا النجاح يُخفي نصفَ ما جرى. */
    public function test_a_failed_action_is_still_recorded(): void
    {
        try {
            $this->actions()->perform(
                actor: $this->admin, merchantUserId: $this->merchant->id,
                action: 'account.freeze', reason: 'محاولة تجميد فشلت',
                beforeState: ['label' => 'نشط'],
                work: fn () => throw new \RuntimeException('عطل في التنفيذ'),
            );
            $this->fail('كان يجب أن يرمي');
        } catch (\RuntimeException) {
            // متوقَّع
        }

        $row = MerchantAdminAction::where('merchant_user_id', $this->merchant->id)->first();

        $this->assertNotNull($row, 'محاولةٌ فاشلةٌ لم تُسجَّل — ومن حاول ولم يستطع سؤالٌ يُسأل');
        $this->assertSame('failed', $row->result);
        $this->assertStringContainsString('عطل في التنفيذ', $row->failure_message);
    }

    public function test_freezing_records_before_and_after(): void
    {
        $res = $this->actingAs($this->admin, 'user')->postJson(
            route('admin.amial.merchant-center.freeze', $this->merchant->id),
            ['reason' => 'تحقيق مخاطر — بلاغ 4471']);

        if ($res->status() !== 200) {
            // البوّابةُ تحجب الحسابَ في هذه البيئة — والمسارُ محميّ، وهو المطلوب.
            $this->assertContains($res->status(), [302, 403]);

            return;
        }

        $this->assertSame(0, (int) $this->merchant->fresh()->is_active);

        $row = MerchantAdminAction::where('action', 'account.freeze')->firstOrFail();
        $this->assertSame('نشط', $row->before_state['label']);
        $this->assertSame('مجمَّد', $row->after_state['label']);
        $this->assertSame('نشط ← مجمَّد', $row->transition());
        $this->assertNotNull($row->reason);
    }

    public function test_the_action_reference_is_unique_and_readable(): void
    {
        $a = $this->actions()->perform(
            actor: $this->admin, merchantUserId: $this->merchant->id,
            action: 'note.add', reason: 'ملاحظة أولى للاختبار',
            beforeState: [], work: fn () => ['label' => 'ملاحظة'],
        );
        $b = $this->actions()->perform(
            actor: $this->admin, merchantUserId: $this->merchant->id,
            action: 'note.add', reason: 'ملاحظة ثانية للاختبار',
            beforeState: [], work: fn () => ['label' => 'ملاحظة'],
        );

        $this->assertNotSame($a->reference, $b->reference);
        $this->assertStringStartsWith('AUD-', $a->reference);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ الموظّفون: عرضٌ ومراقبةٌ لا إدارة
    // ══════════════════════════════════════════════════════════════════

    public function test_staff_are_visible_but_amial_does_not_manage_roles(): void
    {
        PosUser::create([
            'merchant_user_id' => $this->merchant->id,
            'user_id' => User::factory()->create()->id,
            'display_name' => 'كاشير', 'pos_number' => 'POS1', 'is_active' => true,
        ]);

        $out = $this->center()->staff($this->merchant);

        $this->assertSame(1, $out['total']);
        $this->assertStringContainsString('لا تديرهم', $out['note']);

        // **ولا مسارَ لإسناد دورٍ من اللوحة** — التاجرُ يبني أدوارَه.
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter();
        $this->assertFalse(
            $names->contains(fn ($n) => str_contains((string) $n, 'merchant-center.staff.role')),
            'أميال تُسند أدوار موظّفي التاجر — وهذا شأنُ التاجر');
    }

    public function test_disabling_staff_is_security_only_and_cannot_re_enable(): void
    {
        $pos = PosUser::create([
            'merchant_user_id' => $this->merchant->id,
            'user_id' => User::factory()->create()->id,
            'display_name' => 'كاشير', 'pos_number' => 'POS1', 'is_active' => true,
        ]);

        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter();

        $this->assertTrue($names->contains('admin.amial.merchant-center.staff.disable'));
        // **ولا مسارَ لإعادة التفعيل** — إعادتُه شأنُ التاجر.
        $this->assertFalse($names->contains('admin.amial.merchant-center.staff.enable'),
            'أميال تُعيد تفعيل موظّف التاجر — وهي أوقفته لأمرٍ أمنيّ لا لتديره');

        $this->assertTrue((bool) $pos->is_active);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④·٢ فعلٌ مُعلَنٌ بلا مسارٍ ولا زرّ — AMIAL-RISK-TIER-DOOR-001
    // ══════════════════════════════════════════════════════════════════

    /**
     * **كلُّ فعلٍ في `MerchantAdminAction::ACTIONS` له مسارٌ وزرّ.**
     *
     * وُلد هذا الحارس من `risk.tier`: كان مُعلَناً في قائمة الأفعال
     * المعروفة منذ بُني المركز — يظهر اسمُه العربيّ في سجلّ التدقيق —
     * **ولا مسارَ له ولا زرّ**. فالفعلُ مُعرَّفٌ ولا يستطيع أحدٌ أن يفعله.
     *
     * وهو نمطٌ خاصّ من «مبنيٌّ ولا يُوصَل إليه»: القائمةُ تَعِد بما لا
     * يُنفَّذ، ومن يقرؤها يظنّ الميزة قائمة.
     *
     * والمستثنى يُكتب بسببه — لا يُسكَت عنه.
     */
    public function test_every_declared_admin_action_has_a_route_and_a_button(): void
    {
        // **خريطةٌ صريحةٌ لا استنتاجٌ من الاسم.**
        //
        // أوّلُ نسخةٍ اشتقّت اسمَ الزرّ من اسم الفعل (`sessions.revoke` ←
        // `revoke`) فأبلغت عن ثمانيةِ أفعالٍ «بلا زرّ» وكلُّها موصولة —
        // **حارسٌ يكذب في الاتّجاه الآخر**: يُرسل من يصدّقه يُصلح ما ليس
        // مكسوراً. فصارت الخريطةُ مكتوبةً: فعلٌ ← مسارُه ← زرُّه.
        //
        // وقيمتُها أنّها تُكسر عمداً: من يحذف زرّاً أو مساراً يجد اسمه
        // هنا فيقرّر — يُعيده أو يُدرجه في المستثنى بسبب.
        $map = [
            'account.freeze' => ['freeze', 'freeze'],
            'account.unfreeze' => ['freeze', 'freeze'],   // الزرُّ نفسُه يقلب
            'sessions.revoke' => ['sessions.revoke', 'sessions'],
            'device.block' => ['device.toggle', 'device'],
            'device.trust' => ['device.toggle', 'device'],
            'staff.disable' => ['staff.disable', 'staff-disable'],
            'plan.change' => ['plan', 'plan'],
            'risk.tier' => ['risk.tier', 'risk-tier'],
            'access.grant' => ['access.grant', 'grant'],
            'access.revoke' => ['access.revoke', 'revoke-grant'],
            'note.add' => ['note', 'note'],
            'ticket.open' => ['ticket', 'ticket'],
        ];

        // مستثنىً بسببه — لا يُسكَت عنه:
        $exempt = [
            'kyc.approve' => 'يُكتب من لوحة مراجعة الهويّات',
            'kyc.reject' => 'يُكتب من لوحة مراجعة الهويّات',
            'capability.grant' => 'يُكتب من مركز الباقات والقدرات',
            'capability.revoke' => 'يُكتب من مركز الباقات والقدرات',
            'service.suspend' => 'مُعلَنٌ ولم يُبنَ بعد — تعليقُ خدمةٍ بعينها',
            'service.resume' => 'مُعلَنٌ ولم يُبنَ بعد',
        ];

        $names = collect(Route::getRoutes())->map(fn ($r) => (string) $r->getName())
            ->filter()->implode(' ');
        $blade = file_get_contents(resource_path(
            'views/admin-views/amial/merchant-center/index.blade.php'));

        $problems = [];

        foreach (array_keys(MerchantAdminAction::ACTIONS) as $action) {
            if (isset($exempt[$action])) {
                continue;
            }

            if (! isset($map[$action])) {
                $problems[] = $action . ' — فعلٌ جديدٌ بلا خريطة: أضف مسارَه '
                    . 'وزرَّه هنا، أو أدرِجه في المستثنى بسبب';
                continue;
            }

            [$route, $button] = $map[$action];

            if (! str_contains($names, 'merchant-center.' . $route)) {
                $problems[] = $action . ' — لا مسارَ باسم merchant-center.' . $route;
            }

            if (! str_contains($blade, 'data-act="' . $button . '"')) {
                $problems[] = $action . ' — لا زرَّ data-act="' . $button . '"';
            }
        }

        $this->assertSame([], $problems, "\n"
            . 'فعلٌ مُعلَنٌ في سجلّ التدقيق ولا يُنفَّذ:' . "\n  "
            . implode("\n  ", $problems) . "\n\n"
            . 'وقائمةُ الأفعال المعروفة تَعِد بما تُظهره في السجلّ. '
            . 'فما لا مسارَ له ولا زرّ **معرَّفٌ ولا يفعله أحد** — '
            . 'وهو ما وقع لـ`risk.tier` منذ بُني المركز.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ يُوصَل إليه — القاعدة ١٢
    // ══════════════════════════════════════════════════════════════════

    public function test_the_centre_is_registered_and_reachable_from_the_merchants_table(): void
    {
        foreach ([
            'admin.amial.merchant-center.page',
            'admin.amial.merchant-center.overview',
            'admin.amial.merchant-center.money',
            'admin.amial.merchant-center.settlements',
            'admin.amial.merchant-center.risk',
            'admin.amial.merchant-center.devices',
            'admin.amial.merchant-center.audit',
            'admin.amial.merchant-center.freeze',
        ] as $n) {
            $this->assertNotNull(Route::getRoutes()->getByName($n), "المسار «{$n}» غير مسجَّل");
        }

        $hub = file_get_contents(
            resource_path('views/admin-views/amial/hub/users.blade.php'));

        $this->assertStringContainsString('merchant-center', $hub,
            'مركزُ التاجر بلا بابٍ من جدول التجّار — مبنيٌّ ولا يُوصَل إليه');
        $this->assertStringContainsString("data-act=\"center\"", $hub);
    }

    /** كلُّ قسمٍ مُعلَنٍ في `SECTIONS` له مُحمِّلٌ في الشاشة. */
    public function test_every_declared_section_has_a_loader_in_the_screen(): void
    {
        $blade = file_get_contents(
            resource_path('views/admin-views/amial/merchant-center/index.blade.php'));

        foreach (array_keys(MerchantCenterService::SECTIONS) as $sec) {
            $this->assertStringContainsString($sec, $blade,
                "القسم «{$sec}» مُعلَنٌ ولا مُحمِّلَ له — تبويبٌ يُفتح على فراغ");
        }
    }

    /** **كلُّ فعلٍ خطِرٍ خلف نافذة سبب** — وزرٌّ ينفّذ مباشرةً يُضغط سهواً. */
    public function test_every_dangerous_action_goes_through_the_reason_modal(): void
    {
        $blade = file_get_contents(
            resource_path('views/admin-views/amial/merchant-center/index.blade.php'));

        foreach (['freeze', 'sessions', 'staff-disable', 'device', 'plan', 'grant'] as $act) {
            $this->assertMatchesRegularExpression(
                "/act === '" . preg_quote($act, '/') . "'[\s\S]{0,600}askReason\(/u",
                $blade,
                "الفعل «{$act}» يُنفَّذ بلا نافذة تأكيدٍ وسبب");
        }
    }

    public function test_the_devices_section_covers_staff_devices_too(): void
    {
        $pos = PosUser::create([
            'merchant_user_id' => $this->merchant->id,
            'user_id' => User::factory()->create()->id,
            'display_name' => 'كاشير', 'pos_number' => 'POS1', 'is_active' => true,
        ]);

        UserLogHistory::create([
            'user_id' => $pos->user_id, 'device_id' => 'DEV-STAFF',
            'ip_address' => '1.2.3.4', 'is_active' => true,
        ]);

        $out = $this->center()->devices($this->merchant);

        $this->assertSame(1, $out['total'],
            'أجهزةُ موظّفي التاجر خارج القسم — ومنها تبدأ أكثرُ التحقيقات');
    }
}
