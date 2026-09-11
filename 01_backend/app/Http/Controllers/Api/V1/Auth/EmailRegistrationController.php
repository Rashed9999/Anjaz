<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\EmailOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-EMAIL-OTP-REG-001
 *
 * Email-first pilot adapter around the existing, battle-tested registration
 * controller. It proves email ownership first, then creates a short-lived
 * internal phone-verification row so the legacy registration transaction can
 * run unchanged. No SMS is sent and the legacy SMS path remains available.
 */
class EmailRegistrationController extends Controller
{
    public function __construct(private readonly EmailOtpService $otp) {}

    public function register(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email|max:320',
            'email_challenge_id' => 'required|string|size:26',
            'email_verification_token' => 'required|string|min:32|max:200',
            'dial_country_code' => 'required|string|max:8',
            'phone' => 'required|string|min:5|max:20',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => Helpers::error_processor($v)], 403);
        }

        $email = $this->otp->normalizeEmail((string) $request->input('email'));

        // Email is now an account-recovery credential. New pilot accounts must
        // therefore have one owner only. We use LOWER() because old data was not
        // normalized consistently and adding a DB unique index now could fail on
        // historical duplicates.
        $existing = User::whereRaw('LOWER(email) = ?', [$email])
            ->when(Schema::hasColumn('users', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->first();
        if ($existing) {
            return response()->json(['errors' => [[
                'code' => 'email',
                'message' => 'البريد الإلكتروني مستخدم في حساب آخر.',
            ]]], 403);
        }

        $challenge = DB::table('otp_challenges')
            ->where('challenge_id', (string) $request->input('email_challenge_id'))
            ->where('identifier', $email)
            ->where('purpose', EmailOtpService::PURPOSE_REGISTRATION)
            ->first();

        if (!$challenge || !$challenge->verified_at || !$challenge->verification_token_hash) {
            return $this->verificationError('EMAIL_NOT_VERIFIED', 'تحقق من البريد الإلكتروني أولاً.');
        }
        if ($challenge->consumed_at) {
            return $this->verificationError('EMAIL_VERIFICATION_USED', 'تم استخدام جلسة التحقق هذه. اطلب رمزاً جديداً.');
        }
        if (!$challenge->verification_expires_at || Carbon::parse($challenge->verification_expires_at)->isPast()) {
            return $this->verificationError('EMAIL_VERIFICATION_EXPIRED', 'انتهت جلسة التحقق. اطلب رمزاً جديداً.');
        }
        if (!Hash::check((string) $request->input('email_verification_token'), (string) $challenge->verification_token_hash)) {
            return $this->verificationError('EMAIL_VERIFICATION_INVALID', 'جلسة التحقق غير صحيحة.');
        }

        $request->merge(['email' => $email]);
        $syntheticVerificationId = null;

        try {
            // RegisterController still enforces the legacy phone OTP when the
            // business switch is on. During the email pilot we satisfy that
            // internal gate only after email ownership has been cryptographically
            // verified. The random value is never returned and the row is always
            // removed in finally{}.
            if ((int) (Helpers::get_business_settings('phone_verification') ?? 0) === 1) {
                $phone = \App\Support\Phone::canonical(
                    (string) $request->input('dial_country_code') . (string) $request->input('phone')
                );
                $internalOtp = (string) random_int(100000, 999999);
                $syntheticVerificationId = DB::table('phone_verifications')->insertGetId([
                    'phone' => $phone,
                    'otp' => $internalOtp,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $request->merge(['otp' => $internalOtp]);
            }

            /** @var JsonResponse $response */
            $response = app(RegisterController::class)->customerRegistration($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $phone = \App\Support\Phone::canonical(
                    (string) $request->input('dial_country_code') . (string) $request->input('phone')
                );
                $user = User::whereIn('phone', \App\Support\Phone::variants($phone))
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->orderByDesc('id')
                    ->first();

                if (!$user) {
                    // Never mark a challenge consumed unless the account actually
                    // exists. This also makes operational inconsistencies visible.
                    return response()->json(['errors' => [[
                        'code' => 'registration',
                        'message' => 'تمت العملية جزئياً وتعذر تأكيد الحساب. تواصل مع الدعم.',
                    ]]], 500);
                }

                if (Schema::hasColumn('users', 'email_verified_at')) {
                    $user->forceFill(['email_verified_at' => now()])->saveQuietly();
                }

                DB::table('otp_challenges')->where('id', $challenge->id)->update([
                    'user_id' => $user->id,
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $response;
        } finally {
            if ($syntheticVerificationId !== null) {
                DB::table('phone_verifications')->where('id', $syntheticVerificationId)->delete();
            }
        }
    }

    private function verificationError(string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => (object) [],
        ], 422);
    }
}
