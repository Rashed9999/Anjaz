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

    public function customerRegistration(Request $request): JsonResponse
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
            'f_name' => 'required',
            'l_name' => 'required',
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
            'email' => 'nullable|email',
            'password' => 'required|min:4|max:4',
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
            'father_name' => 'sometimes|nullable|string|max:60',
            'grandfather_name' => 'sometimes|nullable|string|max:60',
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
            'business_type' => 'sometimes|nullable|in:' . implode(',', \App\Support\Access\AccessConstants::ALL_BUSINESS_TYPES),
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
        DB::transaction(function () use ($request, $verify, $phone, $signaturePath, $accountType, &$loginNumbers) {
            $verify?->delete();

            $user = $this->user;
            $user->f_name = $request->f_name;
            $user->l_name = $request->l_name;
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
            // PIN المعاملات = نفس رمز الدخول المُدخل (يغيّره المستخدم لاحقاً)
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'transaction_pin')) {
                $user->transaction_pin = $request->password;
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

            $user->save();

            $user->find($user->id);
            $user->unique_id = $user->id . mt_rand(1111, 99999);
            $user->save();

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

            $emoney = $this->eMoney;
            $emoney->user_id = $user->id;
            $emoney->save();

            // AMIAL-REG-ROLES: سجلات التاجر — رقم دخوله + ملف «قيد التحقق»
            if ($accountType === MERCHANT_TYPE) {
                $mr = new \App\Models\Merchant();
                $mr->user_id = $user->id;
                $mr->store_name = trim((string) $request->input('store_name'));
                $mr->merchant_number = sprintf('M-%05d', $user->id);
                $mr->address = trim((string) $request->input('address', '')) ?: '—';
                $mr->save();
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
                $dossierService->archiveSelfRegistration($dossierType, $phone, $user, [
                    'full_name' => trim((string) ($user->f_name . ' ' . $user->l_name)),
                    'gender' => $user->gender, 'phone' => $phone,
                    'identification_number' => $user->identification_number,
                    'identification_type' => $user->identification_type,
                    'address' => $user->address ?? null,
                    'business_name' => $accountType === MERCHANT_TYPE ? $request->input('store_name') : null,
                    'business_type' => $accountType === MERCHANT_TYPE ? $request->input('business_type') : null,
                ]);
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
            'verification_status' => 'pending_review',
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
            'email' => 'nullable|email',
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

            $user = $this->user;
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

            $emoney = $this->eMoney;
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
}
