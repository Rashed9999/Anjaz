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
use App\Models\KycDocument;
use App\Services\KycDocumentService;
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

        // AMIAL-DEMO-OTP: كان ->first()->value بلا حارس. سجلّ phone_verification
        // غير موجود في قاعدة نظيفة (ولا بذرة تُنشئه)، فيُقرأ من null ويُرمى
        // "Attempt to read property value on null" → 500 → يرى المستخدم
        // «خطأ» عند خطوة الرمز بلا سبب مفهوم.
        if ((int) (Helpers::get_business_settings('phone_verification') ?? 0) === 1) {
            $OTPIntervalTime= Helpers::get_business_settings('otp_resend_time') ?? 60;// seconds
            $OTPVerificationData= DB::table('phone_verifications')->where('phone', $request['phone'])->first();

            if(isset($OTPVerificationData) &&  Carbon::parse($OTPVerificationData->created_at)->DiffInSeconds() < $OTPIntervalTime){
                $time= $OTPIntervalTime - Carbon::parse($OTPVerificationData->created_at)->DiffInSeconds();

                return response()->json([
                    'code' => 'otp',
                    'message' => translate('please_try_again_after_') . Helpers::timeHumanReadableFormat($time)
                ], 200);
            }

            // AMIAL-OTP-SPLIT-001: الرقمُ يحدّد الرمز، لا مفتاحٌ عامّ.
            $policy = app(\App\Services\Otp\OtpPolicy::class);
            $otp = $policy->codeFor($request['phone']);

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

            // AMIAL-OTP-SPLIT-001: **الإفصاح لأرقام العرض وحدها.**
            //
            // كان يُفصح عن الرمز لأيّ رقم — فيُلغى التحقّق من أصله: يصير
            // «أثبت أنّك تملك الرقم» «انسخ ما أعطيناك». ولرقمٍ حقيقيّ
            // يبقى `null` مهما كان `AMIAL_DEMO_OTP` مضبوطاً.
            $demoHint = $policy->mayDisclose($request['phone']) ? (string) $otp : null;

            return response()->json([
                'message' => 'Number is ready to register',
                'otp' => 'active',
                'demo_otp' => $demoHint,
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
            // AMIAL-OTP-SPLIT-001: الرقمُ يحدّد الرمز، لا مفتاحٌ عامّ.
            $otp = app(\App\Services\Otp\OtpPolicy::class)->codeFor($phone);

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
            // AMIAL-PIN-SEPARATION-001: القديم كان محدوداً بأربعة محارف تماماً.
            //
            // الحساب الذي لم يُعيَّن له رمز معاملات قطّ يتحقّق بكلمة مروره
            // (توافق 6cash في pin_check)، وكلمة المرور أطول من أربعة محارف —
            // فكان التحقّق يُرفض قبل أن يبلغ المنطق، ولا سبيل لصاحبه أن
            // يُنشئ رمزاً أصلاً. أي أن الطريق إلى البنية الصحيحة كان مقفلاً
            // على من هو أحوج الناس إليه.
            //
            // القديم اعتمادٌ يُتحقَّق منه لا صيغةٌ تُخزَّن، فلا يُقيَّد بطولها.
            'old_pin' => 'required|string|min:4|max:64',
            'new_pin' => 'required|digits:4',
            'confirm_pin' => 'required|digits:4',
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

        // AMIAL-PIN-SEPARATION-001 — الرمز يُكتب في transaction_pin لا في password.
        //
        // كان السطر: `$user->password = bcrypt($request->confirm_pin);`
        //
        // أي أن تغيير رمز المعاملات كان يمحو كلمة مرور الدخول ويضع مكانها
        // أربعة أرقام. ثلاث نتائج، كلّها صامتة:
        //   1) العميل يجد نفسه يدخل حسابه بالرمز الجديد بدل كلمة مروره —
        //      وهو ما اشتكى منه المستخدم حرفياً.
        //   2) كلمة مرور الدخول تهبط إلى أربعة أرقام: عشرة آلاف احتمال
        //      يُجرَّب آلياً. انهيار في قوّة الاعتماد لا يراه أحد.
        //   3) الأسوأ: pin_check يقرأ transaction_pin أولاً، وهو لم يتغيّر.
        //      فالرمز الذي ظنّ العميل أنه غيّره ظلّ القديم في المعاملات،
        //      بينما «تم التغيير بنجاح» على شاشته.
        //
        // البنية الصحيحة موجودة أصلاً (TransactionPinService) ولم تكن موصولة
        // بأي مسار. هذا يصلها.
        try {
            $user = $this->user->find($request->user()->id);
            app(\App\Services\TransactionPinService::class)->setPin($user, (string) $request->confirm_pin);

            return response()->json(['message' => 'PIN updated successfully'], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
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
            'identification_image' => 'required|array|size:3',
            'identification_image.*' => 'required|file|max:8192|mimetypes:image/jpeg,image/png,image/heic,image/heif,application/pdf',
            // AMIAL-KYC: العنوان + التوقيع الإلكتروني + الإقرار (اختيارية توافقاً
            // مع أي عميل قديم؛ التطبيق الجديد يرسلها ويشترطها في الواجهة).
            'address' => 'sometimes|nullable|string|max:500',
            'signature' => 'sometimes|nullable|string|max:255',
            'declaration_accepted' => 'sometimes',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user = $this->user->find($request->user()->id);
        if($user->is_kyc_verified == 1) {
            return response()->json(Helpers::response_formatter(DEFAULT_FAIL_200), 200);
        }
        // هذا الطرف هو نموذج التطبيق القديم. لا نسمح له بعد اليوم أن يخزّن
        // صور هوية في المسار القديم غير المشفّر أو أن يترك «رفع KYC» خارج
        // طابور المراجعة الحديث. الصور الثلاث بالترتيب: وجه البطاقة، ظهرها،
        // وصورة حيّة؛ وتظهر كلها في مركز KYC للمراجع.
        try {
            $documents = app(KycDocumentService::class);
            foreach ([
                KycDocument::TYPE_ID_FRONT,
                KycDocument::TYPE_ID_BACK,
                KycDocument::TYPE_SELFIE,
            ] as $index => $type) {
                $documents->uploadAndRead($user, $type, $request->file('identification_image')[$index]);
            }
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user->identification_number = $request->identification_number;
        $user->identification_type = $request->identification_type;
        // لا نكرر بيانات الهوية في التخزين القديم؛ KycDocumentService يحفظها
        // مشفّرة ويخفي مسارها ولا يفتحها إلا للمراجع المخوّل.
        $user->identification_image = json_encode([], JSON_UNESCAPED_UNICODE);

        // AMIAL-KYC: حفظ العنوان + التوقيع + الإقرار (فقط إن أُرسلت وإن وُجدت الأعمدة)
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'address') && $request->filled('address')) {
            $user->address = $request->address;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_signature') && $request->filled('signature')) {
            $user->kyc_signature = $request->signature;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_declaration_accepted')
            && in_array((string) $request->declaration_accepted, ['1', 'true'], true)) {
            $user->kyc_declaration_accepted = true;
            $user->kyc_declared_at = now();
        }

        $user->is_kyc_verified = 0;
        $user->save();

        return response()->json(Helpers::response_formatter(DEFAULT_UPDATE_200), 200);
    }
}
