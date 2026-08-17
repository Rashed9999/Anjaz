<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Otp\OtpPolicy;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-OTP-CENTER-001 — مركز التحقّق: كلُّ زرٍّ يعمل، وكلُّ فعلٍ يُقاس أثرُه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وقياسُ ما بعد الضغطة ليس «هل ظهر خطأ» بل «ماذا تغيّر».**
 *
 * زرُّ «إقفال الباب» قد يردّ 200 ويُبهج الشاشة، ثمّ يبقى الرمزُ الثابت
 * مقبولاً. فالفحصُ هنا يضغط الزرّ **ثمّ يسأل `OtpPolicy` نفسَها**: أما
 * زال هذا الرقم يُقبل؟ (القاعدة التاسعة.)
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما فيه: أنّ الإقفال يغلب البذرة.**
 *
 * `AMIAL_DEMO_PHONES` يبقى مقروءاً حين يكون الجدولُ فارغاً — وإلّا
 * لأُقفلت حساباتُ العرض على الخوادم القائمة فجأة. **لكنّ الإقفال يجب أن
 * يغلبه**، وإلّا صار الزرُّ يكذب: يقول «أُقفل» والمتغيّرُ يفتح.
 */
class OtpCenterScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = PlatformRoleService::ADMIN, string $phone = '967770006001'): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'التحقّق', 'phone' => $phone,
            'email' => $phone . '@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        app(PlatformRoleService::class)->assign($u, $role);

        return $u->fresh();
    }

    private function policy(): OtpPolicy
    {
        OtpPolicy::forget();

        return app(OtpPolicy::class);
    }

    // ══════════════════════════════════════════════════════════════
    // ١) الشاشة تُفتح ويُوصل إليها  (القاعدة ١٢)
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الصفحة تُفتح، وفيها كلُّ ما تعد به.**
     */
    public function the_page_opens_with_its_parts(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get('/admin/amial/otp')->assertOk()->getContent();

        foreach (['otp-door', 'otp-kpis', 'otp-chart', 'otp-providers',
                  'otp-rows', 'otp-search', 'otp-export', 'otp-close',
                  'otp-add', 'otp-refresh', 'otp-help'] as $part) {
            $this->assertStringContainsString($part, $html, "ناقصٌ من الشاشة: {$part}");
        }
    }

    /**
     * @test
     *
     * **ويُوصل إليها من القائمة الجانبيّة — لا بعنوانٍ يُكتب يدويّاً.**
     *
     * (القاعدة ١٢: المسارُ المسجَّل ليس ظهوراً.)
     */
    public function the_screen_is_reachable_from_the_sidebar(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('/admin/amial/otp', $html,
            'لا رابطَ إلى مركز التحقّق من أيّ صفحةٍ يمرّ بها المدير');
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) الأزرار تعمل — ويُقاس أثرُها
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **زرُّ «إضافة» يُضيف — والرقمُ يصير مقبولاً فعلاً.**
     */
    public function adding_a_number_makes_it_accept_the_fixed_code(): void
    {
        $admin = $this->admin();
        $phone = '967739111222';

        $this->assertFalse($this->policy()->isDemo($phone), 'الرقم مقبولٌ قبل إضافته');

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/otp/numbers', ['phone' => '+' . $phone, 'label' => 'فحص'])
            ->assertOk();

        $this->assertDatabaseHas('otp_demo_numbers', ['phone' => $phone]);

        // **القياسُ الحاسم:** ليس «ردّ 200» بل «صار يُقبل».
        $this->assertTrue($this->policy()->isDemo($phone),
            'أُضيف الرقم ولم يصر مقبولاً — الزرّ يعمل ولا يفعل شيئاً');
    }

    /**
     * @test
     *
     * **وزرُّ «تعطيل» يمنع — ولا يمحو الأثر.**
     */
    public function disabling_a_number_stops_it_without_erasing_its_trace(): void
    {
        $admin = $this->admin();
        $phone = '967739111333';

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/otp/numbers', ['phone' => $phone])->assertOk();

        $id = DB::table('otp_demo_numbers')->where('phone', $phone)->value('id');

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/otp/numbers/{$id}/toggle")->assertOk();

        $this->assertFalse($this->policy()->isDemo($phone), 'عُطّل الرقم وما زال يُقبل');

        // الصفُّ باقٍ — أثرُ من فتح باباً لا يُمحى.
        $this->assertDatabaseHas('otp_demo_numbers', ['phone' => $phone, 'is_active' => 0]);
    }

    /**
     * @test
     *
     * **وزرُّ «إقفال الباب» يُقفل فعلاً — ويغلب البذرة.**
     *
     * وهذا أخطرُ اختبارٍ في الملفّ: `AMIAL_DEMO_PHONES` يبقى مقروءاً حين
     * يكون الجدولُ فارغاً. فلو لم يغلبه الإقفالُ لقال الزرُّ «أُقفل»
     * وبقي المتغيّرُ يفتح — **زرٌّ يكذب في بابِ تسجيل.**
     */
    public function closing_the_door_beats_the_environment_seed(): void
    {
        $admin = $this->admin();

        // بذرةٌ حيّة: الجدولُ فارغ ⇒ المتغيّرُ هو العامل.
        $seeded = '967777100001';
        $this->assertTrue($this->policy()->isDemo($seeded), 'البذرةُ لا تعمل — الاختبار يفحص فراغاً');

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/otp/close-door', ['confirm' => 'إقفال'])
            ->assertOk();

        $this->assertFalse($this->policy()->isDemo($seeded),
            'أُقفل الباب وما زال رقمُ البذرة يُقبل — الزرّ يكذب');

        $this->assertNull($this->policy()->demoNumbers() ? ($this->policy()->demoNumbers()[0] ?? null) : null,
            'بقيت أرقامٌ مقبولةٌ بعد الإقفال');
    }

    /**
     * @test
     *
     * **ولا يُقفل بضغطةٍ — يلزم تأكيدٌ مكتوب.**
     *
     * فمن ضغطه سهواً لا يعرف ما فعل حتّى يشتكي عميل.
     */
    public function closing_the_door_requires_a_written_confirmation(): void
    {
        $this->actingAs($this->admin(), 'user')
            ->postJson('/admin/amial/otp/close-door', ['confirm' => 'نعم'])
            ->assertStatus(422);

        $this->assertTrue($this->policy()->isDemo('967777100001'),
            'أُقفل الباب بلا تأكيد');
    }

    /**
     * @test
     *
     * **والرقمُ المكرَّر يُرفض برسالةٍ — لا بصفٍّ ثانٍ.**
     */
    public function a_duplicate_number_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/otp/numbers', ['phone' => '967739111444'])->assertOk();

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/otp/numbers', ['phone' => '+967739111444'])
            ->assertStatus(422);

        $this->assertSame(1, DB::table('otp_demo_numbers')->where('phone', '967739111444')->count());
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) القراءة والتصدير والفلاتر
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **المؤشّرات تُحسب — ولا تُكتب ثابتة.**
     */
    public function the_stats_are_computed_from_real_state(): void
    {
        $m = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/otp/stats')->assertOk()->json('meta');

        $this->assertIsBool($m['door_open']);
        $this->assertSame(10, count($m['providers']), 'قائمةُ المزوّدين ليست من السجلّ');
        $this->assertCount(14, $m['trend'], 'الرسمُ ليس أربعةَ عشرَ يوماً');

        // «لا محاولات» ليست «٠٪» — الغيابُ يُقال. (القاعدة السابعة)
        //
        // ══════════════════════════════════════════════════════════════
        // **AMIAL-OTP-TRUTH-001 — والاسمُ نفسُه كان يكذب.**
        //
        // كان المفتاحُ `success_rate` و«النسبة» تُعرض «نسبةَ النجاح».
        // وجدولُ `phone_verifications` **لا يُثبت نجاحَ تحقّق**: يُثبت أنّ
        // محاولةً وقعت وأنّها لم تُحظَر. فرقمٌ يقول «٩٨٪ نجحت» بينما
        // ٩٨٪ **لم تُحظَر فقط** — وقد يكون نصفُهم لم يُدخل الرمزَ أصلاً.
        //
        // فصار `non_blocked_rate` باسمه الصادق. (`amial-financial-truth`:
        // الرقمُ يُسمّى بما يقيسه لا بما نتمنّاه.)
        $this->assertArrayNotHasKey('success_rate', $m['today'],
            'عاد الاسمُ المضلِّل — والجدولُ لا يُثبت نجاحَ تحقّقٍ إطلاقاً');

        $this->assertNull($m['today']['non_blocked_rate']);
    }

    /**
     * @test
     *
     * **والبحثُ يُصفّي فعلاً.**
     */
    public function search_actually_filters(): void
    {
        $admin = $this->admin();

        // **مفتاحُ مصفوفةٍ يشبه رقماً يصير عدداً صحيحاً في PHP** — فيسقط
        // التحقّق بـ«must be a string». فتُستعمل أزواجٌ صريحة.
        foreach ([['967739222001', 'ألف'], ['967739222002', 'باء']] as [$p, $l]) {
            $this->actingAs($admin, 'user')
                ->postJson('/admin/amial/otp/numbers', ['phone' => $p, 'label' => $l])->assertOk();
        }

        $m = $this->actingAs($admin, 'user')
            ->getJson('/admin/amial/otp/numbers?search=222001')->assertOk()->json('meta');

        $this->assertCount(1, $m['rows'], 'البحثُ لا يُصفّي');
    }

    /**
     * @test
     *
     * **والتصديرُ يُخرج ملفّاً فيه الصفوف.**
     */
    public function the_export_returns_a_csv_with_the_rows(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'user')
            ->postJson('/admin/amial/otp/numbers', ['phone' => '967739333001'])->assertOk();

        $r = $this->actingAs($admin, 'user')->get('/admin/amial/otp/export')->assertOk();

        $this->assertStringContainsString('text/csv', (string) $r->headers->get('Content-Type'));
        $this->assertStringContainsString('967739333001', $r->getContent());
    }

    // ══════════════════════════════════════════════════════════════
    // ٤) الصلاحيّة
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **وموظّفُ الدعم لا يفتح الباب ولا يُقفله.**
     *
     * ويُفحص من **كلا المدخلين**: الصفحة ونقطةُ الفعل. فحراسةُ الصفحة
     * وحدها تترك الفعلَ مفتوحاً لمن يعرف عنوانه — وهو أوّل ما يُجرَّب.
     */
    public function support_staff_can_neither_see_nor_change_the_door(): void
    {
        $sup = $this->admin(PlatformRoleService::SUPPORT, '967770006002');

        $this->actingAs($sup, 'user')->get('/admin/amial/otp')->assertForbidden();
        $this->actingAs($sup, 'user')->getJson('/admin/amial/otp/stats')->assertForbidden();

        $this->actingAs($sup, 'user')
            ->postJson('/admin/amial/otp/numbers', ['phone' => '967739444001'])->assertForbidden();

        $this->actingAs($sup, 'user')
            ->postJson('/admin/amial/otp/close-door', ['confirm' => 'إقفال'])->assertForbidden();

        $this->assertDatabaseCount('otp_demo_numbers', 0);
    }
}
