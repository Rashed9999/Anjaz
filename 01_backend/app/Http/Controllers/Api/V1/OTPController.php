<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\CentralLogics\helpers;
use App\CentralLogics\SmsModule;
use App\Models\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Modules\Gateways\Traits\SmsGateway;
use Illuminate\Support\Facades\Validator;

class OTPController extends Controller
{
    public function __construct(
        private PhoneVerification $phoneVerification
    ){}

    public function checkOtp(Request $request): JsonResponse
    {
        try {
            // AMIAL-OTP-ENV-001 — `env()` تُرجع null بعد `config:cache`،
            // فكان الرمزُ `1234` ثابتاً في الإنتاج. البابُ الواحد الآن هو
            // `OtpPolicy`: رقمُ العرض يأخذ الثابت، والحقيقيُّ عشوائيّاً.
            $otp = (string) app(\App\Services\Otp\OtpPolicy::class)
                ->codeFor((string) $request->user()->phone);

            DB::table('phone_verifications')->updateOrInsert(['phone' => $request->user()->phone], [
                'otp' => $otp,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if(addon_published_status('Gateways')){
                $response = SmsGateway::send($request->user()->phone,$otp);
            }else{
                $response = SmsModule::send($request->user()->phone, $otp);
            }

            return response()->json(['message' => 'success'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'failed'], 200);
        }
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required|min:4|max:4'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $verify = $this->phoneVerification->where(['phone' => $request->user()->phone, 'otp' => $request['otp']])->first();

        if (isset($verify)) {
            $verify->delete();
            return response()->json([
                'message' => 'OTP verified!',
            ], 200);
        }

        return response()->json(['errors' => [
            ['code' => 'otp', 'message' => 'OTP is not found!']
        ]], 404);
    }
}
