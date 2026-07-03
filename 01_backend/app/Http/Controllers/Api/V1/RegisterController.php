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
            'password' => 'required|min:4|max:4'
        ]);


        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $phone = $request->dial_country_code . $request->phone;
        $customerPhone = $this->user->where(['phone' => $phone])->first();
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

        DB::transaction(function () use ($request, $verify, $phone) {
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

        $phone = $request->dial_country_code . $request->phone;
        $agentPhone = $this->user->where(['phone' => $phone])->first();
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
