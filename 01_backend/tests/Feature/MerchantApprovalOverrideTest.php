<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\MerchantOverrideService;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-APPROVAL-003 — **منحةٌ لا تُستعمَل ليست فصلاً بل شلل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:** `merchant_role_permissions.approval` عمودٌ يُكتب ولا يقرؤه
 * أحد. و`assert()` تعُدّ «يحتاج اعتماداً» رفضاً وتُحيل إلى `evaluate()` —
 * **ولا مُنادي لها في المشروع كلِّه.**
 *
 * فثلاثُ منحٍ مُسندةٍ في القوالب لا تُستعمَل أبداً:
 *
 *   · كاشيرُ التجزئة    → لا يُنشئ مرتجعاً
 *   · موظّفُ المستودع    → لا يُسجّل هالكاً
 *   · مشرفُ ورديّة الوقود → لا يُلغي بيعة
 *
 * **ومنحةٌ تُعرَض في الشاشة وتُسنَد ولا تفتح باباً هي «مبنيٌّ ولا يُوصَل
 * إليه» في أخبث صوره** — لأنّ الشاشةَ تقول إنّها ممنوحة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والموظّفُ هو من ينفّذ، لا المدير.** فالإذنُ يُمنح ولا يُنفَّذ عنه —
 * وبذلك يبقى «من فعل» صادقاً في سجلّ التدقيق، ويُقيَّد معه «من أذن».
 */
class MerchantApprovalOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $vertical = A::BIZ_RETAIL): User
    {
        $m = User::factory()->create(['type' => 3]);

        MerchantProfile::create(['user_id' => $m->id,
            'business_name' => 'متجر', 'business_type' => $vertical]);

        app(VerticalBootstrapService::class)->ensureFor($m);

        return $m->refresh();
    }

    private function staff(User $merchant, string $roleCode): User
    {
        $u = User::factory()->create(['type' => 3]);

        PosUser::create(['merchant_user_id' => $merchant->id, 'user_id' => $u->id,
            'pos_number' => 'POS-' . $u->id, 'is_active' => true]);

        $role = DB::table('merchant_roles')->where('merchant_user_id', $merchant->id)
            ->where('code', $roleCode)->first();

        $this->assertNotNull($role, "الدورُ «{$roleCode}» غيرُ مزروع");

        DB::table('merchant_user_roles')->insert([
            'merchant_user_id' => $merchant->id, 'user_id' => $u->id,
            'merchant_role_id' => $role->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① العطل: منحةٌ ممنوحةٌ لا تُستعمَل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_cashier_holds_the_return_permission_but_needs_an_approval(): void
    {
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');
        $perm = app(MerchantPermissionService::class);

        // **يملكها** — وهذا شرطُ المسألة كلِّها.
        $this->assertTrue($perm->can($cashier, P::RETAIL_RETURN_CREATE));

        // **ولا يستطيعها بلا إذن** — لكنّ الرفضَ يقول أيَّ رفضٍ هو.
        try {
            $perm->assert($cashier, P::RETAIL_RETURN_CREATE);
            $this->fail('نُفّذ فعلٌ يحتاج اعتماداً بلا إذن');
        } catch (DomainException $e) {
            $this->assertSame(MerchantPermissionService::APPROVAL_REQUIRED, $e->getCode(),
                'الرفضُ لا يميّز «يحتاج إذناً» عن «خارج نطاقك» — '
                . 'والخلطُ يُرسل الموظّفَ إلى الدعم بدل مديره');
        }
    }

    /** @test */
    public function with_a_managers_permit_the_same_action_goes_through(): void
    {
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');
        $perm = app(MerchantPermissionService::class);
        $svc = app(MerchantOverrideService::class);

        $id = $svc->request($cashier, P::RETAIL_RETURN_CREATE, 'العميل أعاد صنفاً معيباً');
        $svc->grant($m, $id);              // المالكُ يأذن

        $perm->assert($cashier, P::RETAIL_RETURN_CREATE);

        $this->assertDatabaseHas('merchant_permission_overrides',
            ['id' => $id, 'status' => 'consumed']);
    }

    /** @test */
    public function a_permit_is_spent_once_and_not_twice(): void
    {
        // **إذنٌ يبقى صالحاً إذنٌ دائم** — وذاك منحُ صلاحيّةٍ بخطواتٍ زائدة.
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');
        $perm = app(MerchantPermissionService::class);
        $svc = app(MerchantOverrideService::class);

        $svc->grant($m, $svc->request($cashier, P::RETAIL_RETURN_CREATE, 'مرتجع'));

        $perm->assert($cashier, P::RETAIL_RETURN_CREATE);

        $this->expectException(DomainException::class);
        $perm->assert($cashier, P::RETAIL_RETURN_CREATE);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② والفصلُ يسقط بأيٍّ من اثنين
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function nobody_approves_their_own_request(): void
    {
        // **وهو الفصلُ بعينه لا تفصيلاً فيه.**
        $m = $this->merchant();
        $manager = $this->staff($m, 'store_manager');
        $svc = app(MerchantOverrideService::class);

        $id = $svc->request($manager, P::RETAIL_RETURN_CREATE, 'مرتجع');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('لا يعتمد أحدٌ طلبَ نفسِه');

        $svc->grant($manager, $id);
    }

    /** @test */
    public function a_cashier_cannot_approve_for_another_cashier(): void
    {
        // **ومن يأذن يملك ما يأذن به** — وإلّا صار الإذنُ تبادلَ خدماتٍ
        // بين زميلين، وهو أسوأ من غياب القيد لأنّه يُوهم بضبط.
        $m = $this->merchant();
        $one = $this->staff($m, 'cashier');
        $two = $this->staff($m, 'cashier');
        $svc = app(MerchantOverrideService::class);

        $id = $svc->request($one, P::RETAIL_RETURN_CREATE, 'مرتجع');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('لا تملك سلطةَ الإذن');

        $svc->grant($two, $id);
    }

    /** @test */
    public function a_manager_of_another_shop_cannot_approve_here(): void
    {
        // القاعدة الثامنة: الهويّة تحدّد النطاق، ومعرِّفٌ من الطلب لا يُوثَق به.
        $mine = $this->merchant();
        $other = $this->merchant();

        $cashier = $this->staff($mine, 'cashier');
        $svc = app(MerchantOverrideService::class);

        $id = $svc->request($cashier, P::RETAIL_RETURN_CREATE, 'مرتجع');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('منشأةً أخرى');

        $svc->grant($other, $id);
    }

    /** @test */
    public function a_permit_cannot_grant_a_permission_the_staff_never_had(): void
    {
        // **الإذنُ يرفع قيدَ الاعتماد ولا يمنح صلاحيّةً جديدة.** وبلا هذا
        // يصير بابَ تصعيد: يطلب الكاشيرُ إذناً بفعلٍ لا يملكه فيمنحه
        // مديرٌ لا ينتبه.
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');

        $this->assertFalse(app(MerchantPermissionService::class)
            ->can($cashier, P::RETAIL_STOCK_ADJUST));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('خارج صلاحيّاتك');

        app(MerchantOverrideService::class)
            ->request($cashier, P::RETAIL_STOCK_ADJUST, 'أريد تعديلَ المخزون');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والسقفُ يُقاس على ما يُنفَّذ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_permit_for_five_hundred_does_not_authorise_fifty_thousand(): void
    {
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');
        $svc = app(MerchantOverrideService::class);

        $svc->grant($m, $svc->request($cashier, P::RETAIL_DISCOUNT_APPLY, 'خصمٌ استثنائيّ', '500'));

        // **والآذنُ رأى خمسمئة.** فخمسون ألفاً ليست ما أذن به.
        $this->assertFalse($svc->consume($cashier, P::RETAIL_DISCOUNT_APPLY, '50000'));

        // ولم يُستهلَك — فما زال صالحاً لمبلغه.
        $this->assertTrue($svc->consume($cashier, P::RETAIL_DISCOUNT_APPLY, '500'));
    }

    /** @test */
    public function a_permit_with_no_amount_does_not_cover_an_action_with_one(): void
    {
        // **الآذنُ لم يرَ رقماً** — فلا يُقرأ إذنُه سقفاً مفتوحاً.
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');
        $svc = app(MerchantOverrideService::class);

        $svc->grant($m, $svc->request($cashier, P::RETAIL_DISCOUNT_APPLY, 'خصم'));

        $this->assertFalse($svc->consume($cashier, P::RETAIL_DISCOUNT_APPLY, '9000'));
    }

    /** @test */
    public function an_expired_permit_is_not_a_permit(): void
    {
        // **وإذنٌ بلا نهايةٍ صمتٌ مؤجَّل.**
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');
        $svc = app(MerchantOverrideService::class);

        $id = $svc->request($cashier, P::RETAIL_RETURN_CREATE, 'مرتجع');
        $svc->grant($m, $id);

        DB::table('merchant_permission_overrides')->where('id', $id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse($svc->consume($cashier, P::RETAIL_RETURN_CREATE));
    }

    /** @test */
    public function a_permit_belongs_to_the_one_who_asked_for_it(): void
    {
        // إذنٌ لزميلٍ ليس إذناً لي — وإلّا صار الإذنُ رخصةً للورديّة كلِّها.
        $m = $this->merchant();
        $one = $this->staff($m, 'cashier');
        $two = $this->staff($m, 'cashier');
        $svc = app(MerchantOverrideService::class);

        $svc->grant($m, $svc->request($one, P::RETAIL_RETURN_CREATE, 'مرتجع'));

        $this->assertFalse($svc->consume($two, P::RETAIL_RETURN_CREATE));
        $this->assertTrue($svc->consume($one, P::RETAIL_RETURN_CREATE));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ والمنحُ الثلاثُ الميّتةُ صارت حيّةً — وهي سببُ هذا كلِّه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_three_grants_that_could_never_be_used_now_can(): void
    {
        $cases = [
            [A::BIZ_RETAIL, 'cashier', P::RETAIL_RETURN_CREATE],
            [A::BIZ_RETAIL, 'warehouse_staff', P::RETAIL_WASTE_RECORD],
            [A::BIZ_FUEL, 'supervisor', P::FUEL_SALE_CANCEL],
        ];

        $perm = app(MerchantPermissionService::class);
        $svc = app(MerchantOverrideService::class);

        foreach ($cases as [$vertical, $role, $code]) {
            $m = $this->merchant($vertical);
            $staff = $this->staff($m, $role);

            // قبل الإذن: يملكها ولا يستطيعها.
            $this->assertTrue($perm->can($staff, $code));

            try {
                $perm->assert($staff, $code);
                $this->fail("{$vertical}/{$role} نفّذ {$code} بلا إذن");
            } catch (DomainException $e) {
                $this->assertSame(MerchantPermissionService::APPROVAL_REQUIRED,
                    $e->getCode());
            }

            // وبعده: تمضي.
            $svc->grant($m, $svc->request($staff, $code, 'حالةٌ استثنائيّة'));

            $perm->assert($staff, $code);

            $this->assertTrue(true);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ ولا إذنَ بلا أثر
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_request_and_every_grant_leaves_a_trace(): void
    {
        // **إذنٌ بلا أثرٍ لا يُراجَع** — ومن أذن يُسأل كمن نفّذ.
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');
        $svc = app(MerchantOverrideService::class);

        $svc->grant($m, $svc->request($cashier, P::RETAIL_RETURN_CREATE, 'صنفٌ معيب'));

        $this->assertDatabaseHas('audit_decisions',
            ['action' => 'MERCHANT_OVERRIDE_REQUESTED', 'actor_user_id' => $cashier->id]);

        $this->assertDatabaseHas('audit_decisions',
            ['action' => 'MERCHANT_OVERRIDE_GRANTED', 'actor_user_id' => $m->id]);
    }

    /** @test */
    public function a_request_without_a_reason_is_refused(): void
    {
        $m = $this->merchant();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('سببُ الطلب مطلوب');

        app(MerchantOverrideService::class)
            ->request($this->staff($m, 'cashier'), P::RETAIL_RETURN_CREATE, '   ');
    }

    /** @test */
    public function a_rejection_must_say_why_and_closes_the_door(): void
    {
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');
        $svc = app(MerchantOverrideService::class);

        $id = $svc->request($cashier, P::RETAIL_RETURN_CREATE, 'مرتجع');
        $svc->reject($m, $id, 'لا يوجد إيصالُ شراء');

        $this->assertFalse($svc->consume($cashier, P::RETAIL_RETURN_CREATE));
        $this->assertDatabaseHas('merchant_permission_overrides',
            ['id' => $id, 'status' => 'rejected']);
    }

    /** @test */
    public function an_action_that_needs_no_approval_is_untouched(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة.** بيعُ الكاشير لا يحتاج
        // إذناً، ويجب ألّا يمرّ بهذا المسار إطلاقاً.
        $m = $this->merchant();
        $cashier = $this->staff($m, 'cashier');

        app(MerchantPermissionService::class)->assert($cashier, P::RETAIL_PRODUCT_VIEW);

        $this->assertSame(0, DB::table('merchant_permission_overrides')->count(),
            'فعلٌ لا يحتاج إذناً استهلك إذناً');
    }
}
