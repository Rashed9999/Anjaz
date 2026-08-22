<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformLoginPinService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-AUTH-PIN-FORCE-003 — **وسمٌ يُعرَض ولا يمنع ليس حاجزاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل، وقد أُثبت بالتشغيل قبل كتابة سطرٍ من العلاج:**
 *
 *     must_change في القاعدة: 1
 *     حالةُ الردّ: 302  →  /admin
 *     أدخل؟ نعم — بلا مطالبةٍ بالتغيير
 *     واللوحةُ تُفتح؟ نعم
 *
 * عمودُ `must_change` يُكتب في ثلاثة مواضع، **ولا يقرؤه إلّا شارةٌ صفراءُ
 * في شاشة الأدوار**. ولا سطرَ في مسار الدخول يفحصه.
 *
 * **وأثرُه على أخطر حساب:** المشرفُ الجذر — أعلى صلاحيّةٍ في المنصّة،
 * ويملك `platform.money.move` وحدَه — يدخل بـ`1234` إلى الأبد. ورمزُ
 * الإقلاع مكتوبٌ في هجرةٍ في المستودع، فهو معروفٌ لكلّ من يقرأ الشيفرة.
 *
 * **والشارةُ الصفراءُ تجعله أسوأ**: تُوهم بأنّ الأمرَ مضبوطٌ فلا يبحث أحد.
 */
class ForcePlatformPinChangeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd!2026';

    private function admin(bool $mustChange, string $pin = '1234'): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'admin',
            'phone' => '+967711900777',
            'password' => Hash::make(self::PASSWORD),
        ]);

        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        app(PlatformLoginPinService::class)->issue(
            $u, $pin, null, 'bootstrap_admin_default',
            mustChange: $mustChange, deliveryStatus: 'not_required',
        );

        return $u->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الحاجزُ يمنع فعلاً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_bootstrap_pin_does_not_open_the_panel(): void
    {
        $admin = $this->admin(mustChange: true);

        $this->actingAs($admin, 'user')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.auth.pin.change'));
    }

    /** @test */
    public function it_blocks_every_page_not_only_the_dashboard(): void
    {
        // **والفحصُ في كلّ طلبٍ لا عند الدخول وحدَه.** فحصٌ عند الدخول
        // يترك جلسةً قائمةً تعمل بعد أن يُوسَم الرمزُ — ومن أُعيد تعيينُ
        // رمزِه لاشتباهٍ يبقى داخلاً حتّى يخرج بنفسه.
        $admin = $this->admin(mustChange: true);

        foreach (['admin.amial.audit.index', 'admin.amial.saher.index',
            'admin.amial.ops.roles.index'] as $route) {
            $this->actingAs($admin, 'user')->get(route($route))
                ->assertRedirect(route('admin.auth.pin.change'));
        }
    }

    /** @test */
    public function a_write_route_is_blocked_too_not_just_reads(): void
    {
        // **وحاجزُ قراءةٍ وحدَه يترك البابَ الخطِر مفتوحاً.**
        $admin = $this->admin(mustChange: true);

        $this->actingAs($admin, 'user')
            ->post(route('admin.amial.saher.scan'))
            ->assertRedirect(route('admin.auth.pin.change'));
    }

    /** @test */
    public function a_json_caller_is_told_why_not_silently_redirected(): void
    {
        // إعادةُ توجيهٍ صامتةٌ إلى HTML في وجه مُنادٍ يطلب JSON تُقرأ عطلاً.
        $admin = $this->admin(mustChange: true);

        $this->actingAs($admin, 'user')
            ->getJson(route('admin.amial.saher.index'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'PIN_CHANGE_REQUIRED');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② وحاجزٌ بلا مخرجٍ سجن
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_change_screen_itself_stays_reachable(): void
    {
        // **وإلّا صارت حلقةَ توجيهٍ مغلقة** — وهي عطلٌ وقع في هذا المشروع
        // من قبل وأقفل اللوحةَ كلَّها.
        $this->actingAs($this->admin(mustChange: true), 'user')
            ->get(route('admin.auth.pin.change'))->assertOk();
    }

    /** @test */
    public function logging_out_stays_possible(): void
    {
        // من نسي رمزَه الحاليَّ يجب أن يخرج ليطلب إعادةَ تعيينه.
        $this->actingAs($this->admin(mustChange: true), 'user')
            ->get(route('admin.auth.logout'))
            ->assertRedirect(route('admin.auth.login'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والتغييرُ يُغيّر فعلاً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function changing_the_pin_lifts_the_block(): void
    {
        $admin = $this->admin(mustChange: true);

        $this->actingAs($admin, 'user')->post(route('admin.auth.pin.update'), [
            'current_pin' => '1234',
            'new_pin' => '8351',
            'new_pin_confirmation' => '8351',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertSame(0, (int) DB::table('platform_login_pins')
            ->where('user_id', $admin->id)->value('must_change'));

        $this->actingAs($admin, 'user')->get(route('admin.dashboard'))->assertOk();
    }

    /** @test */
    public function the_same_pin_is_refused_as_a_change(): void
    {
        // **«غيّرتُه» التي لا تُغيّر شيئاً تُطفئ الوسمَ وتُبقي الخطر**،
        // وهي أسوأ من عدم التغيير لأنّها تُخفيه.
        $admin = $this->admin(mustChange: true);

        $this->actingAs($admin, 'user')->post(route('admin.auth.pin.update'), [
            'current_pin' => '1234',
            'new_pin' => '1234',
            'new_pin_confirmation' => '1234',
        ])->assertSessionHasErrors('new_pin');

        $this->assertSame(1, (int) DB::table('platform_login_pins')
            ->where('user_id', $admin->id)->value('must_change'),
            'الوسمُ أُطفئ برمزٍ لم يتغيّر');
    }

    /** @test */
    public function a_stolen_session_cannot_change_the_pin_without_the_current_one(): void
    {
        // **وإلّا صارت الشاشةُ بابَ استيلاء**: من جلس على جهازٍ مفتوحٍ
        // يُبدّل الرمزَ ويُقفل صاحبَه خارجاً.
        $admin = $this->admin(mustChange: true);

        $this->actingAs($admin, 'user')->post(route('admin.auth.pin.update'), [
            'current_pin' => '9999',
            'new_pin' => '8351',
            'new_pin_confirmation' => '8351',
        ])->assertSessionHasErrors('current_pin');

        $this->assertTrue(app(PlatformLoginPinService::class)
            ->verify($admin, '1234')['ok'], 'الرمزُ تغيّر بلا إثبات ملكيّته');
    }

    /** @test */
    public function a_mismatched_confirmation_is_refused(): void
    {
        $this->actingAs($this->admin(mustChange: true), 'user')
            ->post(route('admin.auth.pin.update'), [
                'current_pin' => '1234',
                'new_pin' => '8351',
                'new_pin_confirmation' => '8352',
            ])->assertSessionHasErrors('new_pin');
    }

    /** @test */
    public function the_change_is_written_to_the_audit_log(): void
    {
        // **ومن غيّر رمزَ دخوله يُقيَّد** — وهو حدثُ أمانٍ يُراجَع.
        $admin = $this->admin(mustChange: true);

        $this->actingAs($admin, 'user')->post(route('admin.auth.pin.update'), [
            'current_pin' => '1234', 'new_pin' => '8351',
            'new_pin_confirmation' => '8351',
        ]);

        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'PLATFORM_LOGIN_PIN_CHANGED',
            'actor_user_id' => $admin->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ ولا يشلّ من لا شأنَ له
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_admin_with_a_changed_pin_works_normally(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرةٍ تُكتشَف بتدقيق.**
        $this->actingAs($this->admin(mustChange: false, pin: '7412'), 'user')
            ->get(route('admin.dashboard'))->assertOk();
    }

    /** @test */
    public function an_admin_with_no_pin_row_is_not_locked_out(): void
    {
        // **صفٌّ غائبٌ ليس «يجب التغيير»** — والحاجزُ الذي يُقفل على
        // الغياب يُقفل اللوحةَ على كلّ من سبق الميزة. (القاعدة السابعة.)
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        $this->actingAs($u->refresh(), 'user')
            ->get(route('admin.dashboard'))->assertOk();
    }

    /** @test */
    public function the_bootstrap_default_is_actually_flagged(): void
    {
        // **وشرطُ صحّة هذا كلِّه**: لو وُلد رمزُ الإقلاع بلا وسمٍ لَما
        // منع الحاجزُ شيئاً — ولَخرج هذا الملفُّ أخضرَ على بابٍ مفتوح.
        $admin = $this->admin(mustChange: true);

        $this->assertSame(1, (int) DB::table('platform_login_pins')
            ->where('user_id', $admin->id)->value('must_change'));

        $this->assertTrue(app(PlatformLoginPinService::class)
            ->verify($admin, '1234')['ok'],
            'رمزُ الإقلاع ليس 1234 — يُراجَع هذا المقياس');
    }
}
