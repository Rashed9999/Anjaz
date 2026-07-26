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
                $demoOtp = config("app.amial_demo_otp");
                // AMIAL-DEMO-OTP: رمز تجريبي موحّد للتسجيل (بلا بوابة SMS بعد) —
                // يعمل فقط عند ضبط AMIAL_DEMO_OTP؛ الإنتاج يربط بوابة حقيقية.
                if (is_string($demoOtp) && $demoOtp !== "" && hash_equals($demoOtp, (string) $request["otp"])) {
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
            }
            if ($accountType === AGENT_TYPE) {
                $loginNumbers['agent_number'] = $user->agent_number;
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
                $demoOtp = config("app.amial_demo_otp");
                // AMIAL-DEMO-OTP: رمز تجريبي موحّد للتسجيل (بلا بوابة SMS بعد) —
                // يعمل فقط عند ضبط AMIAL_DEMO_OTP؛ الإنتاج يربط بوابة حقيقية.
                if (is_string($demoOtp) && $demoOtp !== "" && hash_equals($demoOtp, (string) $request["otp"])) {
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
