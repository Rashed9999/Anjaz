<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-POS-SEAT-002 — **المقعدُ يُشغَّل بالضغط، لا يُقرأ في جدول.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **كشفه صاحبُ المشروع مرّتين، والثانيةُ لأنّ حارسي كذب.**
 *
 * ① أوّلاً: «لم أستطع التجربة بحساب نقطة بيع — ليس هناك طريقة لإنشاء
 *    موظّف نقطة بيع». وقِيس فكان `employees => 0` مع `pos_devices => 1`:
 *    مقعدٌ يُباع ولا يُشغَّل.
 *
 * ② فرُفع `employees` إلى ١، **وكُتبت الصياغةُ الأولى من هذا الحارس
 *    تقرأ `PLAN_LIMITS` وتقارن رقمين**. فمرّت خضراء — **والمسارُ ما زال
 *    يردّ ٤٠٢**: قدرةُ `employees` أرضيّتُها `PLAN_BUSINESS` في
 *    `CapabilityRegistry`، والحدُّ في الجدول لا يعرف شيئاً عن ذلك.
 *
 *    فبقي صاحبُ المشروع عاجزاً عن التجربة **وبوّابتي خضراء**، حتّى صرخ:
 *    «حرام عليك، نسيتَ أهمّ مسار».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والدرسُ في صنف الحارس لا في رقمه:** جدولُ الحدود مصدرٌ واحدٌ من
 * ثلاثة يحكم المقعد — ومعه أرضيّةُ القدرة، والصلاحيّةُ على المسار.
 * **وحارسٌ يقرأ أحدَها يقول «سليم» ولم يضغط.** (القاعدة السادسة: يُقاس
 * بالتشغيل. والتاسعة: زرٌّ لم يُضغط ليس مبنيّاً.)
 *
 * فصار يُنشئ تاجراً حقيقيّاً و**يطلب موظّفاً من المسار نفسِه** الذي
 * يطلبه التطبيق.
 */
class PosSeatIsUsableGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function merchantOn(string $plan): User
    {
        $this->seq++;

        $m = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'is_active' => 1,
            'phone' => '9677002' . str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
        ]);

        MerchantProfile::create([
            'user_id' => $m->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => $plan,
        ]);

        return $m->fresh();
    }

    /** يطلب موظّفاً من المسار الحقيقيّ، ويُعيد الردّ. */
    private function askForEmployee(User $merchant, string $code): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/staff', [
                'employee_code' => $code,
                'display_name' => 'كاشير ' . $code,
                'password' => 'pos12345',
                'permissions' => [],
            ]);
    }

    /**
     * @test
     *
     * **كلُّ باقةٍ تعِد بمقعدٍ تُسلّمه فعلاً — والقياسُ بالمسار.**
     *
     * ولا يُقرأ الجدولُ وحدَه: ثلاثةُ مصادرَ تحكم المقعد، والاتّفاقُ
     * بينها هو ما يُشتكى من غيابه.
     */
    public function every_plan_that_sells_a_seat_actually_delivers_it(): void
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

            // ── ① الجدولُ متّسقٌ مع نفسه ──
            if ($sellsSeat !== $allowsStaff) {
                $broken[] = sprintf(
                    '  %-11s جدولُ الحدود يناقض نفسَه: نقاط بيع %d · موظّفون %d',
                    $plan, $seats, $staff);

                continue;
            }

            // ── ② والمسارُ يوافق الجدول ──
            //
            // **وهذه هي الخطوةُ التي لم تكن.** الجدولُ يقول «واحد»
            // والقدرةُ أرضيّتُها الأعمال، فيردّ المسارُ ٤٠٢ ولا يعلم
            // الجدولُ شيئاً.
            $response = $this->askForEmployee($this->merchantOn($plan), 'E1');
            $accepted = $response->status() === 201;

            if ($sellsSeat && !$accepted) {
                $broken[] = sprintf(
                    "  %-11s تَعِد بمقعدٍ **والمسارُ يردّ %d**: %s",
                    $plan, $response->status(),
                    (string) ($response->json('message') ?? ''));
            }

            if (!$sellsSeat && $accepted) {
                $broken[] = sprintf(
                    '  %-11s لا تَعِد بمقعدٍ **والمسارُ يقبل** — فالحدُّ زينة',
                    $plan);
            }
        }

        $this->assertSame(count(A::ALL_PLANS), $checked,
            'لم تُفحص كلُّ الباقات — وحارسٌ يفحص بعضَها يقول «سليم» ولم ينظر');

        $this->assertSame([], $broken,
            "**باقاتٌ تَعِد بما لا تُسلّم، أو تُسلّم ما لا تَعِد به:**\n"
            . implode("\n", $broken) . "\n\n"
            . "وثلاثةُ مصادرَ تحكم المقعد ولا يعرف بعضُها بعضاً:\n"
            . "  `PLAN_LIMITS`  · أرضيّةُ القدرة في `CapabilityRegistry` "
            . "· الصلاحيّةُ على المسار.\n"
            . 'فيُرفع أحدُها ويُترك الآخر، ويبقى التاجرُ عاجزاً وبوّابتنا خضراء.');
    }

    /**
     * @test
     *
     * **وأرضيّةُ القدرة توافق الجدول — وهو ما فات الصياغةَ الأولى.**
     */
    public function the_capability_floor_agrees_with_the_plan_limits(): void
    {
        $cap = CapabilityRegistry::find('employees');

        $this->assertNotNull($cap, 'قدرةُ «الموظفون» اختفت من الكتالوج');

        $floor = $cap->minimumPlan();
        $order = array_flip(A::ALL_PLANS);

        $this->assertArrayHasKey($floor, $order,
            "أرضيّةُ القدرة «{$floor}» ليست باقةً معروفة");

        $mismatch = [];

        foreach (A::ALL_PLANS as $plan) {
            $sells = (int) (A::PLAN_LIMITS[$plan]['employees'] ?? 0) !== 0;
            $reaches = $order[$plan] >= $order[$floor];

            if ($sells !== $reaches) {
                $mismatch[] = sprintf('  %-11s الجدول: %s · القدرة: %s',
                    $plan, $sells ? 'يبيع' : 'لا يبيع',
                    $reaches ? 'مفتوحة' : 'مقفلة');
            }
        }

        $this->assertSame([], $mismatch,
            "**جدولُ الحدود وأرضيّةُ القدرة يفترقان:**\n"
            . implode("\n", $mismatch) . "\n\n"
            . 'فباقةٌ تبيع مقعداً وقدرتُه مقفلةٌ تردّ ٤٠٢ أبداً — '
            . '**والجدولُ وحدَه لا يكشف ذلك**، وهو ما مرّ على الحارس السابق.');
    }

    /**
     * @test
     *
     * **والحدُّ يبقى حدّاً — لا يُلغى بحجّة التشغيل.**
     *
     * فالمقصودُ تسليمُ ما بيع لا فتحُ الباب على مصراعيه: الموظّفُ الذي
     * يتجاوز سقفَ الباقة يُردّ، وإلّا صارت الباقاتُ بلا معنى.
     */
    public function a_plan_still_stops_at_its_declared_ceiling(): void
    {
        $ceiling = (int) (A::PLAN_LIMITS[A::PLAN_BUSINESS]['employees'] ?? 0);

        $this->assertGreaterThan(0, $ceiling,
            'سقفُ باقة الأعمال صفرٌ — الاختبار يفحص فراغاً');

        $merchant = $this->merchantOn(A::PLAN_BUSINESS);

        for ($i = 0; $i < $ceiling; $i++) {
            $this->askForEmployee($merchant, 'E' . $i)->assertStatus(201);
        }

        $this->assertSame($ceiling,
            PosUser::where('merchant_user_id', $merchant->id)->count(),
            'لم يُنشأ العددُ المسموح — الحارسُ يفحص حالةً غير قائمة');

        $over = $this->askForEmployee($merchant, 'E-OVER');

        $this->assertNotSame(201, $over->status(),
            '**تجاوز التاجرُ سقفَ باقته** — فالحدُّ مكتوبٌ ولا يُطبَّق، '
            . 'والباقاتُ تُباع بلا معنى.');
    }

    /**
     * @test
     *
     * **وباقةُ الأعمال تُسلّم فعلاً — وإلّا كان الحارسُ قفلاً.**
     *
     * فحارسٌ يمنع ما دُفع ثمنُه عطلٌ لا حماية.
     */
    public function a_business_merchant_really_creates_a_pos_employee(): void
    {
        $merchant = $this->merchantOn(A::PLAN_BUSINESS);

        $r = $this->askForEmployee($merchant, 'E01');

        $r->assertStatus(201);

        $pos = PosUser::where('merchant_user_id', $merchant->id)->first();

        $this->assertNotNull($pos, 'ردّ المسارُ نجاحاً ولم يُنشئ شيئاً');
        $this->assertSame('E01', $pos->pos_number);
        $this->assertTrue((bool) $pos->is_active,
            'أُنشئ الموظّفُ معطَّلاً — فلا يدخل، والدخولُ يشترط `active()`');

        // **وحسابُ الدخول يُنشأ معه** — فرمزُ موظّفٍ بلا حسابٍ لا يدخل.
        $user = User::find($pos->user_id);

        $this->assertNotNull($user, 'مقعدٌ بلا حسابِ دخول');
        $this->assertSame(4, (int) $user->type, 'حسابُ الموظّف ليس من نوع نقطة بيع');
        $this->assertSame(1, (int) $user->is_active, 'حسابُ الموظّف معطَّل');
    }
}
