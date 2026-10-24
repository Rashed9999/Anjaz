<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication as Opening;
use App\Models\User;
use App\Services\Otp\EmailOtpService;
use App\Services\TransactionPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Muwathaq provisions an approved owner with an *unusable random password*.
 * Only the real mailbox owner can claim that already-approved account, choose
 * the login password and transaction PIN. Reviewer sessions never do this.
 */
final class WebActivationController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly TransactionPinService $pins,
    ) {}

    public function show(): View
    {
        return view('merchant-web.activate');
    }

    private function normalizedEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function approvedUnclaimed(string $email, bool $lock = false): ?User
    {
        $q = User::query()->where('type', MERCHANT_TYPE)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('is_active', 1)->where('is_email_verified', 0);
        if ($lock) {
            $q->lockForUpdate();
        }
        $rows = $q->limit(2)->get();
        if ($rows->count() !== 1) {
            return null;
        }
        $owner = $rows->first();
        if ($owner->trashed() || (int) ($owner->is_temp_blocked ?? 0) === 1 ||
            ($owner->security_hold_until && \Illuminate\Support\Carbon::parse($owner->security_hold_until)->isFuture())) {
            return null;
        }
        if (Schema::hasColumn('users', 'email_canonical')
            && $this->normalizedEmail((string) $owner->email_canonical) !== $email) {
            return null;
        }
        if (! MerchantProfile::query()->where('user_id', $owner->id)
            ->where('verification_status', 'verified')->exists()) {
            return null;
        }
        if (! Opening::query()->where('user_id', $owner->id)->where('origin', 'muwathaq')
            ->where('subject_kind', 'merchant')->where('status', Opening::STATUS_APPROVED)->exists()) {
            return null;
        }
        if (! Merchant::query()->where('user_id', $owner->id)
            ->whereNotNull('merchant_number')->where('merchant_number', '!=', '')->exists()) {
            return null;
        }
        return $owner;
    }

    public function requestCode(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required','email','max:255']]);
        $email = $this->normalizedEmail((string) $request->input('email'));
        $key = 'muwathaq-merchant-activation:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'محاولات كثيرة، حاول لاحقاً.'], 429);
        }
        RateLimiter::hit($key, 900);
        $owner = $this->approvedUnclaimed($email);
        $reply = ['success' => true, 'message' => 'إذا كان حساب التاجر معتمداً وينتظر التفعيل، فسيصلك رمز على البريد المسجل.'];
        if (!$owner) {
            return response()->json($reply)->header('Cache-Control', 'no-store');
        }
        if ((string) config('amial_otp.resend.api_key') === '') {
            return response()->json(['success' => false,
                'message' => 'إرسال رمز البريد غير مهيأ على الخادم. لا يمكن تفعيل الحساب قبل تهيئة خدمة البريد.'], 503);
        }
        try {
            $this->otp->issue($email, EmailOtpService::PURPOSE_ACCOUNT_ACTIVATION,
                (int) $owner->id, 'user', (int) $owner->id, ['source' => 'muwathaq-merchant-web']);
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'OTP_DELIVERY_FAILED') {
                return response()->json(['success' => false,
                    'message' => 'تعذر إرسال الرمز. تحقق من إعدادات خدمة البريد على الخادم.'], 503);
            }
            if (str_starts_with($error->getMessage(), 'RESEND_TOO_SOON:')) {
                return response()->json(['success' => false,
                    'message' => 'تم طلب رمز مؤخرًا. تحقق من البريد قبل إعادة الطلب.'], 429);
            }
            return response()->json(['success' => false,
                'message' => 'تعذر إصدار الرمز، حاول لاحقاً.'], 422);
        }
        return response()->json($reply)->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required','email','max:255'],
            'otp' => ['required','digits:6'],
            'password' => ['required','string','min:8','max:72','confirmed'],
            'pin' => ['required','digits_between:4,6','confirmed'],
        ]);
        $email = $this->normalizedEmail($data['email']);
        $key = 'muwathaq-merchant-activation-verify:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['success'=>false, 'message'=>'محاولات كثيرة، حاول لاحقاً.'], 429);
        }
        RateLimiter::hit($key, 900);
        // No challenge ID comes from the browser, so the same generic UI
        // works for existing users without exposing who owns an email.
        $challenge = DB::table('otp_challenges')
            ->where('identifier', $email)
            ->where('purpose', EmailOtpService::PURPOSE_ACCOUNT_ACTIVATION)
            ->whereNull('consumed_at')
            ->orderByDesc('id')->first();
        if (!$challenge) {
            return response()->json(['success'=>false,
                'message'=>'الرمز غير صالح أو منتهي. اطلب رمز تفعيل جديداً.'], 422);
        }
        try {
            $merchantNumber = $this->otp->verifyAndConsume(
                (string) $challenge->challenge_id, $email,
                EmailOtpService::PURPOSE_ACCOUNT_ACTIVATION,
                (string) $data['otp'],
                function (object $proof) use ($email, $data): string {
                    $owner = $this->approvedUnclaimed($email, true);
                    if (!$owner || (int) $proof->user_id !== (int) $owner->id) {
                        throw new RuntimeException('ACTIVATION_NOT_ALLOWED');
                    }
                    $merchant = Merchant::query()->where('user_id', $owner->id)->firstOrFail();
                    // The PIN service applies the same PIN policy and audit log
                    // as every other Amial account. No PIN/plain password in logs.
                    $this->pins->setPin($owner, (string) $data['pin']);
                    $owner->password = Hash::make((string) $data['password']);
                    $owner->is_email_verified = 1;
                    if (Schema::hasColumn('users', 'email_verified_at')) {
                        $owner->email_verified_at = now();
                    }
                    $owner->save();
                    return (string) $merchant->merchant_number;
                },
            );
        } catch (\InvalidArgumentException $error) {
            return response()->json(['success'=>false, 'message'=>'رمز PIN غير مسموح؛ اختر رمزاً غير متسلسل أو مكرر.'], 422);
        } catch (RuntimeException $error) {
            return response()->json(['success'=>false,
                'message'=>'الرمز غير صالح أو انتهت صلاحيته، أو تغيرت حالة الاعتماد.'], 422);
        } catch (\Throwable $error) {
            // Infrastructure failures are NOT user OTP mistakes. Let the
            // exception handler alert operators, without leaking internals to
            // the public response or swallowing a server-side defect.
            report($error);
            throw $error;
        }
        RateLimiter::clear($key);
        return response()->json(['success' => true,
            'message' => 'تم تأكيد البريد وتعيين بيانات دخول التاجر.',
            'data' => ['merchant_number' => $merchantNumber,
                'login_url' => route('merchant.web.login')]])
            ->header('Cache-Control', 'no-store');
    }
}
