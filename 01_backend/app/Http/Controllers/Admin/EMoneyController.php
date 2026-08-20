<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Services\PlatformTreasuryService;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EMoneyController extends Controller
{
    public function __construct(
        private EMoney $eMoney,
        private readonly PlatformTreasuryService $treasury,
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
            'reference' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'amount.regex' => translate('Amount must be a valid number with up to 12 digits before the decimal point and up to 2 digits after the decimal point.'),
            'amount.required' => translate('Amount is required.'),
        ]);

        try {
            $issued = $this->treasury->issueAdminFloat(
                $request->input('amount'), $request->user(),
                $request->input('reference'), $request->input('reason'),
                $request->header('Idempotency-Key'),
            );
            Helpers::send_transaction_notification(
                Helpers::get_admin_id(), (float) $request->input('amount'), CASH_IN);
            Toastr::success($issued['duplicate']
                ? translate('This treasury issuance was already recorded.')
                : translate('Treasury issuance posted successfully.'));
        } catch (\Throwable $e) {
            Toastr::error($e->getMessage() ?: translate('Something went wrong!'));
        }
        return back();
    }
}
