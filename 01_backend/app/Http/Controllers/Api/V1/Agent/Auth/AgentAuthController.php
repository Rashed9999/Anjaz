<?php

namespace App\Http\Controllers\Api\V1\Agent\Auth;

use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use App\CentralLogics\helpers;
use Illuminate\Support\Carbon;
use App\Models\BusinessSetting;
use App\CentralLogics\SmsModule;
use App\Models\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Modules\Gateways\Traits\SmsGateway;
use Illuminate\Support\Facades\Validator;

class AgentAuthController extends Controller
{
    public function __construct(
        private User $user,
        private BusinessSetting $businessSetting,
        private PhoneVerification $phoneVerification,
    ){}

    public function checkPhone(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|min:5|max:20'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $agent = $this->user->where(['phone' => $request['phone']])->first();

        if (isset($agent) && $agent->type == 1){
            return response()->json([
                'message' => 'This phone is already taken',
                'user_type' => 'agent',
            ], 403);
        }

        if (isset($agent) && $agent->type != 1 ){
            return response()->json([
                'message' => 'This phone is already register as customer',
                'user_type' => 'customer',
            ], 403);
        }

        if ($this->businessSetting->where(['key' => 'phone_verification'])->first()->value) {

            $otpIintervalTime = Helpers::get_business_settings('otp_resend_time') ?? 60;
            $otpVerificationData= DB::table('phone_verifications')->where('phone', $request['phone'])->first();

            if(isset($otpVerificationData) &&  Carbon::parse($otpVerificationData->created_at)->DiffInSeconds() < $otpIintervalTime){
                $time = $otpIintervalTime - Carbon::parse($otpVerificationData->created_at)->DiffInSeconds();

                return response()->json([
                    'code' => 'otp',
                    'message' => translate('please_try_again_after_') . Helpers::timeHumanReadableFormat($time)
                ], 200);
            }

            $otp = env('APP_MODE') != LIVE ? '1234' : mt_rand(1000, 9999);

            DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']], [
                'otp' => $otp,
                'otp_hit_count' => 0,
                'is_temp_blocked' => 0,
                'temp_block_time' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if(addon_published_status('Gateways')){
                $response = SmsGateway::send($request['phone'],$otp);
            }else{
                $response = SmsModule::send($request['phone'], $otp);
            }

            return response()->json([
                'message' => 'Number is ready to register',
                'otp' => 'active'
            ], 200);
        }
        else{
            return response()->json([
                'message' => 'OTP sent failed',
                'otp' => 'inactive'
            ], 200);
        }
    }

    public function verifyPhone(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'otp' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $maxOtpHit = Helpers::get_business_settings('maximum_otp_hit') ?? 5;
        $maxOtpHitTime = Helpers::get_business_settings('otp_resend_time') ?? 60;// seconds
        $tempBlockTime = Helpers::get_business_settings('temporary_block_time') ?? 600; // seconds

        $verify = $this->phoneVerification->where(['phone' => $request['phone'], 'otp' => $request['otp']])->first();

        if (isset($verify)) {

            if(isset($verify->temp_block_time ) && Carbon::parse($verify->temp_block_time)->DiffInSeconds() <= $tempBlockTime){
                $time = $tempBlockTime - Carbon::parse($verify->temp_block_time)->DiffInSeconds();

                return response()->json(['errors' => [
                    ['code' => 'otp', 'message' => translate('please_try_again_after_') . Helpers::timeHumanReadableFormat($time)]
                ]], 404);
            }

            return response()->json([
                'message' => 'OTP verified!',
            ], 200);
        }
        else{
            $verificationData= $this->phoneVerification->where('phone', $request['phone'])->first();

            if(isset($verificationData)){

                if(isset($verificationData->temp_block_time ) && Carbon::parse($verificationData->temp_block_time)->DiffInSeconds() <= $tempBlockTime){
                    $time= $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();

                    return response()->json(['errors' => [
                        ['code' => 'otp', 'message' => translate('please_try_again_after_') . Helpers::timeHumanReadableFormat($time)]
                    ]], 404);
                }

                if($verificationData->is_temp_blocked == 1 && Carbon::parse($verificationData->updated_at)->DiffInSeconds() >= $tempBlockTime){
                    DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                        [
                            'otp_hit_count' => 0,
                            'is_temp_blocked' => 0,
                            'temp_block_time' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                if($verificationData->otp_hit_count >= $maxOtpHit &&  Carbon::parse($verificationData->updated_at)->DiffInSeconds() < $maxOtpHitTime &&  $verificationData->is_temp_blocked == 0){

                    DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                        [
                            'is_temp_blocked' => 1,
                            'temp_block_time' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    $time= $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();

                    return response()->json(['errors' => [
                        ['code' => 'otp', 'message' => translate('Too_many_attempts. Please_try_again_after_') . Helpers::timeHumanReadableFormat($time)]
                    ]], 404);
                }
            }
            DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                [
                    'otp_hit_count' => DB::raw('otp_hit_count + 1'),
                    'updated_at' => now(),
                    'temp_block_time' => null,
                ]);
        }


        return response()->json(['errors' => [
            ['code' => 'otp', 'message' => 'OTP is not matched!']
        ]], 404);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|min:5|max:20|unique:users'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $phone = $request['phone'];
        try {
            $otp = env('APP_MODE') != LIVE ? '1234' : mt_rand(1000, 9999);

            DB::table('phone_verifications')->updateOrInsert(['phone' => $phone], [
                'otp' => $otp,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if(addon_published_status('Gateways')){
                $response = SmsGateway::send($phone,$otp);
            }else{
                $response = SmsModule::send($phone, $otp);
            }

            return response()->json([
                'message' => 'OTP sent successfully',
                'otp' => 'active'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'OTP sent failed',
                'otp' => 'inactive'
            ], 200);
        }
    }
}
