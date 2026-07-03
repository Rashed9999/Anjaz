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
                'amount.not_in' => translate('Amount must be greater than zero!'),
            ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $sendMoneyStatus = Helpers::get_business_settings('send_money_status');

        if (!$sendMoneyStatus)
            return response()->json(['message' => translate('send money feature is not activate')], 403);

        $receiverPhone = Helpers::filter_phone($request->phone);
        $user = $this->user->where('phone', $receiverPhone)->first();

        if (!isset($user))
            return response()->json(['message' => translate('Receiver not found')], 403); //Receiver Check

        if($user->is_kyc_verified != 1)
            return response()->json(['message' => translate('Receiver is not verified')], 403); //kyc check

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('Complete your account verification')], 403); //kyc check

        if ($request->user()->phone == $receiverPhone)
            return response()->json(['message' => translate('Transaction should not with own number')], 400); //own number check

        if($user->type != 2)
            return response()->json(['message' => translate('Receiver must be a user')], 400); //'if receiver is customer' check

        if (!Helpers::pin_check($request->user()->id, $request->pin))
            return response()->json(['message' => translate('PIN is incorrect')], 403); //PIN Check

        $sendMoneyLimit = Helpers::get_business_settings('customer_send_money_limit');

        if(isset($sendMoneyLimit) && $sendMoneyLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $request['amount'], 'send_money', $sendMoneyLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
        }

        $charge = Helpers::get_sendmoney_charge();
        $favouriteNumberStatus = helpers::get_business_settings('favorite_number_status') ?? 0;

        if ($favouriteNumberStatus){
            $favouriteList = FavouriteNumber::where('user_id', $request->user()->id)->pluck('phone')->toArray();
            $isFavouriteExist = in_array($receiverPhone, $favouriteList);

            if ($isFavouriteExist){
                $discountOnCharge = (float) helpers::get_business_settings('favorite_number_send_money_charge_discount') ?? 0;

                if ($discountOnCharge > 0) {
                    $discountAmount = $charge * ($discountOnCharge / 100);
                    $charge -= $discountAmount;
                }
            }
        }

        $customerTransaction = $this->customer_send_money_transaction($request->user()->id, Helpers::get_user_id($receiverPhone), $request['amount'], $charge);

        if (is_null($customerTransaction)) {
            return response()->json(['message' => translate('fail')], 501);
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

        return response()->json(['message' => 'success', 'transaction_id' => $customerTransaction], 200);
    }

    public function cashOut(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|min:4|max:4',
            'phone' => 'required',
            'amount' => 'required|min:0|not_in:0',
        ],
            [
                'amount.not_in' => translate('Amount must be greater than zero!'),
            ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $cashOutStatus = Helpers::get_business_settings('cash_out_status');

        if (!$cashOutStatus)
            return response()->json(['message' => translate('cash out feature is not activate')], 403);

        $receiverPhone = Helpers::filter_phone($request->phone);
        $user = $this->user->where('phone', $receiverPhone)->first();

        if (!isset($user))
            return response()->json(['message' => translate('Receiver not found')], 403); //Receiver Check

        if($user->is_kyc_verified != 1)
            return response()->json(['message' => translate('Receiver is not verified')], 403); //kyc check

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('Verify your account information')], 403); //kyc check

        if ($request->user()->phone == $receiverPhone)
            return response()->json(['message' => translate('Transaction should not with own number')], 400); //own number check

        if($user->type != 1)
            return response()->json(['message' => translate('Receiver must be an agent')], 400); //'if receiver is customer' check

        if (!Helpers::pin_check($request->user()->id, $request->pin))
            return response()->json(['message' => translate('PIN is incorrect')], 403); //PIN Check

        $cashOutLimit = Helpers::get_business_settings('customer_cash_out_limit');

        if(isset($cashOutLimit) && $cashOutLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $request['amount'], 'cash_out', $cashOutLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
        }

        $charge = Helpers::get_cashout_charge($request['amount']);
        $favouriteNumberStatus = helpers::get_business_settings('favorite_number_status') ?? 0;

        if ($favouriteNumberStatus){
            $favouriteList = FavouriteNumber::where('user_id', $request->user()->id)->pluck('phone')->toArray();
            $isFavouriteExist = in_array($receiverPhone, $favouriteList);

            if ($isFavouriteExist){
                $discountOnCharge = (float) helpers::get_business_settings('favorite_number_cash_out_charge_discount') ?? 0;

                if ($discountOnCharge > 0) {
                    $discountAmount = $charge * ($discountOnCharge / 100);
                    $charge -= $discountAmount;
                }
            }
        }

        $customerTransaction = $this->customer_cash_out_transaction($request->user()->id, Helpers::get_user_id($receiverPhone), $request['amount'], $charge);

        if (is_null($customerTransaction)) return response()->json(['message' => translate('fail')], 501);

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

        return response()->json(['message' => 'success', 'transaction_id' => $customerTransaction], 200);
    }

    public function requestMoney(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'amount' => 'required|min:0|not_in:0',
            'note' => '',
        ],
            [
                'amount.not_in' => translate('Amount must be greater than zero!'),
            ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $sendMoneyRequestStatus = Helpers::get_business_settings('send_money_request_status');

        if (!$sendMoneyRequestStatus)
            return response()->json(['message' => translate('request money feature is not activate')], 403);

        $receiverPhone = Helpers::filter_phone($request->phone);
        $user = $this->user->where('phone', $receiverPhone)->first();

        if (!isset($user))
            return response()->json(['message' => translate('Receiver not found')], 403); //Receiver Check

        if($user->is_kyc_verified != 1)
            return response()->json(['message' => translate('Receiver is not verified')], 403); //kyc check

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('Verify your account information')], 403); //kyc check

        if ($request->user()->phone == $receiverPhone)
            return response()->json(['message' => translate('Transaction should not with own number')], 400); //own number check

        if($user->type !=  2)
            return response()->json(['message' => translate('Receiver must be a user')], 400); //'if receiver is customer' check

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
            return response()->json(['message' => translate('Invalid request')], 403);
        }

        $sendMoneyStatus = Helpers::get_business_settings('send_money_status');

        if (!$sendMoneyStatus)
            return response()->json(['message' => translate('send money feature is not activate')], 403);

        $requestMoney = $this->requestMoney->find($request->id);

        if (!isset($requestMoney))
            return response()->json(['message' => translate('Request not found')], 404);

        if($this->user->find($requestMoney->to_user_id)->is_kyc_verified != 1)
            return response()->json(['message' => translate('Receiver is not verified')], 403);

        if($request->user()->is_kyc_verified != 1)
            return response()->json(['message' => translate('Complete your account verification')], 403);

        if($requestMoney->to_user_id != $request->user()->id)
            return response()->json(['message' => translate('unauthorized request')], 403);

        if (!Helpers::pin_check($request->user()->id, $request->pin))
            return response()->json(['message' => translate('PIN is incorrect')], 403);

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

        $charge = Helpers::get_sendmoney_charge();
        $favouriteNumberStatus = helpers::get_business_settings('favorite_number_status') ?? 0;
        $receiverPhone = $this->user->find($requestMoney->from_user_id)->phone;

        if ($favouriteNumberStatus){
            $favouriteList = FavouriteNumber::where('user_id', $request->user()->id)->pluck('phone')->toArray();
            $isFavouriteExist = in_array($receiverPhone, $favouriteList);

            if ($isFavouriteExist){
                $discountOnCharge = (float) helpers::get_business_settings('favorite_number_send_money_charge_discount') ?? 0;

                if ($discountOnCharge > 0) {
                    $discountAmount = $charge * ($discountOnCharge / 100);
                    $charge -= $discountAmount;
                }
            }
        }

        $customerTransaction = $this->customer_request_money_transaction($requestMoney->to_user_id, $requestMoney->from_user_id, $requestMoney->amount, $charge);

        if (is_null($customerTransaction)) return response()->json(['message' => translate('You have not sufficient balance')], 501);

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

        return response()->json(['message' => 'success', 'transaction_id' => $customerTransaction], 200);
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
            return response()->json(['message' => translate('add money feature is not activate')], 403);

        if($request->user()->is_kyc_verified != 1) {
            return response()->json(['message' => translate('Verify your account information')], 403);
        }

        $amount = $request->amount;
        $bonus = Helpers::get_add_money_bonus($amount, $request->user()->id, 'customer');
        $totalAmount = $amount + $bonus;

        $adminEmoney = $this->eMoney->where('user_id', Helpers::get_admin_id())->first();
        if($adminEmoney && $totalAmount > $adminEmoney->current_balance) {
            return response()->json(['message' => translate('The amount is too big. Please contact with admin')], 403);
        }

        $addMoneyLimit = Helpers::get_business_settings('customer_add_money_limit');

        if(isset($addMoneyLimit) && $addMoneyLimit['status'] == 1){
            $check = Helpers::check_customer_transaction_limit($request->user(), $request['amount'], 'add_money', $addMoneyLimit);
            if (!$check['status']){
                return response()->json(['message' => translate($check['message'])], 400);
            }
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

        $pdf = Pdf::loadView('admin-views.transaction.statement', [
            'transactions' => $allTransactions,
            'user' => $request->user(),
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'transaction_type' => $request->transaction_type,
        ]);

        return $pdf->download('transaction-history.pdf');
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
                'amount.not_in' => translate('Amount must be greater than zero!'),
            ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $withdrawRequestStatus = Helpers::get_business_settings('withdraw_request_status');

        if (!$withdrawRequestStatus)
            return response()->json(['message' => translate('withdraw request feature is not activate')], 403);

        if($request->user()->is_kyc_verified != 1) {
            return response()->json(['message' => translate('Your account is not verified, Complete your account verification')], 403);
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
        $charge = helpers::get_withdraw_charge($amount);
        $totalAmount = $amount + $charge;

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
            return response()->json(['message' => translate('Your account do not have enough balance')], 403);
        }

        $userEmoney->current_balance -= $totalAmount;
        $userEmoney->pending_balance += $totalAmount;

        DB::transaction(function () use ($withdrawRequest, $userEmoney) {
            $withdrawRequest->save();
            $userEmoney->save();
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
