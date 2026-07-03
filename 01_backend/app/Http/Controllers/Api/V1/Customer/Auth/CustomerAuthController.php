<?php

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\Models\Transaction;
use App\Traits\UploadSizeHelperTrait;
use Exception;
use App\Models\User;
use App\Models\Purpose;
use Carbon\CarbonInterval;
use App\Models\RequestMoney;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use App\Models\LinkedWebsite;
use App\CentralLogics\Helpers;
use Illuminate\Support\Carbon;
use App\Models\BusinessSetting;
use App\Models\WithdrawRequest;
use App\Models\TransactionLimit;
use App\CentralLogics\SmsModule;
use App\Models\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Gateways\Traits\SmsGateway;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\RequestMoneyResource;

class CustomerAuthController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private User $user,
        private BusinessSetting $businessSetting,
        private PhoneVerification $phoneVerification,
        private LinkedWebsite $linkedWebsite,
        private RequestMoney $requestMoney,
        private Purpose $purpose,
        public WithdrawRequest $withdrawRequest
    ){}

    public function checkPhone(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|min:5|max:20'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $customer = $this->user->where(['phone' => $request['phone']])->first();

        if (isset($customer) && $customer->type == 2){
            return response()->json([
                'message' => 'This phone is already taken',
                'user_type' => 'customer',
                'user_name' => $customer->f_name. ' '. $customer->l_name
            ], 403);
        }

        if (isset($customer) && $customer->type != 2 ){
            return response()->json([
                'message' => 'This phone is already register as agent',
                'user_type' => 'agent',
                'user_name' => $customer->f_name. ' '. $customer->l_name
            ], 403);
        }

        if ($this->businessSetting->where(['key' => 'phone_verification'])->first()->value) {
            $OTPIntervalTime= Helpers::get_business_settings('otp_resend_time') ?? 60;// seconds
            $OTPVerificationData= DB::table('phone_verifications')->where('phone', $request['phone'])->first();

            if(isset($OTPVerificationData) &&  Carbon::parse($OTPVerificationData->created_at)->DiffInSeconds() < $OTPIntervalTime){
                $time= $OTPIntervalTime - Carbon::parse($OTPVerificationData->created_at)->DiffInSeconds();

                return response()->json([
                    'code' => 'otp',
                    'message' => translate('please_try_again_after_') . Helpers::timeHumanReadableFormat($time)
                ], 200);
            }

            $otp = (env('APP_MODE') != 'live') ? '1234' : rand(1000, 9999);

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

    public function resendOTP(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|min:5|max:20|unique:users'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $phone = $request['phone'];
        try {
            $otp = (env('APP_MODE') != 'live') ? '1234' : rand(1000, 9999);

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
        } catch (Exception $e) {
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

        $maxOTPHit = Helpers::get_business_settings('maximum_otp_hit') ?? 5;
        $maxOTPHitTime = Helpers::get_business_settings('otp_resend_time') ?? 60;// seconds
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
                    $time = $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();

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

                if($verificationData->otp_hit_count >= $maxOTPHit &&  Carbon::parse($verificationData->updated_at)->DiffInSeconds() < $maxOTPHitTime &&  $verificationData->is_temp_blocked == 0){

                    DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                        [
                            'is_temp_blocked' => 1,
                            'temp_block_time' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    $time = $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();

                    return response()->json(['errors' => [
                        ['code' => 'otp', 'message' => translate('Too_many_attempts. please_try_again_after_'). Helpers::timeHumanReadableFormat($time)]
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

    public function logout(Request $request): JsonResponse
    {
        if (Auth::check()) {
            Auth::user()->AauthAcessToken()->delete();
            return response()->json(['message' => 'Logout successful'], 200);
        } else {
            return response()->json(['message' => 'Logout failed'], 403);
        }
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'l_name' => 'required',
            'gender' => 'required',
            'occupation' => '',
            'image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user = $this->user->find($request->user()->id);
        $user->f_name = $request->f_name;
        $user->l_name = $request->l_name;
        $user->email = $request->email;
        $user->image = $request->has('image') ? Helpers::update('customer/', $user->image, APPLICATION_IMAGE_FORMAT, $request->image) : $user->image;
        $user->gender = $request->gender;
        $user->occupation = $request->occupation;
        $user->save();

        return response()->json(['message' => 'Profile successfully updated'], 200);
    }

    public function verifyPin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|min:4|max:4'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        if(Helpers::pin_check($request->user()->id, $request->pin)) {
            return response()->json(['message' => 'PIN is correct'], 200);
        }else{
            return response()->json(['message' => 'PIN is incorrect'], 403);
        }
    }

    public function changePin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'old_pin' => 'required|min:4|max:4',
            'new_pin' => 'required|min:4|max:4',
            'confirm_pin' => 'required|min:4|max:4',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if (!Helpers::pin_check($request->user()->id, $request->old_pin)) {
            return response()->json(['message' => 'Old PIN is incorrect'], 401);
        }

        if ($request->new_pin != $request->confirm_pin) {
            return response()->json(['message' => 'PIN Mismatch'], 404);
        }

        try {
            $user = $this->user->find($request->user()->id);
            $user->password = bcrypt($request->confirm_pin);
            $user->save();
            return response()->json(['message' => 'PIN updated successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'PIN updated failed'], 401);
        }
    }

    public function updateFcmToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user = $this->user->find($request->user()->id);
        if(isset($user)) {
            $user->fcm_token = $request->token;
            $user->save();
            return response()->json(['message' => 'FCM token successfully updated'], 200);

        } else {
            return response()->json(['message' => 'User not found'], 404);
        }
    }

    public function getCustomer(Request $request): JsonResponse
    {
        try {
            $customer = $this->user->with('emoney')->customer()->find($request->user()->id);
            $pendingWithdraw = $this->withdrawRequest->where(['user_id' => $customer->id, 'request_status' => 'pending'])->count();

            $data = [];
            $data['name'] = $customer['f_name'] . ' ' . $customer['l_name'];
            $data['phone'] = $customer['phone'];
            $data['type'] = $customer['type'];
            $data['image'] = $customer['image'];
            $qr = Helpers::get_qrcode($data);

            $transactionLimitData = TransactionLimit::where(['user_id' => $request->user()->id])->get();

            $types = [
                'add_money',
                'send_money',
                'cash_out',
                'send_money_request',
                'withdraw_request'
            ];

            $limits = [];

            foreach ($types as $type) {
                $typeData = $transactionLimitData->where('type', $type)->first();

                $currentDay = now()->day;
                $currentMonth = now()->month;
                $currentYear = now()->year;

                if ($typeData) {
                    if ($currentDay !== $typeData['updated_at']->day || $currentMonth !== $typeData['updated_at']->month) {
                        $typeData['todays_count'] = 0;
                        $typeData['todays_amount'] = 0;
                    }

                    if ($currentMonth !== $typeData['updated_at']->month || $currentYear !== $typeData['updated_at']->year) {
                        $typeData['this_months_count'] = 0;
                        $typeData['this_months_amount'] = 0;
                    }

                    $limits["daily_{$type}_count"] = $typeData['todays_count'];
                    $limits["monthly_{$type}_count"] = $typeData['this_months_count'];
                    $limits["daily_{$type}_amount"] = $typeData['todays_amount'];
                    $limits["monthly_{$type}_amount"] = $typeData['this_months_amount'];

                    $typeData->save();

                } else {
                    $limits["daily_{$type}_count"] = 0;
                    $limits["monthly_{$type}_count"] = 0;
                    $limits["daily_{$type}_amount"] = 0;
                    $limits["monthly_{$type}_amount"] = 0;
                }
            }

            $totalWithdrawMoney = Transaction::where('user_id', $customer->id)
                ->where('transaction_type', WITHDRAW)
                ->sum('debit');

            $totalSendMoney = Transaction::where('user_id', $customer->id)
                ->where('transaction_type', SEND_MONEY)
                ->sum('debit');

            $totalCashOut = Transaction::where('user_id', $customer->id)
                ->where('transaction_type', CASH_OUT)
                ->sum('debit');

            return response()->json(
                [
                    'f_name' => $customer->f_name,
                    'l_name' => $customer->l_name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'image' => $customer->image,
                    'type' => $customer->type,
                    'gender' => $customer->gender,
                    'occupation' => $customer->occupation,
                    'two_factor' => (integer)$customer->two_factor,
                    'fcm_token' => $customer->fcm_token,
                    'balance' => (float)$customer->emoney->current_balance,
                    'pending_balance' => (float)$customer->emoney->pending_balance,
                    'pending_withdraw_count' => $pendingWithdraw,
                    'unique_id' => $customer->unique_id,
                    'qr_code' => strval($qr),
                    'is_kyc_verified' => (int)$customer->is_kyc_verified,
                    'transaction_limits' => $limits,
                    'total_withdraw_money_amount'  => $totalWithdrawMoney,
                    'total_send_money_amount'  => $totalSendMoney,
                    'total_cash_out_amount'  => $totalCashOut,
                ]
                , 200);
        } catch (Exception $e) {
            return response()->json([], 200);
        }
    }

    public function getRequestedMoney(Request $request): array
    {
        $limit = $request->has('limit') ? $request->limit : 10;
        $offset = $request->has('offset') ? $request->offset : 1;

        $requestMoney = $this->requestMoney->where('to_user_id', $request->user()->id);

        $requestMoney->when(request('type') == 'pending', function ($q) {
            return $q->where('type', 'pending');
        });
        $requestMoney->when(request('type') == 'approved', function ($q) {
            return $q->where('type', 'approved');
        });
        $requestMoney->when(request('type') == 'denied', function ($q) {
            return $q->where('type', 'denied');
        });

        $requestMoney = RequestMoneyResource::collection($requestMoney->latest()->paginate($limit, ['*'], 'page', $offset));
        return [
            'total_size' => $requestMoney->total(),
            'limit' => $limit,
            'offset' => $offset,
            'requested_money' => $requestMoney->items()
        ];
    }

    public function getOwnRequestedMoney(Request $request): array
    {
        $limit = $request->has('limit') ? $request->limit : 10;
        $offset = $request->has('offset') ? $request->offset : 1;

        $requestMoney = $this->requestMoney->where('from_user_id', $request->user()->id);

        $requestMoney->when(request('type') == 'pending', function ($q) {
            return $q->where('type', 'pending');
        });
        $requestMoney->when(request('type') == 'approved', function ($q) {
            return $q->where('type', 'approved');
        });
        $requestMoney->when(request('type') == 'denied', function ($q) {
            return $q->where('type', 'denied');
        });

        $requestMoney = RequestMoneyResource::collection($requestMoney->latest()->paginate($limit, ['*'], 'page', $offset));
        return [
            'total_size' => $requestMoney->total(),
            'limit' => $limit,
            'offset' => $offset,
            'requested_money' => $requestMoney->items()
        ];
    }

    public function updateTwoFactor(Request $request): JsonResponse
    {
        try {
            $user = $this->user->find($request->user()->id);
            $user->two_factor = !$request->user()->two_factor;
            $user->save();
            return response()->json(['message' => 'Two factor updated'], 200);

        } catch (\Exception $e) {
            return response()->json(['errors' => 'failed'], 403);
        }
    }

    public function getPurpose(Request $request): Collection
    {
        $purposes = $this->purpose->select('title', 'logo', 'color')->get();
        return $purposes;
    }

    public function linkedWebsite(Request $request): Collection
    {
        $linkedWebsites = $this->linkedWebsite->select('name', 'image', 'url')->active()->orderBy("id", "desc")->take(20)->get();
        return $linkedWebsites;
    }

    public function removeAccount(Request $request): JsonResponse
    {
        $customer = $this->user->find($request->user()->id);
        if(isset($customer)) {
            Helpers::file_remover('customer/', $customer->image);
            $customer->delete();
        } else {
            return response()->json(['status_code' => 404, 'message' => translate('Not found')], 200);
        }
        return response()->json(['status_code' => 200, 'message' => translate('Successfully deleted')], 200);
    }

    public function updateKycInformation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identification_number' => 'required',
            'identification_type' => 'required|in:passport,driving_licence,nid,trade_license',
            'identification_image' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $identityImages = [];
        foreach ($request->identification_image as $image) {
            $identityImages[] = Helpers::file_uploader('user/identity/', 'png', $image);
        }

        $user = $this->user->find($request->user()->id);
        if($user->is_kyc_verified == 1) {
            return response()->json(Helpers::response_formatter(DEFAULT_FAIL_200), 200);
        }
        $user->identification_number = $request->identification_number;
        $user->identification_type = $request->identification_type;
        $user->identification_image = $identityImages;
        $user->is_kyc_verified = 0;
        $user->save();

        return response()->json(Helpers::response_formatter(DEFAULT_UPDATE_200), 200);
    }
}
