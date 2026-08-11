<?php

namespace Tests\Feature;

use App\Models\FuelStation;
use App\Models\MerchantProfile;
use App\Models\Pharmacy;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-BOOTSTRAP-001 — **حسابٌ يُنشأ «محطّة وقود» ولا محطّةَ له.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * أنشأ صاحبُ المشروع حساب محطّةٍ من لوحة الإدارة، ثمّ فتح التطبيق فوجد:
 *
 *     ⚠️ تعذَّر إتمام العملية
 *        لا توجد محطة مرتبطة بهذا الحساب
 *
 * واللوحةُ كتبت `business_type = fuel` في الملفّ **ولم تُنشئ صفَّ
 * `fuel_stations`**. فالملفُّ يقول «محطّة» والقطاعُ بلا سجلّ.
 *
 * **ولا خطأَ في أيّ سجلّ:** الإنشاءُ نجح، والدخولُ نجح، والشاشةُ فتحت ثمّ
 * رفضت. وهو نمطُ العطل الأكثر تكراراً في المشروع بصورةٍ جديدة: ليست
 * شاشةً بلا رابط، بل **حساباً بلا الشيء الذي أُنشئ من أجله**.
 *
 * وثلاثةُ متحكّماتٍ أخرى كانت تُنشئ عند الحاجة، وهذا وحدَه يرفض —
 * سلوكان لقطاعٍ واحد.
 */
class VerticalBootstrapGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $businessType): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id,
            'business_type' => $businessType,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        return $u->fresh();
    }

    private function boot(): VerticalBootstrapService
    {
        return app(VerticalBootstrapService::class);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① كلُّ نشاطٍ يحتاج سجلّاً يناله
    // ══════════════════════════════════════════════════════════════════

    public function test_a_fuel_account_gets_its_station(): void
    {
        $m = $this->merchant(A::BIZ_FUEL);

        $this->assertFalse(FuelStation::where('merchant_user_id', $m->id)->exists(),
            'التهيئةُ تفترض غياب المحطّة — وإلّا لم تقس شيئاً');

        $out = $this->boot()->ensureFor($m);

        $this->assertSame(A::BIZ_FUEL, $out['vertical']);
        $this->assertTrue($out['created']);
        $this->assertTrue(FuelStation::where('merchant_user_id', $m->id)->exists(),
            'حسابٌ أُنشئ محطّةً وبقي بلا محطّة');
    }

    public function test_a_pharmacy_account_gets_its_pharmacy(): void
    {
        $m = $this->merchant(A::BIZ_PHARMACY);

        $this->boot()->ensureFor($m);

        $this->assertTrue(Pharmacy::where('merchant_user_id', $m->id)->exists());
    }

    public function test_a_wholesale_account_gets_its_business(): void
    {
        $m = $this->merchant(A::BIZ_WHOLESALE);

        $this->boot()->ensureFor($m);

        $this->assertTrue(WholesaleBusiness::where('merchant_user_id', $m->id)->exists());
    }

    public function test_running_twice_does_not_create_a_second_record(): void
    {
        // **آمنُ التكرار** — يُنادى من ثلاثة أبوابٍ ومن أمرِ الشفاء.
        $m = $this->merchant(A::BIZ_FUEL);

        $this->boot()->ensureFor($m);
        $second = $this->boot()->ensureFor($m);

        $this->assertFalse($second['created'], 'النداءُ الثاني أنشأ سجلّاً ثانياً');
        $this->assertSame(1, FuelStation::where('merchant_user_id', $m->id)->count());
    }

    public function test_a_retail_account_needs_no_vertical_record(): void
    {
        // **والصمتُ هنا قرارٌ لا إهمال**: التجزئةُ تعمل على جداولَ عامّة.
        $m = $this->merchant(A::BIZ_RETAIL);

        $this->assertNull($this->boot()->ensureFor($m));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② لا يُعطِّل إنشاءَ الحساب
    // ══════════════════════════════════════════════════════════════════

    public function test_a_merchant_without_a_profile_is_not_an_error(): void
    {
        // حسابٌ بلا ملفٍّ بعد — يقع في منتصف الإنشاء. ولا يُرمى استثناء.
        $u = User::factory()->create(['type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT]);

        $this->assertNull($this->boot()->ensureFor($u));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ الشاشةُ التي أخرجت الرسالة — تُقاس من الباب
    // ══════════════════════════════════════════════════════════════════

    public function test_the_station_dashboard_no_longer_refuses_a_fresh_fuel_account(): void
    {
        // **هذا هو العطلُ كما رآه صاحبُ المشروع.** لا سجلَّ محطّةٍ، ويفتح
        // شاشة «لوحة المحطة».
        $m = $this->merchant(A::BIZ_FUEL);

        $r = $this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/merchant/fuel/tanks');

        $this->assertNotSame(422, $r->status(),
            'ما زالت الشاشةُ ترفض حساب محطّةٍ جديد');

        $this->assertTrue(FuelStation::where('merchant_user_id', $m->id)->exists(),
            'الشاشةُ فتحت ولم تُنشئ المحطّة — فالمرّةُ القادمة ترفض مرّةً أخرى');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ كلُّ بابٍ يُنشئ حساباً يمرّ بالتهيئة
    // ══════════════════════════════════════════════════════════════════

    /**
     * **ثلاثةُ أبوابٍ تُنشئ الحسابات** — اللوحةُ والتسجيلُ الذاتيّ وأمرُ
     * حسابات العرض. وإصلاحُ بابٍ واحدٍ يترك البقيّةَ على العطل نفسه.
     */
    public function test_every_account_creating_door_bootstraps_the_vertical(): void
    {
        $doors = [
            'app/Http/Controllers/Admin/AdminHubController.php' => 'لوحة الإدارة',
            'app/Http/Controllers/Api/V1/RegisterController.php' => 'التسجيل الذاتيّ',
        ];

        $missing = [];

        foreach ($doors as $file => $label) {
            $src = file_get_contents(base_path($file));

            // يكتب `business_type` ⇒ لا بدّ أن ينادي التهيئة.
            if (! str_contains($src, "'business_type'")) {
                continue;
            }

            if (! str_contains($src, 'VerticalBootstrapService')) {
                $missing[] = $label . ' (' . $file . ')';
            }
        }

        $this->assertSame([], $missing, "\n"
            . 'بابٌ يُنشئ حساب تاجرٍ بنشاطٍ ولا يبني سجلَّ قطاعه:' . "\n  "
            . implode("\n  ", $missing) . "\n\n"
            . 'فالحسابُ يُنشأ «محطّة» ويقرأ صاحبُه «لا توجد محطة مرتبطة '
            . 'بهذا الحساب» — والإنشاءُ نجح ولا خطأَ في أيّ سجلّ.');
    }

    /** وأمرُ الشفاء موجودٌ ومربوطٌ بالإقلاع — فما أُنشئ قبل الإصلاح يُشفى. */
    public function test_the_heal_command_exists_and_runs_on_boot(): void
    {
        $this->artisan('amial:heal-verticals')->assertExitCode(0);

        $entry = file_get_contents(base_path('docker/entrypoint.prod.sh'));

        $this->assertStringContainsString('amial:heal-verticals', $entry,
            'الأمرُ موجودٌ ولا يُشغَّل — والحساباتُ القائمة تبقى مكسورة. '
            . '(إصلاحُ المنبع يحمي ما بعده ولا يشفي ما قبله.)');
    }

    public function test_the_heal_command_actually_heals(): void
    {
        $m = $this->merchant(A::BIZ_FUEL);

        $this->assertFalse(FuelStation::where('merchant_user_id', $m->id)->exists());

        $this->artisan('amial:heal-verticals')->assertExitCode(0);

        $this->assertTrue(FuelStation::where('merchant_user_id', $m->id)->exists(),
            'أمرُ الشفاء لم يُنشئ المحطّة الناقصة');
    }
}
