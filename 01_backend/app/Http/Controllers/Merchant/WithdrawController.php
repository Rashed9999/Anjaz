<?php

namespace App\Http\Controllers\Merchant;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\User;
use App\Models\WithdrawalMethod;
use App\Models\WithdrawRequest;
use App\Services\MoneyService;
use App\Traits\PostsToLedger;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WithdrawController extends Controller
{
    use PostsToLedger;

    public function __construct(
        private WithdrawRequest $withdrawRequest,
        private WithdrawalMethod $withdrawalMethod,
        private User $user,
        private EMoney $eMoney
    ) {}

    public function list(Request $request): View
    {
        $userId = auth()->id();
        $search = $request->input('search');
        $requestStatus = $request->input('request_status', 'all');
        $method = $request->input('withdrawal_method', 'all');

        $queryParams = array_filter([
            'search'      => $search,
            'request_status'    => $requestStatus,
            'withdrawal_method'   => $method
        ]);

        $withdrawRequests = $this->withdrawRequest
            ->with(['user', 'withdrawal_method'])
            ->where('user_id', $userId)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('f_name', 'like', "%{$search}%")
                            ->orWhere('l_name', 'like', "%{$search}%");
                    });
                });
            })
            ->when($requestStatus !== 'all', function ($query) use ($requestStatus) {
                $query->where('request_status', $requestStatus);
            })
            ->when($method !== 'all', function ($query) use ($method) {
                $query->where('withdrawal_method_id', $method);
            });

        $withdrawRequests = $withdrawRequests->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);
        $withdrawalMethods = $this->withdrawalMethod->latest()->get();

        return view('merchant-views.withdraw.list', compact('withdrawRequests', 'withdrawalMethods', 'method', 'search', 'requestStatus'));
    }

    public function withdrawRequests(): View
    {
        $withdrawalMethods = $this->withdrawalMethod->latest()->get();
        $merchantEmoney = $this->eMoney->where(['user_id' => auth()->user()->id])->first();
        $maximumAmount = $merchantEmoney->current_balance;

        return view('merchant-views.withdraw.index', compact('withdrawalMethods', 'maximumAmount'));
    }

    public function withdrawRequestStore(Request $request): RedirectResponse
    {
        $request->validate([
            'amount' => 'required|min:0|not_in:0',
            'note' => 'max:255',
            'withdrawal_method_id' => 'required',
        ], [
            'amount.not_in' => translate('Amount must be greater than zero!'),
        ]);

        $withdrawalMethod = $this->withdrawalMethod->find($request->withdrawal_method_id);
        $fields = array_column($withdrawalMethod->method_fields, 'input_name');
        $values = $request->all();
        $data = [];

        foreach ($fields as $field) {
            if (key_exists($field, $values)) {
                $data[$field] = $values[$field];
            }
        }

        $amount = MoneyService::normalize($request->amount);
        $charge = MoneyService::normalize(0);
        $totalAmount = MoneyService::add($amount, $charge);

        try {
            DB::beginTransaction();

            $merchantEmoney = $this->eMoney
                ->where('user_id', auth()->user()->id)
                ->lockForUpdate()
                ->first();

            if (!$merchantEmoney || !MoneyService::gte((string) $merchantEmoney->current_balance, $totalAmount)) {
                DB::rollBack();
                Toastr::warning(translate('Your account do not have enough balance.'));
                return back();
            }

            $withdrawRequest = $this->withdrawRequest;
            $withdrawRequest->user_id = auth()->user()->id;
            $withdrawRequest->amount = $amount;
            $withdrawRequest->admin_charge = $charge;
            $withdrawRequest->request_status = 'pending';
            $withdrawRequest->is_paid = 0;
            $withdrawRequest->sender_note = $request->sender_note;
            $withdrawRequest->withdrawal_method_id = $request->withdrawal_method_id;
            $withdrawRequest->withdrawal_method_fields = $data;
            $withdrawRequest->save();

            $merchantEmoney->current_balance = MoneyService::sub((string) $merchantEmoney->current_balance, $totalAmount);
            $merchantEmoney->pending_balance = MoneyService::add((string) $merchantEmoney->pending_balance, $totalAmount);
            $merchantEmoney->save();

            $this->ledgerWithdrawRequested((int) $merchantEmoney->user_id, $totalAmount, (string) $withdrawRequest->id);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Toastr::warning(translate('Withdraw request send failed!'));
            return back();
        }

        Toastr::success(translate('Withdraw request send !'));
        return \Illuminate\Support\Facades\Route::has('merchant.withdraw.list')
            ? redirect()->route('merchant.withdraw.list')
            : back();
    }

    public function withdrawMethod(Request $request): JsonResponse
    {
        $method = $this->withdrawalMethod->where(['id' => $request->withdrawal_method_id])->first();

        return response()->json($method, 200);
    }

    public function download(Request $request): StreamedResponse|string
    {
        $withdrawRequests = $this->withdrawRequest
            ->when($request->has('search'), function ($query) use ($request) {
                $key = explode(' ', $request['search']);

                $userIds = $this->user->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('id', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%")
                            ->orWhere('f_name', 'like', "%{$value}%")
                            ->orWhere('l_name', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%");
                    }
                })->get()->pluck('id')->toArray();

                return $query->whereIn('user_id', $userIds);
            })
            ->with('user', 'withdrawal_method')
            ->when($request->has('withdrawal_method') && $request->withdrawal_method != 'all', function ($query) use ($request) {
                return $query->where('withdrawal_method_id', $request->withdrawal_method);
            })
            ->where('user_id', auth()->user()->id)
            ->get();

        $storage = [];

        foreach ($withdrawRequests as $key => $withdrawRequest) {
            if (!is_null($withdrawRequest->user) && !is_null($withdrawRequest->withdrawal_method_fields)) {
                $data = [
                    'No' => $key + 1,
                    'UserName' => $withdrawRequest->user->f_name . ' ' . $withdrawRequest->user->l_name,
                    'UserPhone' => $withdrawRequest->user->phone,
                    'UserEmail' => $withdrawRequest->user->email,
                    'MethodName' => $withdrawRequest->withdrawal_method->method_name ?? '',
                    'Amount' => $withdrawRequest->amount,
                    'RequestStatus' => $withdrawRequest->request_status,
                ];

                $storage[] = array_merge($data, $withdrawRequest->withdrawal_method_fields);
            }
        }

        return (new FastExcel($storage))->download(time() . '-file.xlsx');
    }
}
