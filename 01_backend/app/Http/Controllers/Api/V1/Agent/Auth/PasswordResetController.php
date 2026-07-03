<?php

namespace App\Http\Controllers\Api\V1\Agent\Auth;

use function now;
use App\Models\User;
use function bcrypt;
use function response;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Carbon;
use App\CentralLogics\SmsModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Modules\Gateways\Traits\SmsGateway;
use Illuminate\Support\Facades\Validator;

class PasswordResetController extends Controller
{
    public function __construct(
        private User $user
    ){}

    public function resetPasswordRequest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $customer = $this->user->where(['phone' => $request->phone])->first();

        if(isset($customer) && $customer->type != AGENT_TYPE) {
            return response()->json(['errors' => [
                ['code' => 'forbidden', 'message' => 'Access forbidden!']
            ]], 403);
        }

        if (isset($customer)) {
            $otpIntervalTime = Helpers::get_business_settings('otp_resend_time') ?? 60; // seconds
            $passwordVerificationData= DB::table('password_resets')->where('phone', $request['phone'])->first();

            if(isset($passwordVerificationData) && Carbon::parse($passwordVerificationData->created_at)->DiffInSeconds() < $otpIntervalTime){
                $time = $otpIntervalTime - Carbon::parse($passwordVerificationData->created_at)->DiffInSeconds();

                return response()->json(['errors' => [
                    ['code' => 'otp', 'message' => translate('please_try_again_after_') . Helpers::timeHumanReadableFormat($time)]
                ]], 403);
            }

            $otp = env('APP_MODE') != LIVE ? '1234' : rand(1000, 9999);

            DB::table('password_resets')->updateOrInsert(['phone' => $request->phone], [
                'token' => $otp,
                'otp_hit_count' => 0,
                'is_temp_blocked' => 0,
                'temp_block_time' => null,
                'created_at' => now(),
            ]);

            try {
                if(addon_published_status('Gateways')){
                    $response = SmsGateway::send($customer['phone'],$otp);
                }else{
                    $response = SmsModule::send($customer['phone'], $otp);
                }
                return response()->json([
                    'message' => 'OTP sent successfully.'
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => $response
                ], 200);
            }
        }
        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => 'Agent not found!']
        ]], 404);
    }

    public function verifyToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'otp' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $maxOtpHit = Helpers::get_business_settings('maximum_otp_hit') ?? 5;
        $maxOtpHitTime = Helpers::get_business_settings('otp_resend_time') ?? 60;    // seconds
        $tempBlockTime = Helpers::get_business_settings('temporary_block_time') ?? 600;   // seconds

        $data = DB::table('password_resets')->where(['token' => $request['otp'], 'phone' => $request->phone])->first();
        if (isset($data)) {

            if(isset($data->temp_block_time ) && Carbon::parse($data->temp_block_time)->DiffInSeconds() <= $tempBlockTime){
                $time = $tempBlockTime - Carbon::parse($data->temp_block_time)->DiffInSeconds();

                return response()->json(['errors' => [
                    ['code' => 'otp_block_time', 'message' => translate('please_try_again_after_') . $time . ' ' . translate('seconds')]
                ]], 403);
            }

            return response()->json(['message' => "OTP found, you can proceed"], 200);
        }
        else{
            $verificationData=  DB::table('password_resets')->where(['phone' => $request->phone])->first();

            if(isset($verificationData)){
                $time = $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();

                if(isset($verificationData->temp_block_time ) && Carbon::parse($verificationData->temp_block_time)->DiffInSeconds() <= $tempBlockTime){
                    $time= $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();

                    return response()->json(['errors' => [
                        ['code' => 'otp_block_time', 'message' => translate('please_try_again_after_') . Helpers::timeHumanReadableFormat($time)]
                    ]], 403);
                }

                if($verificationData->is_temp_blocked == 1 && Carbon::parse($verificationData->created_at)->DiffInSeconds() >= $tempBlockTime){
                    DB::table('password_resets')->updateOrInsert(['phone' => $request['phone']],
                        [
                            'otp_hit_count' => 0,
                            'is_temp_blocked' => 0,
                            'temp_block_time' => null,
                            'created_at' => now(),
                        ]);
                }

                if($verificationData->otp_hit_count >= $maxOtpHit &&  Carbon::parse($verificationData->created_at)->DiffInSeconds() < $maxOtpHitTime &&  $verificationData->is_temp_blocked == 0){

                    DB::table('password_resets')->updateOrInsert(['phone' => $request['phone']],
                        [
                            'is_temp_blocked' => 1,
                            'temp_block_time' => now(),
                            'created_at' => now(),
                        ]);

                    $time= $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();



                    return response()->json(['errors' => [
                        ['code' => 'otp_block_time', 'message' => translate('Too_many_attempts. Please_try_again_after_'). Helpers::timeHumanReadableFormat($time)]
                    ]], 403);
                }
            }

            DB::table('password_resets')->updateOrInsert(['phone' => $request['phone']],
                [
                    'otp_hit_count' => DB::raw('otp_hit_count + 1'),
                    'created_at' => now(),
                    'temp_block_time' => null,
                ]);
        }
        return response()->json(['errors' => [
            ['code' => 'invalid', 'message' => 'Invalid OTP.']
        ]], 400);
    }

    public function resetPasswordSubmit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'otp' => 'required',
            'password' => 'required',
            'confirm_password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $data = DB::table('password_resets')->where(['phone' => $request->phone])
            ->where(['token' => $request['otp']])->first();

        if (isset($data)) {

            if ($request['password'] == $request['confirm_password']) {
                $customer = $this->user->where(['phone' => $request->phone])->first();
                $customer->password = bcrypt($request['confirm_password']);
                $customer->save();

                DB::table('password_resets')
                    ->where(['phone' => $request->phone])
                    ->where(['token' => $request['otp']])->delete();

                return response()->json(['message' => 'Password changed successfully.'], 200);
            }
            return response()->json(['errors' => [
                ['code' => 'mismatch', 'message' => "Password didn't match!"]
            ]], 401);
        }
        return response()->json(['errors' => [
            ['code' => 'invalid', 'message' => 'Invalid OTP.']
        ]], 400);
    }
}
