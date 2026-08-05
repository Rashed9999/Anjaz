<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentStaff;
use App\Services\AgentStaffService;
use App\Support\PortalHost;
use App\Models\User;
use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;

/**
 * AMIAL-UNIFIED-WEB-LOGIN-001 — بابٌ واحدٌ يفتح على ثلاثة مسارات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما يفعله ولا يفعله.**
 *
 * يفعل: يقبل معرّفاً واحداً وكلمة سرّ، ويعرف صاحبَه، ويفتح له مساره.
 * لا يفعل: **لا يوحّد الحارسين.** فلكلٍّ حارسُه كما كان:
 *
 *     موظّف الصرافة / الشركة  →  حارس `agent_staff`
 *     إدارة المنصّة            →  حارس `user`
 *
 * ولا تُفتح جلسةٌ على الاثنين معاً أبداً. وهذا ليس تشدّداً: كان صاحب شركة
 * الصرافة يدخل على حارس `user` — وهو نفسه حارس لوحة الإدارة — فمن دخل
 * بوّابة الوكيل ثمّ فتح لوحة الإدارة وقع في حلقةٍ لا تنتهي:
 *
 *     /admin → «لستَ مديراً» → /admin/auth/login → «أنت داخلٌ» → /admin
 *
 * وكلّ ردٍّ فيها 302 سليم، فلا يظهر في أيّ سجلّ. فيُخرَج الحارسُ الآخر
 * صراحةً قبل كلّ دخول.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والكابتشا تدرُّجيّة لا دائمة.**
 *
 * لوحة الإدارة تطلب كابتشا في كلّ دخول. والصرّاف يدخل شبّاكه مرّاتٍ في
 * اليوم — فكابتشا دائمةٌ هنا ضريبةٌ على أكثر المستعملين استعمالاً، وتُدفع
 * بلا مقابل: الكابتشا تحمي من الآلة، والآلةُ لا تظهر إلّا بعد محاولاتٍ
 * فاشلة. فتُطلب بعد ثلاثِ محاولاتٍ فاشلة من العنوان نفسه، لا قبلها.
 *
 * وفوقها خنقٌ صريح: عشرُ محاولاتٍ في الدقيقة لكلّ عنوانٍ ومعرّف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والبابان القديمان يبقيان.** `/agent/login` و`/admin/auth/login`
 * يعملان كما كانا: التطبيق وروابطُ الناس ومجموعةُ الاختبارات كلُّها تعتمد
 * عليهما، وإغلاقُهما اليوم يكسر ما يعمل مقابل لا شيء.
 */
class UnifiedLoginController extends Controller
{
    /** بعد كم محاولةٍ فاشلة تُطلب الكابتشا. */
    private const CAPTCHA_AFTER = 3;

    /** أقصى محاولاتٍ في الدقيقة. */
    private const MAX_ATTEMPTS = 10;

    public function show(Request $request)
    {
        // من كان داخلاً فعلاً لا يُعرض له نموذجٌ يملؤه بلا داعٍ.
        if (Auth::guard('agent_staff')->check()) {
            return redirect()->route('agent.dashboard');
        }

        if (Auth::guard('user')->check() && (int) Auth::guard('user')->user()->type === ADMIN_TYPE) {
            return redirect()->route('admin.dashboard');
        }

        return view('site.login', [
            'needsCaptcha' => $this->needsCaptcha($request),
            'adminHost'    => PortalHost::admin(),
            'agentHost'    => PortalHost::agent(),
        ]);
    }

    public function submit(Request $request)
    {
        $request->validate([
            'username' => 'required|string|max:120',
            'password' => 'required|string|max:200',
        ], [], [
            'username' => 'المعرّف',
            'password' => 'كلمة السرّ',
        ]);

        $identifier = trim((string) $request->input('username'));
        $password   = (string) $request->input('password');

        // ── الخنق: قبل أيّ عملٍ، لا بعده ────────────────────────────
        $throttleKey = $this->throttleKey($request, $identifier);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withInput($request->only('username'))->withErrors([
                'username' => "محاولاتٌ كثيرة. أعِد المحاولة بعد {$seconds} ثانية.",
            ]);
        }

        // ── الكابتشا: تدرُّجيّة ──────────────────────────────────────
        if ($this->needsCaptcha($request)) {
            $given    = mb_strtolower(trim((string) $request->input('captcha')));
            $expected = mb_strtolower((string) Session::get('unified_captcha_code'));

            if ($given === '' || $expected === '' || $given !== $expected) {
                Session::forget('unified_captcha_code');
                RateLimiter::hit($throttleKey, 60);

                return back()->withInput($request->only('username'))
                    ->withErrors(['captcha' => 'رمز الصورة غير صحيح']);
            }

            Session::forget('unified_captcha_code');
        }

        // ── ١) موظّف شركة الصرافة برمزه ─────────────────────────────
        $staff = AgentStaff::whereRaw('UPPER(username) = ?', [mb_strtoupper($identifier)])->first();

        if ($staff) {
            if (! Hash::check($password, (string) $staff->password)) {
                return $this->failed($request, $identifier);
            }

            if (! $staff->is_active) {
                return $this->reject($request, 'الحساب معطَّل — راجع إدارة شركتك');
            }

            $company = User::find($staff->agent_user_id);

            if (! $company || (int) $company->is_active !== 1) {
                return $this->reject($request, 'حساب الشركة موقوف — راجع إدارة أميال');
            }

            return $this->enterAgent($request, $staff, $throttleKey);
        }

        // ── ٢) صاحب شركة الصرافة بهاتفه، أو الأدمن بهاتفه ───────────
        //
        // الحقلان يقبلان الهاتف، فيُقرأ نوعُ الحساب لا نيّةُ الكاتب. وهذا
        // هو المقصود بـ«حسب الصلاحيّة»: الوجهة تُحدَّد من الحساب نفسه.
        $phone = preg_replace('/\D+/', '', $identifier);
        $user  = $phone !== '' ? User::where('phone', $phone)->first() : null;

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            return $this->failed($request, $identifier);
        }

        if ((int) ($user->is_temp_blocked ?? 0) === 1) {
            return $this->reject($request, 'الحساب موقوف — راجع إدارة أميال');
        }

        if ((int) $user->type === ADMIN_TYPE) {
            return $this->enterAdmin($request, $user, $throttleKey);
        }

        if ((int) $user->type === AGENT_TYPE) {
            if ((int) $user->is_active !== 1) {
                return $this->reject($request, 'حساب الشركة موقوف — راجع إدارة أميال');
            }

            return $this->enterAgentCompany($request, $user, $throttleKey);
        }

        // ── ٣) عميلٌ أو تاجر: لا لوحةَ له على المتصفّح ───────────────
        //
        // ولا يُقال له «بيانات خاطئة» — بياناتُه صحيحة. الصمتُ هنا يجعله
        // يُعيد كتابة كلمةٍ صحيحةٍ عشر مرّات ثمّ يظنّ حسابه ضاع.
        return $this->reject($request,
            'حسابك حساب عميل أو تاجر — وخدماتُه كلّها في تطبيق أميال باي، لا على المتصفّح.');
    }

    /** صورة الكابتشا. تُولَّد عند الطلب فقط. */
    public function captcha()
    {
        $phrase  = new PhraseBuilder;
        $code    = $phrase->build(4);
        $builder = new CaptchaBuilder($code, $phrase);

        $builder->setBackgroundColor(238, 242, 251);
        $builder->setMaxAngle(22);
        $builder->setMaxBehindLines(0);
        $builder->setMaxFrontLines(0);
        $builder->build(130, 46);

        Session::put('unified_captcha_code', $builder->getPhrase());

        ob_start();
        $builder->output();
        $image = ob_get_clean();

        return Response::make($image, 200, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // الدخول — ولكلّ مسارٍ حارسُه، ويُخرَج الآخر صراحةً
    // ══════════════════════════════════════════════════════════════

    private function enterAgent(Request $request, AgentStaff $staff, string $key)
    {
        Auth::guard('user')->logout();
        Auth::guard('agent_staff')->login($staff, $request->boolean('remember'));
        $request->session()->regenerate();
        RateLimiter::clear($key);

        $staff->forceFill(['last_login_at' => now()])->save();

        return $this->toPortal($request, route('agent.dashboard'), PortalHost::agent());
    }

    /**
     * ينقل إلى اللوحة على **مضيفها** حين يكون الفصل مفعّلاً.
     *
     * وبلا هذا يدخل الصرّاف من `amialpay.com/login` فيُنقل إلى
     * `amialpay.com/agent` — ثمّ يردّه وسيطُ المضيف إلى
     * `agent.amialpay.com/agent`، **وقد ضاعت جلستُه في الطريق**: الكوكي
     * لا تُشارَك بين المضيفَين عمداً. فيرى شاشة الدخول من جديدٍ ولا يفهم
     * لماذا. فيُرسَل من البداية إلى حيث ستكون جلسته.
     */
    private function toPortal(Request $request, string $url, ?string $host)
    {
        if ($host === null || mb_strtolower($request->getHost()) === $host) {
            return redirect($url);
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return redirect()->away($request->getScheme() . '://' . $host . $path);
    }

    private function enterAgentCompany(Request $request, User $user, string $key)
    {
        // **لا جلسةَ على حارس `user` من هنا.** انظر شرح الصنف أعلاه.
        //
        // ويُستعمل ما تستعمله البوّابة نفسها (`ensurePortalAccount`) لا
        // إنشاءٌ موازٍ: بابان يُنشئان حساب صاحب الشركة بطريقتين يُنتجان
        // حسابين متنافسين، ويظهر ذلك بعد شهرٍ في تقريرٍ لا يُطابق.
        $hq = app(AgentStaffService::class)->ensurePortalAccount($user);

        return $this->enterAgent($request, $hq, $key);
    }

    private function enterAdmin(Request $request, User $user, string $key)
    {
        Auth::guard('agent_staff')->logout();
        Auth::guard('user')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        RateLimiter::clear($key);

        return $this->toPortal($request, route('admin.dashboard'), PortalHost::admin());
    }

    // ══════════════════════════════════════════════════════════════

    /**
     * فشلُ اعتماد: رسالةٌ واحدةٌ للجميع.
     *
     * ولا يُقال «هذا المعرّف غير موجود» — فذلك يُمكّن من عدّ الحسابات
     * القائمة بلا كلمة سرّ.
     */
    private function failed(Request $request, string $identifier)
    {
        RateLimiter::hit($this->throttleKey($request, $identifier), 60);
        $this->countFailure($request);

        return back()->withInput($request->only('username'))
            ->withErrors(['username' => 'بيانات الدخول غير صحيحة']);
    }

    /** رفضٌ لسببٍ معلوم (حسابٌ موقوف مثلاً) — يُقال صراحةً، ولا يُعدّ فشلَ اعتماد. */
    private function reject(Request $request, string $message)
    {
        return back()->withInput($request->only('username'))
            ->withErrors(['username' => $message]);
    }

    private function throttleKey(Request $request, string $identifier): string
    {
        return 'amial-login:' . sha1(mb_strtolower($identifier) . '|' . $request->ip());
    }

    private function failureKey(Request $request): string
    {
        return 'amial-login-fails:' . sha1((string) $request->ip());
    }

    private function countFailure(Request $request): void
    {
        $k = $this->failureKey($request);
        cache()->put($k, ((int) cache()->get($k, 0)) + 1, now()->addMinutes(15));
    }

    private function needsCaptcha(Request $request): bool
    {
        return ((int) cache()->get($this->failureKey($request), 0)) >= self::CAPTCHA_AFTER;
    }
}
