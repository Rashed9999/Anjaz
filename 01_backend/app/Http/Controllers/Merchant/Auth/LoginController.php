<?php

namespace App\Http\Controllers\Merchant\Auth;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use Gregwar\Captcha\PhraseBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Gregwar\Captcha\CaptchaBuilder;
use Stevebauman\Location\Facades\Location;

class LoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest:user', ['except' => ['logout']]);
    }

    public function captcha($tmp): void
    {

        $phrase = new PhraseBuilder;
        $code = $phrase->build(4);
        $builder = new CaptchaBuilder($code, $phrase);
        $builder->setBackgroundColor(220, 210, 230);
        $builder->setMaxAngle(25);
        $builder->setMaxBehindLines(0);
        $builder->setMaxFrontLines(0);
        $builder->build($width = 100, $height = 40, $font = null);
        $phrase = $builder->getPhrase();

        if (Session::has('default_captcha_code')) {
            Session::forget('default_captcha_code');
        }

        Session::put('default_captcha_code', $phrase);
        header("Cache-Control: no-cache, must-revalidate");
        header("Content-Type:image/jpeg");
        $builder->output();
    }

    public function login(Request $request): View
    {
        $ip = env('APP_MODE') == 'live' ? $request->ip() : '61.247.180.82';
        $currentUserInfo = Location::get($ip);

        return view('merchant-views.auth.login', compact('currentUserInfo'));
    }

    public function submit(Request $request): RedirectResponse
    {
        $request->validate([
            'phone' => 'required|min:8|max:20',
            'password' => 'required|min:8',
        ]);

        $recaptcha = Helpers::get_business_settings('recaptcha');
        if (isset($recaptcha) && $recaptcha['status'] == 1) {
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

        $phone = $request->phone;

        if (auth('user')->attempt(['phone' => $phone, 'password' => $request->password, 'type' => MERCHANT_TYPE], $request->remember)) {

            return redirect()->route('merchant.dashboard');
        }

        return redirect()->back()->withInput($request->only('email', 'remember'))
            ->withErrors(['Credentials does not match.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        auth()->guard('user')->logout();
        return redirect()->route('merchant.auth.login');
    }
}
