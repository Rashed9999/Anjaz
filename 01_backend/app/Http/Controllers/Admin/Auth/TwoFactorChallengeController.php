<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AMIAL-2FA-DOOR-001 — **بوّابةُ الرمز الثاني بعد كلمة المرور.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `TwoFactorAuthService` مكتملٌ منذ v1.8 — سرٌّ ورمزُ QR ورموزُ استرداد
 * وتأكيدٌ وتحقّق. **ولا شاشةَ تُفعّله ولا سطرَ يفحصه عند الدخول.**
 * فحمايةٌ تُخزَّن ولا تُقرأ أسوأ من غيابها: تُطمئن صاحبَها إلى بابٍ مفتوح.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والجلسةُ لا تُفتح قبل الرمز.**
 *
 * الطريقةُ الشائعة أن يُسجَّل الدخول ثمّ يُطلب الرمز — وهي خطأ: الجلسةُ
 * قائمةٌ فعلاً، ومن يعرف عنوان أيّ صفحةٍ يتخطّى الشاشة. فيُخرَج المستعمل
 * بعد نجاح كلمة المرور، ويُحفَظ معرّفُه في الجلسة وحده — **بلا أيّ
 * صلاحيّة** — حتّى يمرّ الرمز.
 *
 * **وبحدِّ محاولات**: ستّةُ أرقامٍ مساحتُها مليون، ومئةُ محاولةٍ في
 * الدقيقة تكسرها في أسبوع. فخمسُ محاولاتٍ لكلّ حساب، ثمّ انتظار.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 300;

    public function __construct(private readonly TwoFactorAuthService $twoFactor) {}

    /** المعرّفُ المعلَّق في الجلسة — أو `null` إن لم تمرّ كلمة المرور. */
    private function pendingId(Request $request): ?int
    {
        $id = $request->session()->get('amial.2fa.pending_user');

        return $id ? (int) $id : null;
    }

    public function show(Request $request): View|RedirectResponse
    {
        // **من وصل هنا بلا مرورٍ بكلمة المرور يُعاد** — والشاشةُ ليست
        // بوّابةً مستقلّةً تُفتح بعنوانها.
        if (! $this->pendingId($request)) {
            return redirect()->route('admin.auth.login');
        }

        return view('admin-views.auth.two-factor');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string|min:6|max:20']);

        $id = $this->pendingId($request);

        if (! $id) {
            return redirect()->route('admin.auth.login')
                ->withErrors(['انتهت الجلسة — سجّل الدخول مرّةً أخرى']);
        }

        $key = '2fa:' . $id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'تجاوزت عدد المحاولات — حاول بعد ' . ceil($seconds / 60) . ' دقيقة',
            ]);
        }

        $admin = User::find($id);

        // قد يُعطّل الموظف بين إدخال كلمة المرور والرمز الثاني. لا تُنشئ
        // البوابة جلسةً متأخرةً لحساب أُغلق أثناء التحقق.
        if (! $admin || ! (bool) $admin->is_active) {
            $request->session()->forget(['amial.2fa.pending_user', 'amial.2fa.remember']);

            return redirect()->route('admin.auth.login')
                ->withErrors(['الحساب غير متاح لتسجيل الدخول.']);
        }

        // `verify` تقبل رمزَ التطبيق **أو** رمزَ استرداد، وتستهلك
        // الأخير — فهاتفٌ ضائعٌ لا يعني حساباً ضائعاً.
        if (! $this->twoFactor->verify($admin, (string) $request->input('code'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            // **المحاولةُ الفاشلة تُسجَّل**: خمسُ محاولاتٍ على حساب مديرٍ
            // إشارةٌ في ذاتها، وصمتُها يُضيّعها.
            Log::warning('Admin 2FA challenge failed', [
                'user_id' => $admin->id,
                'ip' => $request->ip(),
                'remaining' => RateLimiter::remaining($key, self::MAX_ATTEMPTS),
            ]);

            return back()->withErrors(['الرمز غير صحيح']);
        }

        RateLimiter::clear($key);

        $remember = (bool) $request->session()->pull('amial.2fa.remember', false);
        $request->session()->forget('amial.2fa.pending_user');

        auth('user')->login($admin, $remember);

        // **وتُجدَّد الجلسة بعد المصادقة** — وإلّا بقي المعرّفُ القديم
        // صالحاً لمن التقطه قبل الرمز (session fixation).
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    /** الرجوعُ إلى الدخول يُلغي التعليق — ولا يُترك معرّفٌ معلّقٌ في الجلسة. */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(['amial.2fa.pending_user', 'amial.2fa.remember']);

        return redirect()->route('admin.auth.login');
    }
}
