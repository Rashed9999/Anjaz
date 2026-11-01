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

        // القطاع يختار تهيئة لوحة المنشأة بعد الاعتماد؛ لا نطلب عنواناً
        // أو مستندات هنا لأن موثّق يملك متطلبات المراجعة.
        return view('merchant-web.register', compact('businessTypes'));
    }

    public function recover(): View
    {
        return view('merchant-web.recover');
    }

    /**
     * Reopen only the evidence-completion session for a pending merchant.
     * This is NOT a merchant login or an authorization for financial actions.
     * Inactive applicants authenticate using the verified email and password
     * created during registration; enumeration and guessing are rate limited.
     */
    public function resumeOpening(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $email = mb_strtolower(trim((string) $data['email']));
        $key = 'merchant-opening-resume:'.hash('sha256', $email.'|'.$request->ip());
        $generic = ['success' => false, 'message' => 'تعذر استعادة الطلب؛ تحقق من بيانات الحساب أو حالته.'];

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'محاولات كثيرة؛ حاول لاحقاً.'], 429);
        }

        $matching = User::query()->where('type', MERCHANT_TYPE)
            ->where(function ($query) use ($email): void {
                $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'email_canonical')) {
                    $query->orWhere('email_canonical', $email);
                }
            })->limit(2)->get();

        $owner = $matching->count() === 1 ? $matching->first() : null;
        $opening = $owner ? \App\Models\MuwathaqAccountOpeningApplication::query()
            ->where('user_id', $owner->id)->where('subject_kind', 'merchant')->first() : null;

        if (!$owner || (int) ($owner->is_email_verified ?? 0) !== 1
            || (string) ($owner->role ?? '') !== A::ROLE_MERCHANT
            || (int) ($owner->is_temp_blocked ?? 0) === 1
            || ($owner->security_hold_until && \Illuminate\Support\Carbon::parse($owner->security_hold_until)->isFuture())
            || !Hash::check((string) $data['password'], (string) $owner->password)
            || !$opening) {
            RateLimiter::hit($key, 900);
            return response()->json($generic, 403);
        }

        RateLimiter::clear($key);
        $payload = [
            'application_ulid' => $opening->application_ulid,
            'status' => $opening->status,
        ];

        if (!$opening->isFinal()) {
            $payload['account_opening_access_token'] =
                app(\App\Services\Muwathaq\MuwathaqOpeningProgressService::class)
                    ->issueAccessToken($opening);
        }

        return response()->json(['success' => true, 'data' => $payload])
            ->header('Cache-Control', 'no-store');
    }

    /** Public completion wizard: no wallet, merchant or customer session. */
    public function opening(): View
    {
        return view('merchant-web.opening');
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
