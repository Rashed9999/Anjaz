<?php

namespace App\Http\Controllers\Admin\Auth;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use Gregwar\Captcha\PhraseBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Gregwar\Captcha\CaptchaBuilder;
use Illuminate\Support\Facades\Response;


class LoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest:user', ['except' => ['logout']]);
    }

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

        ob_start(); // Capture the binary output
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
        $request->validate([
            'phone' => 'required|min:5|max:20',
            'password' => 'required|min:8',
        ]);

        // AMIAL-UI-001: تخطّي الكابتشا في وضع العرض/الاختبار فقط (افتراضياً معطّل —
        // الإنتاج يُبقي الكابتشا). يُفعَّل عبر AMIAL_DISABLE_ADMIN_CAPTCHA=true.
        $captchaDisabled = (bool) config('amial.disable_admin_captcha', false);

        $recaptcha = Helpers::get_business_settings('recaptcha');
        if ($captchaDisabled) {
            // لا فحص كابتشا في وضع العرض/الاختبار
        } elseif (isset($recaptcha) && $recaptcha['status'] == 1 && !$request?->set_default_captcha) {
            $request->validate([
                'g-recaptcha-response' => [
                    function ($attribute, $value, $fail) {
                        $secret_key = Helpers::get_business_settings('recaptcha')['secret_key'];
                        $response = $value;

                        $gResponse = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                            'secret' => $secret_key,
                            'response' => $value,
                            'remoteip' => \request()->ip(),
                        ]);

                        if (!$gResponse->successful()) {
                            $fail(translate('ReCaptcha Failed'));
                        }
                    },
                ],
            ]);
        } else {
            if (strtolower($request->default_captcha_value) != strtolower(Session('default_captcha_code'))) {
                Session::forget('default_captcha_code');
                return back()->withErrors(translate('Captcha Failed'));
            }
        }


        if (auth('user')->attempt(['phone' => $request->phone, 'password' => $request->password, 'type' => ADMIN_TYPE], $request->remember)) {
            // ══════════════════════════════════════════════════════════
            // AMIAL-2FA-DOOR-001 — **مصادقةٌ ثنائيّةٌ مبنيّةٌ ولا تُفرض.**
            //
            // `Admin2FAController` و`TwoFactorAuthService` مكتملان منذ
            // v1.8: توليدُ سرٍّ ورمزُ QR ورموزُ استرداد وتأكيدٌ وتعطيل.
            // والأعمدةُ الخمسةُ في `users`. **ولم يكن لها شاشةٌ واحدة،
            // ولا سطرٌ واحدٌ يفحصها عند الدخول.**
            //
            // أي أنّ من فعّلها — لو استطاع — يدخل بكلمة المرور وحدها
            // كأنّه لم يفعّلها. **حمايةٌ تُخزَّن ولا تُقرأ**، وهي أسوأ من
            // غيابها: تُطمئن صاحبَها إلى بابٍ مفتوح.
            //
            // فصار الدخولُ يقف هنا لمن فعّلها: الجلسةُ تُعلَّق حتّى يمرّ
            // الرمز. ومن لم يفعّلها يدخل كما كان — **ولا يُقفل الباب على
            // أحدٍ بتغييرٍ صامت**.
            $admin = auth('user')->user();

            // `attempt` يتحقق من كلمة المرور والنوع، لا من صلاحية تشغيل
            // الحساب. إبقاء هذا الفحص في واجهة اللوحة فقط يعني أن «تعطيل
            // موظف» يظلّ يستطيع فتح كل شيء بكلمة مروره.
            if (!$admin || !(bool) $admin->is_active) {
                auth('user')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('admin.auth.login')
                    ->withErrors(['تم تعطيل حساب الموظف. راجع مدير المنصّة.']);
            }

            if ($admin && $admin->two_factor_enabled && $admin->two_factor_confirmed_at) {
                auth('user')->logout();

                $request->session()->put('amial.2fa.pending_user', $admin->id);
                $request->session()->put('amial.2fa.remember', (bool) $request->remember);

                return redirect()->route('admin.auth.two-factor');
            }

            return redirect()->route('admin.dashboard');
        }


        return redirect()->back()->withInput($request->only('email', 'remember'))
            ->withErrors(['Credentials does not match.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        auth()->guard('user')->logout();
        return redirect()->route('admin.auth.login');
    }
}
