<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-PROD-DEFECTS-001 — **الأبوابُ التي بلّغ عنها مركزُ الأعطال.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **المصدرُ ليس اجتهاداً — هو شاشةُ صاحب المشروع.** أرسل صورةَ «مركز
 * الأعطال» وفيها تسعةَ عشرَ عطلاً مفتوحاً، ولكلٍّ **موضعُه ومسارُه**:
 *
 *     ViewException            GET /admin/amial/hub/customers
 *     RelationNotFoundException GET /admin/amial/fuel/stations
 *     ViewException            GET /admin/amial/system/health
 *     ParseError               GET /admin/amial/customer
 *     ViewException            GET /admin/transaction/export
 *     BadMethodCallException   /amial/merchant/cashier/sales
 *
 * **وهذه أثمنُ ما يصل هذا المشروع**: أعطالٌ من الاستعمال الحقيقيّ، لا
 * من اختبارٍ كتبتُه أنا فأصبتُ فيه ما توقّعتُه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ لا إصلاحٌ وحدَه:** لأنّ ثلاثةً منها **أُصلحت ثمّ عادت**
 * (خطأُ Blade في مساحة العمل ظهر مرّتين بسطرين مختلفين: ٤٥ و٥٦). وصفحةٌ
 * تُصلَح بلا حارسٍ تعود بعد أسابيع بالطريقة نفسِها.
 *
 * **والقياسُ فتحُ الباب لا قراءةُ الشيفرة.** خطأُ نطاقٍ في قالبٍ لا
 * يمسكه `php -l` ولا `flutter analyze` — يمسكه من يفتح الصفحة.
 */
class ProductionReportedDoorsGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    /**
     * @test
     *
     * **كلُّ بابٍ بلّغ عنه مركزُ الأعطال يُفتح.**
     *
     * ولا يُقبل ٥٠٠ ولا ٤٠٣: الأوّلُ عطل، والثاني يعني أنّ الحارسَ يفحص
     * باباً مقفلاً فلا يبلغ الشيفرةَ التي تكسر. (القاعدة الرابعة.)
     */
    public function every_door_the_error_centre_reported_opens(): void
    {
        $admin = $this->admin();

        // ══════════════════════════════════════════════════════════════
        // **وتُصنع البياناتُ قبل الفتح.**
        //
        // أوّلُ صياغةٍ لهذا الحارس فتحت الأبوابَ على قاعدةٍ فارغةٍ
        // **فمرّت كلُّها** — ومنها `/admin/amial/fuel/stations` وهي
        // مكسورةٌ فعلاً: `FuelStation::merchant` غيرُ معلَنة. الحلقةُ
        // تمرّ على صفرِ صفوفٍ فلا تُقرأ العلاقةُ أصلاً.
        //
        // وهو الفخُّ المكتوب في `CLAUDE.md` بنصّه: «مسحٌ على قاعدةٍ
        // فارغةٍ يُطمئن ولا يفحص». **فالحارسُ يصنع الحالةَ بنفسِه.**
        // ══════════════════════════════════════════════════════════════
        $merchant = User::factory()->create([
            'type' => 3, 'zone_code' => 'SOUTH',
            'f_name' => 'صاحب', 'l_name' => 'المحطّة',
        ]);

        \App\Models\MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => \App\Support\Access\AccessConstants::BIZ_FUEL,
            'verification_status' => 'verified',
        ]);

        \App\Models\FuelStation::create([
            'merchant_user_id' => $merchant->id,
            'station_name' => 'محطّة الاختبار',
            'city' => 'عدن', 'is_active' => true, 'zone_code' => 'SOUTH',
        ]);

        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        \App\Models\EMoney::create([
            'user_id' => $customer->id, 'current_balance' => '1000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        $broken = [];

        foreach ([
            '/admin/amial/workspace' => 'مساحة العمل',
            '/admin/amial/hub/customers' => 'مركز العملاء (إنشاء)',
            '/admin/amial/fuel/stations' => 'رقابة محطات الوقود',
            '/admin/amial/system/health' => 'صحّة النظام والأعطال',
            '/admin/amial/customer' => 'مركز العملاء',
            '/admin/amial/fees' => 'الرسوم والأرباح',
            '/admin/amial/charity' => 'الجمعيات والتبرعات',
        ] as $path => $label) {
            $status = $this->actingAs($admin, 'user')->get($path)->getStatusCode();

            if ($status >= 500) {
                $broken[] = sprintf('  %-34s → %d  (%s)', $path, $status, $label);
            }
        }

        $this->assertSame([], $broken,
            "**أبوابٌ تردّ خطأَ خادمٍ — وقد بلّغ عنها مركزُ الأعطال فعلاً:**\n"
            . implode("\n", $broken) . "\n\n"
            . 'وصفحةٌ تردّ ٥٠٠ ليست عطلاً في نفسها فحسب: إن كانت تحمل '
            . 'روابطَ غيرِها سقطت معها كلُّها.');
    }
}
