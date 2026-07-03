<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Exceptions\TransactionFailedException;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EMoneyController extends Controller
{
    public function __construct(
        private EMoney $eMoney,
    )
    {
    }

    public function index(): View
    {
        $usedBalance = $this->eMoney->with('user')
            ->where('user_id', '!=', Helpers::get_admin_id())
            ->sum('current_balance');

        $unusedBalance = $this->eMoney->with('user')
            ->where('user_id', Helpers::get_admin_id())
            ->sum('current_balance');

        $pendingBalance = $this->eMoney->with('user')->sum('pending_balance');

        $totalBalance = $usedBalance + $unusedBalance;
        $chargeEarned = $this->eMoney->with('user')->find(Auth::id())->charge_earned ?? 0;

        $balance = [];
        $balance['total_balance'] = $totalBalance;
        $balance['used_balance'] = $usedBalance + $pendingBalance;
        $balance['unused_balance'] = $unusedBalance;
        $balance['total_earned'] = $chargeEarned;
        return view('admin-views.emoney.index', compact('balance'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ], [
            'amount.regex' => translate('Amount must be a valid number with up to 12 digits before the decimal point and up to 2 digits after the decimal point.'),
            'amount.required' => translate('Amount is required.'),
        ]);

        DB::beginTransaction();
        $data = [];
        $data['from_user_id'] = Helpers::get_admin_id();
        $data['to_user_id'] = $data['from_user_id'];


        try {
            $data['user_id'] = $data['from_user_id'];
            $data['type'] = 'credit';
            $data['transaction_type'] = CASH_IN;
            $data['ref_trans_id'] = null;
            $data['amount'] = $request->amount;

            $adminTransaction = Helpers::make_transaction($data);

            Helpers::send_transaction_notification($data['user_id'], $data['amount'], $data['transaction_type']);

            if ($adminTransaction == null) {
                throw new TransactionFailedException('Transaction from receiver is failed');
            }

            DB::commit();
            Toastr::success(translate('EMoney generated successfully!'));

        } catch (TransactionFailedException $e) {
            DB::rollBack();
            Toastr::error(translate('Something went wrong!'));
        }
        return back();
    }
}
