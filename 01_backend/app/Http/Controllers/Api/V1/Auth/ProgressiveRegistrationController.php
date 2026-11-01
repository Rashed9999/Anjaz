<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailIdentityService;
use App\Services\Kyc\LegalNameService;
use App\Services\LegalTermsService;
use App\Services\Otp\EmailOtpService;
use App\Services\ZoneAssignmentService;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use App\Services\Muwathaq\MuwathaqRegistrationContractService;
use App\Services\Muwathaq\MuwathaqOpeningProgressService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
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
        private readonly LegalNameService $legalNames,
        private readonly MuwathaqAccountOpeningService $muwathaq,
        private readonly MuwathaqRegistrationContractService $contracts,
        private readonly MuwathaqOpeningProgressService $openingProgress,
    ) {}

    public function register(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ',
            'message' => 'إنشاء الحساب يتم حصراً عبر موثّق؛ أميال باي لا ينشئ حسابات جديدة.',
            'muwathaq_url' => rtrim((string) config('muwathaq.base_url'), '/').'/open-account?type=customer',
        ], 410);
        $validator = Validator::make($request->all(), [
            // الاسم هنا ليس لقب عرض. هو الاسم القانوني المُصرّح به
            // الذي سنقارنه بإثبات السكن ثم الهوية.
            'given_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'father_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'grandfather_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'family_name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            // ليس اختيار سياسة خصوصية في الواجهة: يحدد الخادم المسار تلقائياً.
            'gender' => ['required', 'in:male,female'],
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

        // لا ننشئ حساباً إذا تعذر الحصول على سياسة موثّق المعتمدة.
        // هذا يمنع اختلاف التطبيق عن البوابة عند تعديل سياسة النساء أو العميل القياسي.
        try {
            $contract = $this->contracts->resolve('customer', (string) $request->input('gender'));
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'MUWATHAQ_POLICY_UNAVAILABLE',
                'message' => $e->getMessage(),
            ], 503);
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

        $given = trim((string) $request->input('given_name'));
        $father = trim((string) $request->input('father_name'));
        $grandfather = trim((string) $request->input('grandfather_name'));
        $family = trim((string) $request->input('family_name'));

        try {
            $application = null;
            $user = DB::transaction(function () use ($request, $email, $phone, $given, $father, $grandfather, $family, $contract, &$application): User {
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
                $user->f_name = $given;
                $user->father_name = $father;
                $user->grandfather_name = $grandfather;
                $user->family_name = $family;
                $user->l_name = $family; // توافق الشاشات القديمة
                $user->declared_legal_name = $this->legalNames->compose([
                    'given_name' => $given,
                    'father_name' => $father,
                    'grandfather_name' => $grandfather,
                    'family_name' => $family,
                ]);
                $user->legal_name_status = 'declared';
                $user->dial_country_code = (string) $request->input('dial_country_code');
                $user->phone = $phone;
                $user->email = $email;
                $user->gender = (string) $request->input('gender');
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

                if (Schema::hasTable('legal_name_events')) {
                    DB::table('legal_name_events')->insert([
                        'user_id' => $user->id,
                        'event_type' => 'DECLARED_AT_REGISTRATION',
                        'source' => 'registration',
                        'old_name_encrypted' => null,
                        'new_name_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString(
                            (string) $user->declared_legal_name
                        ),
                        'document_id' => null,
                        'reviewer_id' => null,
                        'match_status' => null,
                        'match_score' => null,
                        'reason' => null,
                        'created_at' => now(),
                    ]);
                }

                if (Schema::hasColumn('users', 'unique_id')) {
                    $user->unique_id = $user->id . random_int(1111, 99999);
                    $user->save();
                }

                $this->identities->markCurrentEmailVerified($user);
                $application = $this->muwathaq->submitFor($user);
                $this->contracts->assertMatchesApplication($contract, $application);

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
                    'action' => 'ACCOUNT_OPENING_SUBMITTED',
                    'decision_code' => 'MUWATHAQ_ACCOUNT_OPENING',
                    'severity' => 'info',
                    'context' => [
                        'registration_mode' => 'quick',
                        'declared_legal_name_parts' => 4,
                        'legal_name_status' => 'declared',
                        'email_verified' => true,
                        'phone_verified' => false,
                        'verification_path' => $application->verification_path,
                        'application_ulid' => $application->application_ulid,
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

        $openingAccessToken = $this->openingProgress->issueAccessToken($application);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب فتح الحساب إلى موثّق للمراجعة.',
            'data' => [
                'user_id' => (int) $user->id,
                'wallet_created' => false,
                'account_status' => 'submitted',
                'muwathaq_application_ulid' => $application?->application_ulid,
                // token مقيد بطلب الفتح فقط؛ لا يمثل جلسة دخول أو محفظة.
                'account_opening_access_token' => $openingAccessToken,
                'account_opening_access_expires_at' => $application?->fresh()->opening_access_expires_at?->toIso8601String(),
                'verification_path' => $application?->verification_path,
                'transaction_pin_configured' => true,
                'declared_legal_name' => (string) $user->declared_legal_name,
                'legal_name_status' => (string) $user->legal_name_status,
                'next_steps' => ['confirm_phone', 'submit_residence'],
                'financial_status' => 'wallet_not_created_until_approved',
            ],
        ], 201);
    }
}
