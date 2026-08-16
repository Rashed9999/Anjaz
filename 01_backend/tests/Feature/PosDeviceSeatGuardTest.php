<?php

namespace Tests\Feature;

use App\Models\Merchant\PosDevice;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Services\Merchant\PosDeviceRegistrar;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-POS-DEVICES-001 — **مقعدُ الجهاز ليس صفَّ موظّف.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان الحدّان يقرآن `PosUser` نفسَها، فباقةُ البداية تَعِد بصفر موظّفين
 * وتُعطي واحداً، وباقةُ الأعمال تبيع خمسةً وتُسلّم ثلاثة.
 *
 * وهذا الملفُّ يُثبت الفصلَ **بالعمل لا بالوصف**.
 */
class PosDeviceSeatGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $plan): User
    {
        $u = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL, 'subscription_plan' => $plan,
        ]);

        return $u->refresh();
    }

    private function reg(): PosDeviceRegistrar
    {
        return app(PosDeviceRegistrar::class);
    }

    private function seatsSeenByPlanEngine(User $m): int
    {
        return (int) (app(EntitlementService::class)
            ->state($m, 'multi_pos')['usage']['used'] ?? -1);
    }

    /**
     * @test
     *
     * **إضافةُ موظّفٍ لا تزيد عددَ الأجهزة** — وهو نصُّ العطل الذي وُجد.
     */
    public function adding_an_employee_does_not_consume_a_device_seat(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        $before = PosDevice::activeSeats($m->id);

        $staffUser = User::factory()->create(['type' => 2]);

        PosUser::create([
            'user_id' => $staffUser->id,
            'merchant_user_id' => $m->id,
            'pos_number' => 'POS-001',
            'display_name' => 'كاشير',
            'is_active' => true,
        ]);

        $this->assertSame($before, PosDevice::activeSeats($m->id),
            'موظّفٌ جديدٌ استهلك مقعدَ جهاز — فالحدّان ما زالا واحداً');
    }

    /**
     * @test
     *
     * **المجّانيّة: الأوّلُ ينجح والثاني يُرفض.**
     */
    public function the_free_plan_allows_exactly_one_device(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $first = $this->reg()->register($m, 'device-aaa-0001');
        $second = $this->reg()->register($m, 'device-bbb-0002');

        $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED, $first['result'],
            'الجهازُ الأوّل رُفض على المجّانيّة وحدُّها واحد');

        $this->assertSame(PosDeviceRegistrar::RESULT_LIMIT, $second['result'],
            'الجهازُ الثاني مرّ على المجّانيّة — والحدُّ واحد');
    }

    /**
     * @test
     *
     * **الأعمال: ثلاثةٌ تمرّ والرابعُ يُرفض.**
     */
    public function the_business_plan_allows_three_devices(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        foreach (range(1, 3) as $i) {
            $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED,
                $this->reg()->register($m, "device-ok-{$i}")['result'],
                "الجهازُ رقم {$i} رُفض وحدُّ الأعمال ثلاثة");
        }

        $this->assertSame(PosDeviceRegistrar::RESULT_LIMIT,
            $this->reg()->register($m, 'device-over-4')['result'],
            'الجهازُ الرابعُ مرّ وحدُّ الأعمال ثلاثة');
    }

    /**
     * @test
     *
     * **والعودةُ بالبصمة نفسِها لا تستهلك مقعداً.**
     *
     * وإلّا استنفد التاجرُ حدَّه بإعادة فتح التطبيق.
     */
    public function the_same_device_returning_does_not_take_a_second_seat(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $this->reg()->register($m, 'device-same-uuid');
        $again = $this->reg()->register($m, 'device-same-uuid');

        $this->assertSame(PosDeviceRegistrar::RESULT_EXISTING, $again['result'],
            'الجهازُ نفسُه عُدّ جهازاً ثانياً');

        $this->assertSame(1, PosDevice::activeSeats($m->id),
            'مقعدان لبصمةٍ واحدة');
    }

    /**
     * @test
     *
     * **والملغى لا يشغل مقعداً.**
     */
    public function a_revoked_device_frees_its_seat(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $first = $this->reg()->register($m, 'device-to-revoke');

        $this->reg()->revoke($first['device'], $m->id);

        $this->assertSame(0, PosDevice::activeSeats($m->id),
            'الملغى ما زال يشغل مقعداً');

        $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED,
            $this->reg()->register($m, 'device-after-revoke')['result'],
            'المقعدُ لم يُفرَج عنه بعد الإلغاء');
    }

    /**
     * @test
     *
     * **ومحرّكُ الباقات يقرأ الجدولَ الجديد لا صفوفَ الموظّفين.**
     *
     * وهذه هي التجربةُ التي تُثبت الفصلَ في **مسار الإنفاذ** لا في
     * المُسجِّل وحدَه: يُنشأ موظّفٌ ولا يُسجَّل جهاز، فيجب أن يقول المحرّكُ
     * «صفرُ أجهزة».
     */
    public function the_plan_engine_counts_devices_not_employees(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        $staffUser = User::factory()->create(['type' => 2]);

        PosUser::create([
            'user_id' => $staffUser->id,
            'merchant_user_id' => $m->id,
            'pos_number' => 'POS-009',
            'display_name' => 'كاشير',
            'is_active' => true,
        ]);

        $seen = $this->seatsSeenByPlanEngine($m);

        if ($seen < 0) {
            $this->markTestSkipped('قدرةُ «multi_pos» لا تُعلن حدّاً — فالمحرّكُ لا يُسأل عنه');
        }

        $this->assertSame(0, $seen,
            'محرّكُ الباقات ما زال يعدّ الموظّفين أجهزةً — الفصلُ لم يصل إلى مسار الإنفاذ');
    }
}
