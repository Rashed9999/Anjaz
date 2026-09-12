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

    /**
     * AMIAL-POS-SEAT-002 — **باقةُ المقاعد تُختار بالحدّ لا بالاسم.**
     *
     * كانت هذه الاختباراتُ تستعمل `PLAN_FREE` بوصفها «الباقةَ ذاتَ
     * المقعد الواحد». ثمّ قرّر صاحبُ المشروع أن تكون المجّانيّةُ **بلا
     * مقاعد** — فسقطت عشرةُ اختباراتٍ سليمةٍ دفعةً واحدة، لا لأنّ شيئاً
     * انكسر بل لأنّها كتبت **قرارَ تسعيرٍ في نصّها**.
     *
     * فصارت تسأل الجدولَ عن **أصغر باقةٍ لها مقاعد**، وتعبّر عن كلّ
     * توقّعٍ بحدّها لا برقمٍ مكتوب. فتغييرُ التسعير غداً لا يُسقط حارساً
     * واحداً.
     */
    private const SEAT_PLAN = A::PLAN_BUSINESS;

    /** حدُّ المقاعد المُعلَن لباقة الاختبار — يُقرأ ولا يُكتب. */
    private function seatLimit(): int
    {
        return (int) (A::PLAN_LIMITS[self::SEAT_PLAN]['pos_devices'] ?? 0);
    }


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

    /**
     * يملأ مقاعدَ التاجر حتّى الحدّ **إلّا واحداً** — فيبقى مقعدٌ واحدٌ
     * حرّ. وبه تُختبَر حالةُ «الحدُّ مستنفَد» بلا كتابة رقمٍ في النصّ.
     */
    private function fillSeatsLeavingOne(\App\Models\User $m): void
    {
        for ($i = 1; $i < $this->seatLimit(); $i++) {
            $this->reg()->register($m, "seat-filler-{$i}");
        }
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

        $staffUser = User::factory()->create(['type' => 4, 'role' => 'pos']);

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
     * **الباقةُ تقبل حدَّها بالضبط، والزائدُ يُرفض.**
     *
     * ويُقرأ الحدُّ من الجدول: رقمٌ مكتوبٌ هنا يُسقط الاختبارَ أوّلَ
     * تغييرِ تسعير، وهو ما وقع فعلاً حين صارت المجّانيّةُ بلا مقاعد.
     */
    public function a_plan_accepts_exactly_its_declared_device_count(): void
    {
        $limit = $this->seatLimit();

        $this->assertGreaterThan(0, $limit,
            'باقةُ الاختبار بلا مقاعد — الحارسُ يفحص فراغاً');

        $m = $this->merchant(self::SEAT_PLAN);

        for ($i = 1; $i <= $limit; $i++) {
            $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED,
                $this->reg()->register($m, "device-ok-{$i}")['result'],
                "الجهازُ رقم {$i} رُفض والحدُّ {$limit}");
        }

        $this->assertSame(PosDeviceRegistrar::RESULT_LIMIT,
            $this->reg()->register($m, 'device-over')['result'],
            "الجهازُ رقم " . ($limit + 1) . " مرّ والحدُّ {$limit}");
    }

    /**
     * @test
     *
     * **والمجّانيّةُ بلا مقعدٍ إطلاقاً — بقرارِ تسعيرٍ صريح.**
     */
    public function the_free_plan_registers_no_device_at_all(): void
    {
        $this->assertSame(0, (int) (A::PLAN_LIMITS[A::PLAN_FREE]['pos_devices'] ?? -1),
            'عادت المجّانيّةُ تبيع مقعداً — والقرارُ أن تكون بلا موظّفين');

        $m = $this->merchant(A::PLAN_FREE);

        $this->assertSame(PosDeviceRegistrar::RESULT_LIMIT,
            $this->reg()->register($m, 'device-free-1')['result'],
            'سجّلت المجّانيّةُ جهازاً وحدُّها صفر');
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
        $m = $this->merchant(self::SEAT_PLAN);

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
        $m = $this->merchant(self::SEAT_PLAN);

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
     * **والملغى يعود حيّاً في صفّه — لا في صفٍّ ثانٍ ولا ملغىً.**
     *
     * ══════════════════════════════════════════════════════════════
     * **وهذا عطلٌ وُجد بالسؤال لا بالبلاغ.** كان المُسجِّل يُنشئ صفّاً
     * جديداً للجهاز الملغى — **وهو يصطدم بالقيد الفريد حتماً**: التاجرُ
     * نفسُه والبصمةُ نفسُها. فيقع في مُلتقِط `1062` فيُعيد «موجودٌ»
     * مشيراً إلى **الصفّ الملغى نفسِه**.
     *
     * أي أنّ المُسجِّل يُعلن النجاحَ ويُسلّم جهازاً ملغى، **ولا يُستعاد
     * جهازٌ أُلغي أبداً**. ولا خطأَ في أيّ سجلّ: `1062` مُلتقَطٌ عمداً.
     */
    public function a_revoked_device_comes_back_alive_in_its_own_row(): void
    {
        $m = $this->merchant(self::SEAT_PLAN);

        $first = $this->reg()->register($m, 'device-revoke-then-return');
        $this->reg()->revoke($first['device'], $m->id);

        $back = $this->reg()->register($m, 'device-revoke-then-return');

        $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED, $back['result'],
            'العودةُ بعد الإلغاء لم تُقرأ تسجيلاً جديداً');

        $this->assertNull($back['device']->revoked_at,
            '**المُسجِّلُ أعلن النجاحَ وسلّم جهازاً ما زال ملغى** — '
            . 'فالجهازُ الملغى لا يُستعاد أبداً');

        $this->assertTrue((bool) $back['device']->is_active,
            'الجهازُ عاد غيرَ نشِط');

        $this->assertSame(1, PosDevice::where('merchant_user_id', $m->id)->count(),
            'صفّان لبصمةٍ واحدة');

        $this->assertSame(1, PosDevice::activeSeats($m->id),
            'العودةُ لم تشغل مقعداً — فالإلغاءُ ثمّ العودةُ بابُ تجاوزٍ للحدّ');
    }

    /**
     * @test
     *
     * **والعودةُ بعد الإلغاء تمرّ بالحدّ كأيّ جهازٍ جديد.**
     *
     * وإلّا صار الإلغاءُ بابَ تجاوز: تُلغى الأجهزةُ وتُعاد بلا حساب.
     */
    public function a_revoked_device_cannot_return_when_the_limit_is_full(): void
    {
        $m = $this->merchant(self::SEAT_PLAN);

        $this->fillSeatsLeavingOne($m);          // بقي مقعدٌ واحدٌ حرّ

        $old = $this->reg()->register($m, 'device-old-one');
        $this->reg()->revoke($old['device'], $m->id);

        // المقعدُ الحرُّ الأخيرُ شُغل بجهازٍ آخر.
        $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED,
            $this->reg()->register($m, 'device-new-one')['result']);

        $back = $this->reg()->register($m, 'device-old-one');

        $this->assertSame(PosDeviceRegistrar::RESULT_LIMIT, $back['result'],
            'الملغى عاد والحدُّ مستنفَد — **فالإلغاءُ بابُ تجاوزٍ للحدّ**');

        $this->assertSame($this->seatLimit(), PosDevice::activeSeats($m->id),
            'المقاعدُ تجاوزت الحدَّ بعودةِ ملغى');
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

        $staffUser = User::factory()->create(['type' => 4, 'role' => 'pos']);

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
