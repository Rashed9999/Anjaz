<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Traits\UploadSizeHelperTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RegisterController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private User $user,
        private PhoneVerification $phoneVerification,
        private EMoney $eMoney
    ){}

    // $verifiedEmail is supplied ONLY by EmailRegistrationController after
    // atomic proof consumption; request input never authorizes this argument.
    public function customerRegistration(Request $request, ?string $verifiedEmail = null): JsonResponse
    {
        // التسجيل بمساعدة موظف لا يفتح محفظة خلف ظهر صاحب الرقم: يُستعاد
        // ما كتبه الموظف *قبل* التحقق، ثم يبقى OTP وPIN إلزاميين في هذا
        // المسار نفسه. مدخلات العميل الحالية تتقدّم دائماً على المسودة.
        if ($request->filled('dial_country_code') && $request->filled('phone')) {
            $phoneForDossier = \App\Support\Phone::canonical(
                (string) $request->input('dial_country_code') . (string) $request->input('phone')
            );
            $dossierType = $request->input('account_type') === 'merchant' ? 'merchant' : 'customer';
            $prefill = app(\App\Services\RegistrationDossierService::class)
                ->prefillForPhone($dossierType, $phoneForDossier);
            if ($prefill) {
                $request->merge(array_replace($prefill, $request->all()));
            }
        }

        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $validator = Validator::make($request->all(), [
            // AMIAL-LEGAL-NAME-001 — التسجيل الذاتي لا يقبل لقب عرض حر.
            // الأجزاء الأربعة مطلوبة حتى في الباب القديم.
            'f_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'father_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'grandfather_name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'family_name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\pM\s\-\x27]+$/u'],
            'l_name' => 'sometimes|nullable|string|max:80',
            'image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'gender' => 'required',
            'occupation' => 'nullable',
            'dial_country_code' => 'required',
            'phone' => [
                'required',
                Rule::unique('users')->where(function ($query) {
                    return $query->whereNull('deleted_at');
                }),
                'min:5',
                'max:20',
            ],
            'email' => 'required|email|max:255',
            'password' => 'required|min:4|max:4',
            // P0-CREDENTIAL-SEPARATION — هذا endpoint قديم وتستعمله نسخ
            // سابقة. لا نكسره، لكن لا نسمح له بعد اليوم بنسخ كلمة الدخول
            // إلى PIN. النسخة الحديثة ترسل transaction_pin صراحةً؛ القديمة
            // تُنشئ الحساب مع requires_pin_setup=true ولا تحرّك المال.
            'transaction_pin' => [
                'sometimes', 'nullable', 'digits_between:4,6', 'different:password',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $weak = ['0000','1111','2222','3333','4444','5555','6666','7777','8888','9999','1234','4321','0123'];
                    if ($value !== null && in_array((string) $value, $weak, true)) {
                        $fail('اختر رمز PIN غير متسلسل وغير مكرر.');
                    }
                },
            ],
            // AMIAL-SIGNATURE-001: التوقيع الإلكتروني (base64 PNG مرسوم على الشاشة) —
            // اختياري للتوافق الخلفي، ويُحفَظ مشفّراً كسجلّ قانوني لفتح الحساب.
            'signature' => 'nullable|string|max:3000000',
            // AMIAL-REG-WIZARD: حقول التسجيل متعدّد الخطوات (اختيارية توافقاً)
            'identification_number' => 'sometimes|nullable|string|max:50',
            'identification_type' => 'sometimes|nullable|in:passport,driving_licence,nid,trade_license',
            'identification_image' => 'sometimes|nullable|array',
            'address' => 'sometimes|nullable|string|max:500',
            // AMIAL-GOVERNORATES-001: محافظتا الأصل (من الهوية) والسكن (من
            // وثيقة العنوان). المنطقة التشغيلية تتبع السكن، والأصل إشارة
            // يقارنها المراجع البشري.
            'origin_governorate' => 'sometimes|nullable|string|max:64',
            'residence_governorate' => 'sometimes|nullable|string|max:64',
            'kin_name' => 'sometimes|nullable|string|max:150',
            'kin_phone' => 'sometimes|nullable|string|max:30',
            'kin_relation' => 'sometimes|nullable|string|max:60',

            // ══════════════════════════════════════════════════════════
            // AMIAL-KYC-INTL-001 — حقولُ «اعرف عميلك» الرقابيّة.
            //
            // **كلُّها `sometimes`** — مئاتُ الحسابات قائمةٌ بلا هذه
            // البيانات، وإلزامُها هنا يُقفل التسجيلَ على من سبق الميزة.
            // **وشاشةُ اعتماد الهويّة هي التي تطالب بها**، فالإلزامُ
            // موضعُه بوّابةُ الاعتماد لا بوّابةُ الدخول.
            // ══════════════════════════════════════════════════════════
            'name_en' => 'sometimes|nullable|string|max:150|regex:/^[A-Za-z\s.\-\x27]+$/',
            'country_of_birth' => 'sometimes|nullable|string|max:60',
            'dual_nationality' => 'sometimes|nullable|string|max:60',
            'id_place_of_issue' => 'sometimes|nullable|string|max:80',
            'marital_status' => 'sometimes|nullable|in:single,married,divorced,widowed',
            'residence_district' => 'sometimes|nullable|string|max:80',
            'residence_area' => 'sometimes|nullable|string|max:120',
            'residence_landmark' => 'sometimes|nullable|string|max:150',
            'housing_type' => 'sometimes|nullable|in:owned,rented,family,other',
            'employer_name' => 'sometimes|nullable|string|max:150',
            'job_title' => 'sometimes|nullable|string|max:80',
            'work_address' => 'sometimes|nullable|string|max:200',
            'income_source' => 'sometimes|nullable|in:'
                . implode(',', \App\Support\Kyc\KycProfileFields::INCOME_SOURCES),
            'account_purpose' => 'sometimes|nullable|in:'
                . implode(',', \App\Support\Kyc\KycProfileFields::ACCOUNT_PURPOSES),
            'monthly_income' => 'sometimes|nullable|numeric|min:0|max:9999999999999',
            'kin2_name' => 'sometimes|nullable|string|max:150',
            'kin2_phone' => 'sometimes|nullable|string|max:30',
            'kin2_relation' => 'sometimes|nullable|string|max:60',
            // **وثلاثيُّ الحالة**: غيابُ الحقل «لم يُسأل»، لا «لا».
            'is_pep' => 'sometimes|nullable|boolean',
            'pep_position' => 'sometimes|nullable|string|max:200',
            'declaration_accepted' => 'sometimes',
            'date_of_birth' => 'sometimes|nullable|date',
            'identification_issue_date' => 'sometimes|nullable|date',
            'identification_expiry_date' => 'sometimes|nullable|date',
            // AMIAL-REG-ROLES: التسجيل الذاتي للأدوار الثلاثة من نفس المعالج —
            // الحساب يُنشأ «قيد التحقق» (kyc=0) ويظهر في لوحة التحقق للاعتماد.
            'account_type' => 'sometimes|nullable|in:customer,merchant,agent',
            'store_name' => 'sometimes|nullable|string|max:120',
            'business_type' => 'sometimes|nullable|in:' . implode(',', \App\Domain\Verticals\VerticalRegistry::codes()),
        ]);


        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $phone = \App\Support\Phone::canonical($request->dial_country_code . $request->phone);
        $customerPhone = $this->user->whereIn('phone', \App\Support\Phone::variants($phone))->first();
        if (isset($customerPhone)){
            return response()->json(['errors' => [
                ['code' => 'phone', 'message' => 'This phone number is already taken.']
            ]], 403);
        }

        $verify = null;
        $phoneOwnershipVerified = false;
        $emailAuthorized = $verifiedEmail !== null
            && hash_equals($verifiedEmail, (string) $request->input('email'));

        if (! $emailAuthorized && Helpers::get_business_settings('phone_verification') == 1) {
            if (!$request->has('otp')) {
                return response()->json(['errors' => [
                    ['code' => 'otp', 'message' => 'OTP is required.']
                ]], 403);
            }

            $policy = app(\App\Services\Otp\OtpPolicy::class);
            $submittedOtp = (string) $request->input('otp');

            // AMIAL-PHONE-OWNERSHIP-REG-001
            //
            // مسار التسجيل القديم كان يتحقق من الرمز ثم يرمي الدليل:
            // يحذف phone_verifications وينشئ الحساب مع is_phone_verified=0.
            // النتيجة أن KYC يرفض الحساب لاحقاً رغم أن صاحبه أثبت الرقم.
            //
            // في Pilot الحالي كل هاتف عميل يستخدم الرمز المرحلي 123456
            // عبر OtpPolicy::pilotCustomerPhoneCode(). أرقام العرض تبقى على
            // demoCode، وما عدا ذلك عند إطفاء Pilot يجب أن يطابق تحدياً
            // مخزناً وصل عبر المزود الحقيقي.
            $pilotOtp = $policy->pilotCustomerPhoneCode();
            $demoOtp = $policy->isDemo($phone) ? $policy->demoCode() : null;

            if ($pilotOtp !== null && hash_equals($pilotOtp, $submittedOtp)) {
                $phoneOwnershipVerified = true;
            } elseif ($demoOtp !== null && hash_equals($demoOtp, $submittedOtp)) {
                $phoneOwnershipVerified = true;
            } else {
                $verify = $this->phoneVerification
                    ->whereIn('phone', \App\Support\Phone::variants($phone))
                    ->where('otp', $submittedOtp)
                    ->first();

                if (!isset($verify)) {
                    return response()->json(['errors' => [
                        ['code' => 'otp', 'message' => 'OTP is not found!']
                    ]], 404);
                }

                $phoneOwnershipVerified = true;
            }
        }

        // AMIAL-SIGNATURE-001: التقاط التوقيع الإلكتروني وتخزينه مشفّراً (خارج المعاملة
        // لأنّه IO ملفّات) — نمرّر المسار للحفظ داخل المعاملة.
        $signaturePath = $this->storeSignature($request->input('signature'));

        // AMIAL-REG-ROLES: نوع الحساب المطلوب (افتراضياً عميل)
        $accountType = match ($request->input('account_type')) {
            'merchant' => MERCHANT_TYPE,
            'agent' => AGENT_TYPE,
            default => CUSTOMER_TYPE,
        };
        if ($accountType === MERCHANT_TYPE && trim((string) $request->input('store_name', '')) === '') {
            return response()->json(['errors' => [
                ['code' => 'store_name', 'message' => 'اسم المتجر مطلوب لحساب التاجر'],
            ]], 403);
        }

        $loginNumbers = ['agent_number' => null, 'merchant_number' => null];
        DB::transaction(function () use ($request, $verify, $phone, $phoneOwnershipVerified, $signaturePath, $accountType, &$loginNumbers) {
            $verify?->delete();

            // A reused controller must never mutate the previous registrant.
            $user = $this->user->newInstance();
            $user->f_name = trim((string) $request->f_name);
            $user->father_name = trim((string) $request->father_name);
            $user->grandfather_name = trim((string) $request->grandfather_name);
            $user->family_name = trim((string) $request->family_name);
            $user->l_name = $user->family_name;
            $user->declared_legal_name = app(\App\Services\Kyc\LegalNameService::class)->compose([
                'given_name' => $user->f_name,
                'father_name' => $user->father_name,
                'grandfather_name' => $user->grandfather_name,
                'family_name' => $user->family_name,
            ]);
            $user->legal_name_status = 'declared';
            $user->image = $request->has('image') ? Helpers::upload('customer/', APPLICATION_IMAGE_FORMAT, $request->file('image')) : null;
            $user->gender = $request->gender;
            $user->occupation = $request->occupation;
            $user->dial_country_code = $request->dial_country_code;
            $user->phone = $phone;
            $user->email = $request->email;
            $user->identification_image = json_encode([]);
            $user->password = bcrypt($request->password);
            $user->type = $accountType;
            $user->referral_id = $request->referral_id ?? null;
            // لا fallback إلى كلمة المرور. إن كانت نسخة العميل حديثة تضبط
            // PIN مستقلاً الآن؛ وإلا يبقى الحساب بحاجة إعداد PIN صريح.
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'transaction_pin')) {
                $pin = $request->filled('transaction_pin')
                    ? (string) $request->input('transaction_pin')
                    : null;
                $user->transaction_pin = $pin;
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'transaction_pin_set_at')) {
                    $user->transaction_pin_set_at = $pin !== null ? now() : null;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'requires_pin_setup')) {
                    $user->requires_pin_setup = $pin === null;
                }
            }
            if ($accountType === AGENT_TYPE && \Illuminate\Support\Facades\Schema::hasColumn('users', 'agent_number')) {
                $user->agent_number = sprintf('AG-%03d', User::where('type', AGENT_TYPE)->count() + 1);
            }
            if ($signaturePath) {
                $user->signature_encrypted_path = $signaturePath;
                $user->signature_captured_at = now();
            }

            // AMIAL-REG-WIZARD: حقول التسجيل متعدّد الخطوات (هوية/عنوان/قريب/إقرار)
            if ($request->filled('identification_number')) {
                $user->identification_number = $request->identification_number;
            }
            if ($request->filled('identification_type')) {
                $user->identification_type = $request->identification_type;
            }
            if (is_array($request->identification_image) && !empty($request->identification_image)) {
                $imgs = [];
                foreach ($request->identification_image as $img) {
                    try {
                        $imgs[] = Helpers::file_uploader('user/identity/', 'png', $img);
                    } catch (\Throwable $e) { /* تجاهل صورة تالفة */ }
                }
                if (!empty($imgs)) {
                    $user->identification_image = json_encode($imgs);
                }
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'address') && $request->filled('address')) {
                $user->address = $request->address;
            }
            foreach (['kin_name', 'kin_phone', 'kin_relation'] as $kc) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', $kc) && $request->filled($kc)) {
                    $user->{$kc} = $request->input($kc);
                }
            }

            // ══════════════════════════════════════════════════════════
            // AMIAL-KYC-INTL-001 — **وحقلٌ يُطلَب في النموذج ولا يُحفَظ
            // أسوأ من غيابه**: يُوهم بأنّ البيانَ عندنا فلا يُطلَب ثانية.
            //
            // ويُقرأ الجردُ من مصدرٍ واحد (`KycProfileFields`) لا يُكتب
            // ها هنا — فقائمتان تفترقان بحقلٍ تُنتجان حقلاً يُرسَل ولا
            // يصل، وهو عطلٌ صامت.
            // ══════════════════════════════════════════════════════════
            \App\Support\Kyc\KycProfileFields::fill($user, $request);
            // AMIAL-GOVERNORATES-001: نخزّن رمز ISO لا النصّ الحرّ — الرمز
            // يُقارَن ويُفهرَس، والنصّ الحرّ («صنعا»، «Sanaa»، «امانة العاصمة»)
            // لا يُقارَن بشيء. codeFromName يبتلع الصيغ كلها.
            foreach (['origin_governorate', 'residence_governorate'] as $gc) {
                if (!\Illuminate\Support\Facades\Schema::hasColumn('users', $gc)) {
                    continue;
                }
                $code = \App\Support\YemenGovernorates::codeFromName(
                    (string) $request->input($gc, '')
                );
                if ($code !== null) {
                    $user->{$gc} = $code;
                }
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_declaration_accepted')
                && in_array((string) $request->declaration_accepted, ['1', 'true'], true)) {
                $user->kyc_declaration_accepted = true;
                $user->kyc_declared_at = now();
            }
            // AMIAL-KYC-003: تواريخ (ميلاد + إصدار/انتهاء الهوية)
            foreach ([
                'date_of_birth', 'identification_issue_date', 'identification_expiry_date',
            ] as $dc) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', $dc) && $request->filled($dc)) {
                    $user->{$dc} = $request->input($dc);
                }
            }
            $user->is_kyc_verified = 0; // بانتظار مراجعة الإدارة (لوحة التحقق)

            // نجاح OTP في هذه المعاملة هو إثبات ملكية الهاتف، وليس مجرد
            // شرط مرور مؤقت. نحفظ الحقيقة على الحساب قبل أن يختفي التحدي.
            if ($phoneOwnershipVerified) {
                $user->is_phone_verified = 1;
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_tier')) {
                    $user->kyc_tier = max(1, (int) ($user->kyc_tier ?? 0));
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_tier_updated_at')) {
                    $user->kyc_tier_updated_at = now();
                }
            }

            $user->save();

            if ($phoneOwnershipVerified) {
                app(\App\Services\AuditService::class)->record([
                    'actor_type' => 'customer',
                    'actor_user_id' => (int) $user->id,
                    'subject_type' => 'user',
                    'subject_id' => (string) $user->id,
                    'action' => 'PHONE_OWNERSHIP_VERIFIED',
                    'decision_code' => 'PHONE_OTP_VERIFIED_AT_REGISTRATION',
                    'severity' => 'info',
                    'context' => [
                        'phone_verified' => true,
                        'source' => 'registration',
                    ],
                ]);
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('legal_name_events')) {
                \Illuminate\Support\Facades\DB::table('legal_name_events')->insert([
                    'user_id' => $user->id,
                    'event_type' => 'DECLARED_AT_REGISTRATION',
                    'source' => 'legacy_self_registration',
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

            $user->find($user->id);
            $user->unique_id = $user->id . mt_rand(1111, 99999);
            $user->save();

            // AMIAL-SELFREG-KYCDOCS-001 — انظر `ingestKycDocuments` أسفله.
            $this->ingestKycDocuments($user, $request);

            // ══════════════════════════════════════════════════════════
            // AMIAL-ZONE-REG-001 — **إسنادُ المنطقة عند التسجيل.**
            //
            // `ZoneAssignmentService::assignOnRegistration()` مبنيّةٌ منذ
            // v2.0 **ولم يُنادِها أحدٌ قطّ** — قِيس: صفرُ صفوفٍ في
            // `zone_assignment_logs`. فكلُّ حسابٍ يولد `UNKNOWN` **بلا
            // أثرٍ يقول لماذا ولا إشاراتٍ تُبنى عليها**.
            //
            // **والمنطقةُ تبقى `UNKNOWN` عمداً** — «ممنوعٌ حتّى يثبت»،
            // وتُحسم عند اعتماد التوثيق بـ`assignFromKyc`. لكنّ الفرقَ
            // كبير: الإشاراتُ (المحافظةُ المصرَّحة، عنوانُ التسجيل،
            // مقدّمةُ الرقم) تُلتقط الآن وتُحفظ، **فيرى المدقّقُ على أيّ
            // شيءٍ يبني قرارَه** بدل أن يبدأ من فراغ.
            //
            // ولا تُعطَّل: فشلُ إسنادٍ لا يجوز أن يمنع إنشاء حساب.
            try {
                app(\App\Services\ZoneAssignmentService::class)
                    ->assignOnRegistration($user, $request);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('zone assignment on registration failed', [
                    'user_id' => $user->id, 'error' => $e->getMessage(),
                ]);
            }

            $emoney = $this->eMoney->newInstance();
            $emoney->user_id = $user->id;
            $emoney->save();

            // AMIAL-REG-ROLES: سجلات التاجر — رقم دخوله + ملف «قيد التحقق»
            if ($accountType === MERCHANT_TYPE) {
                $mr = new \App\Models\Merchant();
                $mr->user_id = $user->id;
                $mr->store_name = trim((string) $request->input('store_name'));
                $mr->address = trim((string) $request->input('address', '')) ?: '—';

                // AMIAL-MERCHANT-NUMBER-001 — **ستّةُ أرقامٍ عشوائيّةٍ بلا
                // صفر.** وكان `sprintf('M-%05d', $user->id)` — مشتقّاً من
                // رقم المستخدم، فيُفشي عدَّ التجّار ويُخمَّن جارُه.
                // و`assignTo` تحفظ الصفَّ وتعيد المحاولةَ على تصادم
                // القيد الفريد.
                app(\App\Services\Merchant\MerchantNumberService::class)->assignTo($mr);

                $loginNumbers['merchant_number'] = $mr->merchant_number;

                \App\Models\MerchantProfile::firstOrCreate(['user_id' => $user->id], [
                    'business_type' => $request->input('business_type')
                        ?: \App\Support\Access\AccessConstants::BIZ_RETAIL,
                    'verification_status' => 'pending_review',
                    'zone_code' => 'SOUTH',
                    'subscription_plan' => \App\Support\Access\AccessConstants::PLAN_FREE,
                ]);

                // AMIAL-VERTICAL-BOOTSTRAP-001 — **البابُ الثاني.**
                //
                // الحساباتُ تُنشأ من ثلاثة أبواب: اللوحةُ، والتسجيلُ
                // الذاتيّ هذا، وأمرُ حسابات العرض. وإصلاحُ بابٍ واحدٍ
                // يترك البقيّةَ على العطل نفسه — فالقطاعُ يُبنى حيثما
                // يُكتب `business_type`.
                app(\App\Services\Vertical\VerticalBootstrapService::class)
                    ->ensureFor($user);
            }
            if ($accountType === AGENT_TYPE) {
                $loginNumbers['agent_number'] = $user->agent_number;
            }

            $dossierType = $accountType === MERCHANT_TYPE ? 'merchant' : 'customer';
            $dossierService = app(\App\Services\RegistrationDossierService::class);
            $claimed = $dossierService->claimForConfirmedRegistration($dossierType, $phone, $user);
            // التسجيل الذاتي يستحق أرشفة قابلة للطباعة هو أيضاً. أما إن بدأ
            // الملف لدى موظف فلا ننشئ نسخة ثانية؛ يبقى مرجع الموظف هو الأصل.
            if (!$claimed && $accountType !== AGENT_TYPE) {
                // نفس مخطط الملف الذي تستعمله لوحة الموظف؛ لا نطبع نسخة
                // مبتورة للتسجيل الإلكتروني ثم ندّعي أن الأرشيف موحّد.
                $dossierPayload = $request->only([
                    'dial_country_code', 'phone', 'gender', 'email', 'name_en', 'father_name', 'grandfather_name', 'family_name',
                    'date_of_birth', 'country_of_birth', 'dual_nationality', 'marital_status',
                    'identification_type', 'identification_number', 'identification_issue_date',
                    'identification_expiry_date', 'id_place_of_issue', 'address', 'origin_governorate',
                    'residence_governorate', 'residence_district', 'residence_area', 'residence_landmark',
                    'housing_type', 'occupation', 'employer_name', 'job_title', 'work_address',
                    'income_source', 'monthly_income', 'monthly_income_currency', 'account_purpose',
                    'is_pep', 'pep_position', 'kin_name', 'kin_phone', 'kin_relation', 'kin2_name',
                    'kin2_phone', 'kin2_relation', 'store_name', 'business_type', 'declaration_accepted',
                ]);
                $dossierPayload += [
                    'full_name' => (string) ($user->declared_legal_name
                        ?: trim((string) ($user->f_name . ' ' . $user->l_name))),
                    'gender' => $user->gender, 'phone' => $phone,
                    'identification_number' => $user->identification_number,
                    'identification_type' => $user->identification_type,
                    'address' => $user->address ?? null,
                    'business_name' => $accountType === MERCHANT_TYPE ? $request->input('store_name') : null,
                    'business_type' => $accountType === MERCHANT_TYPE ? $request->input('business_type') : null,
                    'phone_canonical' => $phone,
                    'subject_type' => $dossierType,
                    'schema_version' => 'opening-dossier-v1',
                ];
                $dossierService->archiveSelfRegistration($dossierType, $phone, $user, $dossierPayload);
            }
        });

        if($request->has('referral_id')) {
            try {
                Helpers::add_refer_commission($request->referral_id);

            } catch (\Exception $e){}
        }

        return response()->json([
            'message' => 'Registration Successful',
            // أرقام الدخول للتاجر/الوكيل — يعرضها التطبيق في شاشة النجاح
            'agent_number' => $loginNumbers['agent_number'],
            'merchant_number' => $loginNumbers['merchant_number'],
            // AMIAL-PROGRESSIVE-KYC-LOGIN-001 — العميل يبدأ نشطاً Tier 0،
            // لا نسمّيه «قيد المراجعة» قبل أن يرسل أصلاً طلب ترقية.
            'verification_status' => $accountType === CUSTOMER_TYPE
                ? 'active_unverified'
                : 'pending_review',
            'kyc_tier' => 0,
            'requires_pin_setup' => !$request->filled('transaction_pin'),
        ], 200);
    }

    /**
     * AMIAL-SIGNATURE-001 — يفكّ توقيعاً إلكترونياً (base64 PNG/JPEG، data-URI أو خام)،
     * يتحقّق أنّه صورة صالحة ضمن حدّ الحجم، ثمّ يخزّنه *مشفّراً* عبر EncryptedFileStorage.
     *
     * @return string|null المسار المشفّر، أو null لو غاب/غير صالح.
     */
    private function storeSignature(?string $base64): ?string
    {
        if (empty($base64)) {
            return null;
        }

        // اقبل data-URI (data:image/png;base64,xxxx) أو base64 خام
        if (preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $base64)) {
            $base64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $base64);
        }
        $binary = base64_decode(strtr(trim($base64), ' ', '+'), true);
        if ($binary === false || strlen($binary) < 64) {
            return null; // ليس base64 صالحاً
        }
        if (strlen($binary) > 2_097_152) {
            return null; // > 2MB — توقيع لا يحتاج أكثر
        }
        // تأكّد أنّه صورة فعلية (لا ملفّ ضارّ)
        $info = @getimagesizefromstring($binary);
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'amial_sig_');
        try {
            file_put_contents($tmp, $binary);
            return app(\App\Services\EncryptedFileStorage::class)
                ->encryptAndStore($tmp, 'signatures');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Signature] store failed', ['error' => $e->getMessage()]);
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    public function agentRegistration(Request $request): JsonResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'l_name' => 'required',
            'image' => 'image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'gender' => 'required',
            'occupation' => 'nullable',
            'dial_country_code' => 'required',
            'phone' => [
                'required',
                Rule::unique('users')->where(function ($query) {
                    return $query->whereNull('deleted_at');
                }),
                'min:5',
                'max:20',
            ],
            'email' => 'required|email|max:255',
            'password' => 'required|min:4|max:4'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $phone = \App\Support\Phone::canonical($request->dial_country_code . $request->phone);
        $agentPhone = $this->user->whereIn('phone', \App\Support\Phone::variants($phone))->first();
        if (isset($agentPhone)){
            return response()->json(['errors' => [
                ['code' => 'phone', 'message' => 'This phone number is already taken.']
            ]], 403);
        }

        $verify = null;
        if(Helpers::get_business_settings('phone_verification') == 1) {
            if($request->has('otp')) {
                // AMIAL-OTP-SPLIT-001: الرمزُ الثابت **لأرقام العرض وحدها**.
                //
                // كان هذا الشرطُ يقبل `123456` من أيّ رقم ما دام المتغيّر
                // مضبوطاً — فمن يعرف العنوان يسجّل باسم رقمٍ لا يملكه،
                // ويصير صاحبَ محفظته. صار الرقمُ هو من يحدّد الطريق.
                $policy = app(\App\Services\Otp\OtpPolicy::class);
                $demoOtp = $policy->isDemo($phone) ? $policy->demoCode() : null;

                if ($demoOtp !== null && hash_equals($demoOtp, (string) $request["otp"])) {
                    $verify = null; // مقبول تجريبياً — لا صفّ للحذف
                } else {
                $verify = $this->phoneVerification->where(["phone" => $phone, "otp" => $request["otp"]])->first();
                if (!isset($verify)) {
                    return response()->json(['errors' => [
                        ["code" => "otp", "message" => "OTP is not found!"]
                    ]], 404);

                }
                }
            }else{
                return response()->json(['errors' => [
                    ['code' => 'otp', 'message' => 'OTP is required.']
                ]], 403);
            }
        }

        DB::transaction(function () use ($request, $verify, $phone) {
            $verify?->delete();

            $user = $this->user->newInstance();
            $user->f_name = $request->f_name;
            $user->l_name = $request->l_name;
            $user->image = $request->has('image') ? Helpers::upload('agent/', APPLICATION_IMAGE_FORMAT, $request->file('image')) : null;
            $user->gender = $request->gender;
            $user->identification_image = json_encode([]);
            $user->occupation = $request->occupation;
            $user->dial_country_code = $request->dial_country_code;
            $user->phone = $phone;
            $user->email = $request->email;
            $user->password = bcrypt($request->password);
            $user->type = AGENT_TYPE;    //['Admin'=>0, 'Agent'=>1, 'Customer'=>2]
            $user->referral_id = null;
            $user->save();

            $user->find($user->id);
            $user->unique_id = $user->id . mt_rand(1111, 99999);
            $user->save();

            // AMIAL-SELFREG-KYCDOCS-001 — انظر `ingestKycDocuments` أسفله.
            $this->ingestKycDocuments($user, $request);

            $emoney = $this->eMoney->newInstance();
            $emoney->user_id = $user->id;
            $emoney->save();
        });

        if($request->has('referral_id')) {
            try {
                Helpers::add_refer_commission($request->referral_id);

            } catch (\Exception $e){}
        }

        return response()->json(['message' => 'Registration Successful'], 200);
    }

    /**
     * AMIAL-SELFREG-KYCDOCS-001 — **نظامان لا يلتقيان، وزرُّ الاعتماد بينهما.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ما قِيس:** التسجيلُ الذاتيُّ يحفظ صورَ الوثائق في
     * `users.identification_image` (‏JSON من مسارات)، و
     * `KycDocumentService::decideAccountVerification` — التي يمرّ بها
     * **زرُّ الاعتماد في لوحة التحقّق** — تشترط صفوفاً في `kyc_documents`
     * من ثلاثة أنواع: وجهُ الهوية · ظهرُها · صورةٌ شخصيّة.
     *
     * فالزرُّ يردّ ٤٢٢ «لا يُعتمد الحسابُ قبل رفع هذه المستندات» على حسابٍ
     * **رفع وثائقَه فعلاً**، ولا سبيلَ في تلك الشاشة إلى رفعها. أي أنّ
     * **كلَّ حسابٍ سجّل ذاتيّاً — عميلاً أو تاجراً أو وكيلاً — لا يمكن
     * اعتمادُه أبداً.** (قِيس بالتشغيل: `SelfRegisteredMerchantIsUsableTest`.)
     *
     * **ولمَ لم يمسكه اختبار:** كلُّ اختبارات الاعتماد ترفع المستنداتِ
     * بيدها عبر `upload()` ثمّ تعتمد — فتفحص المنطقَ وتتخطّى الفجوة.
     * والفجوةُ ليست في طرفٍ منهما بل **في الوصلة بينهما**.
     *
     * ══════════════════════════════════════════════════════════════════
     * **والوصلُ يحتاج نوعاً، والنوعُ لا يُخمَّن.** فمستندٌ يُسجَّل «وجهَ
     * هويّة» وهو ظهرُها يُفسد ملفَّ امتثالٍ بصمت، وهو أسوأُ من غيابه.
     * فتُقبل الحقولُ **مسمّاةً** (`kyc_id_front` …)، والتطبيقُ يرسلها في
     * خاناتٍ معنونة. وما لا نوعَ له يبقى حيث هو ولا يُخترَع له نوع
     * (القاعدة السابعة: «غير معروف» ليس قيمةً تُملأ).
     *
     * **ولا يُسقِط فشلُ مستندٍ تسجيلاً**: الحسابُ أُنشئ، وفقدُ مستندٍ
     * يُعالَج برفعه ثانيةً — لا بإلغاء الحساب. فيُلتقط كلُّ استثناءٍ
     * ويُسجَّل، ويمضي التسجيل.
     */
    private function ingestKycDocuments(\App\Models\User $user, Request $request): void
    {
        $map = [
            'kyc_id_front' => \App\Models\KycDocument::TYPE_ID_FRONT,
            'kyc_id_back' => \App\Models\KycDocument::TYPE_ID_BACK,
            'kyc_selfie' => \App\Models\KycDocument::TYPE_SELFIE,
            'kyc_address_proof' => \App\Models\KycDocument::TYPE_ADDRESS_PROOF,
        ];

        $svc = app(\App\Services\KycDocumentService::class);

        foreach ($map as $field => $docType) {
            if (!$request->hasFile($field)) {
                continue;
            }
            try {
                $svc->upload($user, $docType, $request->file($field));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'AMIAL-SELFREG-KYCDOCS-001: تعذّر إدراج مستند التسجيل',
                    ['user_id' => $user->id, 'doc_type' => $docType, 'error' => $e->getMessage()],
                );
            }
        }
    }
}
