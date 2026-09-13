<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailIdentityService;
use App\Services\Otp\EmailOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * AMIAL-EMAIL-OTP-REG-001 + AMIAL-EMAIL-IDENTITY-001
 *
 * Email-first registration adapter around the existing registration controller.
 * Email ownership is proven before account creation, and the canonical email
 * identity service guarantees that the same mailbox cannot be attached to a
 * second phone/account, including soft-deleted historical accounts.
 */
class EmailRegistrationController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly EmailIdentityService $identities,
    ) {}

    public function register(Request $request): JsonResponse
    {
        if ((string) config('amial_otp.registration_channel', 'email') !== 'email') {
            return response()->json(['errors' => [[
                'code' => 'email_registration_disabled',
                'message' => 'التسجيل عبر البريد غير مفعّل حالياً.',
            ]]], 409);
        }

        $v = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'email_challenge_id' => 'required|string|size:26',
            'email_verification_token' => 'required|string|min:32|max:200',
            'dial_country_code' => 'required|string|max:8',
            'phone' => 'required|string|min:5|max:20',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => Helpers::error_processor($v)], 403);
        }

        $email = $this->identities->normalize((string) $request->input('email'));

        if (! $this->identities->isAvailable($email)) {
            return response()->json(['errors' => [[
                'code' => 'email',
                'message' => 'البريد الإلكتروني مستخدم في حساب آخر.',
            ]]], 403);
        }

        $request->merge(['email' => $email]);
        try {
            return DB::transaction(function () use ($request, $email): JsonResponse {
                $challenge = $this->otp->consumeVerification(
                    (string) $request->input('email_challenge_id'),
                    $email,
                    EmailOtpService::PURPOSE_REGISTRATION,
                    (string) $request->input('email_verification_token'),
                );
                // The second argument is server-only and follows consumption of
                // real email proof. No fabricated phone-verification row exists.
                $response = app(RegisterController::class)->customerRegistration($request, $email);
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    throw new HttpResponseException($response);
                }
                $phone = \App\Support\Phone::canonical(
                    (string) $request->input('dial_country_code') . (string) $request->input('phone')
                );

                $query = User::whereIn('phone', \App\Support\Phone::variants($phone));
                if (Schema::hasColumn('users', 'email_canonical')) {
                    $query->where('email_canonical', $email);
                } else {
                    $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
                }
                $user = $query->orderByDesc('id')->first();

                if (!$user) {
                    throw new RuntimeException('EMAIL_REGISTRATION_FAILED');
                }

                $this->identities->markCurrentEmailVerified($user);

                DB::table('otp_challenges')->where('id', $challenge->id)->update([
                    'user_id' => $user->id,
                    'updated_at' => now(),
                ]);
                return $response;
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (RuntimeException $e) {
            if (! in_array($e->getMessage(), [
                'OTP_NOT_VERIFIED', 'OTP_ALREADY_USED', 'VERIFICATION_EXPIRED', 'VERIFICATION_INVALID',
            ], true)) {
                throw $e;
            }
            return $this->verificationError(
                $e->getMessage(),
                'تعذر إكمال التسجيل. تأكد من البيانات وصلاحية جلسة التحقق ثم أعد المحاولة.',
            );
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
