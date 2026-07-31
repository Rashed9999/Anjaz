<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceId;
use App\Models\User;
use App\Models\UserLogHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

/**
 * AMIAL-DEVICE-TRUST-001 — حظر الجهاز يمنع فعلاً، لا يُسجَّل فقط.
 *
 * **الحالة التي بُني لها هذا كلّه:** يتّصل عميل ويقول «سُرق هاتفي». وقبلها
 * لم يكن أمام الدعم إلّا تجميد الحساب — فيُمنع العميل من ماله في اللحظة
 * التي يحتاجه فيها، والسارق يُمنع بالتبعية. عقوبةٌ على الضحيّة.
 *
 * **والفخّ الذي يجب أن يُسدّ صراحةً:** أن يُضاف عمودُ `is_blocked` ويُكتب من
 * لوحة الدعم ولا يقرأه أحد. فتعرض الشاشة «محظور» ويستمرّ الجهاز يعمل. وهذا
 * أسوأ من غياب الميزة: الدعم يطمئنّ والسرقة تستمرّ.
 *
 * ولذلك يُختبر **الوسيط نفسه** لا الشاشة: هل يمنع؟
 */
class DeviceTrustTest extends TestCase
{
    use RefreshDatabase;

    private function deviceFor(User $u, string $deviceId, array $extra = []): UserLogHistory
    {
        return UserLogHistory::create(array_merge([
            'user_id' => $u->id,
            'device_id' => $deviceId,
            'ip_address' => '10.0.0.1',
            'device_model' => 'Test Phone',
            'os' => 'Android 14',
            'is_active' => 1,
        ], $extra));
    }

    /** يمرّر طلباً على الوسيط ويُرجع true إن سُمح. */
    private function passesGate(User $u, string $deviceId): bool
    {
        $request = Request::create('/api/v1/amial/me', 'GET');
        $request->headers->set('device-id', $deviceId);
        // الوسيط يمرّر ::1 بلا فحص (بيئة محلية)، فيُزوَّر IP حقيقيّ وإلّا
        // مرّ كلُّ اختبارٍ هنا على استثناء البيئة لا على المنطق.
        $request->server->set('REMOTE_ADDR', '196.1.2.3');
        $request->setUserResolver(fn () => $u);

        // `abort(response()->json(...))` يرمي HttpResponseException لا
        // HttpException — والتقاطُ الصنف الخطأ يجعل الاختبار يسقط بخطأٍ بدل
        // أن يقرأ «مُنع»، فيبدو العطل في الشيفرة وهو في الفحص.
        try {
            (new CheckDeviceId())->handle($request, fn ($r) => response('ok'));

            return true;
        } catch (HttpResponseException) {
            return false;
        }
    }

    // ── الأساس: الاختبار يقيس المنطق لا استثناء البيئة ─────────────────

    public function test_an_active_device_passes_the_gate(): void
    {
        // لو مرّ كلُّ شيء لكان ما بعده بلا معنى: يجب أن يُسمح للسليم أوّلاً.
        $u = User::factory()->create();
        $this->deviceFor($u, 'DEV-OK');

        $this->assertTrue($this->passesGate($u, 'DEV-OK'),
            'مُنع جهازٌ نشط سليم — البوّابة تمنع الجميع فلا تحرس شيئاً');
    }

    public function test_an_unknown_device_is_refused(): void
    {
        $u = User::factory()->create();
        $this->deviceFor($u, 'DEV-OK');

        $this->assertFalse($this->passesGate($u, 'DEV-STRANGER'));
    }

    // ── الحظر: جوهر الميزة ─────────────────────────────────────────────

    public function test_a_blocked_device_is_refused_even_though_it_is_active(): void
    {
        // هذه هي الحالة التي يسقط فيها كل تطبيقٍ ناقص: الجهاز محظور **وهو
        // النشط**. فلو فُحص النشاط أوّلاً لمرّ، ولبقي `is_blocked` عموداً
        // يُكتب ولا يُقرأ.
        $u = User::factory()->create();
        $this->deviceFor($u, 'DEV-STOLEN', [
            'is_active' => 1,
            'is_blocked' => true,
            'block_reason' => 'بلاغ سرقة',
        ]);

        $this->assertFalse($this->passesGate($u, 'DEV-STOLEN'),
            'مرّ جهازٌ محظور. العمود يُكتب ولا يُقرأ — والدعم يطمئنّ '
            . 'والسرقة تستمرّ.');
    }

    public function test_blocking_one_device_does_not_lock_the_customer_out(): void
    {
        // الغاية كلّها: أن يُمنع الجهاز وحده لا الحساب. فلو مُنع العميل من
        // جهازه السليم لعدنا إلى تجميد الحساب بصورة أخرى.
        $u = User::factory()->create();
        $this->deviceFor($u, 'DEV-STOLEN', ['is_active' => 0, 'is_blocked' => true]);
        $this->deviceFor($u, 'DEV-MINE', ['is_active' => 1]);

        $this->assertFalse($this->passesGate($u, 'DEV-STOLEN'));
        $this->assertTrue($this->passesGate($u, 'DEV-MINE'),
            'مُنع العميل من جهازه السليم — هذا تجميد حسابٍ بثوبٍ آخر');
    }

    // ── الثغرة الخفيّة: إعادة التنشيط بالدخول ──────────────────────────

    public function test_logging_in_does_not_revive_a_blocked_device(): void
    {
        // بلا هذا الشرط يكفي تسجيل دخولٍ ليعود is_active = 1 على جهازٍ حظره
        // الدعم. والبوّابة ستمنعه، لكن حالة الصفّ تصير كاذبة: تقول «نشط» عن
        // جهازٍ ممنوع، فيقرأ الدعمُ شاشةً تناقض ما فعله بيده.
        $u = User::factory()->create();
        $device = $this->deviceFor($u, 'DEV-STOLEN', [
            'is_active' => 0, 'is_blocked' => true, 'block_reason' => 'سرقة',
        ]);

        $svc = app(\App\Services\UnifiedAuthService::class);
        $method = new \ReflectionMethod($svc, 'registerDevice');
        $method->setAccessible(true);

        $request = Request::create('/login', 'POST');
        $request->headers->set('device-id', 'DEV-STOLEN');
        $method->invoke($svc, $u, $request);

        $device->refresh();
        $this->assertFalse((bool) $device->is_active,
            'أُعيد تنشيط جهازٍ محظور بمجرّد تسجيل الدخول');
        $this->assertTrue((bool) $device->is_blocked,
            'رُفع الحظر بالدخول — وهو ما لا يملكه المستخدم');
    }

    // ── الحالة التي يفترضها الوسيط ولا يفحصها أحد ──────────────────────

    public function test_last_seen_is_recorded_when_the_gate_passes(): void
    {
        // سؤال الدعم الأوّل عن جهازٍ مشبوه: «متى استُعمل آخر مرّة؟» وتاريخ
        // الدخول لا يجيبه — المستخدم يدخل مرّة ويعمل ساعات.
        $u = User::factory()->create();
        $d = $this->deviceFor($u, 'DEV-OK', ['last_seen_at' => null]);

        $this->passesGate($u, 'DEV-OK');

        $d->refresh();
        $this->assertNotNull($d->last_seen_at,
            'لم يُسجَّل آخر استعمال — فالعمود موجود ولا يُملأ');
    }
}
