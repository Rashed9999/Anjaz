<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\UsageLimitService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-POS-SEAT-001 — **مقعدُ نقطة البيع يُشغَّل، لا يُباع فقط.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **كشفه صاحبُ المشروع بالمحاولة:** «لم أستطع التجربة بحساب نقطة بيع —
 * ليس هناك طريقة لإنشاء موظّف نقطة بيع».
 *
 * وقِيس فلم يكن الخللُ في غياب شاشةٍ ولا مسار — **كلاهما مبنيٌّ**:
 * `POST /amial/merchant/staff` يُنشئ الحساب (`type = 4`) برمز موظّفٍ
 * وكلمةِ مرور، و`merchant_staff_screen.dart` تناديه.
 *
 * **بل في تركيبةِ حدودٍ لا تجتمع:**
 *
 *     الباقة المجّانيّة :  pos_devices = 1   ·   employees = 0
 *
 * والدخولُ إلى نقطة البيع يكون **برمز موظّف** (`pos_users.pos_number`).
 * فـ`assertCanAddEmployee` تقارن `0 >= 0` وترفض أوّلَ إنشاء — **والمقعدُ
 * المُعلَن في الباقة لا يُملأ أبداً**، لا بترقيةٍ ولا بغيرها، لأنّ العطل
 * في الجمع لا في العدد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا صنفٌ لا تمسكه الاختبارات المعتادة:** كلُّ رقمٍ منهما صحيحٌ
 * وحدَه، ولا سطرَ يكسر، ولا خطأَ في أيّ سجلّ. **والتناقضُ في العلاقة
 * بينهما** — ولا يظهر إلّا لمن حاول أن يستعمل ما اشترى.
 *
 * فالحارسُ يسأل سؤالاً واحداً عن **كلّ باقة**: أَوعَدْتَ بمقعدٍ؟ فهل
 * تسمح بمن يجلس فيه؟
 */
class PosSeatIsUsableGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     *
     * **كلُّ باقةٍ تعِد بنقطة بيعٍ تسمح بموظّفٍ يشغّلها.**
     */
    public function a_plan_that_sells_a_pos_seat_allows_someone_to_fill_it(): void
    {
        $broken = [];
        $checked = 0;

        foreach (A::ALL_PLANS as $plan) {
            $limits = A::PLAN_LIMITS[$plan] ?? null;

            $this->assertNotNull($limits, "باقةٌ بلا حدود: {$plan}");

            $checked++;

            $seats = (int) ($limits['pos_devices'] ?? 0);
            $staff = (int) ($limits['employees'] ?? 0);

            // اللانهائيّ (-1) لا يحدّ شيئاً.
            $sellsSeat = $seats !== 0;
            $allowsStaff = $staff !== 0;

            if ($sellsSeat && ! $allowsStaff) {
                $broken[] = sprintf(
                    '  %-11s → نقاط بيع: %d · موظّفون: %d',
                    $plan, $seats, $staff);
            }
        }

        $this->assertSame(count(A::ALL_PLANS), $checked,
            'لم تُفحص كلُّ الباقات — وحارسٌ يفحص بعضَها يقول «سليم» ولم ينظر');

        $this->assertSame([], $broken,
            "**باقاتٌ تبيع مقعدَ نقطة بيعٍ ولا تسمح بمن يشغله:**\n"
            . implode("\n", $broken) . "\n\n"
            . 'والدخولُ إلى نقطة البيع برمزِ موظّف، فمقعدٌ بلا موظّفٍ '
            . "**لا يُستعمل أبداً**.\n"
            . 'فإمّا يُسمح بموظّفٍ واحدٍ على الأقلّ، وإمّا يُنزَل '
            . '`pos_devices` إلى صفرٍ — ولا يُترك تناقضاً صامتاً.');
    }

    /**
     * @test
     *
     * **ويُقاس بالتشغيل لا بالجدول.**
     *
     * جدولُ الحدود قد يقول «واحد» ويرفض المسارُ لسببٍ آخر. فيُنشأ تاجرٌ
     * مجّانيٌّ ويُطلب منه موظّفٌ فعلاً. (القاعدة السادسة.)
     */
    public function a_free_merchant_can_actually_create_its_first_pos_employee(): void
    {
        $merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_PHARMACY,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);

        // لا يرمي = المقعدُ الأوّلُ مسموح.
        app(UsageLimitService::class)->assertCanAddEmployee($merchant->fresh());

        $this->assertTrue(true,
            'تاجرٌ مجّانيٌّ لا يستطيع إنشاء موظّفه الأوّل — ونقطةُ البيع '
            . 'المُعلَنةُ في باقته لا تُستعمل');
    }

    /**
     * @test
     *
     * **والحدُّ يبقى حدّاً — لا يُلغى بحجّة التشغيل.**
     *
     * فالمقصودُ فتحُ المقعد الأوّل لا فتحُ الباب على مصراعيه: الثاني
     * يُردّ في المجّانيّة، وإلّا صارت الباقاتُ بلا معنى.
     */
    public function the_free_plan_still_stops_at_its_declared_ceiling(): void
    {
        $merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_PHARMACY,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);

        $ceiling = (int) A::PLAN_LIMITS[A::PLAN_FREE]['employees'];

        $this->assertGreaterThan(0, $ceiling, 'السقفُ صفرٌ — الاختبار يفحص فراغاً');

        // يُملأ السقفُ بموظّفين حقيقيّين.
        for ($i = 0; $i < $ceiling; $i++) {
            $staff = User::factory()->create(['type' => 4, 'zone_code' => 'SOUTH']);

            \App\Models\PosUser::create([
                'user_id' => $staff->id,
                'merchant_user_id' => $merchant->id,
                'pos_number' => 'E' . $i,
                'display_name' => 'موظّف ' . $i,
                'is_active' => true,
                'permissions' => [],
            ]);
        }

        $this->expectException(\App\Exceptions\UsageLimitExceededException::class);

        app(UsageLimitService::class)->assertCanAddEmployee($merchant->fresh());
    }
}
