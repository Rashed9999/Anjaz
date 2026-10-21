<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Verticals\VerticalRegistry;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailIdentityService;
use App\Services\Kyc\LegalNameService;
use App\Services\LegalTermsService;
use App\Services\Merchant\MerchantNumberService;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use App\Services\Muwathaq\MuwathaqRegistrationContractService;
use App\Services\Otp\EmailOtpService;
use App\Services\Vertical\VerticalBootstrapService;
use App\Services\ZoneAssignmentService;
use App\Support\Access\AccessConstants;
use App\Support\Phone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * AMIAL-MERCHANT-OPENING-002
 *
 * تسجيل المنشأة لا يمر بمعالج KYC القديم. القطاع يحدد تجهيز لوحة التاجر
 * وقدراتها فقط، بينما متطلبات الاعتماد يملكها موثّق بعقد مستقل.
 */
class MerchantAccountOpeningController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly EmailIdentityService $identities,
        private readonly LegalNameService $legalNames,
        private readonly LegalTermsService $legalTerms,
        private readonly MuwathaqAccountOpeningService $muwathaq,
        private readonly MuwathaqRegistrationContractService $contracts,
        private readonly MerchantNumberService $merchantNumbers,
        private readonly VerticalBootstrapService $verticals,
        private readonly AuditService $audit,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $codes = implode(',', VerticalRegistry::codes());
        $validator = Validator::make($request->all(), [
            'store_name' => ['required', 'string', 'min:2', 'max:120'],
            'business_type' => ['required', 'in:'.$codes],
            'given_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'father_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'grandfather_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'family_name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'dial_country_code' => ['required', 'string', 'max:8'],
            'phone' => ['required', 'string', 'min:5', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
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
                    if (in_array((string) $value, ['0000','1111','2222','3333','4444','5555','6666','7777','8888','9999','1234','4321','0123'], true)) {
                        $fail('اختر رمز PIN غير متسلسل وغير مكرر.');
                    }
                },
            ],
            'email_challenge_id' => ['required', 'string', 'size:26'],
            'email_verification_token' => ['required', 'string', 'min:32', 'max:200'],
            'terms_accepted' => ['required', 'accepted'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $businessType = (string) $request->input('business_type');
        try {
            $contract = $this->contracts->resolve('merchant', null, $businessType);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'MUWATHAQ_POLICY_UNAVAILABLE',
                'message' => $e->getMessage(),
            ], 503);
        }

        $email = $this->identities->normalize((string) $request->input('email'));
        $phone = Phone::canonical((string) $request->input('dial_country_code').(string) $request->input('phone'));

        if (!$this->identities->isAvailable($email)) {
            return response()->json(['success' => false, 'code' => 'EMAIL_ALREADY_USED', 'message' => 'البريد الإلكتروني مستخدم في حساب آخر.'], 409);
        }
        if (User::whereIn('phone', Phone::variants($phone))->exists()) {
            return response()->json(['success' => false, 'code' => 'PHONE_ALREADY_USED', 'message' => 'رقم الهاتف مستخدم في حساب آخر.'], 409);
        }

        $given = trim((string) $request->input('given_name'));
        $father = trim((string) $request->input('father_name'));
        $grandfather = trim((string) $request->input('grandfather_name'));
        $family = trim((string) $request->input('family_name'));

        try {
            $application = null;
            $merchantNumber = null;
            $user = DB::transaction(function () use ($request, $contract, $businessType, $email, $phone, $given, $father, $grandfather, $family, &$application, &$merchantNumber): User {
                $challenge = $this->otp->consumeVerification(
                    (string) $request->input('email_challenge_id'),
                    $email,
                    EmailOtpService::PURPOSE_REGISTRATION,
                    (string) $request->input('email_verification_token'),
                );

                if (!$this->identities->isAvailable($email)
                    || User::whereIn('phone', Phone::variants($phone))->lockForUpdate()->exists()) {
                    throw new RuntimeException('REGISTRATION_IDENTITY_ALREADY_USED');
                }

                $user = new User();
                $user->f_name = $given;
                $user->father_name = $father;
                $user->grandfather_name = $grandfather;
                $user->family_name = $family;
                $user->l_name = $family;
                $user->declared_legal_name = $this->legalNames->compose([
                    'given_name' => $given, 'father_name' => $father,
                    'grandfather_name' => $grandfather, 'family_name' => $family,
                ]);
                $user->legal_name_status = 'declared';
                $user->dial_country_code = (string) $request->input('dial_country_code');
                $user->phone = $phone;
                $user->email = $email;
                $user->image = null;
                $user->password = bcrypt((string) $request->input('password'));
                $user->type = MERCHANT_TYPE;
                if (Schema::hasColumn('users', 'role')) {
                    $user->role = AccessConstants::ROLE_MERCHANT;
                }
                if (Schema::hasColumn('users', 'is_active')) {
                    $user->is_active = 0;
                }
                $user->is_phone_verified = 0;
                $user->is_kyc_verified = 0;
                if (Schema::hasColumn('users', 'identification_image')) $user->identification_image = json_encode([]);
                if (Schema::hasColumn('users', 'transaction_pin')) $user->transaction_pin = (string) $request->input('transaction_pin');
                if (Schema::hasColumn('users', 'transaction_pin_set_at')) $user->transaction_pin_set_at = now();
                if (Schema::hasColumn('users', 'requires_pin_setup')) $user->requires_pin_setup = false;
                if (Schema::hasColumn('users', 'kyc_tier')) $user->kyc_tier = 0;
                if (Schema::hasColumn('users', 'zone_code')) $user->zone_code = ZoneAssignmentService::ZONE_UNKNOWN;
                $user->save();

                if (Schema::hasColumn('users', 'unique_id')) {
                    $user->unique_id = $user->id.random_int(1111, 99999);
                    $user->save();
                }

                $merchant = new Merchant();
                $merchant->user_id = $user->id;
                $merchant->store_name = trim((string) $request->input('store_name'));
                $merchant->address = '—';
                $this->merchantNumbers->assignTo($merchant);
                $merchantNumber = $merchant->merchant_number;

                MerchantProfile::query()->firstOrCreate(['user_id' => $user->id], [
                    'business_type' => $businessType,
                    'verification_status' => 'unverified', // حالة ملف التاجر؛ طلب موثّق محفوظ منفصلاً كـ submitted
                    'zone_code' => ZoneAssignmentService::ZONE_UNKNOWN,
                    'subscription_plan' => AccessConstants::PLAN_FREE,
                ]);
                $this->verticals->ensureFor($user);

                $this->identities->markCurrentEmailVerified($user);
                $application = $this->muwathaq->submitFor($user, $businessType);
                $this->contracts->assertMatchesApplication($contract, $application);

                $locale = (string) $request->input('locale', 'ar');
                if ($term = $this->legalTerms->currentTerm($locale)) {
                    $this->legalTerms->accept($user, $term, $request->ip(), (string) $request->userAgent(), $request->header('X-Device-Id'));
                }

                DB::table('otp_challenges')->where('id', $challenge->id)->update([
                    'user_id' => $user->id, 'updated_at' => now(),
                ]);

                if (Schema::hasTable('legal_name_events')) {
                    DB::table('legal_name_events')->insert([
                        'user_id' => $user->id,
                        'event_type' => 'DECLARED_AT_REGISTRATION',
                        'source' => 'merchant_account_opening',
                        'old_name_encrypted' => null,
                        'new_name_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString((string) $user->declared_legal_name),
                        'document_id' => null, 'reviewer_id' => null, 'match_status' => null,
                        'match_score' => null, 'reason' => null, 'created_at' => now(),
                    ]);
                }

                $this->audit->record([
                    'actor_type' => 'merchant_owner', 'actor_user_id' => (int) $user->id,
                    'subject_type' => 'merchant', 'subject_id' => (string) $user->id,
                    'action' => 'ACCOUNT_OPENING_SUBMITTED', 'decision_code' => 'MUWATHAQ_ACCOUNT_OPENING',
                    'severity' => 'info',
                    'context' => [
                        'registration_mode' => 'merchant_web_contract',
                        'business_type' => $businessType,
                        'verification_path' => $application->verification_path,
                        'policy_version' => $contract['policy_version'],
                        'wallet_created' => false,
                    ],
                ]);

                return $user->fresh();
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'REGISTRATION_IDENTITY_ALREADY_USED') {
                return response()->json(['success' => false, 'code' => $e->getMessage(), 'message' => 'الهاتف أو البريد أصبح مستخدماً في حساب آخر.'], 409);
            }
            if (in_array($e->getMessage(), ['OTP_NOT_VERIFIED', 'OTP_ALREADY_USED', 'VERIFICATION_EXPIRED', 'VERIFICATION_INVALID'], true)) {
                return response()->json(['success' => false, 'code' => $e->getMessage(), 'message' => 'جلسة تحقق البريد غير صالحة أو انتهت.'], 422);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب فتح المنشأة إلى موثّق. ستُفعّل لوحة القطاع بعد الاعتماد.',
            'data' => [
                'user_id' => (int) $user->id,
                'merchant_number' => $merchantNumber,
                'wallet_created' => false,
                'account_status' => 'submitted',
                'muwathaq_application_ulid' => $application?->application_ulid,
                'verification_path' => $application?->verification_path,
                'business_type' => $businessType,
                'policy_version' => $contract['policy_version'],
            ],
        ], 201);
    }
}
