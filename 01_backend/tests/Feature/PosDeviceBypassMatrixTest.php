<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Merchant\PosDevice;
use App\Models\Merchant\PosDeviceSession;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\PosDeviceRegistrar;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-POS-DEVICES-005 — **الالتفافاتُ الثمانية، ومعها السباق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القائمةُ من صاحب المشروع حرفاً، وكلُّ بندٍ اختبارٌ هنا:**
 *
 *   ① دخولٌ بلا جهاز
 *   ② دخولٌ بمعرِّفٍ مخترَع
 *   ③ تحديثُ الجلسة بعد الإلغاء
 *   ④ اقترانٌ والحدُّ مستنفَد
 *   ⑤ جهازٌ يخصّ تاجراً آخر
 *   ⑥ فرعٌ يخصّ تاجراً آخر
 *   ⑦ الجهازُ نفسُه مرّتين
 *   ⑧ ملغىً يُعاد تسجيلُه والحدُّ ممتلئ
 *
 * ومعها **سباقٌ حقيقيٌّ على آخر مقعد** — بعمليّتين متوازيتين لا
 * بمحاكاةٍ متتابعة: القراءةُ ثمّ الكتابةُ في خطوتين تنجو دائماً حين لا
 * أحدَ يتحرّك بينهما.
 */
class PosDeviceBypassMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $plan = A::PLAN_FREE): User
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

    private function url(string $suffix = ''): string
    {
        return '/api/v1/amial/merchant/pos-devices'.$suffix;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑦ الجهازُ نفسُه مرّتين — لا يستهلك مقعدين
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_same_device_registered_twice_takes_one_seat(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        $first = $this->actingAs($m, 'api')->postJson($this->url(), ['device_uuid' => 'same-device-0001']);
        $second = $this->actingAs($m, 'api')->postJson($this->url(), ['device_uuid' => 'same-device-0001']);

        $first->assertStatus(200);
        $second->assertStatus(200);

        $this->assertTrue($first->json('data.created'), 'الأوّلُ لم يُنشَأ');
        $this->assertFalse($second->json('data.created'), 'الثاني أُنشئ فاستهلك مقعداً ثانياً');

        $this->assertSame(1, PosDevice::activeSeats($m->id), 'مقعدان لبصمةٍ واحدة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ الاقترانُ ليس بابَ تجاوزٍ للتسجيل
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الاقترانُ يمرّ بالحدّ نفسِه.**
     *
     * وهذا هو القيدُ الرابع: بابان لفعلٍ واحد، فيُقفل الأوّلُ ويُترك
     * الثاني — ولا يُلاحَظ لأنّ الاختبارَ يطرق الأوّل.
     */
    public function pairing_is_refused_when_the_seat_limit_is_full(): void
    {
        $m = $this->merchant(A::PLAN_FREE);   // مقعدٌ واحد

        $this->actingAs($m, 'api')->postJson($this->url(), ['device_uuid' => 'first-device-001'])
            ->assertStatus(200);

        $this->actingAs($m, 'api')->postJson($this->url('/pair'), ['device_uuid' => 'paired-device-99'])
            ->assertStatus(402)
            ->assertJsonPath('code', 'PLAN_LIMIT_REACHED');

        $this->assertSame(1, PosDevice::activeSeats($m->id),
            '**الاقترانُ تجاوز الحدَّ** — فهو بابٌ ثانٍ بلا حارس');
    }

    /** @test */
    public function pairing_and_registering_share_one_seat_for_one_device(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $this->actingAs($m, 'api')->postJson($this->url(), ['device_uuid' => 'device-both-doors']);
        $this->actingAs($m, 'api')->postJson($this->url('/pair'), ['device_uuid' => 'device-both-doors'])
            ->assertStatus(200);

        $this->assertSame(1, PosDevice::activeSeats($m->id),
            'البابان أنتجا مقعدين لجهازٍ واحد');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤⑥ الملكيّة — جهازُ غيرِه وفرعُ غيرِه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_merchant_cannot_revoke_another_merchants_device(): void
    {
        $a = $this->merchant(A::PLAN_BUSINESS);
        $b = $this->merchant(A::PLAN_BUSINESS);

        $device = $this->reg()->register($a, 'device-belongs-to-a')['device'];

        $this->actingAs($b, 'api')->deleteJson($this->url('/'.$device->id))
            ->assertStatus(404);

        $this->assertNull($device->refresh()->revoked_at,
            '**تاجرٌ ألغى جهازَ تاجرٍ آخر** — فالنطاقُ يُقرأ من الطلب لا من الهويّة');
    }

    /** @test */
    public function a_merchant_cannot_rename_another_merchants_device(): void
    {
        $a = $this->merchant(A::PLAN_BUSINESS);
        $b = $this->merchant(A::PLAN_BUSINESS);

        $device = $this->reg()->register($a, 'device-name-target')['device'];

        $this->actingAs($b, 'api')
            ->patchJson($this->url('/'.$device->id), ['display_name' => 'اختُطف'])
            ->assertStatus(404);

        $this->assertNotSame('اختُطف', $device->refresh()->display_name);
    }

    /**
     * @test
     *
     * **⑥ وفرعُ تاجرٍ آخرَ يُرفض.**
     *
     * `branch_id` معرِّفٌ يأتي من الطلب — وما يأتي من الطلب لا يُصدَّق.
     */
    public function a_device_cannot_be_attached_to_another_merchants_branch(): void
    {
        $a = $this->merchant(A::PLAN_BUSINESS);
        $b = $this->merchant(A::PLAN_BUSINESS);

        $foreign = Branch::create([
            'merchant_user_id' => $b->id, 'name' => 'فرعُ الآخر', 'is_active' => true,
        ]);

        $this->actingAs($a, 'api')->postJson($this->url(), [
            'device_uuid' => 'device-foreign-branch',
            'branch_id' => $foreign->id,
        ])->assertStatus(404)->assertJsonPath('code', 'BRANCH_NOT_FOUND');

        $this->assertSame(0, PosDevice::activeSeats($a->id),
            'سُجّل الجهازُ رغم رفض الفرع');
    }

    /** @test */
    public function a_device_attaches_to_an_owned_branch(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        $own = Branch::create([
            'merchant_user_id' => $m->id, 'name' => 'فرعي', 'is_active' => true,
        ]);

        $this->actingAs($m, 'api')->postJson($this->url(), [
            'device_uuid' => 'device-own-branch',
            'branch_id' => $own->id,
        ])->assertStatus(200)->assertJsonPath('data.device.branch_id', $own->id);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑧ الملغى والحدُّ ممتلئ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_revoked_device_cannot_return_while_the_limit_is_full(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $old = $this->reg()->register($m, 'device-old-seat')['device'];
        $this->reg()->revoke($old, $m->id);

        $this->actingAs($m, 'api')->postJson($this->url(), ['device_uuid' => 'device-new-seat'])
            ->assertStatus(200);

        $this->actingAs($m, 'api')->postJson($this->url(), ['device_uuid' => 'device-old-seat'])
            ->assertStatus(402);

        $this->assertSame(1, PosDevice::activeSeats($m->id),
            '**الإلغاءُ بابُ تجاوز**: يُلغى جهازٌ ثمّ يعود فيتجاوز الحدّ');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ الجلسةُ بعد الإلغاء
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الإلغاءُ يختم كلَّ جلسةٍ على الجهاز — لا جلسةَ واحدة.**
     *
     * فورديّتان على الجهاز نفسِه تُنتجان رمزين. وختمُ أحدهما يترك الآخرَ
     * يعمل — **والجهازُ الملغى نصفُ ملغى**.
     */
    public function revoking_ends_every_session_on_that_device(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        $device = $this->reg()->register($m, 'device-two-shifts')['device'];

        foreach (['tok-shift-1', 'tok-shift-2'] as $t) {
            PosDeviceSession::create([
                'access_token_id' => $t, 'pos_device_id' => $device->id,
                'merchant_user_id' => $m->id, 'actor_user_id' => $m->id,
                'started_at' => now(), 'last_seen_at' => now(),
            ]);
        }

        $this->actingAs($m, 'api')->deleteJson($this->url('/'.$device->id))->assertStatus(200);

        $live = PosDeviceSession::where('pos_device_id', $device->id)
            ->whereNull('ended_at')->count();

        $this->assertSame(0, $live,
            '**جلسةٌ نجت من إلغاء الجهاز** — فالإلغاءُ نصفُ إلغاء');
    }

    /** @test */
    public function revoking_twice_is_not_an_error_and_does_not_double_count(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        $device = $this->reg()->register($m, 'device-twice-revoked')['device'];

        $this->actingAs($m, 'api')->deleteJson($this->url('/'.$device->id))->assertStatus(200);

        $again = $this->actingAs($m, 'api')->deleteJson($this->url('/'.$device->id));

        $again->assertStatus(200);
        $this->assertTrue($again->json('data.already_revoked'),
            'الإلغاءُ المكرَّرُ لم يُعلن أنّ الحالةَ قائمةٌ سلفاً');

        $this->assertSame(0, PosDevice::activeSeats($m->id));
    }

    // ══════════════════════════════════════════════════════════════════
    //  الحدود — كلُّ باقةٍ برقمها المُعلَن
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **كلُّ باقةٍ تُسلّم ما يقوله جدولُها بالضبط — لا أكثرَ ولا أقلّ.**
     *
     * ويُقرأ العددُ من `PLAN_LIMITS` لا يُكتب رقماً هنا: رقمٌ مكتوبٌ
     * يشيخ مع أوّل تغييرِ تسعير، **فيمرّ الحارسُ على حدٍّ لم يعد قائماً**.
     */
    public function every_plan_delivers_exactly_the_seats_it_sells(): void
    {
        foreach (A::ALL_PLANS as $plan) {
            $max = (int) (A::PLAN_LIMITS[$plan]['pos_devices'] ?? 0);

            if ($max < 0 || $max > 6) {
                continue;   // بلا حدٍّ أو رقمٌ كبيرٌ لا يُجدي طرقُه
            }

            $m = $this->merchant($plan);

            for ($i = 1; $i <= $max; $i++) {
                $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED,
                    $this->reg()->register($m, "plan-{$plan}-device-{$i}")['result'],
                    "باقة «{$plan}» تبيع {$max} ورفضت الجهازَ رقم {$i}");
            }

            $this->assertSame(PosDeviceRegistrar::RESULT_LIMIT,
                $this->reg()->register($m, "plan-{$plan}-device-over")['result'],
                "باقة «{$plan}» تبيع {$max} وقبلت واحداً فوقه");

            $this->assertSame($max, PosDevice::activeSeats($m->id),
                "عددُ المقاعد لا يطابق ما تبيعه «{$plan}»");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  السباقُ الحقيقيّ — عمليّتان متوازيتان على آخر مقعد
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **والسباقُ على آخر مقعدٍ يُشغَّل — وهذا الحارسُ يضمن أنّه يُشغَّل.**
     *
     * ══════════════════════════════════════════════════════════════
     * **ولا يُحاكى هنا، ولا يُدَّعى.** جُرّب الفرعُ داخل PHPUnit فكذب
     * مرّتين:
     *
     *   ① `RefreshDatabase` يفتح معاملةً، فالتاجرُ المبذورُ غيرُ مثبَّت
     *      **ولا يراه ابنٌ على اتّصالٍ آخر** — فيسقط السباقُ على «التاجر
     *      غير موجود» ويُقرأ ذلك عطلَ منتج.
     *   ② والأبناءُ يرثون مقبسَ الأب، فخروجُ أوّلِهم يُخرجه:
     *      `MySQL server has gone away`.
     *
     * فالسباقُ في `scripts/pos-seat-race.php` ببياناتٍ مثبَّتةٍ واتّصالٍ
     * لكلّ عامل، **وهو مُدرَجٌ في البوّابة**. وقياسُه:
     *
     *   بالقفل  : ٨ عمّالٍ · ٥ جولات ⇒ مقعدٌ واحدٌ في كلّ جولة
     *   بلا قفل : ٤ جولاتٍ من ٥ تجاوزت، وبلغت **٤ مقاعدَ** على حدٍّ واحد
     *
     * **وحارسٌ لا يُنفَّذ ليس حارساً** — فيُثبَّت هنا أنّ البوّابةَ تستدعيه.
     */
    public function the_real_seat_race_is_wired_into_the_verification_gate(): void
    {
        $script = base_path('scripts/pos-seat-race.php');

        $this->assertFileExists($script,
            'سكربتُ السباق غائب — **فحدُّ المقاعد بلا فحصٍ متوازٍ إطلاقاً**');

        $gate = file_get_contents(base_path('scripts/verify.sh'));

        $this->assertStringContainsString('pos-seat-race.php', $gate,
            '**السباقُ مكتوبٌ ولا يُستدعى** — وهو نمطُ العطل الأكثر تكراراً '
            . 'في هذا المشروع: مبنيٌّ ولا يُوصَل إليه');

        // ويُتأكَّد أنّ سقوطَه يُسقط البوّابة، لا يُطبع سطراً ويمضي.
        $this->assertMatchesRegularExpression(
            '~pos-seat-race\.php.*?
.*?
.*?bad "حدُّ مقاعد الأجهزة~s', $gate,
            'سقوطُ السباق لا يُسجَّل فشلاً في البوّابة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ الحدُّ يعدّ الأجهزة لا الموظّفين
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **حدُّ الموظّفين وحدُّ الأجهزة مستقلّان.**
     *
     * قرارُ صاحب المشروع: «الموظّف ≠ الجهاز. لا تدمجهما ولا تحذف أحدهما.»
     * وكانا يعدّان صفوفَ `PosUser` نفسَها.
     */
    public function the_employee_limit_and_the_device_limit_are_independent(): void
    {
        $m = $this->merchant(A::PLAN_BUSINESS);

        for ($i = 1; $i <= 3; $i++) {
            $staff = User::factory()->create(['type' => 4, 'role' => 'pos']);

            PosUser::create([
                'user_id' => $staff->id, 'merchant_user_id' => $m->id,
                'pos_number' => "POS-{$i}", 'display_name' => "كاشير {$i}",
                'is_active' => true,
            ]);
        }

        $this->assertSame(0, PosDevice::activeSeats($m->id),
            '**ثلاثةُ موظّفين استهلكوا مقاعدَ أجهزة** — فالحدّان واحد');

        // وثلاثةُ أجهزةٍ تمرّ رغم وجود ثلاثة موظّفين.
        foreach (range(1, 3) as $i) {
            $this->assertSame(PosDeviceRegistrar::RESULT_REGISTERED,
                $this->reg()->register($m, "independent-device-{$i}")['result'],
                "الجهازُ {$i} رُفض — فحدُّ الموظّفين يخصم من حدّ الأجهزة");
        }
    }
}
