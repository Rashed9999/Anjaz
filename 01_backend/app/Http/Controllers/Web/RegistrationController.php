<?php

namespace App\Http\Controllers\Web;

use Exception;
use App\Models\User;
use App\Models\EMoney;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use Illuminate\Validation\Rule;
use App\CentralLogics\SmsModule;
use App\Models\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Modules\Gateways\Traits\SmsGateway;
use Stevebauman\Location\Facades\Location;

class RegistrationController extends Controller
{
    public function __construct(
        private BusinessSetting   $businessSetting,
        private User              $user,
        private EMoney            $eMoney,
        private PhoneVerification $phoneVerification
    )
    {
    }

    public function agentSelfRegistration(Request $request): View
    {
        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
        ];
        $ip = env('APP_MODE') == 'live' ? $request->ip() : '61.247.180.82';
        $currentUserInfo = Location::get($ip);
        $phone_verification = Helpers::get_business_settings('phone_verification');

        return view('landing.agent.agent-self-registration', compact(['data', 'currentUserInfo', 'phone_verification']));
    }

    public function phoneNumberCheck(Request $request): JsonResponse
    {

        $phone = $request->country_code . $request->phone;
        $agent = $this->user->where(['phone' => $phone])->whereNull('deleted_at')->first();

        if ($agent) {
            return response()->json(['available' => false]);
        }
        return response()->json(['available' => true]);
    }

    public function storeAgentData(Request $request): JsonResponse
    {
        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'image' => 'mimes:jpeg,jpg,png,gif|max:10000',
            'email' => 'required',
            'phone' => [
                'required',
                Rule::unique('users')->where(function ($query) {
                    return $query->whereNull('deleted_at');
                }),
                'min:5',
                'max:20',
            ],
            'country_code' => 'required',
            'gender' => 'required',
            'occupation' => 'required',
            'password' => 'required|min:4|max:4',
        ], [
            'f_name.required' => translate('The first name field is required.'),
            'l_name.required' => translate('The last name field is required.'),
            'password.min' => translate('Password must contain 4 characters.'),
            'password.max' => translate('Password must contain 4 characters.'),
        ]);

        $data = $request->all();

        $phone = $request->country_code . $request->phone;
        $agentPhone = $this->user->where(['phone' => $phone])->first();
        if (isset($agentPhone)) {
            return response()->json([
                'flag' => 'phone_exists',
            ]);
        }

        $phoneVerification = Helpers::get_business_settings('phone_verification');
        if ($phoneVerification) {
            try {
                $otp = mt_rand(1000, 9999);
                if (env('APP_MODE') != LIVE) {
                    $otp = '1234'; //hard coded
                }

                DB::table('phone_verifications')->updateOrInsert(['phone' => $phone], [
                    'otp' => $otp,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (addon_published_status('Gateways')) {
                    $response = SmsGateway::send($phone, $otp);
                } else {
                    $response = SmsModule::send($phone, $otp);
                }

                return response()->json([
                    'flag' => 'open_otp',
                    'view' => view('landing.agent.partials.otp-modal-data', compact('data', 'phone'))->render(),
                ]);

            } catch (\Exception $e) {
                return response()->json([
                    'flag' => 'failed_otp',
                ]);
            }
        } else {
            $user = $this->user;

            $user->f_name = $request->f_name;
            $user->l_name = $request->l_name;
            $user->gender = $request->gender;
            $user->identification_image = json_encode([]);
            $user->occupation = $request->occupation;
            $user->dial_country_code = $request->country_code;
            $user->phone = $phone;
            $user->email = $request->email;
            $user->password = bcrypt($request->password);
            $user->type = AGENT_TYPE;
            $user->referral_id = null;
            $user->save();

            $user->find($user->id);
            $user->unique_id = $user->id . mt_rand(1111, 99999);
            $user->save();

            $emoney = $this->eMoney;
            $emoney->user_id = $user->id;
            $emoney->save();
            return response()->json([
                'status' => 'success',
                'message' => 'successfully saved data'
            ]);
        }
    }

    public function agentVerifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'otp' => 'required|min:4|max:4',
        ]);

        $jsonData = $request->input('data');
        $data = json_decode($jsonData, true);

        $phone = $data['country_code'] . $data['phone'];
        $verify = $this->phoneVerification->where(['phone' => $phone, 'otp' => $request->otp])->first();

        if (isset($verify)) {
            $verify->delete();

            $user = $this->user;
            $user->f_name = $data['f_name'];
            $user->l_name = $data['l_name'];
            $user->gender = $data['gender'];
            $user->identification_image = json_encode([]);
            $user->occupation = $data['occupation'];
            $user->dial_country_code = $data['country_code'];
            $user->phone = $phone;
            $user->email = $data['email'];
            $user->password = bcrypt($data['password']);
            $user->type = AGENT_TYPE;
            $user->referral_id = null;
            $user->save();

            $user->find($user->id);
            $user->unique_id = $user->id . mt_rand(1111, 99999);
            $user->save();

            $emoney = $this->eMoney;
            $emoney->user_id = $user->id;
            $emoney->save();
            return response()->json([
                'status' => 'success',
                'message' => 'successfully saved data'
            ]);
        }

        return response()->json([
            'flag' => 'failed_otp',
        ]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required',
        ]);

        try {
            $otp = mt_rand(1000, 9999);
            if (env('APP_MODE') != LIVE) {
                $otp = '1234';
            }
            DB::table('phone_verifications')->updateOrInsert(['phone' => $request->phone], [
                'otp' => $otp,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (addon_published_status('Gateways')) {
                $response = SmsGateway::send($request->phone_number, $otp);
            } else {
                $response = SmsModule::send($request->phone_number, $otp);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'OTP Send'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'OTP Send failed'
            ]);
        }
    }
}
