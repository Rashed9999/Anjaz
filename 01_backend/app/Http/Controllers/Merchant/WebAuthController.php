<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Contracts\View\View;

/** بوابة المالك: حساب تاجر فقط، جلسة مستقلة، ومنع محاولات التخمين. */
class WebAuthController extends Controller
{
    public function login(): View
    {
        return view('merchant-web.login');
    }

    public function submit(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'identifier' => ['required', 'string', 'max:160'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $identifier = trim((string) $input['identifier']);
        $key = 'amial:merchant-web:' . hash('sha256',
            mb_strtolower($identifier) . '|' . (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withInput($request->only('identifier'))
                ->withErrors(['identifier' => 'محاولات كثيرة؛ أعد المحاولة لاحقاً.']);
        }

        $merchantId = Merchant::where('merchant_number', $identifier)->value('user_id');
        $phones = preg_match('/^[+0-9\s-]+$/', $identifier) === 1
            ? Phone::variants($identifier) : [];

        $owner = User::query()
            ->where('type', MERCHANT_TYPE)
            ->where('role', A::ROLE_MERCHANT)
            ->where(function ($query) use ($identifier, $merchantId, $phones) {
                $query->where('email', $identifier);
                if ($phones !== []) $query->orWhereIn('phone', $phones);
                if ($merchantId) $query->orWhere('id', $merchantId);
            })->first();

        if (!$owner || !Hash::check((string) $input['password'], (string) $owner->password)
            || (int) $owner->is_active !== 1
            || (int) ($owner->is_temp_blocked ?? 0) === 1
            || !MerchantProfile::where('user_id', $owner->id)->exists()) {
            RateLimiter::hit($key, 900);
            return back()->withInput($request->only('identifier'))->withErrors([
                'identifier' => 'تعذر الدخول؛ تحقق من البيانات أو حالة حساب المنشأة.',
            ]);
        }

        RateLimiter::clear($key);
        Auth::guard('merchant_web')->login($owner, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->route('merchant.web.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('merchant_web')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('merchant.web.login');
    }
}
