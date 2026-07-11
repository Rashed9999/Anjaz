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
            'kin_name' => 'sometimes|nullable|string|max:150',
            'kin_phone' => 'sometimes|nullable|string|max:30',
            'kin_relation' => 'sometimes|nullable|string|max:60',
            'declaration_accepted' => 'sometimes',
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
                $verify = $this->phoneVerification->where(['phone' => $phone, 'otp' => $request['otp']])->first();
                if (!isset($verify)) {
                    return response()->json(['errors' => [
                        ['code' => 'otp', 'message' => 'OTP is not found!']
                    ]], 404);

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

        DB::transaction(function () use ($request, $verify, $phone, $signaturePath) {
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
            $user->type = CUSTOMER_TYPE;
            $user->referral_id = $request->referral_id ?? null;
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
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_declaration_accepted')
                && in_array((string) $request->declaration_accepted, ['1', 'true'], true)) {
                $user->kyc_declaration_accepted = true;
                $user->kyc_declared_at = now();
            }
            $user->is_kyc_verified = 0; // بانتظار مراجعة الإدارة

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
                $verify = $this->phoneVerification->where(['phone' => $phone, 'otp' => $request['otp']])->first();
                if (!isset($verify)) {
                    return response()->json(['errors' => [
                        ['code' => 'otp', 'message' => 'OTP is not found!']
                    ]], 404);

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
