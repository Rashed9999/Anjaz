<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CSRF-001 — الكتابة بـ POST لا بـ GET في لوحة الأدمن.
 *
 * حماية Laravel من CSRF تسري على POST و PUT و DELETE، ولا تسري على GET.
 * فمسار GET يكتب في القاعدة يُنفَّذ بمجرّد أن يطلبه متصفّح المدير — ووسم
 * صورة في أي صفحة يزورها يكفي:
 *
 *     <img src="https://amialpay.com/admin/banner/delete/7">
 *
 * بلا نقرة ولا رمز؛ كعكة الجلسة وحدها تُتمّ العمل. ورابط الحذف بالذات خطر
 * مضاعف: الزواحف وجالبات الصفحات المسبقة تتبع الروابط، فتحذف بلا نيّة أحد.
 *
 * الحارس (RouteSurfaceGuardTest) يمنع عودة الصنف. وهذا الملفّ يُثبت أن
 * التحويل وقع فعلاً وأن الصفحات ما زالت تعمل بعده — فمسار مُحوَّل وزرّ لم
 * يُحدَّث معه يعني ميزةً صامتة لا أماناً.
 */
class AdminCsrfHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // AMIAL-ADMIN-DOORS-001 — **نوعُ الحساب لم يعد يفتح الصفحة.**
        //
        // كان `ADMIN_TYPE` وحدَه يكفي، فكان موظّفُ دعمٍ يفتح هذه الشاشةَ
        // ويعدّل فيها. فصار البابُ يسأل «هل يحقّ له؟» لا «أهو موظّف؟» —
        // ويُمنح هذا الاختبارُ الدورَ الذي يفعل ذلك حقيقةً في الإنتاج.
        $u = User::factory()->create(['type' => ADMIN_TYPE]);

        app(\App\Services\PlatformRoleService::class)
            ->assign($u, \App\Services\PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    /** المسارات التي كانت GET تكتب — لم تعد تقبل GET. */
    public static function convertedRoutes(): array
    {
        return [
            'تفعيل لغة' => ['admin/business-settings/language/update-status'],
            'لغة افتراضية' => ['admin/business-settings/language/update-default-status'],
            'حالة إشعار' => ['admin/notification/status/1/1'],
            'حالة لافتة' => ['admin/banner/status/1'],
            'حذف لافتة' => ['admin/banner/delete/1'],
            'حالة سؤال' => ['admin/faq/status/1/1'],
        ];
    }

    /**
     * @dataProvider convertedRoutes
     */
    public function test_the_route_no_longer_answers_a_plain_get(string $uri): void
    {
        // 405 هو الجواب الصحيح: المسار قائم والطريقة مرفوضة. ولو أعاد 200
        // أو 302 لكان لا يزال يُنفَّذ بطلب متصفّح عاديّ.
        $this->actingAs($this->admin(), 'user')
            ->get('/' . $uri)
            ->assertStatus(405);
    }

    /** الطريقة الجديدة تعمل — التحويل أمّن ولم يُعطّل. */
    public function test_toggling_a_banner_still_works_via_post(): void
    {
        // Banner بلا $fillable، والافتراضي في Eloquent يمنع الإسناد الجَماعي.
        $banner = new Banner();
        $banner->forceFill(['title' => 'اختبار', 'image' => 'x.png', 'status' => 1])->save();

        $this->actingAs($this->admin(), 'user')
            ->post('/admin/banner/status/' . $banner->id)
            ->assertRedirect();

        $this->assertSame(0, (int) $banner->fresh()->status);
    }

    public function test_deleting_a_banner_still_works_via_delete(): void
    {
        $banner = new Banner();
        $banner->forceFill(['title' => 'للحذف', 'image' => 'x.png', 'status' => 1])->save();

        $this->actingAs($this->admin(), 'user')
            ->delete('/admin/banner/delete/' . $banner->id)
            ->assertRedirect();

        $this->assertNull(Banner::find($banner->id));
    }

    /**
     * لا زرّ يشير إلى مسار لم يعد يقبل طريقته.
     *
     * تحويل المسار وحده لا يكفي: رابط <a href> باقٍ على مسار صار POST يعطي
     * المستخدم زرّاً يبدو سليماً ويرمي 405 عند الضغط. هذا يفحص القوالب
     * التي حُوّلت أزرارها.
     */
    public function test_the_pages_that_use_them_still_render(): void
    {
        $admin = $this->admin();

        foreach ([
            'admin/banner/add-new' => 'اللافتات',
            'admin/notification/add-new' => 'الإشعارات',
            'admin/faq/index' => 'الأسئلة الشائعة',
            'admin/business-settings/language' => 'اللغات',
        ] as $uri => $label) {
            $this->actingAs($admin, 'user')->get('/' . $uri)
                ->assertStatus(200, "صفحة {$label} لا تُفتح بعد التحويل");
        }
    }

    /** لم يبقَ في هذه الصفحات رابط GET يشير إلى مسار كاتب. */
    public function test_no_anchor_still_points_at_a_converted_route(): void
    {
        $offenders = [];

        foreach ([
            'admin-views/banner/index.blade.php' => ['admin.banner.status', 'admin.banner.delete'],
            'admin-views/notification/index.blade.php' => ['admin.notification.status'],
            'admin-views/business-settings/language/index.blade.php' => [
                'admin.business-settings.language.update-default-status',
            ],
        ] as $view => $names) {
            $html = file_get_contents(resource_path('views/' . $view));

            foreach ($names as $name) {
                // <a href="{{ route('...') }}"> يعني رابطاً يُطلب بـ GET.
                if (preg_match('/<a\s[^>]*href="\{\{\s*route\(\s*\'' . preg_quote($name, '/') . '\'/', $html)) {
                    $offenders[] = "{$view} → {$name}";
                }
            }
        }

        $this->assertSame([], $offenders,
            "روابط <a> ما زالت تشير إلى مسارات صارت POST/DELETE:\n  " . implode("\n  ", $offenders));
    }
}
