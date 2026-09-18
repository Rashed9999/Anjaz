<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailIdentityService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\LegalTermsService;
use App\Services\Otp\EmailOtpService;
use App\Services\ZoneAssignmentService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-QUICK-REG-001 — إنشاء محفظة أساسية من أربع معلومات فقط.
 *
 * الاسم + الهاتف + البريد + كلمة مرور دخول + PIN مالي مستقل.
 * لا هوية ولا سيلفي ولا دخل ولا PEP هنا. ملكية البريد تُثبت قبل الإنشاء،
 * وملكية الهاتف هي الخطوة التالية التي ترفع الحساب إلى Tier 1.
 * الحركة المالية تبقى خلف الإقامة الموثقة.
 */
class ProgressiveRegistrationController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly EmailIdentityService $identities,
        private readonly AuditService $audit,
        private readonly LegalTermsService $legalTerms,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'min:2', 'max:180'],
            'dial_country_code' => ['required', 'string', 'max:8'],
            'phone' => ['required', 'string', 'min:5', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            // P0-CUSTOMER-CREDENTIAL-SEPARATION:
            // كلمةُ الدخول ليست PIN. التسجيل الجديد يرفض كلمةً قصيرة أو رقمية فقط،
            // ويطلب PIN مالياً مستقلاً من أربعة أرقام.
            'password' => [
                'required', 'string', 'min:8', 'max:64', 'confirmed',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (preg_match('/^\d+$/u', (string) $value) === 1) {
                        $fail('كلمة المرور لا يجوز أن تكون أرقاماً فقط.');
                    }
                },
            ],
            'transaction_pin' => [
                'required', 'regex:/^\d{4}$/', 'different:password',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $weak = ['0000','1111','2222','3333','4444','5555','6666','7777','8888','9999','1234','4321','0123'];
                    if (in_array((string) $value, $weak, true)) {
                        $fail('اختر رمز PIN غير متسلسل وغير مكرر.');
                    }
                },
            ],
            'locale' => ['nullable', 'in:ar,en'],
            'email_challenge_id' => ['required', 'string', 'size:26'],
            'email_verification_token' => ['required', 'string', 'min:32', 'max:200'],
            // الموافقة ليست «معلومة خامسة» عن العميل؛ لكنها قرار قانوني
            // يجب أن يثبته الخادم، لا checkbox تجميلي يمكن تجاوزه بطلب API.
            'terms_accepted' => ['required', 'accepted'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = $this->identities->normalize((string) $request->input('email'));
        $phone = Phone::canonical(
            (string) $request->input('dial_country_code') . (string) $request->input('phone')
        );

        if (!$this->identities->isAvailable($email)) {
            return response()->json([
                'success' => false, 'code' => 'EMAIL_ALREADY_USED',
                'message' => 'البريد الإلكتروني مستخدم في حساب آخر.',
            ], 409);
        }
        if (User::whereIn('phone', Phone::variants($phone))->exists()) {
            return response()->json([
                'success' => false, 'code' => 'PHONE_ALREADY_USED',
                'message' => 'رقم الهاتف مستخدم في حساب آخر.',
            ], 409);
        }

        $name = preg_replace('/\s+/u', ' ', trim((string) $request->input('full_name'))) ?: '';
        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = array_shift($parts) ?: $name;
        $last = trim(implode(' ', $parts));

        try {
            $user = DB::transaction(function () use ($request, $email, $phone, $first, $last): User {
                $challenge = $this->otp->consumeVerification(
                    (string) $request->input('email_challenge_id'),
                    $email,
                    EmailOtpService::PURPOSE_REGISTRATION,
                    (string) $request->input('email_verification_token'),
                );

                // نعيد فحص الفريد داخل المعاملة لتضييق نافذة السباق.
                if (!$this->identities->isAvailable($email)
                    || User::whereIn('phone', Phone::variants($phone))->lockForUpdate()->exists()) {
                    throw new RuntimeException('REGISTRATION_IDENTITY_ALREADY_USED');
                }

                $user = new User();
                $user->f_name = $first;
                $user->l_name = $last !== '' ? $last : null;
                $user->dial_country_code = (string) $request->input('dial_country_code');
                $user->phone = $phone;
                $user->email = $email;
                $user->image = null;
                $user->password = bcrypt((string) $request->input('password'));
                $user->type = defined('CUSTOMER_TYPE') ? CUSTOMER_TYPE : 2;
                $user->is_phone_verified = 0;
                $user->is_kyc_verified = 0;
                if (Schema::hasColumn('users', 'identification_image')) {
                    $user->identification_image = json_encode([]);
                }
                if (Schema::hasColumn('users', 'transaction_pin')) {
                    // حقل User يحمل cast=hashed؛ لا نخزن PIN صريحاً.
                    $user->transaction_pin = (string) $request->input('transaction_pin');
                }
                if (Schema::hasColumn('users', 'transaction_pin_set_at')) {
                    $user->transaction_pin_set_at = now();
                }
                if (Schema::hasColumn('users', 'requires_pin_setup')) {
                    $user->requires_pin_setup = false;
                }
                if (Schema::hasColumn('users', 'pin_failed_attempts')) {
                    $user->pin_failed_attempts = 0;
                }
                if (Schema::hasColumn('users', 'kyc_tier')) {
                    $user->kyc_tier = 0;
                }
                if (Schema::hasColumn('users', 'zone_code')) {
                    $user->zone_code = ZoneAssignmentService::ZONE_UNKNOWN;
                }
                $user->save();

                if (Schema::hasColumn('users', 'unique_id')) {
                    $user->unique_id = $user->id . random_int(1111, 99999);
                    $user->save();
                }

                EMoney::firstOrCreate(['user_id' => $user->id]);
                $this->identities->markCurrentEmailVerified($user);
                app(KycPrivacyService::class)->ensure($user);

                // checkbox التسجيل يصبح قبولاً حقيقياً للإصدار القانوني الحالي،
                // لا مجرد boolean في سجل التدقيق. إن لم يُنشر إصدار بعد فلا
                // نسجّل قبولاً وهمياً؛ وحين يُنشر سيحجبه amial.terms حتى يقبله.
                $locale = (string) $request->input('locale', 'ar');
                $currentTerm = $this->legalTerms->currentTerm($locale);
                if ($currentTerm !== null) {
                    $this->legalTerms->accept(
                        $user,
                        $currentTerm,
                        $request->ip(),
                        (string) $request->userAgent(),
                        $request->header('X-Device-Id'),
                    );
                }

                DB::table('otp_challenges')->where('id', $challenge->id)->update([
                    'user_id' => $user->id,
                    'updated_at' => now(),
                ]);

                // لا نعطي SOUTH من أصل أو تصريح. يبدأ UNKNOWN حتى تثبت الإقامة.
                app(ZoneAssignmentService::class)->assignOnRegistration($user, $request);

                $this->audit->record([
                    'actor_type' => 'customer',
                    'actor_user_id' => (int) $user->id,
                    'subject_type' => 'user',
                    'subject_id' => (string) $user->id,
                    'action' => 'BASIC_WALLET_CREATED',
                    'decision_code' => 'PROGRESSIVE_KYC_TIER_0',
                    'severity' => 'info',
                    'context' => [
                        'registration_mode' => 'quick',
                        'email_verified' => true,
                        'phone_verified' => false,
                        'terms_accepted' => true,
                        'legal_version' => $currentTerm?->version,
                        'credentials_separated' => true,
                        'zone' => ZoneAssignmentService::ZONE_UNKNOWN,
                    ],
                ]);

                return $user->fresh();
            });
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'REGISTRATION_IDENTITY_ALREADY_USED') {
                return response()->json([
                    'success' => false, 'code' => $code,
                    'message' => 'الهاتف أو البريد أصبح مستخدماً في حساب آخر.',
                ], 409);
            }
            if (in_array($code, ['OTP_NOT_VERIFIED', 'OTP_ALREADY_USED', 'VERIFICATION_EXPIRED', 'VERIFICATION_INVALID'], true)) {
                return response()->json([
                    'success' => false, 'code' => $code,
                    'message' => 'جلسة تحقق البريد غير صالحة أو انتهت. أعد التحقق وحاول مرة أخرى.',
                ], 422);
            }
            throw $e;
        }

        $token = null;
        try {
            $token = $user->createToken('AmialBasicWallet-' . Str::ulid())->accessToken;
        } catch (\Throwable $e) {
            \Log::warning('Quick registration token issue failed', [
                'user_id' => $user->id, 'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'مبروك، تم إنشاء محفظة أميال باي.',
            'data' => [
                'user_id' => (int) $user->id,
                'wallet_created' => true,
                'transaction_pin_configured' => true,
                'kyc_tier' => 0,
                'tier_name' => 'عميل غير موثق',
                'access_token' => $token,
                'token_type' => $token ? 'Bearer' : null,
                'next_steps' => [
                    'verify_phone',
                    'verify_residence',
                    'upgrade_identity_optional',
                ],
                'financial_status' => 'pending_phone_and_residence_verification',
            ],
        ], 201);
    }
}
