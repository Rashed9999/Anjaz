<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Support\Phone;
use App\Domain\Verticals\VerticalRegistry as VR;
use App\Support\YemenGovernorates;
use App\Services\Geo\YemenRegionsService;
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

    /** إنشاء التاجر صار من بوابة الأعمال نفسها، لا من معالج Flutter القديم. */
    public function register(): View
    {
        $labels = VR::labels();
        $businessTypes = [];
        foreach (VR::current() as $code => $vertical) {
            $businessTypes[] = [
                'value' => (string) $code,
                'label' => (string) ($labels[$code] ?? $vertical->nameAr()),
            ];
        }

        $governorates = YemenGovernorates::all();
        $regions = app(YemenRegionsService::class);
        $districts = [];
        foreach ($governorates as $governorate) {
            try {
                $districts[$governorate['code']] = $regions->districts($governorate['code']);
            } catch (\Throwable) {
                $districts[$governorate['code']] = [];
            }
        }

        return view('merchant-web.register', compact(
            'businessTypes', 'governorates', 'districts'
        ));
    }

    public function recover(): View
    {
        return view('merchant-web.recover');
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

        $profile = $owner
            ? MerchantProfile::where('user_id', $owner->id)->first()
            : null;

        if (!$owner || !Hash::check((string) $input['password'], (string) $owner->password)
            || (int) $owner->is_active !== 1
            || (int) ($owner->is_temp_blocked ?? 0) === 1
            || !$profile) {
            RateLimiter::hit($key, 900);
            return back()->withInput($request->only('identifier'))->withErrors([
                'identifier' => 'تعذر الدخول؛ تحقق من البيانات أو حالة حساب المنشأة.',
            ]);
        }

        if ((string) $profile->verification_status !== 'verified') {
            RateLimiter::clear($key);
            $state = (string) $profile->verification_status;
            $message = match ($state) {
                'rejected' => 'تم رفض ملف المنشأة. راجع سبب الرفض أو تواصل مع الدعم قبل تسجيل الدخول.',
                'resubmission_required' => 'ملف المنشأة يحتاج استكمال مستندات قبل تفعيل لوحة الأعمال.',
                'verification_suspended' => 'توثيق المنشأة موقوف مؤقتاً. تواصل مع الدعم.',
                default => 'حساب المنشأة قيد المراجعة. سنفعّل لوحة الأعمال بعد اعتماد الملف.',
            };

            return back()->withInput($request->only('identifier'))
                ->withErrors(['identifier' => $message]);
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
