<?php

namespace App\Http\Controllers\Admin\Auth;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformLoginPinService;
use App\Support\Phone;
use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;

class LoginController extends Controller
{
    public function __construct(private readonly PlatformLoginPinService $loginPins)
    {
        $this->middleware('guest:user', ['except' => ['logout']]);
    }

    /**
     * مسار CAPTCHA القديم يبقى مؤقتاً حتى لا نكسر روابط قديمة، لكنه لم يعد
     * جزءاً من عقد دخول الإدارة. الدخول الجديد: هاتف + كلمة مرور + PIN.
     */
    public function captcha($tmp)
    {
        $phrase = new PhraseBuilder;
        $code = $phrase->build(4);
        $builder = new CaptchaBuilder($code, $phrase);
        $builder->setBackgroundColor(220, 210, 230);
        $builder->setMaxAngle(25);
        $builder->setMaxBehindLines(0);
        $builder->setMaxFrontLines(0);
        $builder->build($width = 100, $height = 40);

        $phrase = $builder->getPhrase();

        if (Session::has('default_captcha_code')) {
            Session::forget('default_captcha_code');
        }
        Session::put('default_captcha_code', $phrase);

        $headers = [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => 'Sat, 26 Jul 1997 05:00:00 GMT',
        ];

        ob_start();
        $builder->output();
        $imageData = ob_get_clean();

        return Response::make($imageData, 200, $headers);
    }

    public function login(): View
    {
        return view('admin-views.auth.login');
    }

    public function submit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|min:5|max:20',
            'password' => 'required|string|min:8',
            'login_pin' => ['required', 'regex:/^\d{4}$/'],
        ], [
            'login_pin.required' => 'أدخل رمز PIN الخاص بحسابك الوظيفي.',
            'login_pin.regex' => 'رمز PIN يجب أن يتكون من 4 أرقام.',
        ]);

        $phone = Helpers::filter_phone($data['phone']);
        $rateKey = 'admin-login:' . hash('sha256', $request->ip() . '|' . $phone);

        // CAPTCHA أزيل من عقد الدخول، لذلك حماية التخمين يجب ألا تختفي معه.
        // الحد على الهاتف + IP، ويضاف إليه قفل PIN على الحساب نفسه في الخدمة.
        if (RateLimiter::tooManyAttempts($rateKey, PlatformLoginPinService::MAX_ATTEMPTS)) {
            $seconds = max(1, RateLimiter::availableIn($rateKey));
            return back()->withInput($request->only('phone', 'remember'))
                ->withErrors(['تم إيقاف محاولات الدخول مؤقتاً. أعد المحاولة بعد ' . $seconds . ' ثانية.']);
        }

        $admin = User::query()
            ->where('type', ADMIN_TYPE)
            ->whereIn('phone', Phone::variants($phone))
            ->first();

        if (! $admin || ! Hash::check($data['password'], (string) $admin->password)) {
            RateLimiter::hit($rateKey, PlatformLoginPinService::LOCK_MINUTES * 60);
            return back()->withInput($request->only('phone', 'remember'))
                ->withErrors(['بيانات الدخول غير صحيحة.']);
        }

        if (! (bool) $admin->is_active) {
            return back()->withInput($request->only('phone', 'remember'))
                ->withErrors(['تم تعطيل حساب الموظف. راجع مدير المنصّة.']);
        }

        $pinResult = $this->loginPins->verify($admin, $data['login_pin']);
        if (! $pinResult['ok']) {
            if ($pinResult['reason'] === 'not_configured') {
                return back()->withInput($request->only('phone', 'remember'))
                    ->withErrors(['لم يُصدر PIN لهذا الحساب بعد. اطلب من مدير المنصّة إعادة تعيين رمز الدخول وإرساله إلى بريدك.']);
            }

            RateLimiter::hit($rateKey, PlatformLoginPinService::LOCK_MINUTES * 60);

            if ($pinResult['reason'] === 'locked') {
                $seconds = max(1, (int) ($pinResult['retry_after'] ?? PlatformLoginPinService::LOCK_MINUTES * 60));
                return back()->withInput($request->only('phone', 'remember'))
                    ->withErrors(['تم قفل رمز PIN مؤقتاً بعد محاولات غير صحيحة. أعد المحاولة بعد ' . $seconds . ' ثانية.']);
            }

            return back()->withInput($request->only('phone', 'remember'))
                ->withErrors(['رمز PIN غير صحيح.']);
        }

        RateLimiter::clear($rateKey);

        // TOTP الموجود يبقى طبقة إضافية عند تفعيله، ولا نستبدله بالـPIN.
        // PIN هنا هو credential وظيفي ثابت؛ TOTP يظل العامل الأقوى المتغير.
        if ($admin->two_factor_enabled && $admin->two_factor_confirmed_at) {
            $request->session()->put('amial.2fa.pending_user', $admin->id);
            $request->session()->put('amial.2fa.remember', (bool) $request->boolean('remember'));

            return redirect()->route('admin.auth.two-factor');
        }

        auth('user')->login($admin, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        auth()->guard('user')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.auth.login');
    }
}
