<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\EMoney;
use App\Models\FavouriteNumber;
use App\Models\RequestMoney;
use App\Models\Transaction;
use App\Models\TransactionLimit;
use App\Models\User;
use App\Models\WithdrawalMethod;
use App\Models\WithdrawRequest;
use App\Traits\TransactionTrait;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;


class TransactionController extends Controller
{
    use TransactionTrait;

    public function __construct(
        private WithdrawalMethod $withdrawalMethod,
        private WithdrawRequest $withdrawRequest,
        private User $user,
        private EMoney $eMoney,
        private RequestMoney $requestMoney
    ){}

    public function sendMoney(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|min:4|max:4',
            'phone' => 'required',
            'purpose' => '',
            'amount' => 'required|min:0|not_in:0',
        ],
            [
                'amount.not_in' => translate('المبلغ يجب أن يكون أكبر من صفر'),
            ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $sendMoneyStatus = Helpers::get_business_settings('send_money_status');

        if (!$sendMoneyStatus)
            return response()->json(['message' => translate('خدمة تحويل المال غير مفعّلة حالياً')], 403);

        $receiverPhone = Helpers::filter_phone($request->phone);
        $user = $this->user->whereIn('phone', \App\Support\Phone::variants($receiverPhone))->first(); // AMIAL-FIX: مطابقة صيغ الهاتف

        if (!isset($user))
            return response()->json(['message' => translate('المستلِم غير موجود — تحقّق من الرقم')], 403); //Receiver Check

        if($user->is_kyc_verified != 1)
            return response()->json(['message' => translate('حساب المستلِم غير موثّق')], 403); //kyc check

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('أكمل توثيق حسابك أولاً')], 403); //kyc check

        if ($request->user()->phone == $receiverPhone)
            return response()->json(['message' => translate('لا يمكن إجراء المعاملة على رقمك نفسه')], 400); //own number check

        if($user->type != 2)
            return response()->json(['message' => translate('المستلِم يجب أن يكون عميلاً')], 400); //'if receiver is customer' check

        if (!Helpers::pin_check($request->user()->id, $request->pin))
            return response()->json(['message' => translate('رمز PIN غير صحيح')], 403); //PIN Check

        $sendMoneyLimit = Helpers::get_business_settings('customer_send_money_limit');

        if(isset($sendMoneyLimit) && $sendMoneyLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $request['amount'], 'send_money', $sendMoneyLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-FEE-TRUTH-001 — **الرسمُ من المحرّك الواحد، لا من
        // `business_settings`.**
        //
        // **الثمنُ الذي قِيس:** كان هذا السطرُ `Helpers::get_sendmoney_charge()`
        // — يقرأ مفتاحاً في `business_settings` تكتبه شاشةٌ قديمة. بينما
        // «إدارة الرسوم والعمولات» تكتب في `fee_schemes`، ومحرّكُها
        // `FeeService` **مبنيٌّ ومختبَرٌ بالكامل**.
        //
        // وأخطرُ ما فيه: `send_money_with_fee_engine` في `TransactionTrait`
        // كانت موجودةً وتفعل الصواب — **ولا يناديها إلّا الاختبارات**.
        // فالمجموعةُ تُثبت أنّ المحرّك يعمل، والمالُ الحقيقيُّ يمرّ من
        // مكانٍ آخر. (‏قِيس: صفرُ مستهلكٍ إنتاجيّ لكلتا الدالّتين.)
        //
        // فالمديرُ يغيّر الرسمَ في الشاشة ولا يتغيّر شيء، ولا خطأَ في أيّ
        // سجلّ — **وهو ما نهى عنه صاحبُ المشروع نصّاً**.
        $breakdown = app(\App\Services\FeeService::class)
            ->calculate('SEND_MONEY', (string) $request['amount'], ['applies_to' => 'customer']);

        // **والخصمُ سياسةٌ فوق الرسم لا محرّكٌ ثانٍ** — انظر `FeeDiscountPolicy`.
        $discounted = app(\App\Services\FeeDiscountPolicy::class)->applyFavouriteNumber(
            $request->user(), $receiverPhone, $breakdown['fee'], 'SEND_MONEY');

        $charge = $discounted['fee'];

        $customerTransaction = $this->customer_send_money_transaction(
            from_user_id: $request->user()->id,
            to_user_id: Helpers::get_user_id($receiverPhone),
            amount: $request['amount'],
            charge: $charge,
            feeMeta: [
                'scheme_id' => $breakdown['scheme_id'],
                'scheme_version' => $breakdown['scheme_version'],
                'discount' => $discounted['discount'],
                'discount_reason' => $discounted['reason'],
            ],
        );

        if (is_null($customerTransaction)) {
            return response()->json(['message' => translate('فشلت العملية')], 501);
        }

        /** Update Transaction limits data  */
        if(isset($sendMoneyLimit) && $sendMoneyLimit['status'] == 1){
            $transactionLimit = TransactionLimit::where(['user_id' => $request->user()->id, 'type' => 'send_money'])->first();
            $transactionLimit->user_id = $request->user()->id;
            $transactionLimit->todays_count += 1;
            $transactionLimit->todays_amount += $request['amount'];
            $transactionLimit->this_months_count += 1;
            $transactionLimit->this_months_amount += $request['amount'];
            $transactionLimit->type = 'send_money';
            $transactionLimit->updated_at = now();
            $transactionLimit->update();
        }

        return response()->json(['message' => 'success', 'transaction_id' => $customerTransaction, 'transaction_no' => Transaction::where('transaction_id', $customerTransaction)->value('transaction_no')], 200);
    }

    public function cashOut(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|min:4|max:4',
            'phone' => 'required',
            'amount' => 'required|min:0|not_in:0',
        ],
            [
                'amount.not_in' => translate('المبلغ يجب أن يكون أكبر من صفر'),
            ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $cashOutStatus = Helpers::get_business_settings('cash_out_status');

        if (!$cashOutStatus)
            return response()->json(['message' => translate('خدمة السحب النقدي غير مفعّلة حالياً')], 403);

        $receiverPhone = Helpers::filter_phone($request->phone);
        $user = $this->user->whereIn('phone', \App\Support\Phone::variants($receiverPhone))->first(); // AMIAL-FIX: مطابقة صيغ الهاتف

        if (!isset($user))
            return response()->json(['message' => translate('المستلِم غير موجود — تحقّق من الرقم')], 403); //Receiver Check

        if($user->is_kyc_verified != 1)
            return response()->json(['message' => translate('حساب المستلِم غير موثّق')], 403); //kyc check

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('تحقّق من بيانات حسابك')], 403); //kyc check

        if ($request->user()->phone == $receiverPhone)
            return response()->json(['message' => translate('لا يمكن إجراء المعاملة على رقمك نفسه')], 400); //own number check

        if($user->type != 1)
            return response()->json(['message' => translate('المستلِم يجب أن يكون وكيلاً')], 400); //'if receiver is customer' check

        if (!Helpers::pin_check($request->user()->id, $request->pin))
            return response()->json(['message' => translate('رمز PIN غير صحيح')], 403); //PIN Check

        $cashOutLimit = Helpers::get_business_settings('customer_cash_out_limit');

        if(isset($cashOutLimit) && $cashOutLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $request['amount'], 'cash_out', $cashOutLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
        }

        // AMIAL-FEE-TRUTH-001 — المحرّكُ الواحد (‏انظر `sendMoney` أعلاه).
        // والسحبُ يحمل **حصّةَ وكيل** أيضاً، وهي في المخطّط لا في
        // `business_settings` — فالقديمُ كان يُسقطها بالكامل.
        $breakdown = app(\App\Services\FeeService::class)
            ->calculate('CASH_OUT', (string) $request['amount'], ['applies_to' => 'customer']);

        $discounted = app(\App\Services\FeeDiscountPolicy::class)->applyFavouriteNumber(
            $request->user(), $receiverPhone, $breakdown['fee'], 'CASH_OUT');

        $charge = $discounted['fee'];

        $customerTransaction = $this->customer_cash_out_transaction(
            from_user_id: $request->user()->id,
            to_user_id: Helpers::get_user_id($receiverPhone),
            amount: $request['amount'],
            charge: $charge,
            // **حصّةُ الوكيل تُمرَّر من المحرّك** — والمسارُ القديم كان
            // يُسقطها، فيأخذ الوكيلُ ما يحسبه الدفترُ لا ما سعّره الأدمن.
            agentCommissionOverride: $breakdown['agent_commission'] ?? null,
            feeMeta: [
                'scheme_id' => $breakdown['scheme_id'],
                'scheme_version' => $breakdown['scheme_version'],
                'discount' => $discounted['discount'],
                'discount_reason' => $discounted['reason'],
            ],
        );

        if (is_null($customerTransaction)) return response()->json(['message' => translate('فشلت العملية')], 501);

        /** Update Transaction limits data  */
        if(isset($cashOutLimit) && $cashOutLimit['status'] == 1){
            $transactionLimit = TransactionLimit::where(['user_id' => $request->user()->id, 'type' => 'cash_out'])->first();
            $transactionLimit->user_id = $request->user()->id;
            $transactionLimit->todays_count += 1;
            $transactionLimit->todays_amount += $request['amount'];
            $transactionLimit->this_months_count += 1;
            $transactionLimit->this_months_amount += $request['amount'];
            $transactionLimit->type = 'cash_out';
            $transactionLimit->updated_at = now();
            $transactionLimit->update();
        }

        return response()->json(['message' => 'success', 'transaction_id' => $customerTransaction, 'transaction_no' => Transaction::where('transaction_id', $customerTransaction)->value('transaction_no')], 200);
    }

    public function requestMoney(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'amount' => 'required|min:0|not_in:0',
            'note' => '',
        ],
            [
                'amount.not_in' => translate('المبلغ يجب أن يكون أكبر من صفر'),
            ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $sendMoneyRequestStatus = Helpers::get_business_settings('send_money_request_status');

        if (!$sendMoneyRequestStatus)
            return response()->json(['message' => translate('خدمة طلب المال غير مفعّلة حالياً')], 403);

        $receiverPhone = Helpers::filter_phone($request->phone);
        $user = $this->user->whereIn('phone', \App\Support\Phone::variants($receiverPhone))->first(); // AMIAL-FIX: مطابقة صيغ الهاتف

        if (!isset($user))
            return response()->json(['message' => translate('المستلِم غير موجود — تحقّق من الرقم')], 403); //Receiver Check

        if($user->is_kyc_verified != 1)
            return response()->json(['message' => translate('حساب المستلِم غير موثّق')], 403); //kyc check

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('تحقّق من بيانات حسابك')], 403); //kyc check

        if ($request->user()->phone == $receiverPhone)
            return response()->json(['message' => translate('لا يمكن إجراء المعاملة على رقمك نفسه')], 400); //own number check

        if($user->type !=  2)
            return response()->json(['message' => translate('المستلِم يجب أن يكون عميلاً')], 400); //'if receiver is customer' check

        $sendMoneyRequestLimit = Helpers::get_business_settings('customer_send_money_request_limit');

        if(isset($sendMoneyRequestLimit) && $sendMoneyRequestLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $request['amount'], 'send_money_request', $sendMoneyRequestLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
        }

        $requestMoney = $this->requestMoney;
        $requestMoney->from_user_id = $request->user()->id;
        $requestMoney->to_user_id = Helpers::get_user_id($receiverPhone);
        $requestMoney->type = 'pending';
        $requestMoney->amount = $request->amount;
        $requestMoney->note = $request->note;
        $requestMoney->save();

        /** Update Transaction limits data  */
        if(isset($sendMoneyRequestLimit) && $sendMoneyRequestLimit['status'] == 1){
            $transactionLimit = TransactionLimit::where(['user_id' => $request->user()->id, 'type' => 'send_money_request'])->first();
            $transactionLimit->user_id = $request->user()->id;
            $transactionLimit->todays_count += 1;
            $transactionLimit->todays_amount += $request['amount'];
            $transactionLimit->this_months_count += 1;
            $transactionLimit->this_months_amount += $request['amount'];
            $transactionLimit->type = 'send_money_request';
            $transactionLimit->updated_at = now();
            $transactionLimit->update();
        }

        //send notification
        Helpers::send_transaction_notification($requestMoney->from_user_id, $request->amount, 'request_money', 'send_request_money');
        Helpers::send_transaction_notification($requestMoney->to_user_id, $request->amount, 'request_money');

        return response()->json(['message' => 'success'], 200);
    }

    public function requestMoneyStatus(Request $request, string $slug): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|min:4|max:4',
            'id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if (!in_array(strtolower($slug), ['deny', 'approve'])) {
            return response()->json(['message' => translate('طلب غير صالح')], 403);
        }

        $sendMoneyStatus = Helpers::get_business_settings('send_money_status');

        if (!$sendMoneyStatus)
            return response()->json(['message' => translate('خدمة تحويل المال غير مفعّلة حالياً')], 403);

        $requestMoney = $this->requestMoney->find($request->id);

        if (!isset($requestMoney))
            return response()->json(['message' => translate('الطلب غير موجود')], 404);

        if($this->user->find($requestMoney->to_user_id)->is_kyc_verified != 1)
            return response()->json(['message' => translate('حساب المستلِم غير موثّق')], 403);

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('أكمل توثيق حسابك أولاً')], 403);

        if($requestMoney->to_user_id != $request->user()->id)
            return response()->json(['message' => translate('طلب غير مصرّح به')], 403);

        if (!Helpers::pin_check($request->user()->id, $request->pin))
            return response()->json(['message' => translate('رمز PIN غير صحيح')], 403);

        if (strtolower($slug) == 'deny') {
            $requestMoney->type = 'denied';
            $requestMoney->note = $request->note;
            $requestMoney->save();

            Helpers::send_transaction_notification($requestMoney->from_user_id, $requestMoney->amount, 'denied_money');

            return response()->json(['message' => 'success'], 200);
        }

        $sendMoneyLimit = Helpers::get_business_settings('customer_send_money_limit');

        if(isset($sendMoneyLimit) && $sendMoneyLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $requestMoney->amount, 'send_money', $sendMoneyLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
        }

        // AMIAL-FEE-TRUTH-001 — **قبولُ طلب المال تحويلٌ، فيُسعَّر تسعيرَه.**
        //
        // وكان يقرأ `business_settings` كأخوَيه، **فبابٌ ثالثٌ للرسم نفسِه**
        // — ويُنسى عادةً لأنّه لا يُسمّى «تحويلاً» في الشاشة.
        $receiverPhone = $this->user->find($requestMoney->from_user_id)->phone;

        $breakdown = app(\App\Services\FeeService::class)
            ->calculate('SEND_MONEY', (string) $requestMoney->amount, ['applies_to' => 'customer']);

        $discounted = app(\App\Services\FeeDiscountPolicy::class)->applyFavouriteNumber(
            $request->user(), $receiverPhone, $breakdown['fee'], 'SEND_MONEY');

        $charge = $discounted['fee'];

        $customerTransaction = $this->customer_request_money_transaction($requestMoney->to_user_id, $requestMoney->from_user_id, $requestMoney->amount, $charge);

        if (is_null($customerTransaction)) return response()->json(['message' => translate('رصيدك غير كافٍ')], 501);

        /** Update Transaction limits data  */
        if(isset($sendMoneyLimit) && $sendMoneyLimit['status'] == 1){
            $transactionLimit = TransactionLimit::where(['user_id' => $request->user()->id, 'type' => 'send_money'])->first();
            $transactionLimit->user_id = $request->user()->id;
            $transactionLimit->todays_count += 1;
            $transactionLimit->todays_amount += $requestMoney->amount;
            $transactionLimit->this_months_count += 1;
            $transactionLimit->this_months_amount += $requestMoney->amount;
            $transactionLimit->type = 'send_money';
            $transactionLimit->updated_at = now();
            $transactionLimit->update();
        }

        $requestMoney->type = 'approved';
        $requestMoney->note = $request->note;
        $requestMoney->save();

        return response()->json(['message' => 'success', 'transaction_id' => $customerTransaction, 'transaction_no' => Transaction::where('transaction_id', $customerTransaction)->value('transaction_no')], 200);
    }

    public function addMoney(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required',
            'payment_method' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $addMoneyStatus = Helpers::get_business_settings('add_money_status');

        if (!$addMoneyStatus)
            return response()->json(['message' => translate('خدمة إضافة الرصيد غير مفعّلة حالياً')], 403);

        if($request->user()->is_kyc_verified != 1) {
            return response()->json(['message' => translate('تحقّق من بيانات حسابك')], 403);
        }

        $amount = $request->amount;
        $bonus = Helpers::get_add_money_bonus($amount, $request->user()->id, 'customer');
        $totalAmount = $amount + $bonus;

        $adminEmoney = $this->eMoney->where('user_id', Helpers::get_admin_id())->first();
        if($adminEmoney && $totalAmount > $adminEmoney->current_balance) {
            return response()->json(['message' => translate('المبلغ كبير جداً — تواصل مع الإدارة')], 403);
        }

        $addMoneyLimit = Helpers::get_business_settings('customer_add_money_limit');

        if(isset($addMoneyLimit) && $addMoneyLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $request['amount'], 'add_money', $addMoneyLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
        }

        // AMIAL-PILOT-E2E-002 — **بوّابةُ الدفع مُزالة، والاسمُ باقٍ.**
        //
        // `payment-mobile` يُنادى من هنا ومن مسار العميل، **ولا وجود له في
        // جدول المسارات** — أُزيلت حزمةُ البوّابة في استيراد v2.60 وبقي
        // النداءان. فكانت هذه النقطةُ تردّ ٥٠٠ في كلّ مرّةٍ تصلها، لأنّ
        // `route()` يرمي حين لا يجد الاسم.
        //
        // **وخمسُ مئةٍ ليست رفضاً: هي انهيار.** لا تقول للمستعمل شيئاً،
        // ولا تُفرَّق في السجلّات عن عطلٍ في قاعدة البيانات. فيُقال الغياب
        // صراحةً (القاعدة السابعة)، ويُفحص الاسمُ بدل افتراضه — فإن عادت
        // البوّابةُ يوماً عاد المسارُ يعمل بلا تعديل.
        if (!\Illuminate\Support\Facades\Route::has('payment-mobile')) {
            return response()->json([
                'message' => translate('شحن الرصيد عبر بوّابة الدفع غير متاح حالياً. لتغذية رصيدك تواصل مع الإدارة أو وكيلك.'),
                'code' => 'PAYMENT_GATEWAY_UNAVAILABLE',
            ], 503);
        }

        $userId = $request->user()->id;
        $amount = $request->amount;
        $paymentMethod = $request->payment_method;
        $link = route('payment-mobile', ['user_id' => $userId, 'amount' => $amount, 'payment_method' => $paymentMethod]);
        return response()->json(['link' => $link], 200);
    }

    public function transactionHistory(Request $request): array
    {
        $limit = $request->has('limit') ? $request->limit : 10;
        $offset = $request->has('offset') ? $request->offset : 1;

        $search = $request['search'];
        $key = explode(' ', $request['search']);

        $transactions = Transaction::with('dispute')->where('user_id', $request->user()->id);

        $transactions->when(request('transaction_type') == CASH_IN, function ($q) {
            return $q->where('transaction_type', CASH_IN);
        });
        $transactions->when(request('transaction_type') == CASH_OUT, function ($q) {
            return $q->where('transaction_type', CASH_OUT);
        });
        $transactions->when(request('transaction_type') == SEND_MONEY, function ($q) {
            return $q->where('transaction_type', SEND_MONEY);
        });
        $transactions->when(request('transaction_type') == RECEIVED_MONEY, function ($q) {
            return $q->where('transaction_type', RECEIVED_MONEY);
        });
        $transactions->when(request('transaction_type') == ADD_MONEY, function ($q) {
            return $q->where('transaction_type', ADD_MONEY);
        });
        $transactions->when(request('transaction_type') == WITHDRAW, function ($q) {
            return $q->where('transaction_type', WITHDRAW);
        });
        $transactions->when(request('transaction_type') == PAYMENT, function ($q) {
            return $q->where('transaction_type', PAYMENT);
        });
        $transactions->when(request('transaction_type') == ADDED_DISPUTE_MONEY, function ($q) {
            return $q->where('transaction_type', ADDED_DISPUTE_MONEY);
        });
        $transactions->when(request('transaction_type') == DEDUCTED_DISPUTE_MONEY, function ($q) {
            return $q->where('transaction_type', DEDUCTED_DISPUTE_MONEY);
        });

        // Filter by debit or credit based on column value
        $transactions->when($request->filled('balance_type'), function ($q) use ($request) {
            if ($request->balance_type == 'debit') {
                $q->where('debit', '>', 0);
            } elseif ($request->balance_type == 'credit') {
                $q->where('credit', '>', 0);
            }
        });

        // Filter by date range
        $transactions->when($request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request) {
            return $q->whereBetween('created_at', [
                date('Y-m-d 00:00:00', strtotime($request->start_date)),
                date('Y-m-d 23:59:59', strtotime($request->end_date)),
            ]);
        });

        $transactions = $transactions
            ->when($request->has('search'), function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->where('transaction_id', 'like', "%{$value}%");
                }
            })
            ->customer()
            ->where('transaction_type', '!=', ADMIN_CHARGE)
            ->where('transaction_type', '!=', 'agent_commission')
            ->orderBy("created_at", 'desc')
            ->paginate($limit, ['*'], 'page', $offset);

        $transactions = TransactionResource::collection($transactions);

        $adminId = helpers::get_admin_id();
        $admin = User::select(['f_name', 'l_name', 'phone', 'image'])->find($adminId);

        $adminInfo = $admin ? [
            'name' => $admin->f_name . ' ' . $admin->l_name,
            'phone' => $admin->phone,
            'image' => dynamicStorage(path: 'storage/app/public/admin/' . $admin->image),
        ] : [
            'name' => null,
            'phone' => null,
            'image' => null,
        ];

        return [
            'search' => $search,
            'transaction_type' => $request->transaction_type,
            'balance_type' => $request->balance_type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'transaction_admin_info' => $adminInfo,
            'total_size' => $transactions->total(),
            'limit' => (int)$limit,
            'offset' => (int)$offset,
            'transactions' => $transactions->items()
        ];
    }

    public function downloadTransaction(Request $request): Response
    {
        $search = $request['search'];
        $key = explode(' ', $request['search']);

        $transactions = Transaction::where('user_id', $request->user()->id);

        $transactions->when(request('transaction_type') == CASH_IN, function ($q) {
            return $q->where('transaction_type', CASH_IN);
        });
        $transactions->when(request('transaction_type') == CASH_OUT, function ($q) {
            return $q->where('transaction_type', CASH_OUT);
        });
        $transactions->when(request('transaction_type') == SEND_MONEY, function ($q) {
            return $q->where('transaction_type', SEND_MONEY);
        });
        $transactions->when(request('transaction_type') == RECEIVED_MONEY, function ($q) {
            return $q->where('transaction_type', RECEIVED_MONEY);
        });
        $transactions->when(request('transaction_type') == ADD_MONEY, function ($q) {
            return $q->where('transaction_type', ADD_MONEY);
        });
        $transactions->when(request('transaction_type') == WITHDRAW, function ($q) {
            return $q->where('transaction_type', WITHDRAW);
        });
        $transactions->when(request('transaction_type') == PAYMENT, function ($q) {
            return $q->where('transaction_type', PAYMENT);
        });
        $transactions->when(request('transaction_type') == ADDED_DISPUTE_MONEY, function ($q) {
            return $q->where('transaction_type', ADDED_DISPUTE_MONEY);
        });
        $transactions->when(request('transaction_type') == DEDUCTED_DISPUTE_MONEY, function ($q) {
            return $q->where('transaction_type', DEDUCTED_DISPUTE_MONEY);
        });

        // Filter by debit or credit based on column value
        $transactions->when($request->filled('balance_type'), function ($q) use ($request) {
            if ($request->balance_type == 'debit') {
                $q->where('debit', '>', 0);
            } elseif ($request->balance_type == 'credit') {
                $q->where('credit', '>', 0);
            }
        });

        // Filter by date range
        $transactions->when($request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request) {
            return $q->whereBetween('created_at', [
                date('Y-m-d 00:00:00', strtotime($request->start_date)),
                date('Y-m-d 23:59:59', strtotime($request->end_date)),
            ]);
        });

        $allTransactions = $transactions
            ->when($request->has('search'), function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->where('transaction_id', 'like', "%{$value}%");
                }
            })
            ->customer()
            ->where('transaction_type', '!=', ADMIN_CHARGE)
            ->where('transaction_type', '!=', 'agent_commission')
            ->orderBy("created_at", 'desc')
            ->get();

        $totalDebit = $allTransactions->sum('debit');
        $totalCredit = $allTransactions->sum('credit');

        // AMIAL-PDF-CACHE-001: كان يُصيَّر في كل طلب. كشف الحركات المفلتَر
        // من أثقل ما يُصيَّر — صفوفه بعدد حركات الفترة — ويُطلَب من الجوّال،
        // فهو أوّل من يُقطع اتصاله.
        //
        // المفتاح يشمل تلبيد المحتوى نفسه لا المرشّحات وحدها: الفترة قد
        // تمتدّ إلى اليوم فتدخلها حركة بعد لحظة، وتلبيد المحتوى يجعل أي
        // تبدّل يُنتج مفتاحاً جديداً حتماً.
        $bytes = app(\App\Services\PdfCacheService::class)->remember(
            'txn_history_' . $request->user()->id . '_'
                . sha1(json_encode([
                    $allTransactions->pluck('id')->all(),
                    (string) $totalDebit, (string) $totalCredit,
                    $request->start_date, $request->end_date, $request->transaction_type,
                ])),
            fn () => Pdf::loadView('admin-views.transaction.statement', [
                'transactions' => $allTransactions,
                'user' => $request->user(),
                'totalDebit' => $totalDebit,
                'totalCredit' => $totalCredit,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'transaction_type' => $request->transaction_type,
            ])->output(),
        );

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="transaction-history.pdf"',
        ]);
    }

    public function withdrawalMethods(Request $request): JsonResponse
    {
        $withdrawalMethods = $this->withdrawalMethod->latest()->get();
        return response()->json(response_formatter(DEFAULT_200, $withdrawalMethods, null), 200);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|min:4|max:4',
            'amount' => 'required|min:0|not_in:0',
            'note' => 'max:255',
            'withdrawal_method_id' => 'required',
            'withdrawal_method_fields' => 'required',
        ],
            [
                'amount.not_in' => translate('المبلغ يجب أن يكون أكبر من صفر'),
            ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $withdrawRequestStatus = Helpers::get_business_settings('withdraw_request_status');

        if (!$withdrawRequestStatus)
            return response()->json(['message' => translate('خدمة طلب السحب غير مفعّلة حالياً')], 403);

        if($request->user()->is_kyc_verified != 1) {
            return response()->json(['message' => translate('حسابك غير موثّق — أكمل توثيق حسابك أولاً')], 403);
        }

        $withdrawalMethod = $this->withdrawalMethod->find($request->withdrawal_method_id);
        $fields = array_column($withdrawalMethod->method_fields, 'input_name');

        $values = (array)json_decode($request->withdrawal_method_fields)[0];

        foreach ($fields as $field) {
            if(!key_exists($field, $values)) {
                return response()->json(response_formatter(DEFAULT_400, $fields, null), 400);
            }
        }

        $withdrawRequestLimit = Helpers::get_business_settings('customer_withdraw_request_limit');

        if(isset($withdrawRequestLimit) && $withdrawRequestLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $request['amount'], 'withdraw_request', $withdrawRequestLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
        }

        $amount = $request->amount;

        // AMIAL-FEE-TRUTH-001 — **السحبُ إلى وسيلةٍ خارجيّة عمليّةٌ مسعَّرة.**
        //
        // وكان يقرأ `withdraw_charge_percent` من `business_settings` —
        // **ولا حقلَ له في «إدارة الرسوم» أصلاً**، فهو رسمٌ يُخصَم من
        // العميل ولا يستطيع الأدمنُ رؤيتَه ولا تغييرَه من مركزه.
        // فأُضيف الرمزُ `WITHDRAW` إلى السجلّ وصار مسعَّراً كغيره.
        $breakdown = app(\App\Services\FeeService::class)
            ->calculate('WITHDRAW', (string) $amount, ['applies_to' => 'customer']);

        $charge = $breakdown['fee'];
        $totalAmount = \App\Services\MoneyService::add($amount, $charge);

        $withdrawRequest = $this->withdrawRequest;
        $withdrawRequest->user_id = $request->user()->id;
        $withdrawRequest->amount = $amount;
        $withdrawRequest->admin_charge = $charge;
        $withdrawRequest->request_status = 'pending';
        $withdrawRequest->is_paid = 0;
        $withdrawRequest->sender_note = $request->sender_note;
        $withdrawRequest->withdrawal_method_id = $request->withdrawal_method_id;
        $withdrawRequest->withdrawal_method_fields = $values;


        $userEmoney = $this->eMoney->where('user_id', $request->user()->id)->first();
        if ($userEmoney->current_balance < $totalAmount) {
            return response()->json(['message' => translate('رصيدك غير كافٍ')], 403);
        }

        $userEmoney->current_balance -= $totalAmount;
        $userEmoney->pending_balance += $totalAmount;

        DB::transaction(function () use ($withdrawRequest, $userEmoney, $totalAmount) {
            $withdrawRequest->save();
            $userEmoney->save();

            // AMIAL-LEDGER-WITHDRAW-001: حجز المبلغ في الدفتر.
            //
            // كان هذا المسار بلا ترحيل إطلاقاً: يخرج المال من الرصيد المتاح
            // إلى المعلّق ولا يقول الدفتر شيئاً — فيبدو رصيد العميل متاحاً
            // وهو محجوز، ودفترٌ يقول ذلك يُبنى عليه قرارٌ خاطئ.
            $this->ledgerWithdrawRequested(
                userId: $userEmoney->user_id,
                total: (string) $totalAmount,
                sourceId: (string) $withdrawRequest->id,
            );
        });

        /** Update Transaction limits data  */
        if(isset($withdrawRequestLimit) && $withdrawRequestLimit['status'] == 1){
            $transactionLimit = TransactionLimit::where(['user_id' => $request->user()->id, 'type' => 'withdraw_request'])->first();
            $transactionLimit->user_id = $request->user()->id;
            $transactionLimit->todays_count += 1;
            $transactionLimit->todays_amount += $request['amount'];
            $transactionLimit->this_months_count += 1;
            $transactionLimit->this_months_amount += $request['amount'];
            $transactionLimit->type = 'withdraw_request';
            $transactionLimit->updated_at = now();
            $transactionLimit->update();
        }

        return response()->json(response_formatter(DEFAULT_STORE_200, null, null), 200);
    }
}
